<?php
/**
 * Plugin Name: AlyaPay Payment for WooCommerce
 * Plugin URI:  https://alyapay.com
 * Description: BNPL payment gateway powered by AlyaPay
 * Version:     1.0.0
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 6.0
 * WC tested up to: 8.4
 * Author:      AlyaPay
 * License:     GPL-2.0+
 * Text Domain: alyapay
 */

defined('ABSPATH') || exit;

define('ALYAPAY_VERSION',    '1.0.4');
define('ALYAPAY_PLUGIN_FILE', __FILE__);
define('ALYAPAY_PLUGIN_DIR',  plugin_dir_path(__FILE__));
define('ALYAPAY_PLUGIN_URL',  plugin_dir_url(__FILE__));

// HPOS compatibility declaration
add_action('before_woocommerce_init', static function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

add_action('plugins_loaded', static function () {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', static function () {
            printf(
                '<div class="error"><p>%s</p></div>',
                esc_html__('AlyaPay Payment requires WooCommerce to be installed and active.', 'alyapay')
            );
        });
        return;
    }

    require_once ALYAPAY_PLUGIN_DIR . 'includes/class-alyapay-api.php';
    require_once ALYAPAY_PLUGIN_DIR . 'includes/class-alyapay-webhook.php';
    require_once ALYAPAY_PLUGIN_DIR . 'includes/class-alyapay-order-helper.php';
    require_once ALYAPAY_PLUGIN_DIR . 'includes/class-alyapay-widget.php';
    require_once ALYAPAY_PLUGIN_DIR . 'includes/class-alyapay-gateway.php';

    add_filter('woocommerce_payment_gateways', static function (array $gateways): array {
        $gateways[] = 'AlyaPay_Gateway';
        return $gateways;
    });

    // WC API endpoints — fired on init before gateways are loaded
    add_action('woocommerce_api_alyapay_return', static function () {
        (new AlyaPay_Gateway())->handle_return();
    });

    add_action('woocommerce_api_alyapay_webhook', static function () {
        (new AlyaPay_Gateway())->handle_webhook();
    });

    new AlyaPay_Widget();

    // Block checkout integration
    add_action('woocommerce_blocks_loaded', static function () {
        if (!class_exists('Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
            return;
        }
        require_once ALYAPAY_PLUGIN_DIR . 'includes/class-alyapay-blocks.php';
        add_action('woocommerce_blocks_payment_method_type_registration', static function ($registry) {
            $registry->register(new AlyaPay_Blocks());
        });
    });
});
