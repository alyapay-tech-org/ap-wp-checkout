<?php
defined('ABSPATH') || exit;

class AlyaPay_Order_Helper {

    public function approve_and_capture(\WC_Order $order, string $transaction_id, string $source = 'redirect', string $approved_status = ''): void {
        // Idempotent — matches Magento's hasInvoices() guard in approveAndCaptureOrder()
        if ($order->is_paid()) {
            return;
        }

        $order->payment_complete($transaction_id);

        if ($approved_status && !$order->has_status($approved_status)) {
            $order->update_status($approved_status);
        }

        if ($source === 'webhook') {
            $note = sprintf(
                /* translators: %s: AlyaPay transaction ID */
                __('AlyaPay payment approved via webhook. Transaction ID: %s', 'alyapay'),
                $transaction_id
            );
        } elseif ($source === 'reconciliation') {
            $note = sprintf(
                /* translators: %s: AlyaPay transaction ID */
                __('AlyaPay payment approved via reconciliation. Transaction ID: %s', 'alyapay'),
                $transaction_id
            );
        } else {
            $note = sprintf(
                /* translators: %s: AlyaPay transaction ID */
                __('AlyaPay payment approved via redirect fallback. Transaction ID: %s', 'alyapay'),
                $transaction_id
            );
        }

        $order->add_order_note($note);
    }

    public function cancel_order(\WC_Order $order, string $reason = '', string $status = 'cancelled'): void {
        if ($order->has_status(['cancelled', 'refunded', 'failed'])) {
            return;
        }

        $order->update_status($status, $reason ?: __('Order cancelled via AlyaPay.', 'alyapay'));
    }

    /**
     * Orders stuck pending on the AlyaPay payment method, older than
     * $min_age_minutes — the candidate set for reconciliation. One order per
     * vendor reference (WooCommerce has no multivendor/cart-splitting
     * concept, unlike PrestaShop/Magento).
     *
     * @return \WC_Order[]
     */
    public function get_pending_alyapay_orders(int $min_age_minutes): array {
        $orders = wc_get_orders([
            'payment_method' => 'alyapay',
            'status'         => 'pending',
            'date_created'   => '<' . (time() - $min_age_minutes * 60),
            'limit'          => -1,
            'return'         => 'objects',
        ]);

        return is_array($orders) ? $orders : [];
    }
}
