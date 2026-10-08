<?php
/**
 * WooCommerce payment gateway: "Pay with Stellar".
 *
 * Flow (beginners):
 *  1. Customer picks "Pay with Stellar" at checkout.
 *  2. process_payment() creates a unique memo (WOO-{id}-XXXXXX), saves it on the order,
 *     and puts the order on-hold with instructions (address + amount + memo).
 *  3. Customer sends XLM/USDC with that memo from any Stellar wallet.
 *  4. check_payment_for_order() polls Horizon; when a matching payment appears,
 *     the order is marked paid. A WP-Cron hook re-checks pending orders.
 */

declare(strict_types=1);

if (!class_exists('Stellar_Utils')) {
    require_once __DIR__ . '/class-stellar-utils.php';
}
if (!class_exists('WC_Stellar_Checker')) {
    require_once __DIR__ . '/class-wc-stellar-checker.php';
}
if (!class_exists('WC_Stellar_Settings')) {
    require_once __DIR__ . '/class-wc-stellar-settings.php';
}

if (!class_exists('WC_Gateway_Stellar') && class_exists('WC_Payment_Gateway')) :

class WC_Gateway_Stellar extends WC_Payment_Gateway
{
    /** @var string Shop receiving wallet. */
    public $wallet_address = '';

    /** @var string XLM or USDC. */
    public $asset = 'XLM';

    /** @var string testnet or public. */
    public $network = 'testnet';

    public function __construct()
    {
        $this->id                 = 'stellar';
        $this->icon               = '';
        $this->has_fields         = true;
        $this->method_title       = __('Pay with Stellar', 'woo-pay');
        $this->method_description = __('Accept XLM or USDC on Stellar. Orders get a unique memo and auto-confirm via Horizon.', 'woo-pay');

        // Load settings structure + saved values.
        $this->form_fields = WC_Stellar_Settings::form_fields();
        $this->init_settings();

        $this->enabled        = $this->get_option('enabled', 'yes');
        $this->title          = $this->get_option('title', 'Pay with Stellar');
        $this->description    = $this->get_option('description', '');
        $this->wallet_address = (string) $this->get_option('wallet_address', '');
        $this->asset          = strtoupper((string) $this->get_option('asset', 'XLM'));
        $this->network        = (string) $this->get_option('network', 'testnet');

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
        add_action('woocommerce_thankyou_' . $this->id, [$this, 'thankyou_page']);
        add_action('woocommerce_api_wc_gateway_stellar', [$this, 'maybe_check_via_callback']);
    }

    /**
     * Validate and sanitize wallet_address setting field.
     *
     * @param string $key Field key.
     * @param string|null $value Field value posted.
     * @return string
     */
    public function validate_wallet_address_field($key, $value)
    {
        $value = trim((string) $value);

        if ($value !== '' && !Stellar_Utils::is_valid_address($value)) {
            if (function_exists('WC_Admin_Settings::add_error')) {
                \WC_Admin_Settings::add_error(
                    __('Invalid Stellar wallet address. A valid address must start with "G" and contain 56 alphanumeric characters (base32).', 'woo-pay')
                );
            }
            return (string) $this->get_option('wallet_address', '');
        }

        return $value;
    }

    /**
     * Show payment fields at checkout (address, asset, memo hint).
     */
    public function payment_fields(): void
    {
        $desc = $this->get_description();
        if ($desc) {
            echo '<p>' . esc_html($desc) . '</p>';
        }
        echo '<div class="woo-pay-stellar-box" data-asset="' . esc_attr($this->asset) . '">';
        echo '<p><strong>' . esc_html__('Send to:', 'woo-pay') . '</strong> ';
        echo '<code class="woo-pay-address">' . esc_html($this->wallet_address ?: __('(shop wallet not set yet)', 'woo-pay')) . '</code> ';
        echo '<button type="button" class="button woo-pay-copy">' . esc_html__('Copy', 'woo-pay') . '</button></p>';
        echo '<p>' . esc_html__('Asset:', 'woo-pay') . ' <strong>' . esc_html($this->asset) . '</strong> · ';
        esc_html_e('Network:', 'woo-pay');
        echo ' <strong>' . esc_html($this->network) . '</strong></p>';
        echo '<p class="woo-pay-memo-hint">' . esc_html__('You will get a unique memo after placing the order — include it exactly so we can match your payment.', 'woo-pay') . '</p>';
        echo '</div>';
    }

    /**
     * Handle order creation at checkout.
     *
     * @param int $order_id
     * @return array<string,mixed>
     */
    public function process_payment($order_id): array
    {
        $order = wc_get_order($order_id);
        if (!$order) {
            wc_add_notice(__('Order not found.', 'woo-pay'), 'error');
            return ['result' => 'failure'];
        }

        if (!Stellar_Utils::is_valid_address($this->wallet_address)) {
            wc_add_notice(__('Shop Stellar wallet is not configured correctly. Please contact the store.', 'woo-pay'), 'error');
            return ['result' => 'failure'];
        }

        // Unique memo per order for payment matching.
        try {
            $memo = Stellar_Utils::generate_memo((int) $order_id);
        } catch (InvalidArgumentException $e) {
            wc_add_notice(__('Could not create payment memo.', 'woo-pay'), 'error');
            return ['result' => 'failure'];
        }

        $order->update_meta_data('_stellar_memo', $memo);
        $order->update_meta_data('_stellar_asset', $this->asset);
        $order->update_meta_data('_stellar_address', $this->wallet_address);
        $order->update_meta_data('_stellar_network', $this->network);
        $order->update_meta_data('_stellar_expected_amount', (string) $order->get_total());

        // On-hold until Horizon sees the payment.
        $order->update_status(
            'on-hold',
            /* translators: %s memo */
            sprintf(__('Awaiting Stellar payment. Memo: %s. Asset: %s.', 'woo-pay'), $memo, $this->asset)
        );
        $order->save();

        // Schedule a quick re-check in 5 min (WP-Cron) in case customer pays immediately.
        if (!wp_next_scheduled('woo_pay_stellar_check', [$order_id])) {
            wp_schedule_single_event(time() + 5 * MINUTE_IN_SECONDS, 'woo_pay_stellar_check', [$order_id]);
        }

        return [
            'result'   => 'success',
            'redirect' => $this->get_return_url($order),
        ];
    }

    /**
     * Thank-you page: show address + amount + memo + manual "I paid – check now" button.
     *
     * @param int $order_id
     */
    public function thankyou_page($order_id): void
    {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }
        $memo    = (string) $order->get_meta('_stellar_memo');
        $asset   = (string) ($order->get_meta('_stellar_asset') ?: $this->asset);
        $address = (string) ($order->get_meta('_stellar_address') ?: $this->wallet_address);
        $total   = $order->get_total();

        echo '<section class="woo-pay-instructions"><h2>' . esc_html__('Pay with Stellar', 'woo-pay') . '</h2>';
        echo '<ol>';
        echo '<li>' . sprintf(
            /* translators: 1: amount 2: asset 3: address */
            esc_html__('Send %1$s %2$s to %3$s', 'woo-pay'),
            '<strong>' . esc_html($total) . '</strong>',
            '<strong>' . esc_html($asset) . '</strong>',
            '<code class="woo-pay-address">' . esc_html($address) . '</code>'
        ) . ' <button type="button" class="button woo-pay-copy">' . esc_html__('Copy', 'woo-pay') . '</button></li>';
        echo '<li>' . sprintf(
            esc_html__('Include memo %s exactly (this links your payment to order #%d).', 'woo-pay'),
            '<code class="woo-pay-memo">' . esc_html($memo) . '</code>',
            esc_html((string) $order_id)
        ) . ' <button type="button" class="button woo-pay-copy-memo">' . esc_html__('Copy memo', 'woo-pay') . '</button></li>';
        echo '<li>' . esc_html__('Payments confirm automatically. This page checks every 30 seconds.', 'woo-pay') . '</li>';
        echo '</ol>';

        // Status box: assets/checkout.js polls the check URL and writes the answer here.
        // The order key in the URL proves the visitor may see this order's payment details.
        $is_paid   = $order->is_paid();
        $check_url = add_query_arg(
            ['order_id' => $order_id, 'key' => $order->get_order_key()],
            WC()->api_request_url('wc_gateway_stellar')
        );
        echo '<div class="woo-pay-status ' . ($is_paid ? 'woocommerce-message' : 'woocommerce-info') . '" role="status" aria-live="polite"'
            . ' data-state="' . ($is_paid ? 'paid' : 'pending') . '"'
            . ' data-check-url="' . esc_url($check_url) . '">';
        echo $is_paid
            ? esc_html__('Payment received. Thank you!', 'woo-pay')
            : esc_html__('Waiting for your payment…', 'woo-pay');
        echo '</div></section>';
    }

    /**
     * Poll Horizon for this order and mark paid if found.
     *
     * @param int $order_id
     * @return bool True if paid.
     */
    public function check_payment_for_order(int $order_id): bool
    {
        return $this->get_payment_status($order_id)['paid'];
    }

    /**
     * Poll Horizon for this order, mark it paid if found, and explain the result.
     *
     * @param int $order_id
     * @return array{paid:bool,reason:?string,message:string} reason is a Stellar_Utils::REASON_* code when not paid.
     */
    public function get_payment_status(int $order_id): array
    {
        $order = function_exists('wc_get_order') ? wc_get_order($order_id) : null;
        if (!$order) {
            return $this->failed_status(Stellar_Utils::REASON_ORDER_NOT_FOUND);
        }
        if ($order->is_paid()) {
            return $this->paid_status();
        }

        $memo    = (string) $order->get_meta('_stellar_memo');
        $asset   = (string) ($order->get_meta('_stellar_asset') ?: $this->asset);
        $address = (string) ($order->get_meta('_stellar_address') ?: $this->wallet_address);
        $network = (string) ($order->get_meta('_stellar_network') ?: $this->network);
        $amount  = (float) ($order->get_meta('_stellar_expected_amount') ?: $order->get_total());

        // No memo means this order was never sent through "Pay with Stellar".
        if ($memo === '' || $address === '') {
            return $this->failed_status(Stellar_Utils::REASON_ORDER_NOT_FOUND);
        }

        $checker = new WC_Stellar_Checker();
        $result  = $checker->check_payment($address, $asset, $memo, $amount, $network);

        if ($result['paid']) {
            if ($checker->mark_order_paid($order_id, (array) $result['payment'])) {
                return $this->paid_status();
            }
            return $this->failed_status($checker->get_last_error(), $result['context']);
        }

        // We look at Horizon first so a late payment is still credited. Only when there is
        // nothing more specific to say do we tell the shopper the window has closed.
        $reason  = (string) $result['reason'];
        $waiting = in_array($reason, [Stellar_Utils::REASON_NO_PAYMENT, Stellar_Utils::REASON_NETWORK_ERROR], true);
        if ($waiting && $this->is_order_expired($order)) {
            $reason = Stellar_Utils::REASON_EXPIRED;
        }

        return $this->failed_status($reason, $result['context']);
    }

    /**
     * Has the payment window for this order closed?
     *
     * @param WC_Order $order
     * @return bool
     */
    protected function is_order_expired($order): bool
    {
        $created = $order->get_date_created();
        if (!$created) {
            return false;
        }

        /**
         * Minutes an order waits for its Stellar payment. Return 0 to never expire.
         *
         * @param int $minutes
         * @param WC_Order $order
         */
        $window = (int) apply_filters('woo_pay_stellar_payment_window_minutes', Stellar_Utils::PAYMENT_WINDOW_MINUTES, $order);

        return Stellar_Utils::is_expired($created->getTimestamp(), time(), $window);
    }

    /**
     * @return array{paid:bool,reason:?string,message:string}
     */
    private function paid_status(): array
    {
        return ['paid' => true, 'reason' => null, 'message' => __('Payment received. Thank you!', 'woo-pay')];
    }

    /**
     * @param string $reason A Stellar_Utils::REASON_* code.
     * @param array<string,string> $context Details for the message.
     * @return array{paid:bool,reason:?string,message:string}
     */
    private function failed_status(string $reason, array $context = []): array
    {
        return ['paid' => false, 'reason' => $reason, 'message' => WC_Stellar_Checker::failure_message($reason, $context)];
    }

    /**
     * Optional manual callback endpoint: /wc-api/wc_gateway_stellar?order_id=123
     * Lets a button or cron ping the checker without WP-Cron.
     *
     * Add &key={order key} to also get "reason" and "message". Without the key we only
     * say paid true/false, because the message can contain amounts and memos and
     * anyone can guess an order ID.
     */
    public function maybe_check_via_callback(): void
    {
        $order_id = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
        if ($order_id > 0) {
            $status   = $this->get_payment_status($order_id);
            $response = ['order_id' => $order_id, 'paid' => $status['paid']];

            $key   = isset($_GET['key']) ? sanitize_text_field(wp_unslash($_GET['key'])) : '';
            $order = wc_get_order($order_id);
            if ($key !== '' && $order && hash_equals((string) $order->get_order_key(), $key)) {
                $response['reason']  = $status['reason'];
                $response['message'] = $status['message'];
            }

            wp_send_json($response);
        }
        wp_send_json(['paid' => false]);
    }
}

endif;
