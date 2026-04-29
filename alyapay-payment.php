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

define('ALYAPAY_VERSION',    '1.0.5');
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

    // Accordion JS for widget settings sections in admin
    add_action('admin_footer', static function () {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ((isset($_GET['page']) ? $_GET['page'] : '') !== 'wc-settings'
            || (isset($_GET['section']) ? $_GET['section'] : '') !== 'alyapay') {
            return;
        }
        ?>
        <style>
        h3.alya-accordion-header{cursor:pointer;user-select:none;display:flex;align-items:center;justify-content:space-between;background:#f6f7f7;border:1px solid #dcdcde;border-radius:4px 4px 0 0;padding:12px 16px;margin:20px 0 0;font-size:13px;font-weight:600;color:#1d2327;}
        h3.alya-accordion-header:hover{background:#ebebec;border-color:#c3c4c7;}
        h3.alya-accordion-header.alya-collapsed{border-radius:4px;}
        h3.alya-accordion-header::after{content:'▲';font-size:10px;color:#646970;transition:transform .25s ease;}
        h3.alya-accordion-header.alya-collapsed::after{transform:rotate(180deg);}
        .alya-accordion-wrap{border:1px solid #dcdcde;border-top:none;border-radius:0 0 4px 4px;background:#fff;overflow:hidden;transition:max-height .3s ease;max-height:2000px;padding:0 16px;}
        .alya-accordion-wrap.alya-collapsed{max-height:0;border:none;}
        .alya-accordion-wrap table.form-table{margin:0;background:#fff;}
        </style>
        <script>
        (function($){$(function(){
            $('h3.alya-accordion-header').each(function(){
                var $h3=$(this);
                $h3.next('table.form-table').wrap('<div class="alya-accordion-wrap"></div>');
                var $wrap=$h3.next('.alya-accordion-wrap');
                $h3.on('click',function(){
                    $h3.toggleClass('alya-collapsed');
                    $wrap.toggleClass('alya-collapsed');
                });
            });
        });})(jQuery);
        </script>
        <?php
    });

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
