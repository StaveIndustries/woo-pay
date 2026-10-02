<?php
/**
 * Talks to the Stellar Horizon API to see if an order was paid.
 *
 * Beginners: Horizon is Stellar's free REST API. We ask:
 *   GET /accounts/{wallet}/payments?limit=50&order=desc
 * then look for a payment with matching memo + asset + amount.
 */

declare(strict_types=1);

if (!class_exists('Stellar_Utils')) {
    require_once __DIR__ . '/class-stellar-utils.php';
}

class WC_Stellar_Checker
{
    /** @var string REASON_* code from the last failed mark_order_paid() call, or '' if it worked. */
    private $last_error = '';

    /**
     * Fetch recent payments for an address from Horizon.
     *
     * @param string $address Shop wallet address.
     * @param string $network 'testnet' or 'public'.
     * @param int $limit How many payments to fetch (default 50, max 200).
     * @return array<int,array<string,mixed>> List of payment records (may be empty).
     */
    public function fetch_payments(string $address, string $network = 'testnet', int $limit = 50): array
    {
        return $this->request_payments($address, $network, $limit) ?? [];
    }

    /**
     * Same as fetch_payments(), but returns null when Horizon could not be reached or
     * sent something unreadable. That lets check_payment() tell "we could not check"
     * apart from "we checked and there are no payments".
     *
     * @param string $address Shop wallet address.
     * @param string $network 'testnet' or 'public'.
     * @param int $limit How many payments to fetch (default 50, max 200).
     * @return array<int,array<string,mixed>>|null Payment records, or null on failure.
     */
    protected function request_payments(string $address, string $network = 'testnet', int $limit = 50): ?array
    {
        $base = Stellar_Utils::horizon_url($network);
        $url  = sprintf(
            '%s/accounts/%s/payments?limit=%d&order=desc',
            rtrim($base, '/'),
            rawurlencode($address),
            max(1, min(200, $limit))
        );

        // Use WordPress HTTP API when available, else plain file_get_contents (for tests/CLI).
        if (function_exists('wp_remote_get')) {
            $res = wp_remote_get($url, ['timeout' => 15]);
            if (is_wp_error($res)) {
                return null;
            }
            $body = wp_remote_retrieve_body($res);
        } else {
            $ctx  = stream_context_create(['http' => ['timeout' => 15]]);
            $body = @file_get_contents($url, false, $ctx);
            if ($body === false) {
                return null;
            }
        }

        $data = json_decode((string) $body, true);
        if (!is_array($data)) {
            return null;
        }

        // Horizon wraps results in _embedded.records, each needs its transaction memo.
        // A wallet that was never funded answers 404 with no records: that is "no payments", not an outage.
        $records = $data['_embedded']['records'] ?? [];
        if (!is_array($records)) {
            return null;
        }

        // Enrich each payment with its transaction memo (one extra call per tx is slow,
        // so we fetch the transaction memo lazily only if "to" matches – done in check_order()).
        return array_values($records);
    }

    /**
     * Fetch a transaction's memo by its Horizon link.
     *
     * @param string $transaction_url Full Horizon URL for the transaction.
     * @return string Memo value or empty string.
     */
    public function fetch_transaction_memo(string $transaction_url): string
    {
        if ($transaction_url === '') {
            return '';
        }

        if (function_exists('wp_remote_get')) {
            $res = wp_remote_get($transaction_url, ['timeout' => 15]);
            if (is_wp_error($res)) {
                return '';
            }
            $body = wp_remote_retrieve_body($res);
        } else {
            $ctx  = stream_context_create(['http' => ['timeout' => 15]]);
            $body = @file_get_contents($transaction_url, false, $ctx);
            if ($body === false) {
                return '';
            }
        }

        $tx = json_decode((string) $body, true);
        if (!is_array($tx)) {
            return '';
        }
        return (string) ($tx['memo'] ?? '');
    }

    /**
     * Check whether an order has been paid on Stellar.
     *
     * @param string $wallet_address Shop wallet to receive funds.
     * @param string $asset 'XLM' or 'USDC'.
     * @param string $expected_memo Unique memo assigned to this order.
     * @param float $expected_amount Order total.
     * @param string $network 'testnet' or 'public'.
     * @param array<int,array<string,mixed>>|null $prefetched Optional payments (for tests, skips HTTP).
     * @return array<string,mixed>|null Matching payment record, or null if not found.
     */
    public function find_matching_payment(
        string $wallet_address,
        string $asset,
        string $expected_memo,
        float $expected_amount,
        string $network = 'testnet',
        ?array $prefetched = null
    ): ?array {
        $result = $this->check_payment($wallet_address, $asset, $expected_memo, $expected_amount, $network, $prefetched);
        return $result['payment'];
    }

    /**
     * Check whether an order has been paid, and if not, say why.
     *
     * Beginners: find_matching_payment() only answers "which payment?" or null.
     * This returns the full story so the shopper can be told what went wrong:
     *
     *   paid    bool         True when a payment satisfies the order.
     *   reason  string|null  A Stellar_Utils::REASON_* code when not paid.
     *   payment array|null   The matching payment when paid.
     *   context array        Details for the message (amounts, memo, asset).
     *
     * @param string $wallet_address Shop wallet to receive funds.
     * @param string $asset 'XLM' or 'USDC'.
     * @param string $expected_memo Unique memo assigned to this order.
     * @param float $expected_amount Order total.
     * @param string $network 'testnet' or 'public'.
     * @param array<int,array<string,mixed>>|null $prefetched Optional payments (for tests, skips HTTP).
     * @return array{paid:bool,reason:?string,payment:?array,context:array<string,string>}
     */
    public function check_payment(
        string $wallet_address,
        string $asset,
        string $expected_memo,
        float $expected_amount,
        string $network = 'testnet',
        ?array $prefetched = null
    ): array {
        $context = [
            'expected_amount' => Stellar_Utils::format_amount($expected_amount),
            'expected_memo'   => $expected_memo,
            'asset'           => strtoupper(trim($asset)),
        ];

        $payments = $prefetched ?? $this->request_payments($wallet_address, $network);
        if ($payments === null) {
            return ['paid' => false, 'reason' => Stellar_Utils::REASON_NETWORK_ERROR, 'payment' => null, 'context' => $context];
        }

        // If nothing matches we report the most specific problem we saw.
        // Higher number wins: a payment with the right memo says more than a near-miss memo.
        $rank = [
            Stellar_Utils::REASON_MEMO_MISMATCH => 1,
            Stellar_Utils::REASON_WRONG_ASSET   => 2,
            Stellar_Utils::REASON_WRONG_AMOUNT  => 3,
        ];
        $best_reason  = Stellar_Utils::REASON_NO_PAYMENT;
        $best_context = [];

        foreach ($payments as $p) {
            // Skip non-payment types (e.g. create_account). We only want type=payment.
            if (isset($p['type']) && $p['type'] !== 'payment') {
                continue;
            }

            // Attach memo if Horizon didn't inline it.
            if (!isset($p['memo']) && isset($p['transaction']['href'])) {
                $p['memo'] = $this->fetch_transaction_memo((string) $p['transaction']['href']);
            } elseif (!isset($p['memo']) && isset($p['_links']['transaction']['href'])) {
                $p['memo'] = $this->fetch_transaction_memo((string) $p['_links']['transaction']['href']);
            }

            $reason = Stellar_Utils::payment_mismatch_reason($p, $wallet_address, $asset, $expected_memo, $expected_amount);
            if ($reason === null) {
                return ['paid' => true, 'reason' => null, 'payment' => $p, 'context' => $context];
            }

            // A different memo usually means another order's payment. Only a near-miss
            // of OUR memo (same WOO-{id}- prefix) is this shopper's mistake.
            $memo = Stellar_Utils::payment_memo($p);
            if ($reason === Stellar_Utils::REASON_MEMO_MISMATCH && !Stellar_Utils::memo_is_near_miss($memo, $expected_memo)) {
                continue;
            }

            if (($rank[$reason] ?? 0) > ($rank[$best_reason] ?? 0)) {
                $best_reason  = $reason;
                $best_context = [
                    'received_amount' => Stellar_Utils::format_amount((float) ($p['amount'] ?? 0)),
                    'received_memo'   => $memo,
                ];
            }
        }

        return ['paid' => false, 'reason' => $best_reason, 'payment' => null, 'context' => $context + $best_context];
    }

    /**
     * Turn a REASON_* code into a message for the shopper.
     *
     * Every message says what happened and what to do next. None of them tell the
     * shopper to "send the rest": we match one payment per order, so a top-up would not count.
     *
     * @param string $reason A Stellar_Utils::REASON_* code.
     * @param array<string,string> $context The 'context' from check_payment().
     * @return string Plain text (escape it when printing).
     */
    public static function failure_message(string $reason, array $context = []): string
    {
        $asset    = (string) ($context['asset'] ?? '');
        $expected = (string) ($context['expected_amount'] ?? '');

        switch ($reason) {
            case Stellar_Utils::REASON_NO_PAYMENT:
                return __('We have not received your payment yet. Send the exact amount with the memo shown above; it can take a minute to arrive. If you already paid, contact the shop with your transaction ID.', 'woo-pay');

            case Stellar_Utils::REASON_WRONG_AMOUNT:
                return sprintf(
                    /* translators: 1: amount received 2: amount expected 3: asset code */
                    __('We received %1$s %3$s, but this order needs %2$s %3$s in a single payment. Please do not send a top-up. Contact the shop with your transaction ID so we can sort it out.', 'woo-pay'),
                    (string) ($context['received_amount'] ?? '0'),
                    $expected,
                    $asset
                );

            case Stellar_Utils::REASON_MEMO_MISMATCH:
                return sprintf(
                    /* translators: 1: memo received 2: memo expected */
                    __('We found a payment with memo "%1$s", but this order needs memo "%2$s" exactly. Please do not pay again. Contact the shop with your transaction ID so we can match it by hand.', 'woo-pay'),
                    (string) ($context['received_memo'] ?? ''),
                    (string) ($context['expected_memo'] ?? '')
                );

            case Stellar_Utils::REASON_WRONG_ASSET:
                return sprintf(
                    /* translators: %s: asset code, e.g. XLM */
                    __('We received your payment, but in the wrong asset. This order must be paid in %s. Contact the shop with your transaction ID.', 'woo-pay'),
                    $asset
                );

            case Stellar_Utils::REASON_EXPIRED:
                return __('The payment window for this order has closed. Please place a new order. If you already paid, contact the shop with your transaction ID.', 'woo-pay');

            case Stellar_Utils::REASON_NETWORK_ERROR:
                return __('We could not reach the Stellar network to check your payment. We will keep trying, so there is no need to pay again.', 'woo-pay');

            case Stellar_Utils::REASON_ORDER_NOT_FOUND:
                return __('We could not find this order. Contact the shop and quote your order number.', 'woo-pay');
        }

        return __('We could not confirm your payment. Please contact the shop.', 'woo-pay');
    }

    /**
     * Why the last mark_order_paid() call returned false.
     *
     * @return string A Stellar_Utils::REASON_* code, or '' if it succeeded.
     */
    public function get_last_error(): string
    {
        return $this->last_error;
    }

    /**
     * Mark a WooCommerce order as paid (called when a matching payment is found).
     *
     * @param int $order_id WC order ID.
     * @param array<string,mixed> $payment Matching Horizon payment.
     * @return bool True on success. On false, get_last_error() says why.
     */
    public function mark_order_paid(int $order_id, array $payment): bool
    {
        $this->last_error = '';

        if (!function_exists('wc_get_order')) {
            $this->last_error = Stellar_Utils::REASON_ORDER_NOT_FOUND;
            return false;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            $this->last_error = Stellar_Utils::REASON_ORDER_NOT_FOUND;
            return false;
        }

        // Don't double-pay already-completed orders.
        if ($order->is_paid()) {
            return true;
        }

        $tx_hash = (string) ($payment['transaction_hash'] ?? $payment['id'] ?? '');
        $order->payment_complete($tx_hash);
        /* translators: %s Stellar transaction hash */
        $order->add_order_note(sprintf(__('Stellar payment confirmed. TX: %s', 'woo-pay'), $tx_hash));
        $order->update_meta_data('_stellar_tx_hash', $tx_hash);
        $order->save();

        return true;
    }
}
