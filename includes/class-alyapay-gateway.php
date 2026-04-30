<?php
defined('ABSPATH') || exit;

class AlyaPay_Gateway extends WC_Payment_Gateway {
    /** @var AlyaPay_API */
    private $api;
    /** @var AlyaPay_Order_Helper */
    private $order_helper;

    public function __construct() {
        $this->id                 = 'alyapay';
        $this->method_title       = __('AlyaPay', 'alyapay');
        $this->method_description = __('BNPL payment gateway powered by AlyaPay.', 'alyapay');
        $this->has_fields         = true;
        $this->order_button_text  = __('Pay with AlyaPay', 'alyapay');

        $this->init_form_fields();
        $this->init_settings();

        $this->title       = $this->get_option('title');
        $this->description = $this->get_option('description');

        $this->api          = new AlyaPay_API($this->get_option('api_key', ''), $this->api_base_url());
        $this->order_helper = new AlyaPay_Order_Helper();

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);

    }

    public function render_accordion_script(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $page    = isset($_GET['page'])    ? sanitize_text_field(wp_unslash($_GET['page']))    : '';
        $section = isset($_GET['section']) ? sanitize_text_field(wp_unslash($_GET['section'])) : '';
        if ($page !== 'wc-settings' || $section !== $this->id) {
            return;
        }
        ?>
        <style>
        h3.alya-accordion-header { cursor:pointer; user-select:none; display:flex; align-items:center; justify-content:space-between; }
        h3.alya-accordion-header::after { content:'▲'; font-size:11px; color:#999; transition:transform .2s; margin-left:8px; }
        h3.alya-accordion-header.alya-collapsed::after { transform:rotate(180deg); }
        </style>
        <script>
        (function($){
            $(function(){
                $('h3.alya-accordion-header').each(function(){
                    var $h3    = $(this);
                    var $table = $h3.next('table.form-table');
                    $h3.on('click', function(){
                        $h3.toggleClass('alya-collapsed');
                        $table.toggle();
                    });
                });
            });
        })(jQuery);
        </script>
        <?php
    }

    public function init_form_fields(): void {
        $this->form_fields = [
            // ---- General ----
            'enabled'     => [
                'title'   => __('Enable/Disable', 'alyapay'),
                'type'    => 'checkbox',
                'label'   => __('Enable AlyaPay Payment', 'alyapay'),
                'default' => 'yes',
            ],
            'title'       => [
                'title'       => __('Title', 'alyapay'),
                'type'        => 'text',
                'default'     => 'AlyaPay',
                'desc_tip'    => true,
                'description' => __('Payment title shown at checkout.', 'alyapay'),
            ],
            'description' => [
                'title'   => __('Description', 'alyapay'),
                'type'    => 'textarea',
                'default' => __('Pay in installments with AlyaPay.', 'alyapay'),
            ],
            'debug'       => [
                'title'   => __('Debug Mode', 'alyapay'),
                'type'    => 'checkbox',
                'label'   => __('Enable logging', 'alyapay'),
                'default' => 'no',
            ],

            // ---- API ----
            'environment'        => [
                'title'   => __('Environment', 'alyapay'),
                'type'    => 'select',
                'options' => [
                    'sandbox'    => __('Sandbox', 'alyapay'),
                    'production' => __('Production', 'alyapay'),
                ],
                'default' => 'sandbox',
            ],
            'api_key'            => [
                'title'       => __('API Key', 'alyapay'),
                'type'        => 'password',
                'description' => __('Your AlyaPay API key.', 'alyapay'),
                'desc_tip'    => true,
            ],
            'transaction_expiry' => [
                'title'             => __('Transaction Expiry (minutes)', 'alyapay'),
                'type'              => 'number',
                'default'           => '30',
                'custom_attributes' => ['min' => 0, 'max' => 60],
            ],

            // ---- Webhooks ----
            'webhook_url'    => [
                'title'       => __('Store URL', 'alyapay'),
                'type'        => 'text',
                'default'     => home_url('/'),
                'description' => __('Your store base URL. AlyaPay will POST webhook events to this URL (webhook path appended automatically).', 'alyapay'),
                'desc_tip'    => true,
            ],
            'webhook_secret' => [
                'title'       => __('Webhook Secret', 'alyapay'),
                'type'        => 'password',
                'description' => __('Required for production. Verifies webhook signatures.', 'alyapay'),
                'desc_tip'    => true,
            ],

            // ---- Order Status Mapping ----
            'approved_status'  => [
                'title'   => __('Approved Order Status', 'alyapay'),
                'type'    => 'select',
                'options' => wc_get_order_statuses(),
                'default' => 'wc-processing',
            ],
            'cancelled_status' => [
                'title'   => __('Cancelled Order Status', 'alyapay'),
                'type'    => 'select',
                'options' => wc_get_order_statuses(),
                'default' => 'wc-cancelled',
            ],
            'expired_status'   => [
                'title'   => __('Expired Order Status', 'alyapay'),
                'type'    => 'select',
                'options' => wc_get_order_statuses(),
                'default' => 'wc-cancelled',
            ],

            // ---- Limits ----
            'amount_min' => [
                'title'             => __('Minimum Amount', 'alyapay'),
                'type'              => 'number',
                'default'           => '500',
                'custom_attributes' => ['readonly' => 'readonly'],
                'description'       => __('Set by AlyaPay. Contact support to change.', 'alyapay'),
                'desc_tip'          => true,
            ],
            'amount_max' => [
                'title'             => __('Maximum Amount', 'alyapay'),
                'type'              => 'number',
                'default'           => '15000',
                'custom_attributes' => ['readonly' => 'readonly'],
                'description'       => __('Set by AlyaPay. Contact support to change.', 'alyapay'),
                'desc_tip'          => true,
            ],

            // ---- Widgets ----
            'section_global_widget' => [
                'title' => __('Checkout Widget', 'alyapay'),
                'type'  => 'title',
                'class' => 'alya-accordion-header',
            ],
            'widget_enabled'       => [
                'title'   => __('Checkout Widget', 'alyapay'),
                'type'    => 'checkbox',
                'label'   => __('Enable AlyaPay checkout widget', 'alyapay'),
                'default' => 'yes',
            ],
            'widget_theme'         => [
                'title'   => __('Widget Theme', 'alyapay'),
                'type'    => 'select',
                'options' => [
                    'light'         => 'Light',
                    'light-plain'   => 'Light Plain',
                    'dark'          => 'Dark',
                    'dark-plain'    => 'Dark Plain',
                    'neutral'       => 'Neutral',
                    'neutral-plain' => 'Neutral Plain',
                ],
                'default' => 'light',
            ],
            'widget_variant'       => [
                'title'   => __('Widget Variant', 'alyapay'),
                'type'    => 'select',
                'options' => ['default' => 'Default', 'interactive' => 'Interactive'],
                'default' => 'default',
            ],
            'widget_detail'        => [
                'title'   => __('Widget Detail', 'alyapay'),
                'type'    => 'select',
                'options' => ['modal' => 'Modal', 'panel' => 'Panel'],
                'default' => 'modal',
            ],
            'widget_logo_position' => [
                'title'   => __('Widget Logo Position', 'alyapay'),
                'type'    => 'select',
                'options' => ['right' => 'Right', 'left' => 'Left'],
                'default' => 'right',
            ],
            'widget_full_width'    => [
                'title'   => __('Full Width', 'alyapay'),
                'type'    => 'checkbox',
                'label'   => __('Stretch widget to full container width', 'alyapay'),
                'default' => 'no',
            ],
            'widget_margin_x'  => [
                'title'             => __('Margin X (px)', 'alyapay'),
                'type'              => 'number',
                'default'           => '',
                'placeholder'       => '0',
                'description'       => __('Left and right margin in pixels.', 'alyapay'),
                'desc_tip'          => true,
                'custom_attributes' => ['min' => 0],
            ],
            'widget_margin_y'  => [
                'title'             => __('Margin Y (px)', 'alyapay'),
                'type'              => 'number',
                'default'           => '',
                'placeholder'       => '0',
                'description'       => __('Top and bottom margin in pixels.', 'alyapay'),
                'desc_tip'          => true,
                'custom_attributes' => ['min' => 0],
            ],
            'widget_padding_x' => [
                'title'             => __('Padding X (px)', 'alyapay'),
                'type'              => 'number',
                'default'           => '',
                'placeholder'       => '18',
                'description'       => __('Left and right inner padding in pixels.', 'alyapay'),
                'desc_tip'          => true,
                'custom_attributes' => ['min' => 0],
            ],
            'widget_padding_y' => [
                'title'             => __('Padding Y (px)', 'alyapay'),
                'type'              => 'number',
                'default'           => '',
                'placeholder'       => '14',
                'description'       => __('Top and bottom inner padding in pixels.', 'alyapay'),
                'desc_tip'          => true,
                'custom_attributes' => ['min' => 0],
            ],
            // ---- Product Widget ----
            'section_product_widget' => [
                'title' => __('Product Page Widget', 'alyapay'),
                'type'  => 'title',
                'class' => 'alya-accordion-header',
            ],
            'credit_promo_product'         => [
                'title'   => __('Credit Promo on Product Page', 'alyapay'),
                'type'    => 'checkbox',
                'label'   => __('Show BNPL simulator on product pages', 'alyapay'),
                'default' => 'yes',
            ],
            'product_widget_theme'         => [
                'title'       => __('Product Widget Theme', 'alyapay'),
                'type'        => 'select',
                'options'     => ['light' => 'Light', 'light-plain' => 'Light Plain', 'dark' => 'Dark', 'dark-plain' => 'Dark Plain', 'neutral' => 'Neutral', 'neutral-plain' => 'Neutral Plain'],
                'default'     => 'light',
                'description' => __('Theme for the product page widget.', 'alyapay'),
                'desc_tip'    => true,
            ],
            'product_widget_variant'       => [
                'title'       => __('Product Widget Variant', 'alyapay'),
                'type'        => 'select',
                'options'     => ['default' => 'Default', 'interactive' => 'Interactive'],
                'default'     => 'default',
                'description' => __('Variant for the product page widget.', 'alyapay'),
                'desc_tip'    => true,
            ],
            'product_widget_detail'        => [
                'title'       => __('Product Widget Detail', 'alyapay'),
                'type'        => 'select',
                'options'     => ['modal' => 'Modal', 'panel' => 'Panel'],
                'default'     => 'modal',
                'description' => __('Detail overlay style for the product page widget.', 'alyapay'),
                'desc_tip'    => true,
            ],
            'product_widget_logo_position' => [
                'title'       => __('Product Widget Logo Position', 'alyapay'),
                'type'        => 'select',
                'options'     => ['right' => 'Right', 'left' => 'Left'],
                'default'     => 'right',
                'description' => __('Logo position for the product page widget.', 'alyapay'),
                'desc_tip'    => true,
            ],
            'product_widget_position'      => [
                'title'       => __('Widget Position', 'alyapay'),
                'type'        => 'select',
                'options'     => [
                    'after_price'      => __('After Price', 'alyapay'),
                    'before_add_to_cart' => __('Above Add to Cart Button', 'alyapay'),
                ],
                'default'     => 'after_price',
                'description' => __('Where to display the widget on the product page.', 'alyapay'),
                'desc_tip'    => true,
            ],
            'product_widget_full_width'    => [
                'title'   => __('Full Width', 'alyapay'),
                'type'    => 'checkbox',
                'label'   => __('Stretch widget to full container width', 'alyapay'),
                'default' => 'no',
            ],
            'product_widget_margin_x'  => [
                'title'             => __('Margin X (px)', 'alyapay'),
                'type'              => 'number',
                'default'           => '',
                'placeholder'       => '0',
                'description'       => __('Left and right margin in pixels.', 'alyapay'),
                'desc_tip'          => true,
                'custom_attributes' => ['min' => 0],
            ],
            'product_widget_margin_y'  => [
                'title'             => __('Margin Y (px)', 'alyapay'),
                'type'              => 'number',
                'default'           => '',
                'placeholder'       => '0',
                'description'       => __('Top and bottom margin in pixels.', 'alyapay'),
                'desc_tip'          => true,
                'custom_attributes' => ['min' => 0],
            ],
            'product_widget_padding_x' => [
                'title'             => __('Padding X (px)', 'alyapay'),
                'type'              => 'number',
                'default'           => '',
                'placeholder'       => '18',
                'description'       => __('Left and right inner padding in pixels.', 'alyapay'),
                'desc_tip'          => true,
                'custom_attributes' => ['min' => 0],
            ],
            'product_widget_padding_y' => [
                'title'             => __('Padding Y (px)', 'alyapay'),
                'type'              => 'number',
                'default'           => '',
                'placeholder'       => '14',
                'description'       => __('Top and bottom inner padding in pixels.', 'alyapay'),
                'desc_tip'          => true,
                'custom_attributes' => ['min' => 0],
            ],
            'product_widget_shortcode_info' => [
                'type' => 'alya_info',
                'html' => '<div style="background:#f0f6fc;border-left:4px solid #2271b1;border-radius:0 4px 4px 0;padding:14px 16px;">'
                        . '<p style="margin:0 0 6px;font-size:13px;color:#50575e;">'
                        . esc_html__('You can use a shortcode to place this widget anywhere — in page builders, custom templates, or any content area.', 'alyapay')
                        . ' ' . esc_html__('If you prefer automatic placement, enable it above and use the settings to configure appearance.', 'alyapay')
                        . '</p>'
                        . '<p style="margin:0;"><strong style="font-size:12px;text-transform:uppercase;letter-spacing:.5px;color:#2271b1;">' . esc_html__('Shortcode', 'alyapay') . '</strong></p>'
                        . '<code style="display:inline-block;margin-top:4px;background:#fff;border:1px solid #c3d4e8;border-radius:3px;padding:4px 10px;font-size:13px;">[alyapay_promo context=&quot;product&quot;]</code>'
                        . '</div>',
            ],

            // ---- Cart Widget ----
            'section_cart_widget' => [
                'title' => __('Cart Widget', 'alyapay'),
                'type'  => 'title',
                'class' => 'alya-accordion-header',
            ],
            'credit_promo_cart'            => [
                'title'   => __('Credit Promo on Cart', 'alyapay'),
                'type'    => 'checkbox',
                'label'   => __('Show BNPL simulator on cart page', 'alyapay'),
                'default' => 'yes',
            ],
            'cart_widget_theme'            => [
                'title'       => __('Cart Widget Theme', 'alyapay'),
                'type'        => 'select',
                'options'     => ['light' => 'Light', 'light-plain' => 'Light Plain', 'dark' => 'Dark', 'dark-plain' => 'Dark Plain', 'neutral' => 'Neutral', 'neutral-plain' => 'Neutral Plain'],
                'default'     => 'light',
                'description' => __('Theme for the cart widget.', 'alyapay'),
                'desc_tip'    => true,
            ],
            'cart_widget_variant'          => [
                'title'       => __('Cart Widget Variant', 'alyapay'),
                'type'        => 'select',
                'options'     => ['default' => 'Default', 'interactive' => 'Interactive'],
                'default'     => 'default',
                'description' => __('Variant for the cart widget.', 'alyapay'),
                'desc_tip'    => true,
            ],
            'cart_widget_detail'           => [
                'title'       => __('Cart Widget Detail', 'alyapay'),
                'type'        => 'select',
                'options'     => ['modal' => 'Modal', 'panel' => 'Panel'],
                'default'     => 'modal',
                'description' => __('Detail overlay style for the cart widget.', 'alyapay'),
                'desc_tip'    => true,
            ],
            'cart_widget_logo_position'    => [
                'title'       => __('Cart Widget Logo Position', 'alyapay'),
                'type'        => 'select',
                'options'     => ['right' => 'Right', 'left' => 'Left'],
                'default'     => 'right',
                'description' => __('Logo position for the cart widget.', 'alyapay'),
                'desc_tip'    => true,
            ],
            'cart_widget_position'         => [
                'title'       => __('Widget Position', 'alyapay'),
                'type'        => 'select',
                'options'     => [
                    'after_total'          => __('After Order Total', 'alyapay'),
                    'before_checkout_button' => __('Above Proceed to Checkout Button', 'alyapay'),
                ],
                'default'     => 'after_total',
                'description' => __('Where to display the widget on the cart page.', 'alyapay'),
                'desc_tip'    => true,
            ],
            'cart_widget_full_width'    => [
                'title'   => __('Full Width', 'alyapay'),
                'type'    => 'checkbox',
                'label'   => __('Stretch widget to full container width', 'alyapay'),
                'default' => 'no',
            ],
            'cart_widget_margin_x'  => [
                'title'             => __('Margin X (px)', 'alyapay'),
                'type'              => 'number',
                'default'           => '',
                'placeholder'       => '0',
                'description'       => __('Left and right margin in pixels.', 'alyapay'),
                'desc_tip'          => true,
                'custom_attributes' => ['min' => 0],
            ],
            'cart_widget_margin_y'  => [
                'title'             => __('Margin Y (px)', 'alyapay'),
                'type'              => 'number',
                'default'           => '',
                'placeholder'       => '0',
                'description'       => __('Top and bottom margin in pixels.', 'alyapay'),
                'desc_tip'          => true,
                'custom_attributes' => ['min' => 0],
            ],
            'cart_widget_padding_x' => [
                'title'             => __('Padding X (px)', 'alyapay'),
                'type'              => 'number',
                'default'           => '',
                'placeholder'       => '18',
                'description'       => __('Left and right inner padding in pixels.', 'alyapay'),
                'desc_tip'          => true,
                'custom_attributes' => ['min' => 0],
            ],
            'cart_widget_padding_y' => [
                'title'             => __('Padding Y (px)', 'alyapay'),
                'type'              => 'number',
                'default'           => '',
                'placeholder'       => '14',
                'description'       => __('Top and bottom inner padding in pixels.', 'alyapay'),
                'desc_tip'          => true,
                'custom_attributes' => ['min' => 0],
            ],
            'cart_widget_shortcode_info' => [
                'type' => 'alya_info',
                'html' => '<div style="background:#f0f6fc;border-left:4px solid #2271b1;border-radius:0 4px 4px 0;padding:14px 16px;">'
                        . '<p style="margin:0 0 6px;font-size:13px;color:#50575e;">'
                        . esc_html__('You can use a shortcode to place this widget anywhere — in page builders, custom templates, or any content area.', 'alyapay')
                        . ' ' . esc_html__('If you prefer automatic placement, enable it above and use the settings to configure appearance.', 'alyapay')
                        . '</p>'
                        . '<p style="margin:0;"><strong style="font-size:12px;text-transform:uppercase;letter-spacing:.5px;color:#2271b1;">' . esc_html__('Shortcode', 'alyapay') . '</strong></p>'
                        . '<code style="display:inline-block;margin-top:4px;background:#fff;border:1px solid #c3d4e8;border-radius:3px;padding:4px 10px;font-size:13px;">[alyapay_promo context=&quot;cart&quot;]</code>'
                        . '</div>',
            ],
        ];
    }

    public function is_available(): bool {
        if (!parent::is_available()) {
            return false;
        }
        if (WC()->cart) {
            $total = (float) WC()->cart->total;
            $min   = (float) ($this->get_option('amount_min') ?: 500);
            $max   = (float) ($this->get_option('amount_max') ?: 15000);
            if ($total < $min || $total > $max) {
                return false;
            }
        }
        return true;
    }

    public function payment_fields(): void {
        if ($this->description) {
            echo wpautop(wptexturize(esc_html($this->description)));
        }

        $widget_enabled = $this->get_option('widget_enabled') === 'yes';
        if (!$widget_enabled || !WC()->cart) {
            return;
        }

        $total         = number_format((float) WC()->cart->total, 2, '.', '');
        $currency      = get_woocommerce_currency();
        $locale        = get_locale();
        $lang          = strpos($locale, 'fr') === 0 ? 'fr' : (strpos($locale, 'ar') === 0 ? 'ar' : 'en');
        $theme         = $this->get_option('widget_theme', 'light');
        $variant       = $this->get_option('widget_variant', 'default');
        $detail        = $this->get_option('widget_detail', 'modal');
        $logo_position = $this->get_option('widget_logo_position', 'right');
        $full_width = $this->get_option('widget_full_width', 'no') === 'yes';
        $margin_x   = $this->get_option('widget_margin_x', '');
        $margin_y   = $this->get_option('widget_margin_y', '');
        $padding_x  = $this->get_option('widget_padding_x', '');
        $padding_y  = $this->get_option('widget_padding_y', '');

        $extra = '';
        if ($full_width) $extra .= ' full-width="true"';
        if ($margin_x !== '')  $extra .= ' margin-x="'  . esc_attr($margin_x)  . '"';
        if ($margin_y !== '')  $extra .= ' margin-y="'  . esc_attr($margin_y)  . '"';
        if ($padding_x !== '') $extra .= ' padding-x="' . esc_attr($padding_x) . '"';
        if ($padding_y !== '') $extra .= ' padding-y="' . esc_attr($padding_y) . '"';

        printf(
            '<div class="alyapay-widget"><alya-placement key="checkout" price="%s" currency="%s" lang="%s" installments="4" theme="%s" variant="%s" detail="%s" logo-position="%s"%s></alya-placement></div>',
            esc_attr($total),
            esc_attr($currency),
            esc_attr($lang),
            esc_attr($theme),
            esc_attr($variant),
            esc_attr($detail),
            esc_attr($logo_position),
            $extra
        );
    }

    public function generate_alya_info_html(string $key, array $data): string {
        return '<tr><td colspan="2" style="padding:12px 0 8px;border:none;">' . ($data['html'] ?? '') . '</td></tr>';
    }

    public function process_payment($order_id): array {
        $order = wc_get_order($order_id);

        if (!$order) {
            wc_add_notice(__('Order not found.', 'alyapay'), 'error');
            return ['result' => 'failure'];
        }

        try {
            $payload  = $this->build_session_intent_payload($order);
            $response = $this->api->create_session_intent($payload);

            $order->update_meta_data('_alyapay_checkout_token',    $response['checkout_token']    ?? '');
            $order->update_meta_data('_alyapay_payment_intent_id', $response['payment_intent_id'] ?? '');
            $order->update_status('pending', __('Awaiting AlyaPay payment.', 'alyapay'));
            $order->save();

            $return_url = add_query_arg([
                'wc-api'    => 'alyapay_return',
                'order_id'  => $order->get_id(),
                'order_key' => $order->get_order_key(),
            ], home_url('/'));

            $checkout_url = add_query_arg('redirect_url', rawurlencode($return_url), $response['checkout_url']);

            $this->log("Session intent created for order #{$order->get_order_number()}");

            return ['result' => 'success', 'redirect' => $checkout_url];

        } catch (AlyaPay_API_Exception $e) {
            $this->log("Session intent failed for order #{$order->get_order_number()}: {$e->getMessage()}", 'error');
            $order->update_status('failed', sprintf(__('AlyaPay error: %s', 'alyapay'), $e->getMessage()));
            wc_add_notice(__('Payment could not be initiated. Please try again.', 'alyapay'), 'error');
            return ['result' => 'failure'];
        }
    }

    public function handle_return(): void {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $order_id       = isset($_GET['order_id'])      ? absint($_GET['order_id'])                                        : 0;
        $order_key      = isset($_GET['order_key'])     ? sanitize_text_field(wp_unslash($_GET['order_key']))               : '';
        $status         = isset($_GET['status'])        ? sanitize_text_field(wp_unslash($_GET['status']))                  : '';
        $transaction_id = isset($_GET['transaction_id'])
            ? sanitize_text_field(wp_unslash($_GET['transaction_id']))
            : (isset($_GET['transactionId']) ? sanitize_text_field(wp_unslash($_GET['transactionId'])) : '');
        // phpcs:enable

        $order = wc_get_order($order_id);

        if (!$order || !hash_equals($order->get_order_key(), $order_key)) {
            wc_add_notice(__('Invalid order.', 'alyapay'), 'error');
            wp_safe_redirect(wc_get_checkout_url());
            exit;
        }

        $url_status = strtoupper($status);

        // FAILURE — cancel order immediately, matches Magento Success.php FAILURE branch
        if ($url_status === 'FAILURE') {
            $this->order_helper->cancel_order($order, 'Payment failed (URL status: FAILURE)', $this->wc_status('cancelled_status'));
            wc_add_notice(__('Payment failed. Please try again or choose another payment method.', 'alyapay'), 'error');
            wp_safe_redirect(wc_get_checkout_url());
            exit;
        }

        // CANCELED / EXPIRED — restore cart and redirect to checkout
        if ($url_status === 'CANCELED' || $url_status === 'EXPIRED') {
            $status_key = $url_status === 'EXPIRED' ? 'expired_status' : 'cancelled_status';
            $this->order_helper->cancel_order(
                $order,
                sprintf('Payment %s at AlyaPay checkout.', strtolower($url_status)),
                $this->wc_status($status_key)
            );
            wc_add_notice(
                sprintf(
                    /* translators: %s: payment status (canceled or expired) */
                    __('Payment was %s. Please try again.', 'alyapay'),
                    strtolower($url_status)
                ),
                'error'
            );
            wp_safe_redirect(wc_get_checkout_url());
            exit;
        }

        if ($url_status !== 'SUCCESS') {
            $this->order_helper->cancel_order($order, __('Payment cancelled or failed at AlyaPay checkout.', 'alyapay'), $this->wc_status('cancelled_status'));
            wc_add_notice(__('Payment was not completed. Please try again.', 'alyapay'), 'error');
            wp_safe_redirect(wc_get_checkout_url());
            exit;
        }

        // No transaction_id — let webhook confirm, redirect to success page
        if (empty($transaction_id)) {
            $this->log("SUCCESS return with no transaction_id for order #{$order->get_order_number()} — webhook will confirm.", 'error');
            wc_add_notice(__('Your order is being processed. You will be notified when confirmed.', 'alyapay'), 'notice');
            wp_safe_redirect($this->get_return_url($order));
            exit;
        }

        try {
            $status_data = $this->api->get_transaction_status($transaction_id);
            $api_status  = $status_data['status'] ?? '';
        } catch (AlyaPay_API_Exception $e) {
            $this->log("Status check failed for transaction {$transaction_id}: {$e->getMessage()}", 'error');
            wc_add_notice(__('Could not verify payment status. Your order is being reviewed.', 'alyapay'), 'notice');
            wp_safe_redirect($this->get_return_url($order));
            exit;
        }

        if (in_array($api_status, ['APPROVED', 'COMPLETED'], true)) {
            $this->order_helper->approve_and_capture($order, $transaction_id, 'redirect', $this->wc_status('approved_status'));
            $this->log("Payment approved via redirect for order #{$order->get_order_number()}. Transaction: {$transaction_id}");
            wp_safe_redirect($this->get_return_url($order));
            exit;
        }

        if (in_array($api_status, ['CANCELED', 'CANCELLED', 'EXPIRED', 'DECLINED', 'FAILED', 'FAILURE'], true)) {
            $status_key = in_array($api_status, ['EXPIRED'], true) ? 'expired_status' : 'cancelled_status';
            $this->order_helper->cancel_order(
                $order,
                sprintf(
                    /* translators: %s: payment status from AlyaPay */
                    __('AlyaPay payment %s.', 'alyapay'),
                    strtolower($api_status)
                ),
                $this->wc_status($status_key)
            );
            wc_add_notice(__('Payment was not completed. Please try again.', 'alyapay'), 'error');
            wp_safe_redirect(wc_get_checkout_url());
            exit;
        }

        // Pending / unknown — let customer through, webhook will confirm
        wc_add_notice(__('Your order is being processed. You will be notified when confirmed.', 'alyapay'), 'notice');
        wp_safe_redirect($this->get_return_url($order));
        exit;
    }

    public function handle_webhook(): void {
        $payload   = (string) file_get_contents('php://input');
        // phpcs:disable WordPress.Security.ValidatedSanitizedInput
        $signature = isset($_SERVER['HTTP_X_ALYA_SIGNATURE']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_X_ALYA_SIGNATURE'])) : '';
        $timestamp = isset($_SERVER['HTTP_X_ALYA_TIMESTAMP']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_X_ALYA_TIMESTAMP'])) : '';
        // phpcs:enable

        $secret  = $this->get_option('webhook_secret', '');
        $webhook = new AlyaPay_Webhook(
            new AlyaPay_Order_Helper(),
            $this->wc_status('approved_status'),
            $this->wc_status('cancelled_status'),
            $this->wc_status('expired_status')
        );

        if (!$webhook->verify_signature($payload, $signature, $timestamp, $secret)) {
            $this->log('Webhook signature verification failed.', 'error');
            wp_send_json(['success' => false, 'error' => 'Invalid signature'], 401);
            exit;
        }

        $data = json_decode($payload, true);
        if (!is_array($data)) {
            wp_send_json(['success' => false, 'error' => 'Invalid payload'], 400);
            exit;
        }

        $this->log("Webhook received: event=" . ($data['event'] ?? 'unknown'));

        if ($webhook->process($data)) {
            wp_send_json(['success' => true], 200);
        } else {
            $this->log("Webhook processing failed for event: " . ($data['event'] ?? ''), 'error');
            wp_send_json(['success' => false, 'error' => 'Processing failed'], 500);
        }
        exit;
    }

    public function process_admin_options(): bool {
        $result = parent::process_admin_options();
        $this->sync_partner_config();
        return $result;
    }

    // -------------------------------------------------------------------------

    private function sync_partner_config(): void {
        $api_key     = $this->get_option('api_key');
        $environment = $this->get_option('environment', 'sandbox');

        if (empty($api_key) || $this->get_option('enabled') !== 'yes') {
            return;
        }

        $webhook_url = $this->get_webhook_url();

        // AlyaPay API requires HTTPS webhook URL — skip sync on non-HTTPS (local dev)
        if (strpos($webhook_url, 'https://') !== 0) {
            $this->log('Webhook sync skipped: URL must use HTTPS (' . $webhook_url . ')');
            return;
        }

        $payload = [
            'webhookUrl'        => $webhook_url,
            'webhookEnabled'    => !empty(trim($webhook_url)),
            'transactionExpiry' => (int) $this->get_option('transaction_expiry', 30),
            'generateNewSecret' => false,
        ];

        try {
            (new AlyaPay_API($api_key, $this->api_base_url()))->update_partner_config($payload);
        } catch (\Exception $e) {
            $this->log('Partner config sync failed: ' . $e->getMessage(), 'error');

            if ($environment === 'production') {
                WC_Admin_Settings::add_error(
                    __('AlyaPay: Could not sync configuration. Check your API key.', 'alyapay')
                );
            } else {
                WC_Admin_Settings::add_message(
                    __('AlyaPay: Settings saved. Sandbox config sync skipped (API unreachable or key invalid).', 'alyapay')
                );
            }
        }
    }

    private function build_session_intent_payload(\WC_Order $order): array {
        $items = [];

        foreach ($order->get_items() as $item) {
            /** @var \WC_Order_Item_Product $item */
            $qty     = max(1, (int) $item->get_quantity());
            $product = $item->get_product();
            $sku     = $product ? trim((string) $product->get_sku()) : '';
            // Item ID: SKU preferred, fallback product ID — truncated to 64 chars (matches Magento getSku() substr)
            $id      = substr($sku ?: (string) $item->get_product_id(), 0, 64);
            // Tax-inclusive price per unit — matches Magento getPriceInclTax()
            $price   = ((float) $item->get_subtotal() + (float) $item->get_subtotal_tax()) / $qty;
            $items[] = [
                'id'       => $id,
                'name'     => $item->get_name(),
                'price'    => round($price, 2),
                'quantity' => $qty,
            ];
        }

        // Shipping with tax — matches Magento getShippingAmount() + getShippingTaxAmount()
        $shipping = (float) $order->get_shipping_total() + (float) $order->get_shipping_tax();
        if ($shipping > 0) {
            $items[] = [
                'id'       => 'shipping',
                'name'     => __('Shipping', 'alyapay'),
                'price'    => round($shipping, 2),
                'quantity' => 1,
            ];
        }

        // Fallback for empty orders — matches Magento SessionIntentService fallback item
        if (empty($items)) {
            $increment_id = $order->get_order_number();
            $items[]      = [
                'id'       => 'order_' . $increment_id,
                'name'     => 'Order #' . $increment_id,
                'price'    => (float) $order->get_total(),
                'quantity' => 1,
            ];
        }

        return [
            'currency'        => $order->get_currency(),
            'total'           => (float) $order->get_total(),
            'items'           => $items,
            'vendorReference' => $order->get_order_number(),
        ];
    }

    private function get_webhook_url(): string {
        $base = trim($this->get_option('webhook_url', ''));
        if (empty($base)) {
            $base = home_url('/');
        }
        $base    = rtrim($base, '/');
        $suffix  = '/?wc-api=alyapay_webhook';
        return strpos($base, $suffix) !== false ? $base : $base . $suffix;
    }

    private function api_base_url(): string {
        return $this->get_option('environment') === 'production'
            ? 'https://api.alyapay.com'
            : 'https://sandbox-api.alyapay.com';
    }

    private function wc_status(string $option): string {
        $status = $this->get_option($option, 'wc-processing');
        return strpos($status, 'wc-') === 0 ? substr($status, 3) : $status;
    }

    private function log(string $message, string $level = 'info'): void {
        if ($this->get_option('debug') !== 'yes' && $level === 'info') {
            return;
        }
        wc_get_logger()->log($level, $message, ['source' => 'alyapay']);
    }
}
