<?php defined('ABSPATH') || exit; ?>
<div class="alyapay-credit-promo">
    <alya-placement
        key="credit-promotion"
        price="<?php echo esc_attr($price); ?>"
        currency="<?php echo esc_attr($settings['currency']); ?>"
        lang="<?php echo esc_attr($settings['lang']); ?>"
        installments="4"
        theme="<?php echo esc_attr($settings['theme']); ?>"
        variant="<?php echo esc_attr($settings['variant']); ?>"
        detail="<?php echo esc_attr($settings['detail']); ?>"
        logo-position="<?php echo esc_attr($settings['logo_position']); ?>"
    ></alya-placement>
</div>
