<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-wc-stellar-admin.php';

// These stubs run the real admin code without WordPress or network requests.
// The globals are reset in setUp(), so each case is independent.
if (!function_exists('wc_get_order')) {
    function wc_get_order($id)
    {
        return $GLOBALS['woo_pay_test_orders'][(int) $id] ?? false;
    }
}
if (!function_exists('wc_get_orders')) {
    function wc_get_orders($args)
    {
        $GLOBALS['woo_pay_query_args'] = $args;
        return $GLOBALS['woo_pay_query_result'];
    }
}
if (!function_exists('current_user_can')) {
    function current_user_can($cap): bool
    {
        return $cap === 'manage_woocommerce' && (bool) ($GLOBALS['woo_pay_test_authorized'] ?? false);
    }
}
if (!function_exists('wp_die')) {
    function wp_die($message, $title = '', $args = []): void
    {
        throw new RuntimeException((string) $message);
    }
}
if (!function_exists('esc_html')) {
    function esc_html($value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('esc_attr')) {
    function esc_attr($value): string { return esc_html($value); }
}
if (!function_exists('esc_url')) {
    function esc_url($value): string { return esc_html($value); }
}
if (!function_exists('esc_html__')) {
    function esc_html__($text, $domain = 'default'): string { return esc_html($text); }
}
if (!function_exists('admin_url')) {
    function admin_url($page): string { return 'https://shop.example/wp-admin/' . $page; }
}
if (!function_exists('add_query_arg')) {
    function add_query_arg($args, $url): string { return $url . '?' . http_build_query($args); }
}
if (!function_exists('wp_nonce_field')) {
    function wp_nonce_field($action): void { echo '<nonce data-action="' . esc_attr($action) . '"></nonce>'; }
}
if (!function_exists('wp_unslash')) {
    function wp_unslash($value) { return $value; }
}
if (!function_exists('absint')) {
    function absint($value): int { return abs((int) $value); }
}
if (!function_exists('sanitize_key')) {
    function sanitize_key($value): string { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $value)); }
}

final class WooPayFakeOrder
{
    public $status = 'pending';
    public $method = 'stellar';
    public $paid = false;
    public $metadata = [];
    public $id;

    public function __construct(int $id) { $this->id = $id; }
    public function get_id(): int { return $this->id; }
    public function get_payment_method(): string { return $this->method; }
    public function is_paid(): bool { return $this->paid; }
    public function has_status($statuses): bool { return in_array($this->status, (array) $statuses, true); }
    public function get_status(): string { return $this->status; }
    public function get_meta($key) { return $this->metadata[$key] ?? ''; }
    public function get_total(): string { return '14.50'; }
    public function get_edit_order_url(): string { return 'https://shop.example/wp-admin/order/' . $this->id; }
    public function get_order_number(): string { return (string) $this->id; }
}

final class StellarAdminTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['woo_pay_test_orders'] = [];
        $GLOBALS['woo_pay_query_args'] = null;
        $GLOBALS['woo_pay_query_result'] = (object) ['orders' => [], 'max_num_pages' => 1];
        $GLOBALS['woo_pay_test_authorized'] = true;
        $_GET = [];
        $_POST = [];
    }

    public function test_admin_queries_only_stellar_orders_with_hpos_compatible_pagination(): void
    {
        WC_Stellar_Admin::get_orders(3);
        $this->assertSame([
            'payment_method' => 'stellar', 'limit' => 20, 'page' => 3,
            'paginate' => true, 'orderby' => 'date', 'order' => 'DESC',
            'return' => 'objects',
        ], $GLOBALS['woo_pay_query_args']);
        WC_Stellar_Admin::get_orders(-7);
        $this->assertSame(1, $GLOBALS['woo_pay_query_args']['page']);
    }

    public function test_statuses_distinguish_paid_expired_closed_and_waiting(): void
    {
        $order = new WooPayFakeOrder(2);
        $this->assertSame('waiting', WC_Stellar_Admin::payment_state($order));
        $this->assertTrue(WC_Stellar_Admin::can_recheck($order));
        $order->metadata['_stellar_failure_reason'] = Stellar_Utils::REASON_EXPIRED;
        $this->assertSame('expired', WC_Stellar_Admin::payment_state($order));
        $this->assertTrue(WC_Stellar_Admin::can_recheck($order));

        $order->status = 'cancelled';
        $this->assertFalse(WC_Stellar_Admin::can_recheck($order));
        $this->assertSame('cancelled', WC_Stellar_Admin::payment_state($order));
        $order->metadata = [];
        $order->status = 'pending';
        $order->paid = true;
        $this->assertSame('paid', WC_Stellar_Admin::payment_state($order));
        $this->assertFalse(WC_Stellar_Admin::can_recheck($order));

        $order->paid = false;
        $order->method = 'bacs';
        $this->assertFalse(WC_Stellar_Admin::can_recheck($order));
    }

    public function test_render_is_restricted_to_shop_managers(): void
    {
        $GLOBALS['woo_pay_test_authorized'] = false;
        $this->expectException(RuntimeException::class);
        WC_Stellar_Admin::render_page();
    }

    public function test_render_escapes_order_metadata_and_nonces_each_action(): void
    {
        $order = new WooPayFakeOrder(72);
        $order->metadata = [
            '_stellar_memo' => '<script>alert(1)</script>',
            '_stellar_asset' => 'XLM"><img src=x onerror=alert(1)>',
            '_stellar_expected_amount' => '<b>15</b>',
        ];
        $other = new WooPayFakeOrder(73);
        $other->method = 'bacs';
        $GLOBALS['woo_pay_query_result'] = (object) ['orders' => [$order, $other], 'max_num_pages' => 2];

        ob_start();
        WC_Stellar_Admin::render_page();
        $html = ob_get_clean();

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('<b>15</b>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('woo_pay_stellar_recheck_72', $html);
        $this->assertStringNotContainsString('woo_pay_stellar_recheck_73', $html);
        $this->assertStringContainsString('name="order_id" value="72"', $html);
    }

    public function test_recheck_calls_gateway_only_for_valid_open_stellar_order(): void
    {
        $order = new WooPayFakeOrder(15);
        $GLOBALS['woo_pay_test_orders'][15] = $order;
        $gateway = new class {
            public $checked = [];
            public function check_payment_for_order(int $id): bool
            {
                $this->checked[] = $id;
                return true;
            }
        };

        $this->assertSame('invalid', WC_Stellar_Admin::recheck_order(99, $gateway));
        $this->assertSame('paid', WC_Stellar_Admin::recheck_order(15, $gateway));
        $this->assertSame([15], $gateway->checked);

        $order->paid = true;
        $this->assertSame('closed', WC_Stellar_Admin::recheck_order(15, $gateway));
        $order->paid = false;
        $order->status = 'cancelled';
        $this->assertSame('closed', WC_Stellar_Admin::recheck_order(15, $gateway));
        $order->status = 'pending';
        $order->method = 'bacs';
        $this->assertSame('invalid', WC_Stellar_Admin::recheck_order(15, $gateway));
        $this->assertSame([15], $gateway->checked);
    }

    public function test_malformed_query_arrays_do_not_break_admin_rendering(): void
    {
        $_GET = ['paged' => ['oops'], 'woo_pay_result' => ['paid']];
        ob_start();
        WC_Stellar_Admin::render_page();
        $html = ob_get_clean();
        $this->assertStringContainsString('Stellar Payments', $html);
        $this->assertStringNotContainsString('Payment confirmed on Stellar.', $html);
        $this->assertSame(1, $GLOBALS['woo_pay_query_args']['page']);
    }

    public function test_recheck_endpoint_rejects_missing_capability_and_non_post(): void
    {
        $GLOBALS['woo_pay_test_authorized'] = false;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['order_id' => 7];
        try {
            WC_Stellar_Admin::handle_recheck();
            $this->fail('Missing capability must be rejected');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('cannot re-check', $error->getMessage());
        }

        $GLOBALS['woo_pay_test_authorized'] = true;
        $_SERVER['REQUEST_METHOD'] = 'GET';
        try {
            WC_Stellar_Admin::handle_recheck();
            $this->fail('GET must be rejected');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('Invalid request method', $error->getMessage());
        }

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['order_id' => [7]];
        try {
            WC_Stellar_Admin::handle_recheck();
            $this->fail('An array order ID must be rejected');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('Invalid order', $error->getMessage());
        }
        unset($_SERVER['REQUEST_METHOD']);
    }

    public function test_recheck_fails_closed_when_gateway_is_missing_or_throws(): void
    {
        $order = new WooPayFakeOrder(15);
        $GLOBALS['woo_pay_test_orders'][15] = $order;
        $this->assertSame('error', WC_Stellar_Admin::recheck_order(15));

        $broken = new class {
            public function check_payment_for_order($id): bool { throw new RuntimeException('network secret'); }
        };
        $this->assertSame('error', WC_Stellar_Admin::recheck_order(15, $broken));
    }
}
