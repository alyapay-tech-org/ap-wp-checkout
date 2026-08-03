<?php
defined('ABSPATH') || exit;

/**
 * Reconciles AlyaPay orders stuck pending — covers the case where neither
 * the webhook (blocked by merchant firewall/WAF) nor the browser redirect
 * (customer closed the tab) ever confirmed the transaction outcome. Polls
 * AlyaPay directly by vendor reference (the WooCommerce order number),
 * which needs no transaction_id captured earlier in the flow.
 *
 * Mirrors alya-prestashop's AlyaPayReconcilePendingOrders — see
 * docs/platform-parity.md at the repo root for why this exists here too.
 * Unlike PrestaShop/Magento, WooCommerce has no multivendor/cart-splitting
 * concept, so this reconciles one order per vendor reference directly
 * (no sibling-order fan-out).
 *
 * Kept decoupled from WC_Payment_Gateway/get_option() on purpose — callers
 * (the wp-cron hook and the manual admin button) resolve settings and pass
 * primitives in, so this class is usable from either entry point.
 */
class AlyaPay_Reconcile_Pending_Orders {
    private const APPROVED_STATUSES = ['APPROVED', 'COMPLETED'];
    private const FAILED_STATUSES   = ['CANCELED', 'EXPIRED', 'DECLINED', 'FAILED'];

    /** Prefilter floor — narrows the scanned set before per-order expiry is checked */
    private const MIN_AGE_MINUTES = 20;

    /** Wait this long past the configured transaction expiry before polling */
    private const GRACE_MINUTES = 5;

    private const LOCK_TRANSIENT = 'alyapay_reconcile_lock';
    private const LOCK_TTL       = 60;

    /** @var AlyaPay_Order_Helper */
    private $order_helper;

    /** @var AlyaPay_API */
    private $api;

    /** @var string */
    private $approved_status;

    /** @var string */
    private $cancelled_status;

    /** @var string */
    private $expired_status;

    /** @var int */
    private $transaction_expiry;

    public function __construct(
        AlyaPay_Order_Helper $order_helper,
        AlyaPay_API $api,
        string $approved_status,
        string $cancelled_status,
        string $expired_status,
        int $transaction_expiry
    ) {
        $this->order_helper       = $order_helper;
        $this->api                = $api;
        $this->approved_status    = $approved_status;
        $this->cancelled_status   = $cancelled_status;
        $this->expired_status     = $expired_status;
        $this->transaction_expiry = $transaction_expiry;
    }

    /**
     * Entry point — called from the wp-cron scheduled event (automatic
     * default) or from the manual admin button (on-demand fallback). See
     * docs/platform-parity.md at the repo root.
     *
     * @return array{checked: int, reconciled: int} orders examined vs.
     *     actually resolved (approved or closed) — left-pending/too-young/
     *     lookup-failed orders count toward "checked" but not "reconciled".
     */
    public function execute(): array {
        $summary = ['checked' => 0, 'reconciled' => 0];

        // Soft concurrency guard — a transient lock, not a true mutex. Good
        // enough to stop the same batch running twice if a cron tick and a
        // human clicking the button overlap; a race within the lock TTL is
        // an acceptable risk given approve/cancel are themselves idempotent.
        if (get_transient(self::LOCK_TRANSIENT)) {
            return $summary;
        }
        set_transient(self::LOCK_TRANSIENT, 1, self::LOCK_TTL);

        try {
            $orders = $this->order_helper->get_pending_alyapay_orders(self::MIN_AGE_MINUTES);
            $summary['checked'] = count($orders);

            foreach ($orders as $order) {
                try {
                    if ($this->reconcile_order($order)) {
                        $summary['reconciled']++;
                    }
                } catch (\Throwable $e) {
                    $this->log_error(sprintf(
                        'AlyaPay reconciliation: unexpected error reconciling order %s: %s',
                        $order->get_order_number(),
                        $e->getMessage()
                    ));
                }
            }
        } finally {
            delete_transient(self::LOCK_TRANSIENT);
        }

        return $summary;
    }

    private function reconcile_order(\WC_Order $order): bool {
        $threshold_minutes = $this->transaction_expiry > 0
            ? $this->transaction_expiry + self::GRACE_MINUTES
            : self::MIN_AGE_MINUTES;

        $created = $order->get_date_created();
        if (!$created || (time() - $created->getTimestamp()) < ($threshold_minutes * 60)) {
            return false;
        }

        $vendor_reference = $order->get_order_number();

        try {
            $response = $this->api->get_vendor_transaction($vendor_reference);
        } catch (\Throwable $e) {
            $this->log_error(sprintf(
                'AlyaPay reconciliation: vendor lookup failed for order %s: %s',
                $vendor_reference,
                $e->getMessage()
            ));
            return false;
        }

        $status         = strtoupper((string) ($response['status'] ?? ''));
        $transaction_id = (string) ($response['id'] ?? '');

        if ($status === '') {
            return false;
        }

        if (in_array($status, self::APPROVED_STATUSES, true)) {
            $this->order_helper->approve_and_capture($order, $transaction_id, 'reconciliation', $this->approved_status);
            return true;
        }

        if (in_array($status, self::FAILED_STATUSES, true)) {
            $target_status = $status === 'EXPIRED' ? $this->expired_status : $this->cancelled_status;
            $comment = sprintf(
                'AlyaPay: Transaction %s (reconciliation). Transaction ID: %s',
                strtolower($status),
                $transaction_id
            );
            $this->order_helper->cancel_order($order, $comment, $target_status);
            return true;
        }

        // PENDING/PROCESSING/unknown — transaction still in progress on AlyaPay's side, leave order as-is.
        return false;
    }

    private function log_error(string $message): void {
        try {
            wc_get_logger()->error($message, ['source' => 'alyapay']);
        } catch (\Throwable $e) {
            // Logging must never break reconciliation.
        }
    }
}
