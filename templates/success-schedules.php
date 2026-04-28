<?php defined('ABSPATH') || exit; ?>
<?php
$i18n = [
    'en' => [
        'dir' => 'ltr', 'title' => 'Order confirmed',
        'sub' => $installment_count > 0 ? 'Your ' . $installment_count . '-installment plan is active. We\'ll handle the rest.' : 'Your installment plan is active. We\'ll handle the rest.',
        'summary' => 'Order summary', 'label_order' => 'Order', 'label_date' => 'Date',
        'label_method' => 'Payment method', 'label_total' => 'Total',
        'install_note' => $installment_count . ' installments of ' . $installment_amt . ' each',
        'schedule' => 'Payment schedule', 'auto_note' => 'Installments will be deducted automatically on each due date.',
        'paid' => 'Paid', 'upcoming' => 'Upcoming', 'scheduled' => 'Scheduled',
        'btn_view' => 'View order', 'btn_shop' => 'Continue shopping',
        'method_value' => 'AlyaPay · ' . $installment_count . '×',
        'powered' => 'Secured by <strong>AlyaPay</strong> · Buy Now, Pay Later',
    ],
    'fr' => [
        'dir' => 'ltr', 'title' => 'Commande confirmée',
        'sub' => $installment_count > 0 ? 'Votre plan en ' . $installment_count . ' versements est actif. Nous gérons le reste.' : 'Votre plan de paiement est actif. Nous gérons le reste.',
        'summary' => 'Récapitulatif de commande', 'label_order' => 'Commande', 'label_date' => 'Date',
        'label_method' => 'Mode de paiement', 'label_total' => 'Total',
        'install_note' => $installment_count . ' versements de ' . $installment_amt . ' chacun',
        'schedule' => 'Échéancier de paiement', 'auto_note' => 'Les versements seront prélevés automatiquement à chaque échéance.',
        'paid' => 'Payé', 'upcoming' => 'À venir', 'scheduled' => 'Planifié',
        'btn_view' => 'Voir la commande', 'btn_shop' => 'Continuer les achats',
        'method_value' => 'AlyaPay · ' . $installment_count . '×',
        'powered' => 'Sécurisé par <strong>AlyaPay</strong> · Achetez maintenant, payez plus tard',
    ],
    'ar' => [
        'dir' => 'rtl', 'title' => 'تم تأكيد الطلب',
        'sub' => $installment_count > 0 ? 'خطة التقسيط على ' . $installment_count . ' أقساط نشطة. سنتولى الباقي.' : 'خطة التقسيط نشطة. سنتولى الباقي.',
        'summary' => 'ملخص الطلب', 'label_order' => 'الطلب', 'label_date' => 'التاريخ',
        'label_method' => 'طريقة الدفع', 'label_total' => 'المجموع',
        'install_note' => $installment_count . ' أقساط بقيمة ' . $installment_amt . ' لكل منها',
        'schedule' => 'جدول الدفع', 'auto_note' => 'سيتم خصم الأقساط تلقائياً في كل تاريخ استحقاق.',
        'paid' => 'مدفوع', 'upcoming' => 'قادم', 'scheduled' => 'مجدول',
        'btn_view' => 'عرض الطلب', 'btn_shop' => 'مواصلة التسوق',
        'method_value' => 'AlyaPay · ' . $installment_count . '×',
        'powered' => 'مدعوم من <strong>AlyaPay</strong> · اشترِ الآن وادفع لاحقاً',
    ],
];
if (!isset($i18n[$locale])) $locale = 'fr';
$t = $i18n[$locale];
$dir = $t['dir'];
$status_labels  = ['paid' => $t['paid'], 'upcoming' => $t['upcoming'], 'scheduled' => $t['scheduled']];
$status_classes = ['paid' => 'alya-badge--paid', 'upcoming' => 'alya-badge--upcoming', 'scheduled' => 'alya-badge--scheduled'];
$dot_classes    = ['paid' => 'alya-dot alya-dot--paid', 'upcoming' => 'alya-dot alya-dot--upcoming', 'scheduled' => 'alya-dot alya-dot--scheduled'];

$rows = !empty($rows) ? $rows : [];
?>
<style>
.alya-success *{box-sizing:border-box;margin:0;padding:0;}.alya-success{max-width:560px;margin:0 auto;padding:2rem 1rem 3rem;color:inherit;}
.alya-success[dir="rtl"]{text-align:right;}
.alya-check-ring{width:64px;height:64px;border-radius:50%;background:rgba(5,223,114,.12);display:flex;align-items:center;justify-content:center;margin:0 auto 1.25rem;}
.alya-check-ring svg{width:28px;height:28px;}
.alya-check-ring svg path{stroke:#05df72;}
.alya-hero-title{font-size:22px;font-weight:500;text-align:center;margin-bottom:6px;}
.alya-hero-sub{font-size:14px;text-align:center;color:#6b7280;margin-bottom:1.5rem;}
.alya-card{background:#fff;border:0.5px solid rgba(0,0,0,.1);border-radius:12px;padding:1.25rem;margin-bottom:1rem;}
.alya-card-label{font-size:11px;font-weight:500;letter-spacing:.06em;text-transform:uppercase;color:#9ca3af;margin-bottom:1rem;}
.alya-order-row{display:flex;justify-content:space-between;align-items:center;font-size:13px;padding:6px 0;color:#6b7280;}
.alya-order-row.alya-order-row--bordered{border-bottom:0.5px solid rgba(0,0,0,.08);}
.alya-order-row strong{font-weight:500;color:#111;font-size:13px;}
.alya-total-row{display:flex;justify-content:space-between;align-items:center;padding-top:12px;margin-top:4px;border-top:0.5px solid rgba(0,0,0,.15);}
.alya-total-label{font-size:13px;font-weight:500;}
.alya-total-amount{font-size:20px;font-weight:500;}
.alya-install-note{font-size:12px;color:#3333cc;background:oklch(94% .04 272.28);border-radius:8px;padding:8px 12px;margin-top:12px;text-align:center;}
.alya-timeline{position:relative;padding-left:28px;}[dir="rtl"] .alya-timeline{padding-left:0;padding-right:28px;}
.alya-timeline::before{content:'';position:absolute;left:9px;top:8px;bottom:8px;width:1px;background:rgba(0,0,0,.12);}[dir="rtl"] .alya-timeline::before{left:auto;right:9px;}
.alya-step{position:relative;margin-bottom:1.25rem;display:flex;align-items:flex-start;gap:12px;}.alya-step:last-child{margin-bottom:0;}
.alya-dot{position:absolute;left:-28px;top:3px;width:18px;height:18px;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;}[dir="rtl"] .alya-dot{left:auto;right:-28px;}
.alya-dot--paid{background:rgba(5,223,114,.15);border:1.5px solid #05df72;}
.alya-dot--upcoming{background:#f9fafb;border:1.5px solid #d1d5db;}
.alya-dot--scheduled{background:#f9fafb;border:1.5px dashed #d1d5db;}
.alya-dot__inner-paid{width:7px;height:7px;border-radius:50%;background:#05df72;}
.alya-dot__num{font-size:9px;font-weight:500;color:#9ca3af;}
.alya-step-body{flex:1;display:flex;justify-content:space-between;align-items:flex-start;padding-bottom:4px;}
.alya-step-title{font-size:13px;font-weight:500;margin-bottom:2px;}
.alya-step-date{font-size:12px;color:#6b7280;}
.alya-step-amount{font-size:14px;font-weight:500;text-align:right;}[dir="rtl"] .alya-step-amount{text-align:left;}
.alya-step-amount--paid{color:#05df72;}.alya-step-amount--upcoming{color:#111;}.alya-step-amount--scheduled{color:#9ca3af;}
.alya-badge{font-size:10px;font-weight:500;padding:2px 7px;border-radius:6px;margin-top:3px;display:inline-block;}
.alya-badge--paid{background:rgba(5,223,114,.15);color:#05df72;}
.alya-badge--upcoming{background:#fefce8;color:#b45309;}
.alya-badge--scheduled{background:#f3f4f6;color:#9ca3af;}
.alya-auto-note{font-size:12px;color:#6b7280;margin-top:1rem;text-align:center;display:flex;align-items:center;justify-content:center;gap:6px;}
.alya-powered{font-size:11px;color:#9ca3af;text-align:center;margin-top:1.5rem;}.alya-powered strong{color:#6b7280;font-weight:500;}
.alya-method-value{display:flex;align-items:center;gap:6px;}
.alya-success .alya-logo-inline{height:18px!important;max-height:18px!important;width:auto!important;vertical-align:middle;display:inline-block;}
</style>
<div class="alya-success" id="alya-success-block" dir="<?php echo esc_attr($dir); ?>">
    <div class="alya-check-ring"><svg viewBox="0 0 28 28" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M6 14.5L11.5 20L22 9" stroke="#05df72" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg></div>
    <h1 class="alya-hero-title"><?php echo esc_html($t['title']); ?></h1>
    <p class="alya-hero-sub"><?php echo esc_html($t['sub']); ?></p>
    <div class="alya-card">
        <p class="alya-card-label"><?php echo esc_html($t['summary']); ?></p>
        <div class="alya-order-row alya-order-row--bordered"><span><?php echo esc_html($t['label_order']); ?></span><strong>#<?php echo esc_html($order_id); ?></strong></div>
        <div class="alya-order-row alya-order-row--bordered"><span><?php echo esc_html($t['label_date']); ?></span><strong><?php echo esc_html($order_date); ?></strong></div>
        <div class="alya-order-row"><span><?php echo esc_html($t['label_method']); ?></span><strong class="alya-method-value"><img src="https://cdn.alyapay.com/alya-logo.svg" alt="AlyaPay" class="alya-logo-inline" height="18" width="auto" /> <?php echo esc_html($installment_count); ?>×</strong></div>
        <div class="alya-total-row"><span class="alya-total-label"><?php echo esc_html($t['label_total']); ?></span><span class="alya-total-amount"><?php echo esc_html($order_total); ?></span></div>
    </div>
    <?php if ($installment_count > 0 && !empty($rows)) : ?>
    <div class="alya-card">
        <p class="alya-card-label"><?php echo esc_html($t['schedule']); ?></p>
        <div class="alya-timeline">
            <?php foreach ($rows as $idx => $row) : $status = $row['status'] ?? 'scheduled'; $step_num = $idx + 1; ?>
            <div class="alya-step">
                <div class="<?php echo esc_attr($dot_classes[$status] ?? $dot_classes['scheduled']); ?>">
                    <?php if ($status === 'paid') : ?><div class="alya-dot__inner-paid"></div><?php else : ?><span class="alya-dot__num"><?php echo (int) $step_num; ?></span><?php endif; ?>
                </div>
                <div class="alya-step-body">
                    <div><p class="alya-step-title"><?php echo esc_html($row['label'] ?? ''); ?></p><?php if (!empty($row['date'])) : ?><p class="alya-step-date"><?php echo esc_html($row['date']); ?></p><?php endif; ?></div>
                    <div><p class="alya-step-amount alya-step-amount--<?php echo esc_attr($status); ?>"><?php echo esc_html($row['amount'] ?? $installment_amt); ?></p><span class="alya-badge <?php echo esc_attr($status_classes[$status] ?? $status_classes['scheduled']); ?>"><?php echo esc_html($status_labels[$status] ?? $status); ?></span></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <p class="alya-auto-note"><?php echo esc_html($t['auto_note']); ?></p>
    </div>
    <?php endif; ?>
    <p class="alya-powered"><?php echo wp_kses($t['powered'], ['strong' => []]); ?></p>
</div>
