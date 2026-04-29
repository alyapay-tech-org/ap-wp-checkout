<?php
defined('ABSPATH') || exit;

class AlyaPay_Widget {
    /** @var array */
    private $settings;

    public function __construct() {
        $this->settings = get_option('woocommerce_alyapay_settings', []);

        $widget_on  = $this->get('widget_enabled') === 'yes';
        $promo_prod = $this->get('credit_promo_product') === 'yes';
        $promo_cart = $this->get('credit_promo_cart') === 'yes';

        if (!$widget_on && !$promo_prod && !$promo_cart) {
            return;
        }

        add_action('wp_enqueue_scripts', [$this, 'enqueue_widget_script']);

        if ($promo_prod) {
            add_action('woocommerce_single_product_summary', [$this, 'render_product_promo'], 15);
        }

        if ($promo_cart) {
            add_action('woocommerce_cart_totals_after_order_total', [$this, 'render_cart_promo']);
        }

        add_action('woocommerce_thankyou_alyapay', [$this, 'render_success_schedules']);
    }

    public function enqueue_widget_script(): void {
        wp_enqueue_script(
            'alyapay-placement',
            'https://cdn.alyapay.com/js/alya-placement.js',
            [],
            null,
            true
        );
    }

    public function render_product_promo(): void {
        global $product;

        if (!$product) {
            return;
        }

        $price = (float) $product->get_price();
        $min   = (float) ($this->get('amount_min') ?: 500);
        $max   = (float) ($this->get('amount_max') ?: 15000);

        if ($price < $min || $price > $max) {
            return;
        }

        $this->render_template('product-promo', [
            'price'    => $price,
            'settings' => $this->widget_attrs('product'),
        ]);
    }

    public function render_cart_promo(): void {
        if (!WC()->cart) {
            return;
        }
        $total = (float) WC()->cart->total;
        $min   = (float) ($this->get('amount_min') ?: 500);
        $max   = (float) ($this->get('amount_max') ?: 15000);

        if ($total < $min || $total > $max) {
            return;
        }

        $this->render_template('cart-promo', [
            'total'    => $total,
            'settings' => $this->widget_attrs('cart'),
        ]);
    }

    public function render_success_schedules(int $order_id): void {
        $order = wc_get_order($order_id);

        if (!$order) {
            return;
        }

        $transaction_id = $order->get_transaction_id();
        $api_key        = $this->get('api_key');
        $base_url       = $this->api_base_url();

        $installments = [];
        if (empty($transaction_id)) {
            wc_get_logger()->warning('AlyaPay success: no transaction_id on order #' . $order->get_order_number() . ' — showing placeholder schedule', ['source' => 'alyapay']);
        } elseif (!empty($api_key)) {
            try {
                $response     = (new AlyaPay_API($api_key, $base_url))->get_schedules($transaction_id);
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    wc_get_logger()->info('AlyaPay success: schedules API raw response: ' . wp_json_encode($response), ['source' => 'alyapay']);
                }
                $installments = $response['installments'] ?? [];
                if (empty($installments)) {
                    wc_get_logger()->warning('AlyaPay success: schedules API returned empty installments for transaction ' . $transaction_id, ['source' => 'alyapay']);
                }
            } catch (\Exception $e) {
                wc_get_logger()->error('AlyaPay success: schedules API failed for transaction ' . $transaction_id . ': ' . $e->getMessage(), ['source' => 'alyapay']);
            }
        }

        $currency = $order->get_currency() ?: get_woocommerce_currency();

        // Sort by dueDate ascending — matches Magento block
        if (!empty($installments)) {
            usort($installments, static function ($a, $b) {
                $da = $a['dueDate'] ?? '';
                $db = $b['dueDate'] ?? '';
                if (!$da || !$db) return 0;
                return strtotime($da) <=> strtotime($db);
            });
        }

        $installment_count = count($installments);

        // Format amount as plain string matching Magento: "500,00 MAD"
        $format_amount = static function (float $n) use ($currency): string {
            return number_format($n, 2, ',', ' ') . ' ' . $currency;
        };

        // Installment amount — first row, fallback to total/count
        if (!empty($installments)) {
            $installment_amt = $format_amount((float) ($installments[0]['amount'] ?? 0));
        } else {
            $total_float     = (float) $order->get_total();
            $installment_amt = $installment_count > 0 ? $format_amount($total_float / $installment_count) : '';
        }

        // Status mapping matches Magento block exactly
        $status_map = [
            'PAID'      => 'paid',
            'COMPLETED' => 'paid',
            'PENDING'   => 'upcoming',
            'UPCOMING'  => 'upcoming',
            'DUE'       => 'upcoming',
            'SCHEDULED' => 'scheduled',
        ];

        $rows = [];
        foreach ($installments as $item) {
            $status  = $status_map[strtoupper($item['status'] ?? '')] ?? 'scheduled';
            $due_raw = $item['dueDate'] ?? '';
            // Label = formatted date (MMM d, Y) — same as Magento IntlDateFormatter output
            $label   = $due_raw ? date_i18n('M j, Y', strtotime($due_raw)) : '—';
            $rows[]  = [
                'label'  => $label,
                'date'   => '',  // empty — label IS the date, matching Magento
                'amount' => $format_amount((float) ($item['amount'] ?? 0)),
                'status' => $status,
            ];
        }

        $this->render_template('success-schedules', [
            'order_id'          => $order->get_order_number(),
            'order_date'        => $order->get_date_created() ? $order->get_date_created()->date('d/m/Y') : '',
            'order_total'       => $format_amount((float) $order->get_total()),
            'installment_count' => $installment_count,
            'installment_amt'   => $installment_amt,
            'rows'              => $rows,
            'view_order_url'    => $order->get_customer_id() ? $order->get_view_order_url() : '#',
            'continue_url'      => wc_get_page_permalink('shop') ?: home_url('/'),
            'locale'            => $this->widget_lang(),
        ]);
    }

    // -------------------------------------------------------------------------

    private function widget_attrs(string $context = ''): array {
        $resolve = function (string $key, string $global_default) use ($context): string {
            if ($context) {
                $override = $this->get($context . '_widget_' . $key);
                if ($override !== '') {
                    return $override;
                }
            }
            return $this->get('widget_' . $key, $global_default);
        };

        return [
            'theme'         => $resolve('theme', 'light'),
            'variant'       => $resolve('variant', 'default'),
            'detail'        => $resolve('detail', 'modal'),
            'logo_position' => $resolve('logo_position', 'right'),
            'full_width' => $context ? $this->get($context . '_widget_full_width', 'no') : $this->get('widget_full_width', 'no'),
            'margin_x'   => $context ? $this->get($context . '_widget_margin_x', '') : $this->get('widget_margin_x', ''),
            'margin_y'   => $context ? $this->get($context . '_widget_margin_y', '') : $this->get('widget_margin_y', ''),
            'padding_x'  => $context ? $this->get($context . '_widget_padding_x', '') : $this->get('widget_padding_x', ''),
            'padding_y'  => $context ? $this->get($context . '_widget_padding_y', '') : $this->get('widget_padding_y', ''),
            'currency'      => get_woocommerce_currency(),
            'lang'          => $this->widget_lang(),
        ];
    }

    private function widget_lang(): string {
        $locale = get_locale();
        if (strpos($locale, 'fr') === 0) return 'fr';
        if (strpos($locale, 'ar') === 0) return 'ar';
        return 'en';
    }

    private function api_base_url(): string {
        return $this->get('environment') === 'production'
            ? 'https://api.alyapay.com'
            : 'https://sandbox-api.alyapay.com';
    }

    private function get(string $key, string $default = ''): string {
        return (string) ($this->settings[$key] ?? $default);
    }

    private function render_template(string $name, array $data = []): void {
        $file = ALYAPAY_PLUGIN_DIR . "templates/{$name}.php";
        if (!file_exists($file)) {
            return;
        }
        // phpcs:ignore WordPress.PHP.DontExtract.extract_extract
        extract($data);
        include $file;
    }
}
