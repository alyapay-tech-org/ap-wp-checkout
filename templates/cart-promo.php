<?php defined('ABSPATH') || exit; ?>
<tr class="alyapay-credit-promo">
    <td colspan="2">
        <alya-placement
            key="credit-promotion"
            price="<?php echo esc_attr($total); ?>"
            currency="<?php echo esc_attr($settings['currency']); ?>"
            lang="<?php echo esc_attr($settings['lang']); ?>"
            installments="4"
            theme="<?php echo esc_attr($settings['theme']); ?>"
            variant="<?php echo esc_attr($settings['variant']); ?>"
            detail="<?php echo esc_attr($settings['detail']); ?>"
            logo-position="<?php echo esc_attr($settings['logo_position']); ?>"
        ></alya-placement>
    </td>
</tr>
