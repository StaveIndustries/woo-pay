<?php
/**
 * Shop-manager view for Woo Pay Stellar orders.
 *
 * Uses WooCommerce's order APIs instead of querying wp_posts / wp_postmeta,
 * so both legacy order storage and HPOS are supported.
 */
declare(strict_types=1);

final class WC_Stellar_Admin
{
    private const PAGE = 'woo-pay-stellar';
    private const PAGE_SIZE = 20;

    public static function register_menu(): void
    {
        add_submenu_page(
            'woocommerce',
            __('Stellar Payments', 'woo-pay'),
            __('Stellar Payments', 'woo-pay'),
            'manage_woocommerce',
            self::PAGE,
            [self::class, 'render_page']
        );
    }

    /** Only stored payment evidence can mark an order as paid or expired. */
    public static function payment_state($order): string
    {
        if ($order->is_paid()) {
            return 'paid';
        }

        // A terminal WooCommerce status takes precedence over an older failure
        // reason, so a cancelled order is never displayed as still expiring.
        if ($order->has_status(['cancelled', 'refunded', 'failed'])) {
            return 'cancelled';
        }

        if ((string) $order->get_meta('_stellar_failure_reason') === Stellar_Utils::REASON_EXPIRED) {
            return 'expired';
        }

        return 'waiting';
    }

    /** Prevent re-checking a closed order and accidentally reopening checkout. */
    public static function can_recheck($order): bool
    {
        return $order->get_payment_method() === 'stellar'
            && !$order->is_paid()
            && !$order->has_status(['cancelled', 'refunded', 'failed']);
        // Even an expired payment may be verified manually if the order is open.

    }

    /**
     * Return only this gateway's orders. The WC query handles HPOS and paging.
     *
     * @return object|false WC_Order_Query paginated result
     */
    public static function get_orders(int $page)
    {
        if (!function_exists('wc_get_orders')) {
            return false;
        }

        return wc_get_orders([
            'payment_method' => 'stellar',
            'limit' => self::PAGE_SIZE,
            'page' => max(1, $page),
            'paginate' => true,
            'orderby' => 'date',
            'order' => 'DESC',
            'return' => 'objects',
        ]);
    }

    public static function render_page(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You cannot view Stellar payments.', 'woo-pay'));
        }

        // Query params can be arrays; never pass them into scalar WP sanitizers.
        $page = isset($_GET['paged']) && is_scalar($_GET['paged'])
            ? max(1, absint(wp_unslash($_GET['paged']))) : 1;
        $result = self::get_orders($page);
        $orders = $result && isset($result->orders) && is_array($result->orders)
            ? $result->orders : [];
        $pages = $result && isset($result->max_num_pages)
            ? max(1, (int) $result->max_num_pages) : 1;

        echo '<div class="wrap"><h1>' . esc_html__('Stellar Payments', 'woo-pay') . '</h1>';

        if (isset($_GET['woo_pay_result']) && is_scalar($_GET['woo_pay_result'])) {
            $status = sanitize_key(wp_unslash($_GET['woo_pay_result']));
            $notices = [
                'paid' => __('Payment confirmed on Stellar.', 'woo-pay'),
                'waiting' => __('No matching payment yet. Check again later.', 'woo-pay'),
                'closed' => __('This order is already paid or closed.', 'woo-pay'),
                'invalid' => __('This is not a Stellar gateway order.', 'woo-pay'),
                'error' => __('Could not re-check this order. Try again later.', 'woo-pay'),
            ];
            if (isset($notices[$status])) {
                echo '<div class="notice notice-info"><p>' . esc_html($notices[$status]) . '</p></div>';
            }
        }

        echo '<p>' . esc_html__('Latest Stellar gateway orders. Amounts are the expected Stellar asset units; network checks are only triggered when you select Re-check.', 'woo-pay') . '</p>';
        echo '<table class="widefat striped"><thead><tr>';
        foreach ([
            __('Order', 'woo-pay'), __('Amount', 'woo-pay'),
            __('Asset', 'woo-pay'), __('Memo', 'woo-pay'),
            __('Status', 'woo-pay'), __('Action', 'woo-pay'),
        ] as $heading) {
            echo '<th scope="col">' . esc_html($heading) . '</th>';
        }
        echo '</tr></thead><tbody>';

        foreach ($orders as $order) {
            if (!$order || $order->get_payment_method() !== 'stellar') {
                continue;
            }

            $id = (int) $order->get_id();
            $status = self::payment_state($order);
            $amount = (string) ($order->get_meta('_stellar_expected_amount') ?: $order->get_total());
            $asset = (string) ($order->get_meta('_stellar_asset') ?: 'XLM');
            $memo = (string) $order->get_meta('_stellar_memo');

            echo '<tr><td><a href="' . esc_url($order->get_edit_order_url()) . '">#'
                . esc_html((string) $order->get_order_number()) . '</a></td>';
            echo '<td>' . esc_html($amount) . '</td><td>' . esc_html($asset) . '</td>';
            echo '<td><code>' . esc_html($memo) . '</code></td>';
            echo '<td>' . esc_html(ucfirst($status)) . '</td><td>';

            if (self::can_recheck($order)) {
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
                echo '<input type="hidden" name="action" value="woo_pay_stellar_recheck">';
                echo '<input type="hidden" name="order_id" value="' . esc_attr((string) $id) . '">';
                wp_nonce_field('woo_pay_stellar_recheck_' . $id);
                echo '<button type="submit" class="button">' . esc_html__('Re-check', 'woo-pay') . '</button></form>';
            } else {
                echo '&mdash;';
            }
            echo '</td></tr>';
        }

        if (!$orders) {
            echo '<tr><td colspan="6">' . esc_html__('No Stellar payments found.', 'woo-pay') . '</td></tr>';
        }
        echo '</tbody></table>';

        if ($pages > 1) {
            echo '<p class="tablenav-pages">';
            // Do not render thousands of links for a busy shop.
            $start = max(1, $page - 2);
            $end = min($pages, $page + 2);
            $page_numbers = array_unique(array_merge([1], range($start, $end), [$pages]));
            sort($page_numbers);
            foreach ($page_numbers as $i) {
                $url = add_query_arg(['page' => self::PAGE, 'paged' => $i], admin_url('admin.php'));
                echo '<a class="button' . ($i === $page ? ' button-primary' : '') . '" href="'
                    . esc_url($url) . '">' . esc_html((string) $i) . '</a> ';
            }
            echo '</p>';
        }

        echo '</div>';
    }

    /**
     * Shared operation for the admin-post handler and offline regression tests.
     * This is never exposed publicly without the capability + nonce gate below.
     */
    public static function recheck_order(int $order_id, $gateway = null): string
    {
        if ($order_id <= 0 || !function_exists('wc_get_order')) {
            return 'invalid';
        }

        $order = wc_get_order($order_id);
        if (!$order || $order->get_payment_method() !== 'stellar') {
            return 'invalid';
        }
        if (!self::can_recheck($order)) {
            return 'closed';
        }

        if ($gateway === null) {
            if (!function_exists('WC')) {
                return 'error';
            }
            $wc = WC();
            if (!$wc || !method_exists($wc, 'payment_gateways')) {
                return 'error';
            }
            $registry = $wc->payment_gateways();
            if (!$registry || !method_exists($registry, 'payment_gateways')) {
                return 'error';
            }
            $gateways = $registry->payment_gateways();
            $gateway = is_array($gateways) ? ($gateways['stellar'] ?? null) : null;
        }

        if (!$gateway || !method_exists($gateway, 'check_payment_for_order')) {
            return 'error';
        }

        try {
            return $gateway->check_payment_for_order($order_id) ? 'paid' : 'waiting';
        } catch (Throwable $error) {
            return 'error';
        }
    }

    /** WordPress post endpoint. Never accept a GET, missing permission, or invalid nonce. */
    public static function handle_recheck(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You cannot re-check payments.', 'woo-pay'), '', ['response' => 403]);
        }
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            wp_die(esc_html__('Invalid request method.', 'woo-pay'), '', ['response' => 405]);
        }

        $order_id = isset($_POST['order_id']) && is_scalar($_POST['order_id'])
            ? absint(wp_unslash($_POST['order_id'])) : 0;
        if (!$order_id) {
            wp_die(esc_html__('Invalid order.', 'woo-pay'), '', ['response' => 400]);
        }

        check_admin_referer('woo_pay_stellar_recheck_' . $order_id);
        $status = self::recheck_order($order_id);
        $url = add_query_arg(['page' => self::PAGE, 'woo_pay_result' => $status], admin_url('admin.php'));
        wp_safe_redirect($url);
        exit;
    }
}
