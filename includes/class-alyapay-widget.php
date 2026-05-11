<?php
defined('ABSPATH') || exit;

class AlyaPay_Widget {
    /** @var array */
    private $settings;

    public function __construct() {
        $this->settings = get_option('woocommerce_alyapay_settings', []);

        // Shortcode + script always available regardless of widget toggles
        add_action('wp_enqueue_scripts', [$this, 'enqueue_widget_script']);
        add_shortcode('alyapay_promo', [$this, 'shortcode_promo']);

        $widget_on  = $this->get('widget_enabled') === 'yes';
        $promo_prod = $this->get('credit_promo_product') === 'yes';
        $promo_cart = $this->get('credit_promo_cart') === 'yes';

        if (!$widget_on && !$promo_prod && !$promo_cart) {
            return;
        }

        if ($promo_prod) {
            $prod_position = $this->get('product_widget_position', 'after_price');
            if ($prod_position === 'before_add_to_cart') {
                add_action('woocommerce_single_product_summary', [$this, 'render_product_promo'], 29);
            } else {
                add_action('woocommerce_single_product_summary', [$this, 'render_product_promo'], 15);
            }
        }

        if ($promo_cart) {
            $cart_position = $this->get('cart_widget_position', 'after_total');
            if ($cart_position === 'before_checkout_button') {
                add_action('woocommerce_proceed_to_checkout', [$this, 'render_cart_promo'], 15);
            } else {
                add_action('woocommerce_cart_totals_after_order_total', [$this, 'render_cart_promo']);
            }
            add_action('woocommerce_pay_order_before_submit', [$this, 'render_cart_promo']);
            add_action('woocommerce_after_mini_cart', [$this, 'render_mini_cart_promo']);
            add_filter('render_block_woocommerce/filled-mini-cart-contents-block', [$this, 'inject_mini_cart_block_promo'], 10, 1);
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

        wp_add_inline_script('alyapay-placement', '(function(){
            function syncMiniCartPadding(){
                var items=document.querySelector(".wc-block-mini-cart__items");
                var promos=document.querySelectorAll(".wc-block-mini-cart__drawer .alyapay-credit-promo,.wc-block-mini-cart__contents .alyapay-credit-promo");
                if(!items||!promos.length)return;
                var cs=window.getComputedStyle(items);
                promos.forEach(function(el){el.style.paddingLeft=cs.paddingLeft;el.style.paddingRight=cs.paddingRight;el.style.paddingBottom="12px";});
            }
            if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",syncMiniCartPadding);}else{syncMiniCartPadding();}
        })();');
    }

    public function render_product_promo(): void {
        global $product;

        if (!$product) {
            return;
        }

        $price = (float) $product->get_price();
        $max   = (float) ($this->get('amount_max') ?: 15000);
        if ($price > $max) {
            return;
        }

        $this->render_template('product-promo', [
            'price'    => $price,
            'settings' => $this->widget_attrs('product'),
        ]);
    }

    public function render_cart_promo(): void {
        if (is_wc_endpoint_url('order-pay')) {
            $order_id = absint(get_query_var('order-pay'));
            $order    = $order_id ? wc_get_order($order_id) : null;
            $total    = $order ? (float) $order->get_total() : 0.0;
        } elseif (WC()->cart) {
            $total = (float) WC()->cart->total;
        } else {
            return;
        }

        $max = (float) ($this->get('amount_max') ?: 15000);
        if ($total > $max) {
            return;
        }

        $this->render_template('cart-promo', [
            'total'    => $total,
            'settings' => $this->widget_attrs('cart'),
        ]);
    }


    public function render_mini_cart_promo(): void {
        if (!WC()->cart || WC()->cart->is_empty()) {
            return;
        }
        $total = (float) WC()->cart->total;
        if ($total <= 0) {
            $total = (float) WC()->cart->get_subtotal();
        }
        if ($total <= 0) {
            $total = array_sum(array_column(WC()->cart->get_cart(), 'line_total'));
        }
        $max = (float) ($this->get('amount_max') ?: 15000);
        if ($total > $max) {
            return;
        }
        echo $this->mini_cart_promo_html($total);
    }

    public function inject_mini_cart_block_promo(string $html): string {
        if (!WC()->cart || WC()->cart->is_empty()) {
            return $html;
        }
        $total = (float) WC()->cart->total;
        if ($total <= 0) {
            $total = (float) WC()->cart->get_subtotal();
        }
        if ($total <= 0) {
            $total = array_sum(array_column(WC()->cart->get_cart(), 'line_total'));
        }
        $max = (float) ($this->get('amount_max') ?: 15000);
        if ($total > $max) {
            return $html;
        }
        $widget = $this->mini_cart_promo_html($total);

        // Inject before the footer div (handles extra classes / attributes gracefully)
        $replaced = preg_replace(
            '/(<div[^>]+class="[^"]*wc-block-mini-cart__footer[^"]*")/s',
            $widget . '$1',
            $html,
            1
        );
        if ($replaced !== null && $replaced !== $html) {
            return $replaced;
        }

        // Fallback: inject before the last closing div
        $pos = strrpos($html, '</div>');
        if ($pos !== false) {
            return substr($html, 0, $pos) . $widget . substr($html, $pos);
        }

        return $html . $widget;
    }

    private function mini_cart_promo_html(float $total): string {
        $settings  = $this->widget_attrs('cart');
        $extra = '';
        if (!empty($settings['min_amount']))  $extra .= ' min-amount="' . esc_attr($settings['min_amount']) . '" min-display="' . esc_attr($settings['min_display']) . '"';
        if (!empty($settings['full_width']) && $settings['full_width'] === 'yes') $extra .= ' full-width="true"';
        if ($settings['margin_x'] !== '')  $extra .= ' margin-x="'  . esc_attr($settings['margin_x'])  . '"';
        if ($settings['margin_y'] !== '')  $extra .= ' margin-y="'  . esc_attr($settings['margin_y'])  . '"';
        if ($settings['padding_x'] !== '') $extra .= ' padding-x="' . esc_attr($settings['padding_x']) . '"';
        if ($settings['padding_y'] !== '') $extra .= ' padding-y="' . esc_attr($settings['padding_y']) . '"';

        return sprintf(
            '<div class="alyapay-credit-promo"><alya-placement key="cart" price="%s" currency="%s" lang="%s" installments="4" theme="%s" variant="%s" detail="%s" logo-position="%s"%s></alya-placement></div>',
            esc_attr((string) $total),
            esc_attr($settings['currency']),
            esc_attr($settings['lang']),
            esc_attr($settings['theme']),
            esc_attr($settings['variant']),
            esc_attr($settings['detail']),
            esc_attr($settings['logo_position']),
            $extra
        );
    }

    public function shortcode_promo(array $atts): string {
        // Require AlyaPay gateway to be enabled
        $gateway_settings = get_option('woocommerce_alyapay_settings', []);
        if (($gateway_settings['enabled'] ?? 'no') !== 'yes') {
            return '';
        }

        // Auto-detect price: amount/price attr > current product > cart total (works in mini-cart too)
        $price = 0.0;

        if (!empty($atts['amount'])) {
            $price = (float) $atts['amount'];
        } elseif (!empty($atts['price'])) {
            $price = (float) $atts['price'];
        } elseif (is_product()) {
            global $product;
            if ($product) {
                $price = (float) $product->get_price();
            }
        } elseif (WC()->cart && !WC()->cart->is_empty()) {
            $price = (float) WC()->cart->total;
            if ($price <= 0) {
                $price = array_sum(array_column(WC()->cart->get_cart(), 'line_total'));
            }
        }

        if ($price <= 0) {
            return '';
        }

        $max = (float) ($this->get('amount_max') ?: 15000);
        if ($price > $max) {
            return '';
        }

        $context = in_array($atts['context'] ?? '', ['product', 'cart'], true) ? $atts['context'] : '';
        $attrs   = $this->widget_attrs($context);

        // Allow shortcode to override any attr
        $overridable = ['theme', 'variant', 'detail', 'logo_position', 'full_width', 'margin_x', 'margin_y', 'padding_x', 'padding_y'];
        foreach ($overridable as $key) {
            $sc_key = str_replace('_', '-', $key);
            if (isset($atts[$sc_key])) {
                $attrs[$key] = sanitize_text_field($atts[$sc_key]);
            } elseif (isset($atts[$key])) {
                $attrs[$key] = sanitize_text_field($atts[$key]);
            }
        }

        $installments = isset($atts['installments']) ? (int) $atts['installments'] : 4;
        $placement_key = $context === 'cart' ? 'cart' : 'credit-promotion';

        $extra = '';
        if (!empty($attrs['min_amount']))  $extra .= ' min-amount="' . esc_attr($attrs['min_amount']) . '" min-display="' . esc_attr($attrs['min_display']) . '"';
        if (!empty($attrs['full_width']) && $attrs['full_width'] === 'yes') $extra .= ' full-width="true"';
        if ($attrs['margin_x'] !== '')  $extra .= ' margin-x="'  . esc_attr($attrs['margin_x'])  . '"';
        if ($attrs['margin_y'] !== '')  $extra .= ' margin-y="'  . esc_attr($attrs['margin_y'])  . '"';
        if ($attrs['padding_x'] !== '') $extra .= ' padding-x="' . esc_attr($attrs['padding_x']) . '"';
        if ($attrs['padding_y'] !== '') $extra .= ' padding-y="' . esc_attr($attrs['padding_y']) . '"';

        wp_enqueue_script('alyapay-placement');

        return sprintf(
            '<div class="alyapay-credit-promo"><alya-placement key="%s" price="%s" currency="%s" lang="%s" installments="%d" theme="%s" variant="%s" detail="%s" logo-position="%s"%s></alya-placement></div>',
            esc_attr($placement_key),
            esc_attr((string) $price),
            esc_attr($attrs['currency']),
            esc_attr($attrs['lang']),
            $installments,
            esc_attr($attrs['theme']),
            esc_attr($attrs['variant']),
            esc_attr($attrs['detail']),
            esc_attr($attrs['logo_position']),
            $extra
        );
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
            'full_width'    => $context ? $this->get($context . '_widget_full_width', 'no') : $this->get('widget_full_width', 'no'),
            'margin_x'      => $context ? $this->get($context . '_widget_margin_x', '') : $this->get('widget_margin_x', ''),
            'margin_y'      => $context ? $this->get($context . '_widget_margin_y', '') : $this->get('widget_margin_y', ''),
            'padding_x'     => $context ? $this->get($context . '_widget_padding_x', '') : $this->get('widget_padding_x', ''),
            'padding_y'     => $context ? $this->get($context . '_widget_padding_y', '') : $this->get('widget_padding_y', ''),
            'min_amount'    => $this->get('cart_widget_show_below_min') === 'yes' ? ($this->get('amount_min') ?: '500') : '',
            'min_display'   => $this->get('cart_widget_show_below_min') === 'yes' ? ($this->get('cart_widget_min_display') ?: 'rich') : '',
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
