<?php
// File: includes/rest-api.php
// REST API endpoints for ST Virtual Cards transactions & refunds

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

global $wpdb;

// Ensure idempotency table exists (run once, e.g., on plugin activation)
function st_vpc_create_idempotency_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'st_vpc_idempotency';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS `{$table_name}` (
      `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      `idem_key` VARCHAR(191) NOT NULL,
      `response` LONGTEXT NOT NULL,
      `created_at` DATETIME NOT NULL,
      PRIMARY KEY (`id`),
      UNIQUE KEY `uniq_idem_key` (`idem_key`)
    ) ENGINE=InnoDB {$charset_collate};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
}
register_activation_hook( __FILE__, 'st_vpc_create_idempotency_table' );

/**
 * 1) Register REST API endpoints
 */
add_action( 'rest_api_init', 'st_register_transactions_api' );
function st_register_transactions_api() {
    register_rest_route(
        'st-vpc/v1',
        '/transactions',
        array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => 'st_vpc_api_create_transaction',
            'permission_callback' => 'st_vpc_api_key_permission',
        )
    );

    register_rest_route(
        'st-vpc/v1',
        '/refund',
        array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => 'st_vpc_api_create_refund',
            'permission_callback' => 'st_vpc_api_key_permission',
        )
    );
}

/**
 * 2) API key permission check
 */
function st_vpc_api_key_permission( WP_REST_Request $request ) {
    global $wpdb;

    $api_key = $request->get_header( 'x-api-key' );
    if ( empty( $api_key ) ) {
        return new WP_Error( 'no_api_key', 'مفتاح API مفقود.', [ 'status' => 401 ] );
    }

    $table = $wpdb->prefix . 'st_api_keys';
    $row   = $wpdb->get_row( $wpdb->prepare(
        "SELECT id, user_id, status FROM {$table} WHERE api_key = %s",
        $api_key
    ) );

    if ( ! $row ) {
        return new WP_Error( 'invalid_key', 'مفتاح API غير موجود.', [ 'status' => 403 ] );
    }
    if ( $row->status !== 'active' ) {
        return new WP_Error( 'inactive_key', 'مفتاح API معطّل.', [ 'status' => 403 ] );
    }

    $request->set_param( 'api_id', (int) $row->id );
    $request->set_param( 'merchant_user', (int) $row->user_id );

    return true;
}


/**
 * 3) Process payment (freeze) with idempotency and send notifications:
 *    – Customer (card owner via user_id)
 *    – Merchant (api_id)
 */
function st_vpc_api_create_transaction( WP_REST_Request $request ) {
    global $wpdb;
    $cards_table   = $wpdb->prefix . 'st_virtual_cards';
    $txns_table    = $wpdb->prefix . 'merchant_wallet_transactions';
    $wallet_table  = $wpdb->prefix . 'st_merchant_wallet';
    $idem_table    = $wpdb->prefix . 'st_vpc_idempotency';

    // 1) Read Idempotency-Key header
    $idem_key = $request->get_header( 'Idempotency-Key' );
    if ( empty( $idem_key ) ) {
        return new WP_Error( 'no_idempotency_key', 'Idempotency-Key header is required.', [ 'status' => 400 ] );
    }

    // 2) Idempotency: return stored response if exists
    $prev = $wpdb->get_var( $wpdb->prepare(
        "SELECT response FROM {$idem_table} WHERE idem_key = %s",
        $idem_key
    ) );
    if ( $prev ) {
        return rest_ensure_response( json_decode( $prev, true ) );
    }

    // 3) Retrieve & validate input parameters
    $card_number_raw = $request->get_param( 'card_number' );
    $cvv             = $request->get_param( 'cvv' );
    $amount          = floatval( $request->get_param( 'amount' ) );
    $order_id        = intval( $request->get_param( 'order_id' ) ) ?: 0;
    $api_id          = intval( $request->get_param( 'api_id' ) );
    $merchant_id     = intval( $request->get_param( 'merchant_user' ) );

    if ( $amount <= 0 ) {
        return new WP_Error( 'invalid_amount', 'Amount must be greater than zero.', [ 'status' => 400 ] );
    }

    // 4) Validate the card record, include card_number & owner user_id
    $clean_number = preg_replace( '/\s+/', '', $card_number_raw );
    $card = $wpdb->get_row( $wpdb->prepare(
        "SELECT id, card_number, frozen_amount, expires_at, status, user_id
           FROM {$cards_table}
          WHERE REPLACE(card_number, ' ', '') = %s
            AND cvv = %s",
        $clean_number, $cvv
    ) );
    if ( ! $card || $card->status !== 'active' ) {
        return new WP_Error( 'invalid_card', 'The card is invalid or disabled.', [ 'status' => 400 ] );
    }
    if ( strtotime( $card->expires_at ) < current_time( 'timestamp' ) ) {
        return new WP_Error( 'expired_card', 'The card has expired.', [ 'status' => 400 ] );
    }
    if ( floatval( $card->frozen_amount ) < $amount ) {
        return new WP_Error( 'insufficient_funds', 'The card does not have sufficient frozen balance.', [ 'status' => 400 ] );
    }

    // 5) Freeze the requested amount on the card
    $new_card_balance = floatval( $card->frozen_amount ) - $amount;
    $wpdb->update(
        $cards_table,
        [
            'frozen_amount' => $new_card_balance,
            'status'        => $new_card_balance > 0 ? 'active' : 'used',
        ],
        [ 'id' => $card->id ],
        [ '%f', '%s' ],
        [ '%d' ]
    );

    // 6) Credit the merchant's wallet
    $wallet = $wpdb->get_row( $wpdb->prepare(
        "SELECT id, balance FROM {$wallet_table} WHERE user_id = %d",
        $merchant_id
    ) );
    if ( $wallet ) {
        $wpdb->update(
            $wallet_table,
            [ 'balance' => floatval( $wallet->balance ) + $amount ],
            [ 'id' => $wallet->id ],
            [ '%f' ],
            [ '%d' ]
        );
    } else {
        $wpdb->insert(
            $wallet_table,
            [ 'user_id' => $merchant_id, 'balance' => $amount ],
            [ '%d', '%f' ]
        );
    }

    // 7) Record the transaction
    $wpdb->insert(
        $txns_table,
        [
            'api_id'           => $api_id,
            'user_id'          => $merchant_id,
            'amount'           => $amount,
            'order_id'         => $order_id,
            'card_id'          => $card->id,
            'transaction_type' => 'payment',
            'transaction_date' => current_time( 'mysql' ),
            'freeze_until'     => null,
            'refund_status'    => 'none',
        ],
        [ '%d','%d','%f','%d','%d','%s','%s','%s','%s' ]
    );
    $txn_id = $wpdb->insert_id;

    // Prepare notification data
    $digits     = preg_replace( '/\D/', '', $card->card_number );
    $last_four  = substr( $digits, -4 );
    $customer_id = intval( $card->user_id );
 $paid_number = number_format_i18n( floatval( $amount ), 4 );


    // 8a) Notify the customer (card owner)
// 8a) Notify the customer (card owner)
if ( $customer_id && file_exists( WP_PLUGIN_DIR . '/st-mining2/notifications.php' ) ) {
    require_once WP_PLUGIN_DIR . '/st-mining2/notifications.php';
    if ( function_exists( 'add_notification' ) ) {
        // صياغة المبلغ رقميًا فقط بدون رمز العملة
        $paid_number = number_format_i18n( floatval( $amount ), 4 );

        add_notification(
            $customer_id,
            sprintf(
                'تم إجراء عملية دفع للطلب <strong>#%d</strong> بمبلغ <strong>%s ST</strong> باستخدام البطاقة رقم <strong>%s</strong>.',
                $order_id,
                $paid_number,
                $last_four
            )
        );
    }
}

// 8b) Notify the merchant (api_id)
if ( file_exists( WP_PLUGIN_DIR . '/st-mining2/notifications.php' ) ) {
    require_once WP_PLUGIN_DIR . '/st-mining2/notifications.php';
    if ( function_exists( 'add_notification' ) ) {
        // إعادة استخدام نفس الصياغة
        $paid_number = number_format_i18n( floatval( $amount ), 4 );

        add_notification(
            $merchant_id,
            sprintf(
                'لقد تلقيت مبلغ <strong>%s ST</strong> من دفع الطلب <strong>#%d</strong> من البطاقة رقم <strong>%s</strong>.',
                $paid_number,
                $order_id,
                $last_four
            )
        );
    }
}

    // 9) Prepare & store idempotent response
    $response_data = [
        'transaction_id' => $txn_id,
        'status'         => 'frozen',
    ];
    $wpdb->insert(
        $idem_table,
        [
            'idem_key'   => $idem_key,
            'response'   => wp_json_encode( $response_data ),
            'created_at' => current_time( 'mysql' ),
        ],
        [ '%s','%s','%s' ]
    );

    // 10) Return the response
    return rest_ensure_response( $response_data );
}


/**
 * 4) Process refund with idempotency and send notifications:
 *    – Customer (card owner via user_id)
 *    – Merchant (api_id)
 */
function st_vpc_api_create_refund( WP_REST_Request $request ) {
    global $wpdb;

    $txns_table  = $wpdb->prefix . 'merchant_wallet_transactions';
    $cards_table = $wpdb->prefix . 'st_virtual_cards';
    $idem_table  = $wpdb->prefix . 'st_vpc_idempotency';

    // 1) Read Idempotency-Key header
    $idem_key = $request->get_header( 'Idempotency-Key' );
    if ( empty( $idem_key ) ) {
        return new WP_Error( 'no_idempotency_key', 'Idempotency-Key header is required.', [ 'status' => 400 ] );
    }

    // 2) Idempotency: return stored response if exists
    $prev = $wpdb->get_var( $wpdb->prepare(
        "SELECT response FROM {$idem_table} WHERE idem_key = %s",
        $idem_key
    ) );
    if ( $prev ) {
        return rest_ensure_response( json_decode( $prev, true ) );
    }

    // 3) Get & validate input params
    $order_id = intval( $request->get_param( 'order_id' ) );
    $amount   = round( floatval( $request->get_param( 'amount' ) ), 5 );
    if ( ! $order_id || $amount <= 0 ) {
        return new WP_Error( 'missing_params', 'بيانات ناقصة أو المبلغ غير صالح.', [ 'status' => 400 ] );
    }

    // 4) Find original payment txn
    $txn = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$txns_table}
         WHERE order_id = %d
           AND transaction_type = 'payment'
         ORDER BY id DESC
         LIMIT 1",
        $order_id
    ) );
    if ( ! $txn ) {
        return new WP_Error( 'txn_not_found', "لم يتم العثور على معاملة دفع للطلب #{$order_id}.", [ 'status' => 404 ] );
    }

    // 5) Ensure refund amount ≤ original paid amount
    $original_amount = floatval( $txn->amount );
    if ( $amount > $original_amount ) {
        return new WP_Error( 'invalid_amount', sprintf(
            'المبلغ المطلوب استرداده (%.5f) أكبر من المبلغ المدفوع (%.5f).',
            $amount, $original_amount
        ), [ 'status' => 400 ] );
    }

    // 6) Fetch the virtual card record, include owner user_id
    $card_id = intval( $txn->card_id );
    $card    = $wpdb->get_row( $wpdb->prepare(
        "SELECT card_number, frozen_amount, status, user_id
           FROM {$cards_table}
          WHERE id = %d",
        $card_id
    ) );
    if ( ! $card ) {
        return new WP_Error( 'card_not_found', "البطاقة (#{$card_id}) غير موجودة.", [ 'status' => 404 ] );
    }

    // 7) Compute merchant wallet current balance
    $merchant_id     = intval( $txn->user_id );
    $current_balance = floatval( $wpdb->get_var( $wpdb->prepare(
        "SELECT COALESCE(SUM(amount),0) FROM {$txns_table} WHERE user_id = %d",
        $merchant_id
    ) ) );
    if ( $current_balance < $amount ) {
        return new WP_Error( 'insufficient_wallet', sprintf(
            'رصيد المحفظة الحالي (%.5f) غير كافٍ لاسترداد (%.5f).',
            $current_balance, $amount
        ), [ 'status' => 400 ] );
    }

    // 8) Restore amount to the card's frozen_amount and adjust status
    $new_card_balance = floatval( $card->frozen_amount ) + $amount;
    $new_status       = ( $card->status === 'used' ? 'active' : $card->status );
    $wpdb->update(
        $cards_table,
        [ 'frozen_amount' => $new_card_balance, 'status' => $new_status ],
        [ 'id'            => $card_id ],
        [ '%f', '%s' ],
        [ '%d' ]
    );

    // 9) Insert refund transaction record
    $wpdb->insert(
        $txns_table,
        [
            'api_id'           => $txn->api_id,
            'user_id'          => $merchant_id,
            'amount'           => - $amount,
            'order_id'         => $order_id,
            'card_id'          => $card_id,
            'transaction_type' => 'refund',
            'transaction_date' => current_time( 'mysql' ),
            'freeze_until'     => null,
            'refund_status'    => 'completed',
        ],
        [ '%d','%d','%f','%d','%d','%s','%s','%s','%s' ]
    );
    $refund_txn_id = $wpdb->insert_id;

    // 10) Update WooCommerce order status
    if ( $order = wc_get_order( $order_id ) ) {
        $order->update_status( 'refunded', 'تم استرداد المبلغ بواسطة API.' );
    }

// Prepare notification data
$digits      = preg_replace( '/\D/', '', $card->card_number );
$last_four   = substr( $digits, -4 );
$customer_id = intval( $card->user_id );
$api_id      = intval( $txn->api_id );

// 11a) Notify the customer (card owner)
if ( $customer_id && file_exists( WP_PLUGIN_DIR . '/st-mining2/notifications.php' ) ) {
    require_once WP_PLUGIN_DIR . '/st-mining2/notifications.php';
    if ( function_exists( 'add_notification' ) ) {
        // صياغة المبلغ رقميًا فقط بدون رمز العملة
        $formatted_number = number_format_i18n( floatval( $amount ), 4 );

        add_notification(
            $customer_id,
            sprintf(
                'لقد تم إيداع مبلغ <strong>%s ST</strong> إلى بطاقتك رقم <strong>%s</strong> من استرداد الطلب <strong>#%d</strong>.',
                $formatted_number,
                $last_four,
                $order_id
            )
        );
    }
}

// 11b) Notify the merchant (api_id)
if ( $api_id && file_exists( WP_PLUGIN_DIR . '/st-mining2/notifications.php' ) ) {
    require_once WP_PLUGIN_DIR . '/st-mining2/notifications.php';
    if ( function_exists( 'add_notification' ) ) {
        // صياغة المبلغ رقميًا فقط بدون رمز العملة
        $formatted_number = number_format_i18n( floatval( $amount ), 4 );

        add_notification(
            $merchant_id,
            sprintf(
                'لقد تم خصم مبلغ <strong>%s ST</strong> من رصيدك إلى بطاقة العميل رقم <strong>%s</strong> لاسترداد الطلب <strong>#%d</strong>.',
                $formatted_number,
                $last_four,
                $order_id
            )
        );
    }
}

    // 12) Prepare & store idempotent response
    $response_data = [
        'status'         => 'success',
        'refund_txn_id'  => $refund_txn_id,
        'new_wallet_bal' => $current_balance - $amount,
        'new_card_bal'   => $new_card_balance,
    ];
    $wpdb->insert(
        $idem_table,
        [
            'idem_key'   => $idem_key,
            'response'   => wp_json_encode( $response_data ),
            'created_at' => current_time( 'mysql' ),
        ],
        [ '%s','%s','%s' ]
    );

    // 13) Return the response
    return rest_ensure_response( $response_data );
}

