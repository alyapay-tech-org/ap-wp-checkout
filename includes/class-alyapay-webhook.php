<?php
defined('ABSPATH') || exit;

class AlyaPay_Webhook {
    private const VALID_EVENTS      = ['transaction.approved', 'transaction.cancelled', 'transaction.expired'];
    private const TIMESTAMP_TOLERANCE = 300; // seconds

    /** @var AlyaPay_Order_Helper */
    private $order_helper;
    /** @var string */
    private $approved_status;
    /** @var string */
    private $cancelled_status;
    /** @var string */
    private $expired_status;

    public function __construct(
        AlyaPay_Order_Helper $order_helper,
        string $approved_status,
        string $cancelled_status,
        string $expired_status
    ) {
        $this->order_helper     = $order_helper;
        $this->approved_status  = $approved_status;
        $this->cancelled_status = $cancelled_status;
        $this->expired_status   = $expired_status;
    }

    public function verify_signature(string $payload, string $signature, string $timestamp, string $secret): bool {
        // Log warnings matching Magento SignatureVerifier behavior
        if (empty($secret)) {
            wc_get_logger()->warning('AlyaPay webhook: webhook_secret is not configured. Configure it in Admin.', ['source' => 'alyapay']);
            return false;
        }
        if (empty($signature)) {
            wc_get_logger()->warning('AlyaPay webhook: missing signature (webhook_secret is set)', ['source' => 'alyapay']);
            return false;
        }

        // Strip "sha256=" prefix — AlyaPay sends "sha256=<hex>"
        if (strpos($signature, 'sha256=') === 0) {
            $signature = substr($signature, 7);
        }

        if (!empty($timestamp)) {
            $ts = is_numeric($timestamp) ? (int) $timestamp : (int) strtotime($timestamp);
            if ($ts <= 0 || abs(time() - $ts) > self::TIMESTAMP_TOLERANCE) {
                return false;
            }
            // Magento uses dot separator: timestamp.payload
            $data = $timestamp . '.' . $payload;
        } else {
            $data = $payload;
        }

        $expected = bin2hex(hash_hmac('sha256', $data, $secret, true));
        return hash_equals($expected, $signature);
    }

    public function process(array $data): bool {
        $event            = $data['event'] ?? '';
        $transaction_data = $data['data'] ?? [];

        if (!in_array($event, self::VALID_EVENTS, true)) {
            return true; // Unknown events are silently accepted
        }

        $vendor_reference = $transaction_data['vendorReference']
            ?? $transaction_data['orderReference']
            ?? '';
        // AlyaPay sends transaction ID as 'id' — matches Magento WebhookProcessor ($webhookData['id'])
        $transaction_id   = $transaction_data['id'] ?? $transaction_data['transactionId'] ?? '';

        if (empty($vendor_reference)) {
            wc_get_logger()->error('AlyaPay webhook: missing vendorReference in payload. data=' . wp_json_encode($transaction_data), ['source' => 'alyapay']);
            return false;
        }

        $order = $this->get_order_by_vendor_reference($vendor_reference);
        if (!$order) {
            wc_get_logger()->error('AlyaPay webhook: order not found for vendorReference=' . $vendor_reference, ['source' => 'alyapay']);
            return false;
        }

        switch ($event) {
            case 'transaction.approved':
                return $this->handle_approved($order, $transaction_id);

            case 'transaction.cancelled':
                return $this->handle_status_change($order, $this->cancelled_status, 'AlyaPay: transaction cancelled.');

            case 'transaction.expired':
                return $this->handle_status_change($order, $this->expired_status, 'AlyaPay: transaction expired.');
        }

        return true;
    }

    // -------------------------------------------------------------------------

    private function handle_approved(\WC_Order $order, string $transaction_id): bool {
        if ($order->has_status(['cancelled', 'refunded', 'failed'])) {
            return true;
        }

        if ($order->is_paid()) {
            return true; // Redirect fallback already captured
        }

        $this->order_helper->approve_and_capture($order, $transaction_id, 'webhook', $this->approved_status);
        return true;
    }

    private function handle_status_change(\WC_Order $order, string $status, string $note): bool {
        if ($order->has_status(['cancelled', 'refunded', 'failed', 'completed'])) {
            return true;
        }

        $order->update_status($status, $note);
        return true;
    }

    private function get_order_by_vendor_reference(string $reference): ?\WC_Order {
        // Standard WC: order number = order ID
        $order = wc_get_order((int) $reference);
        if ($order instanceof \WC_Order) {
            return $order;
        }

        // Fallback for sequential order number plugins
        $orders = wc_get_orders([
            'meta_key'   => '_order_number',
            'meta_value' => $reference,
            'limit'      => 1,
        ]);

        return !empty($orders) ? $orders[0] : null;
    }
}
