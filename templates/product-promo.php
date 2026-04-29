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
        <?php if (!empty($settings['full_width']) && $settings['full_width'] === 'yes') : ?>full-width="true"<?php endif; ?>
        <?php if ($settings['margin_x'] !== '') : ?>margin-x="<?php echo esc_attr($settings['margin_x']); ?>"<?php endif; ?>
        <?php if ($settings['margin_y'] !== '') : ?>margin-y="<?php echo esc_attr($settings['margin_y']); ?>"<?php endif; ?>
        <?php if ($settings['padding_x'] !== '') : ?>padding-x="<?php echo esc_attr($settings['padding_x']); ?>"<?php endif; ?>
        <?php if ($settings['padding_y'] !== '') : ?>padding-y="<?php echo esc_attr($settings['padding_y']); ?>"<?php endif; ?>
    ></alya-placement>
</div>
