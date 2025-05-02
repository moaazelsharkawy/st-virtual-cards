<?php
/*
Plugin Name: ST Virtual Pay Cards
Description: إنشاء وإدارة بطاقات ST افتراضية للدفع في المتاجر وربطها برصيد المستخدم ورصيد التاجر.
Version: 1.0
Author: Salla developer
Text Domain: st-virtual-cards
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once plugin_dir_path( __FILE__ ) . 'includes/rest-api.php';

// قبل سطر require_once
if ( ! function_exists('verify_transaction_and_get_amount') ) {
    $deposit_file = WP_PLUGIN_DIR . '/st-deposit/st-deposit.php';
    if ( file_exists( $deposit_file ) ) {
        require_once $deposit_file;
    } else {
        error_log( 'ST Virtual Cards: Unable to include ST Deposit plugin (' . $deposit_file . ')' );
    }
}

if ( file_exists( plugin_dir_path( dirname(__FILE__) ) . 'st-mining2/notifications.php' ) ) {
    require_once plugin_dir_path( dirname(__FILE__) ) . 'st-mining2/notifications.php';
}

/* ======================================
 * 1. تحميل CSS و JS مع التنسيق الجديد
 * ====================================== */
add_action( 'wp_enqueue_scripts', 'st_vpc_enqueue_assets' );
function st_vpc_enqueue_assets() {
    $style_path = plugin_dir_path(__FILE__) . 'css/st-vpc-style.css';
    $style_ver  = file_exists( $style_path ) ? filemtime( $style_path ) : '2.7';

    wp_enqueue_style(
        'st-vpc-style',
        plugin_dir_url(__FILE__) . 'css/st-vpc-style.css',
        array(),
        $style_ver
    );

    wp_enqueue_script(
        'sweetalert2',
        'https://cdn.jsdelivr.net/npm/sweetalert2@11',
        array(),
        null,
        true
    );

    wp_enqueue_script(
        'st-vpc-main',
        plugin_dir_url(__FILE__) . 'js/st-vpc-main.js',
        array('jquery', 'sweetalert2'),
        '2.0',
        true
    );

    wp_localize_script('st-vpc-main', 'st_vpc_ajax', array(
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('st_vpc_actions'),
        'strings'  => array(
            'confirm_cancel' => 'هل أنت متأكد من رغبتك في إلغاء هذه البطاقة؟',
            'success_create' => 'تم إنشاء البطاقة بنجاح!'
        )
    ));
}

/* ======================================
 * 2. تفعيل الإضافة: إنشاء الجداول والإعدادات الافتراضية
 * ====================================== */
register_activation_hook( __FILE__, 'st_vpc_activate' );
function st_vpc_activate() {
    global $wpdb;
    $charset = $wpdb->get_charset_collate();
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $sql1 = "CREATE TABLE {$wpdb->prefix}st_virtual_cards (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT NOT NULL,
        card_number VARCHAR(20) NOT NULL,
        cvv VARCHAR(5) NOT NULL,
        expires_at DATETIME NOT NULL,
        status ENUM('active','used','canceled') NOT NULL DEFAULT 'active',
        frozen_amount DECIMAL(18,8) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) $charset;";
    dbDelta( $sql1 );

    $sql2 = "CREATE TABLE {$wpdb->prefix}st_virtual_card_txns (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        card_id BIGINT NOT NULL,
        merchant_id BIGINT NOT NULL,
        order_id BIGINT,
        amount DECIMAL(18,8) NOT NULL,
        txn_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        status ENUM('pending','frozen','released') NOT NULL DEFAULT 'pending'
    ) $charset;";
    dbDelta( $sql2 );

    $sql3 = "CREATE TABLE {$wpdb->prefix}merchant_wallet_transactions (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        api_id BIGINT NOT NULL,
        user_id BIGINT NOT NULL,
        amount DECIMAL(18,8) NOT NULL,
        order_id BIGINT NOT NULL DEFAULT 0,
        transaction_type VARCHAR(20) NOT NULL,
        transaction_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        freeze_until DATETIME DEFAULT NULL,
        KEY api_user (api_id, user_id)
    ) $charset;";
    dbDelta( $sql3 );

    $sql4 = "CREATE TABLE {$wpdb->prefix}st_vpc_used_signatures (
        id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        signature  VARCHAR(255) NOT NULL,
        user_id    BIGINT NOT NULL,
        created_at DATETIME NOT NULL,
        UNIQUE KEY (signature)
    ) $charset;";
    dbDelta( $sql4 );

    // الإعدادات الافتراضية
    add_option( 'st_vpc_fee',             0.5 );
    add_option( 'st_vpc_expiry_days',     3   );
    add_option( 'st_vpc_merchant_freeze', 7   );
    add_option( 'st_vpc_max_cards',       0   );   // 0 = غير محدود
    add_option( 'st_vpc_usage_reward',    0   );   // مكافأة الاستخدام (ST)
}

/* ======================================
 * 3. صفحة الإعدادات في لوحة الإدارة
 * ====================================== */
add_action( 'admin_menu', 'st_vpc_add_admin_menu' );
function st_vpc_add_admin_menu() {
    add_menu_page(
        'ST Virtual Cards',
        'ST Virtual Cards',
        'manage_options',
        'st-vpc-settings',
        'st_vpc_settings_page',
        'dashicons-palmtree'
    );
}

function st_vpc_settings_page() {
    if ( isset( $_POST['st_vpc_nonce'] ) && wp_verify_nonce( $_POST['st_vpc_nonce'], 'st_vpc_save' ) ) {
        update_option( 'st_vpc_fee',             floatval( $_POST['st_vpc_fee'] ) );
        update_option( 'st_vpc_expiry_days',     intval(   $_POST['st_vpc_expiry_days'] ) );
        update_option( 'st_vpc_merchant_freeze', intval(   $_POST['st_vpc_merchant_freeze'] ) );
        update_option( 'st_vpc_max_cards',       intval(   $_POST['st_vpc_max_cards'] ) );
        update_option( 'st_vpc_usage_reward',    floatval( $_POST['st_vpc_usage_reward'] ) );
        echo '<div class="updated"><p>تم حفظ الإعدادات.</p></div>';
    }

    $fee          = get_option( 'st_vpc_fee' );
    $expiry       = get_option( 'st_vpc_expiry_days' );
    $freeze       = get_option( 'st_vpc_merchant_freeze' );
    $max_cards    = get_option( 'st_vpc_max_cards' );
    $usage_reward = get_option( 'st_vpc_usage_reward' );
    ?>
    <div class="wrap st-vpc-admin">
        <div class="st-vpc-header">
            <h1><i class="fas fa-credit-card"></i> إدارة البطاقات الافتراضية</h1>
        </div>

        <form method="post" class="st-vpc-settings-form">
            <?php wp_nonce_field('st_vpc_save','st_vpc_nonce'); ?>

            <div class="st-vpc-setting-card gradient-purple">
                <div class="setting-icon"><i class="fas fa-coins"></i></div>
                <div class="setting-content">
                    <label>رسوم الإنشاء (ST)</label>
                    <input type="number" step="0.00000001"
                           name="st_vpc_fee" value="<?php echo esc_attr($fee); ?>">
                </div>
            </div>

            <div class="st-vpc-setting-card gradient-blue">
                <div class="setting-icon"><i class="fas fa-clock"></i></div>
                <div class="setting-content">
                    <label>صلاحية البطاقة (أيام)</label>
                    <input type="number" name="st_vpc_expiry_days"
                           value="<?php echo esc_attr($expiry); ?>">
                </div>
            </div>

            <div class="st-vpc-setting-card gradient-red">
                <div class="setting-icon"><i class="fas fa-lock"></i></div>
                <div class="setting-content">
                    <label>تجميد الرصيد (أيام)</label>
                    <input type="number" name="st_vpc_merchant_freeze"
                           value="<?php echo esc_attr($freeze); ?>">
                </div>
            </div>

            <div class="st-vpc-setting-card gradient-green">
                <div class="setting-icon"><i class="fas fa-layer-group"></i></div>
                <div class="setting-content">
                    <label>العدد الأقصى لإنشاء البطاقات (0 = غير محدود)</label>
                    <input type="number" name="st_vpc_max_cards"
                           value="<?php echo esc_attr($max_cards); ?>">
                </div>
            </div>

            <div class="st-vpc-setting-card gradient-teal">
                <div class="setting-icon"><i class="fas fa-gift"></i></div>
                <div class="setting-content">
                    <label>مكافأة الاستخدام (ST)</label>
                    <input type="number" step="0.00000001" min="0"
                           name="st_vpc_usage_reward"
                           value="<?php echo esc_attr($usage_reward); ?>">
                </div>
            </div>

            <button type="submit" class="st-vpc-save-btn">
                <i class="fas fa-save"></i> حفظ التغييرات
            </button>
        </form>
    </div>
    <?php
}

/* ======================================
 * 4. Cron ووظيفة الحدث اليومي
 * ====================================== */
add_action( 'wp', 'st_vpc_schedule_cron' );
function st_vpc_schedule_cron() {
    if ( ! wp_next_scheduled( 'st_vpc_daily_events' ) ) {
        wp_schedule_event( time(), 'daily', 'st_vpc_daily_events' );
    }
}
add_action( 'st_vpc_daily_events', 'st_vpc_handle_daily' );
function st_vpc_handle_daily() {
    global $wpdb;
    $now = current_time( 'mysql' );

    // انتهاء صلاحية البطاقات
    $cards = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}st_virtual_cards
         WHERE status='active' AND expires_at <= %s",
        $now
    ) );
    foreach ( $cards as $c ) {
        $bal = floatval( get_user_meta( $c->user_id, 'st_mined_balance', true ) );
        update_user_meta( $c->user_id, 'st_mined_balance',
            $bal + floatval( $c->frozen_amount ) + get_option( 'st_vpc_fee' ) );
        $wpdb->update(
            "{$wpdb->prefix}st_virtual_cards",
            array( 'status' => 'canceled', 'frozen_amount' => 0 ),
            array( 'id' => $c->id ),
            array( '%s','%f' ),
            array( '%d' )
        );
    }

    // إطلاق رصيد التاجر بعد التجميد
    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}merchant_wallet_transactions
         WHERE transaction_type='purchase_frozen' AND freeze_until <= %s",
        $now
    ) );
    foreach ( $rows as $r ) {
        $bal = floatval( get_user_meta( $r->user_id, 'st_mined_balance', true ) );
        update_user_meta( $r->user_id, 'st_mined_balance', $bal + floatval( $r->amount ) );
        $wpdb->update(
            "{$wpdb->prefix}merchant_wallet_transactions",
            array( 'transaction_type' => 'released' ),
            array( 'id' => $r->id ),
            array( '%s' ),
            array( '%d' )
        );
    }
}

/* ======================================
 * 5. دوال مساعدة (رقم البطاقة و CVV)
 * ====================================== */
function st_vpc_generate_card_number() {
    $num = '';
    for ( $i = 0; $i < 16; $i++ ) {
        $num .= rand( 0, 9 );
    }
    return trim( chunk_split( $num, 4, ' ' ) );
}
function st_vpc_generate_cvv() {
    return str_pad( rand( 0, 999 ), 3, '0', STR_PAD_LEFT );
}

/* ======================================
 * 6. شورتكود عرض البوابة
 * ====================================== */
function st_vpc_portal_handler() {


function st_vpc_disable_page_cache() {
    if ( is_page() && has_shortcode( get_post()->post_content, 'st_vpc_portal' ) ) {
        nocache_headers();
        define( 'DONOTCACHEPAGE', true );
    }
}
add_action( 'template_redirect', 'st_vpc_disable_page_cache' );

    
    if ( ! is_user_logged_in() ) {
        return '<div class="st-vpc-alert warning"><i class="fas fa-exclamation-triangle"></i> يرجى تسجيل الدخول أولاً</div>';
    }


$user_id   = get_current_user_id();
$max_cards = intval( get_option('st_vpc_max_cards', 0) );
$hide_form = false;

if ( $max_cards > 0 ) {
    global $wpdb;
    $active_cards = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}st_virtual_cards WHERE user_id = %d AND status = 'active'",
        $user_id
    ) );
    if ( $active_cards >= $max_cards ) {
        $hide_form = true;
    }
}



    $fee = floatval( get_option( 'st_vpc_fee', 0 ) );
    $fixed_destination = 'DepT6CHrr7Mwb4z3fxgvkRDD4QKusrQ2BASwNkkRW2Pr';

    ob_start(); ?>
    <div class="st-vpc-portal">
<?php if ( ! $hide_form ) : ?>
  <div class="st-vpc-deposit-section">
    <h3>إيداع وإنشاء البطاقة الافتراضية</h3>
    <p>رسوم إنشاء البطاقة: <strong><?php echo esc_html( $fee ); ?> ST</strong></p>
    <p>حوّل ST من Phantom إلى العنوان التالي، ثم أدخل Transaction Signature:</p>
    <div class="merchant-address-container">
      <span id="st-vpc-deposit-address"><?php echo esc_html( $fixed_destination ); ?></span>
      <button type="button" id="st-vpc-copy-address" class="copy-btn">
        <i class="fas fa-copy" style="color: #6B46C1;"></i>
      </button>
    </div>
    <form id="st-vpc-deposit-form">
      <input type="text" id="st-vpc-signature" name="signature" placeholder="أدخل التوقيع" required>
      <button type="submit" class="st-vpc-action-btn"><i class="fas fa-credit-card"></i> تأكيد الإنشاء</button>
    </form>
  </div>
<?php else : ?>
<div class="st-vpc-alert warning"><i class="fas fa-ban"></i> <strong>لقد وصلت إلى الحد الأقصى لإنشاء البطاقات المسموح بها.</strong></div>
<?php endif; ?>

      <div class="st-vpc-header"><h2><i class="fas fa-credit-card"></i> البطاقات الافتراضية</h2></div>
      <ul class="st-vpc-cards-grid" id="st-vpc-cards-list"></ul>
    </div>
    <?php
    return ob_get_clean();
}
add_action( 'init', 'st_vpc_register_shortcodes' );
function st_vpc_register_shortcodes() {
    add_shortcode( 'st_vpc_portal', 'st_vpc_portal_handler' );
}

/* ======================================
 * 7. AJAX Handlers
 * ====================================== */
add_action('wp_ajax_st_vpc_deposit_and_create','st_vpc_deposit_and_create');
function st_vpc_deposit_and_create() {
    check_ajax_referer('st_vpc_actions','nonce');
    if ( ! is_user_logged_in() ) wp_send_json_error('يجب تسجيل الدخول.');

    global $wpdb;
    $user_id = get_current_user_id();

    // حد أقصى للبطاقات
    $max_cards = intval( get_option('st_vpc_max_cards', 0) );
    if ( $max_cards > 0 ) {
        $count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}st_virtual_cards WHERE user_id=%d AND status='active'",
            $user_id
        ) );
        if ( $count >= $max_cards ) {
            wp_send_json_error('لقد وصلت إلى الحد الأقصى لإنشاء البطاقات.');
        }
    }

    $signature = sanitize_text_field( $_POST['signature'] ?? '' );
    if ( empty($signature) ) wp_send_json_error('يرجى إدخال التوقيع.');

    // تحقق من الإيداع
    $dep_table = $wpdb->prefix . 'st_deposits';
    if ( (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$dep_table} WHERE signature=%s",
        $signature
    ) ) > 0 ) {
        wp_send_json_error('هذا التوقيع مستخدم بالفعل.');
    }

    // التحقق من المعاملة
    $fixed_destination = 'DepT6CHrr7Mwb4z3fxgvkRDD4QKusrQ2BASwNkkRW2Pr';
    $mint_address      = get_option('st_deposit_mint_address','');
    $amount            = verify_transaction_and_get_amount( $signature, $fixed_destination, $mint_address );
    if ( is_wp_error($amount) ) wp_send_json_error( $amount->get_error_message() );
    $amount = round( floatval($amount), 7 );
    if ( $amount <= 0 ) wp_send_json_error('المبلغ غير صالح.');

    // سجل الإيداع
    if ( ! $wpdb->insert(
        $dep_table,
        [
            'user_id'        => $user_id,
            'deposit_amount' => $amount,
            'signature'      => $signature,
            'status'         => 'approved',
            'created_at'     => current_time('mysql'),
        ],
        ['%d','%f','%s','%s','%s']
    ) ) {
        wp_send_json_error('خطأ في تسجيل الإيداع.');
    }

    // رصيد البطاقة
    $vpc_balance = floatval( get_user_meta($user_id,'st_vpc_balance',true) ) + $amount;
    update_user_meta( $user_id, 'st_vpc_balance', $vpc_balance );

    // خصم الرسوم الثابتة
    $fee = floatval( get_option('st_vpc_fee', 0) );
    $mined_balance = floatval( get_user_meta($user_id,'st_mined_balance',true) );
    if ( $mined_balance < $fee ) wp_send_json_error('رصيدك المعدن لا يكفي لدفع الرسوم.');
    update_user_meta( $user_id, 'st_mined_balance', $mined_balance - $fee );
  //  if ( function_exists('add_notification') ) {
   //     add_notification($user_id, "تم إنشاء البطاقة وخصم رسوم بقيمة " . round($fee,5) . " ST.");
  //  }

    // إنشاء البطاقة
    $now = current_time('mysql');
    $exp = date('Y-m-d H:i:s', strtotime("+" . intval(get_option('st_vpc_expiry_days',3)) . " days", strtotime($now)));
    $wpdb->insert(
        "{$wpdb->prefix}st_virtual_cards",
        [
            'user_id'       => $user_id,
            'card_number'   => st_vpc_generate_card_number(),
            'cvv'           => st_vpc_generate_cvv(),
            'expires_at'    => $exp,
            'frozen_amount' => $amount,
        ],
        ['%d','%s','%s','%s','%f']
    );

    // مكافأة الاستخدام الثابتة
    $reward = floatval( get_option('st_vpc_usage_reward', 0) );
    if ( $reward > 0 ) {
        $mb = floatval( get_user_meta($user_id,'st_mined_balance',true) );
        update_user_meta( $user_id, 'st_mined_balance', $mb + $reward );
        if ( function_exists('add_notification') ) {
            add_notification($user_id, "تم إضافة مكافأة انشاء بطاقة للاختبار بقيمة " . round($reward,5) . " ST.");
        }
    }

    // ردّ النتائج
    wp_send_json_success([
        'amount'        => $amount,
        'fee'           => $fee,
        'vpcBalance'    => $vpc_balance,
        'minedBalance'  => floatval( get_user_meta($user_id,'st_mined_balance',true) ),
        'card'          => [
            'card_number'=> $wpdb->get_var("SELECT card_number FROM {$wpdb->prefix}st_virtual_cards WHERE id={$wpdb->insert_id}"),
            'cvv'        => $wpdb->get_var("SELECT cvv         FROM {$wpdb->prefix}st_virtual_cards WHERE id={$wpdb->insert_id}"),
            'expires_at' => $exp,
        ],
    ]);
}

add_action( 'wp_ajax_st_vpc_list_cards', 'st_vpc_list_cards' );
function st_vpc_list_cards() {
    check_ajax_referer( 'st_vpc_actions', 'nonce' );
    global $wpdb;
    $cards = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}st_virtual_cards WHERE user_id = %d",
        get_current_user_id()
    ) );
    wp_send_json_success( $cards );
}

add_action('wp_ajax_st_vpc_cancel_card', 'st_vpc_cancel_card');
function st_vpc_cancel_card() {
    check_ajax_referer('st_vpc_actions','nonce');
    $card_id = intval($_POST['card_id']);
    global $wpdb;
    $card = $wpdb->get_row( $wpdb->prepare(
        "SELECT frozen_amount, user_id, status 
         FROM {$wpdb->prefix}st_virtual_cards 
         WHERE id=%d AND user_id=%d",
        $card_id, get_current_user_id()
    ) );
    if ( ! $card || $card->status !== 'active' ) {
        wp_send_json_error('البطاقة غير صالحة للإلغاء.');
    }

    $refund = floatval( $card->frozen_amount );
    if ( $refund > 0 ) {
        $mb = floatval( get_user_meta($card->user_id,'st_mined_balance',true) );
        update_user_meta( $card->user_id, 'st_mined_balance', $mb + $refund );

        $vb = floatval( get_user_meta($card->user_id,'st_vpc_balance',true) );
        update_user_meta( $card->user_id, 'st_vpc_balance', max(0, $vb - $refund) );

        if ( function_exists('add_notification') ) {
            add_notification($card->user_id, "تم إلغاء البطاقة واسترداد " . round($refund,5) . " ST.");
        }
    }

    $wpdb->update(
        "{$wpdb->prefix}st_virtual_cards",
        array( 'status' => 'canceled', 'frozen_amount' => 0 ),
        array( 'id' => $card_id ),
        array( '%s','%f' ),
        array( '%d' )
    );

    wp_send_json_success('تم الإلغاء واسترداد ' . round($refund,8) . ' ST.');
}

add_action('wp_ajax_st_vpc_delete_card', 'st_vpc_delete_card');
function st_vpc_delete_card(){
    check_ajax_referer('st_vpc_actions','nonce');
    $card_id = intval($_POST['card_id']);
    global $wpdb;
    $card = $wpdb->get_row( $wpdb->prepare(
      "SELECT status, frozen_amount FROM {$wpdb->prefix}st_virtual_cards WHERE id=%d AND user_id=%d",
      $card_id, get_current_user_id()
    ) );
    // يسمح بالحذف فقط إذا saldo البطاقة صفر
    if ( ! $card || floatval($card->frozen_amount) > 0 ) {
        wp_send_json_error('لا يمكن حذف هذه البطاقة.');
    }

    $deleted = $wpdb->delete(
        "{$wpdb->prefix}st_virtual_cards",
        array('id' => $card_id),
        array('%d')
    );

    if ( $deleted ) {
        wp_send_json_success('تم حذف البطاقة نهائياً.');
    } else {
        wp_send_json_error('فشل حذف البطاقة.');
    }
}
