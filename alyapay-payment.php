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

define('ALYAPAY_RECONCILE_CRON_HOOK', 'alyapay_reconcile_pending_orders_event');
define('ALYAPAY_RECONCILE_CRON_SCHEDULE', 'alyapay_five_minutes');

// Registers the custom 5-minute schedule wp-cron needs to run reconciliation
// on. Matches alya-magento's crontab.xml cadence (`*/5 * * * *`) for the
// same job — see docs/platform-parity.md. Must be registered unconditionally
// on every load (not just plugins_loaded/admin), since wp-cron recalculates
// the next run using whatever schedules are registered on the current
// request, and this filter also needs to be in effect during the activation
// hook below.
add_filter('cron_schedules', static function (array $schedules): array {
    $schedules[ALYAPAY_RECONCILE_CRON_SCHEDULE] = [
        'interval' => 5 * MINUTE_IN_SECONDS,
        'display'  => __('Every 5 Minutes (AlyaPay)', 'alyapay'),
    ];
    return $schedules;
});

// Automatic reconciliation trigger — wp-cron scheduled event. See
// docs/platform-parity.md at the repo root for why wp-cron (rather than a
// manual-only trigger) is the right default on this platform.
register_activation_hook(ALYAPAY_PLUGIN_FILE, static function () {
    if (!wp_next_scheduled(ALYAPAY_RECONCILE_CRON_HOOK)) {
        wp_schedule_event(time(), ALYAPAY_RECONCILE_CRON_SCHEDULE, ALYAPAY_RECONCILE_CRON_HOOK);
    }
});

register_deactivation_hook(ALYAPAY_PLUGIN_FILE, static function () {
    wp_clear_scheduled_hook(ALYAPAY_RECONCILE_CRON_HOOK);
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
    require_once ALYAPAY_PLUGIN_DIR . 'includes/class-alyapay-reconcile-pending-orders.php';
    require_once ALYAPAY_PLUGIN_DIR . 'includes/class-alyapay-widget.php';
    require_once ALYAPAY_PLUGIN_DIR . 'includes/class-alyapay-gateway.php';

    // Automatic reconciliation — runs every 5 minutes via wp-cron (scheduled
    // by register_activation_hook above). No $this gateway context in a bare
    // cron callback, so settings are read directly and a fresh
    // AlyaPay_API/AlyaPay_Order_Helper pair is built here.
    add_action(ALYAPAY_RECONCILE_CRON_HOOK, static function () {
        $settings = get_option('woocommerce_alyapay_settings', []);

        if (($settings['enabled'] ?? '') !== 'yes' || empty($settings['api_key'])) {
            return;
        }

        $strip_wc_prefix = static function (string $status): string {
            return strpos($status, 'wc-') === 0 ? substr($status, 3) : $status;
        };

        $base_url = ($settings['environment'] ?? '') === 'production'
            ? 'https://api.alyapay.com'
            : 'https://sandbox-api.alyapay.com';

        try {
            $job = new AlyaPay_Reconcile_Pending_Orders(
                new AlyaPay_Order_Helper(),
                new AlyaPay_API($settings['api_key'], $base_url),
                $strip_wc_prefix($settings['approved_status'] ?? 'wc-processing'),
                $strip_wc_prefix($settings['cancelled_status'] ?? 'wc-cancelled'),
                $strip_wc_prefix($settings['expired_status'] ?? 'wc-cancelled'),
                (int) ($settings['transaction_expiry'] ?? 30)
            );

            $summary = $job->execute();

            wc_get_logger()->info(
                sprintf(
                    'AlyaPay reconciliation (wp-cron): %d order(s) checked, %d resolved.',
                    $summary['checked'],
                    $summary['reconciled']
                ),
                ['source' => 'alyapay']
            );
        } catch (\Throwable $e) {
            wc_get_logger()->error(
                'AlyaPay reconciliation (wp-cron) failed: ' . $e->getMessage(),
                ['source' => 'alyapay']
            );
        }
    });

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

    add_filter('widget_text', 'do_shortcode');
    add_filter('widget_text_content', 'do_shortcode');
    add_filter('widget_block_content', 'do_shortcode');
    add_filter('widget_custom_html_content', 'do_shortcode');

    // Sync min/max/expiry from AlyaPay backend before WooCommerce initializes gateways.
    // Must run in plugins_loaded (not admin_init) so the gateway reads fresh values on first load.
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    if (is_admin()
        && (isset($_GET['page']) ? $_GET['page'] : '') === 'wc-settings'
        && (isset($_GET['section']) ? $_GET['section'] : '') === 'alyapay') {

        $settings = get_option('woocommerce_alyapay_settings', []);
        $api_key  = $settings['api_key'] ?? '';

        if (!empty($api_key)) {
            $base_url = ($settings['environment'] ?? '') === 'production'
                ? 'https://api.alyapay.com'
                : 'https://sandbox-api.alyapay.com';

            try {
                $config = (new AlyaPay_API($api_key, $base_url))->get_partner_config();

                if (!empty($config)) {
                    if (isset($config['minAmount'])) {
                        $settings['amount_min'] = (string) $config['minAmount'];
                    }
                    if (isset($config['maxAmount'])) {
                        $settings['amount_max'] = (string) $config['maxAmount'];
                    }
                    if (array_key_exists('transactionExpiry', $config)) {
                        $settings['transaction_expiry'] = $config['transactionExpiry'] !== null
                            ? (string) $config['transactionExpiry']
                            : '';
                    }
                    update_option('woocommerce_alyapay_settings', $settings);
                }
            } catch (\Exception $e) {
                wc_get_logger()->warning(
                    'AlyaPay: failed to sync remote config: ' . $e->getMessage(),
                    ['source' => 'alyapay']
                );
            }
        }
    }

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
