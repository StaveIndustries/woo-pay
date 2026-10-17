<?php
/** Send opt-in Stellar payment notifications once per confirmed order. */
declare(strict_types=1);

class WC_Stellar_Emails
{
    /**
     * Send plain-text confirmation. Order meta flags prevent repeated sends
     * if a payment check is retried or called by multiple entry points.
     */
    public static function send_confirmation($order, string $transaction_hash): void
    {
        if (!function_exists('wp_mail')) {
            return;
        }
        $settings = function_exists('get_option')
            ? (array) get_option('woocommerce_stellar_settings', [])
            : [];
        if (($settings['send_confirmation_email'] ?? 'yes') !== 'yes') {
            return;
        }

        $number = $order->get_order_number();
        $total = $order->get_total();
        $asset = (string) $order->get_meta('_stellar_asset') ?: 'XLM';
        $subject = sprintf(__('Stellar payment confirmed for order #%s', 'woo-pay'), $number);
        $body = sprintf(
            __("We received your Stellar payment for order #%s.
Amount: %s %s
Transaction: %s

Thank you!", 'woo-pay'),
            $number,
            $total,
            $asset,
            $transaction_hash
        );
        $headers = ['Content-Type: text/plain; charset=UTF-8'];

        $customer = (string) $order->get_billing_email();
        if ($customer !== '' && !$order->get_meta('_stellar_customer_confirmation_sent')) {
            if (wp_mail($customer, $subject, $body, $headers)) {
                $order->update_meta_data('_stellar_customer_confirmation_sent', gmdate('c'));
                $order->save();
            }
        }

        if (($settings['send_admin_confirmation'] ?? 'no') === 'yes'
            && !$order->get_meta('_stellar_admin_confirmation_sent')
        ) {
            $admin_email = (string) get_option('admin_email', '');
            if ($admin_email !== '' && wp_mail($admin_email, $subject, $body, $headers)) {
                $order->update_meta_data('_stellar_admin_confirmation_sent', gmdate('c'));
                $order->save();
            }
        }
    }
}
