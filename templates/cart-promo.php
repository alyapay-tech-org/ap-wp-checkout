<?php defined('ABSPATH') || exit; ?>
<tr class="alyapay-credit-promo">
    <td colspan="2">
        <alya-placement
            key="cart"
            price="<?php echo esc_attr($total); ?>"
            currency="<?php echo esc_attr($settings['currency']); ?>"
            lang="<?php echo esc_attr($settings['lang']); ?>"
            installments="4"
            theme="<?php echo esc_attr($settings['theme']); ?>"
            variant="<?php echo esc_attr($settings['variant']); ?>"
            detail="<?php echo esc_attr($settings['detail']); ?>"
            logo-position="<?php echo esc_attr($settings['logo_position']); ?>"
            <?php if (!empty($settings['min_amount'])) : ?>min-amount="<?php echo esc_attr($settings['min_amount']); ?>" min-display="<?php echo esc_attr($settings['min_display']); ?>"<?php endif; ?>
            <?php if (!empty($settings['full_width']) && $settings['full_width'] === 'yes') : ?>full-width="true"<?php endif; ?>
            <?php if ($settings['margin_x'] !== '') : ?>margin-x="<?php echo esc_attr($settings['margin_x']); ?>"<?php endif; ?>
            <?php if ($settings['margin_y'] !== '') : ?>margin-y="<?php echo esc_attr($settings['margin_y']); ?>"<?php endif; ?>
            <?php if ($settings['padding_x'] !== '') : ?>padding-x="<?php echo esc_attr($settings['padding_x']); ?>"<?php endif; ?>
            <?php if ($settings['padding_y'] !== '') : ?>padding-y="<?php echo esc_attr($settings['padding_y']); ?>"<?php endif; ?>
        ></alya-placement>
    </td>
</tr>
