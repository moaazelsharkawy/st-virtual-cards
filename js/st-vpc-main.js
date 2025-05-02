(function($) {
  "use strict";

  $(document).ready(function() {

    // إضافة ستايل لقلب البطاقة فقط
    $('<style>')
      .text(`
        .st-vpc-card-inner.flipped {
          transform: rotateY(180deg);
        }
      `)
      .appendTo('head');

    // 1. نسخ عنوان الإيداع
    $('#st-vpc-copy-address').on('click', function(){
      const addr = $('#st-vpc-deposit-address').text().trim();
      if (!addr) return;
      navigator.clipboard.writeText(addr)
        .then(() => Swal.fire('تم النسخ','تم نسخ العنوان بنجاح','success'))
        .catch(() => Swal.fire('خطأ','فشل في نسخ العنوان','error'));
    });

    // 2. تقديم النموذج وإنشاء البطاقة
    $('#st-vpc-deposit-form').on('submit', function(e){
      e.preventDefault();
      const sig = $('#st-vpc-signature').val().trim();
      if (!sig) {
        return Swal.fire('خطأ','يرجى إدخال التوقيع','error');
      }

      $.post(st_vpc_ajax.ajax_url, {
        action:    'st_vpc_deposit_and_create',
        nonce:     st_vpc_ajax.nonce,
        signature: sig
      })
      .done(function(res){
        if (!res.success) {
          return Swal.fire('خطأ', res.data, 'error');
        }

        Swal.fire({
          title: st_vpc_ajax.strings.success_create,
          icon:  'success',
          html:
            'إيداع البطاقة: <strong>'   + res.data.amount       + ' ST</strong><br>' +
            'رسوم الإنشاء: <strong>'    + res.data.fee          + ' ST</strong><br>' +
            'رصيدك المعدن: <strong>'    + res.data.minedBalance + ' ST</strong>'
        });

        $('.st-vpc-deposit-section').slideUp();
        loadCards();
      })
      .fail(function(){
        Swal.fire('خطأ','فشل الاتصال بالخادم','error');
      });
    });

    // 3. تحميل البطاقات عند البداية
    if ($('#st-vpc-cards-list').length) {
      loadCards();
    }

    function loadCards() {
      $.post(st_vpc_ajax.ajax_url, {
        action: 'st_vpc_list_cards',
        nonce:  st_vpc_ajax.nonce
      })
      .done(function(response){
        if (response.success) renderCards(response.data);
      });
    }

    function renderCards(cards) {
      const $list = $('#st-vpc-cards-list').empty();
      if (!cards.length) {
        return $list.append('<li>لا توجد بطاقات حالياً.</li>');
      }

      cards.forEach(card => {
        const frozen = parseFloat(card.frozen_amount);
        const balance = frozen.toFixed(7);
        const $li = $('<li class="st-vpc-card-item"></li>');

        // حذف زر النسخ من الواجهة الأمامية، والاكتفاء بعرض الرقم
        const cardNumberHtml = `
          <div class="st-vpc-number" title="انقر لنسخ الرقم">
            ${card.card_number}
          </div>`;

        const html = `
          <div class="st-vpc-card">
            <div class="st-vpc-card-inner">
              
              <!-- وجه البطاقة -->
              <div class="st-vpc-card-front">
                <div class="st-vpc-chip"></div>
                <div class="st-vpc-logo">
                  <span class="st-vpc-logo-icon">ＳＴ</span>
                  <span class="st-vpc-logo-text">Virtual Pay Card</span>
                </div>

                ${cardNumberHtml}

                <!-- تاريخ الانتهاء أسفل الرقم -->
                <div class="st-vpc-expiry-front">
                  EXP: ${card.expires_at.split(' ')[0]}
                </div>
              </div>

              <!-- ظهر البطاقة -->
              <div class="st-vpc-card-back">
                <div>CVV: ${card.cvv}</div>
                <div style="margin-top:10px;">رصيد البطاقة: ${balance} ST</div>
              </div>

            </div>
          </div>`;

        $li.append(html);

        // أزرار الإلغاء
        if (card.status === 'active') {
          const $btn = $('<button class="st-vpc-cancel">إلغاء البطاقة</button>');
          $btn.on('click', () => {
            Swal.fire({
              title: st_vpc_ajax.strings.confirm_cancel,
              icon: 'warning',
              showCancelButton: true,
              confirmButtonText: 'نعم', cancelButtonText: 'لا'
            }).then(r => {
              if (r.isConfirmed) {
                $.post(st_vpc_ajax.ajax_url, {
                  action:  'st_vpc_cancel_card',
                  nonce:   st_vpc_ajax.nonce,
                  card_id: card.id
                }).done(r2 => {
                  if (r2.success) {
                    Swal.fire('تم','تم الإلغاء','success');
                    loadCards();
                  }
                });
              }
            });
          });
          $li.append($btn);

        } 
        // زر الحذف فقط إذا الحالة 'canceled' ورصيد البطاقة صفر
        else if (card.status === 'canceled' && frozen === 0) {
          $li.append(`<span class="st-vpc-status">ملغاة</span>`);
          const $del = $(`<button class="st-vpc-delete"><i class="fas fa-trash"></i></button>`);
          $del.on('click', () => {
  Swal.fire({
    title: 'تأكيد الحذف',
    text: 'هل أنت متأكد أن هذه البطاقة لن يحدث عليها استرداد لأي عملية دفع؟',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: 'حذف',
    cancelButtonText: 'إلغاء'
  }).then(r => {
    if (r.isConfirmed) {
      $.post(st_vpc_ajax.ajax_url, {
        action:  'st_vpc_delete_card',
        nonce:   st_vpc_ajax.nonce,
        card_id: card.id
      }).done(r3 => {
        if (r3.success) {
          Swal.fire('تم', 'تم الحذف', 'success');
          loadCards();
        } else {
          Swal.fire('خطأ', r3.data || 'فشل حذف البطاقة', 'error');
        }
      });
    }
  });
});

          $li.append($del);
        }

        $list.append($li);
      });

      // منع قلب البطاقة عند النقر على الرقم
      $(document).off('click', '.st-vpc-card').on('click', '.st-vpc-card', function(e){
        if ($(e.target).closest('.st-vpc-number').length) {
          return;
        }
        $(this).find('.st-vpc-card-inner').toggleClass('flipped');
      });

      // عند النقر على رقم البطاقة، نسخه
      $(document).on('click', '.st-vpc-number', function(e){
        const range = document.createRange();
        range.selectNodeContents(this);
        const sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(range);
        try {
          document.execCommand('copy');
          Swal.fire('تم النسخ','تم نسخ رقم البطاقة بنجاح','success');
        } catch {
          Swal.fire('خطأ','فشل في نسخ رقم البطاقة','error');
        }
        sel.removeAllRanges();
      });
    }

  });
})(jQuery);
