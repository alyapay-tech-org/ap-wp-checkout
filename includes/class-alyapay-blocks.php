<?php
defined('ABSPATH') || exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

class AlyaPay_Blocks extends AbstractPaymentMethodType {
    /** @var string */
    protected $name = 'alyapay';
    /** @var AlyaPay_Gateway */
    private $gateway;

    public function initialize(): void {
        $this->settings = get_option('woocommerce_alyapay_settings', []);
        $gateways       = WC()->payment_gateways()->payment_gateways();
        $this->gateway  = $gateways['alyapay'] ?? new AlyaPay_Gateway();
    }

    public function is_active(): bool {
        return $this->gateway->is_available();
    }

    public function get_payment_method_script_handles(): array {
        wp_register_script(
            'alyapay-blocks',
            ALYAPAY_PLUGIN_URL . 'assets/js/alyapay-blocks.js',
            ['wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-i18n'],
            ALYAPAY_VERSION,
            true
        );
        return ['alyapay-blocks'];
    }

    public function get_payment_method_data(): array {
        $s              = $this->settings;
        $widget_enabled = ($s['widget_enabled'] ?? '') === 'yes';

        $widget = null;
        if ($widget_enabled) {
            $locale      = get_locale();
            $lang        = strpos($locale, 'fr') === 0 ? 'fr' : (strpos($locale, 'ar') === 0 ? 'ar' : 'en');
            $cart_total  = WC()->cart ? number_format((float) WC()->cart->total, 2, '.', '') : '0.00';
            $widget = [
                'enabled'      => true,
                'currency'     => get_woocommerce_currency(),
                'lang'         => $lang,
                'theme'        => $s['widget_theme'] ?? 'light',
                'variant'      => $s['widget_variant'] ?? 'default',
                'detail'       => $s['widget_detail'] ?? 'modal',
                'logo_position' => $s['widget_logo_position'] ?? 'right',
                'cart_total'   => $cart_total,
            ];
        }

        return [
            'title'       => $this->gateway->title,
            'description' => $this->gateway->description,
            'supports'    => $this->gateway->supports,
            'widget'      => $widget,
            'amount_min'  => (float) ($s['amount_min'] ?? 500),
            'amount_max'  => (float) ($s['amount_max'] ?? 15000),
        ];
    }
}
