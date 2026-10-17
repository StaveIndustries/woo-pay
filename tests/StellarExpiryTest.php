<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-wc-stellar-checker.php';

class StellarExpiryOrder
{
    public array $meta = [
        '_stellar_payment_deadline' => 1000,
        '_stellar_memo' => 'WOO-21-TEST',
        '_stellar_address' => 'GSHOP',
        '_stellar_asset' => 'XLM',
        '_stellar_network' => 'testnet',
        '_stellar_expected_amount' => '25',
    ];
    public string $status = 'on-hold';
    public bool $paid = false;
    public int $cancellations = 0;

    public function get_payment_method(): string { return 'stellar'; }
    public function is_paid(): bool { return $this->paid; }
    public function get_status(): string { return $this->status; }
    public function get_meta(string $key) { return $this->meta[$key] ?? ''; }
    public function get_total() { return 25; }
    public function get_id(): int { return 21; }
    public function update_status(string $status, string $note): void {
        $this->status = $status;
        $this->cancellations++;
    }
}

class FakeExpiryChecker extends WC_Stellar_Checker
{
    public array $result = ['paid' => false, 'reason' => Stellar_Utils::REASON_NO_PAYMENT, 'payment' => null];
    public int $checked = 0;
    public int $marked = 0;

    public function check_payment(
        string $wallet_address, string $asset, string $expected_memo,
        float $expected_amount, string $network = 'testnet', ?array $prefetched = null
    ): array {
        $this->checked++;
        return $this->result;
    }
    public function mark_order_paid(int $order_id, array $payment): bool {
        $this->marked++;
        return true;
    }
}

class StellarExpiryTest extends TestCase
{
    public function test_overdue_unpaid_order_is_cancelled_after_no_match(): void {
        $order = new StellarExpiryOrder();
        $checker = new FakeExpiryChecker();
        $this->assertTrue(WC_Stellar_Checker::cancel_expired_order($order, $checker, 1000));
        $this->assertSame('cancelled', $order->status);
        $this->assertSame(1, $checker->checked);
    }

    public function test_future_and_missing_deadline_are_not_cancelled(): void {
        $order = new StellarExpiryOrder();
        $checker = new FakeExpiryChecker();
        $this->assertFalse(WC_Stellar_Checker::cancel_expired_order($order, $checker, 999));
        $order->meta['_stellar_payment_deadline'] = 0;
        $this->assertFalse(WC_Stellar_Checker::cancel_expired_order($order, $checker, 1001));
        $this->assertSame(0, $checker->checked);
    }

    public function test_paid_order_is_never_cancelled(): void {
        $order = new StellarExpiryOrder();
        $order->paid = true;
        $checker = new FakeExpiryChecker();
        $this->assertFalse(WC_Stellar_Checker::cancel_expired_order($order, $checker, 1001));
        $this->assertSame(0, $checker->checked);
    }

    public function test_horizon_outage_never_triggers_cancellation(): void {
        $order = new StellarExpiryOrder();
        $checker = new FakeExpiryChecker();
        $checker->result['reason'] = Stellar_Utils::REASON_NETWORK_ERROR;
        $this->assertFalse(WC_Stellar_Checker::cancel_expired_order($order, $checker, 1001));
        $this->assertSame(0, $order->cancellations);
    }

    public function test_matching_payment_prevents_cancellation(): void {
        $order = new StellarExpiryOrder();
        $checker = new FakeExpiryChecker();
        $checker->result = ['paid' => true, 'reason' => null, 'payment' => ['id' => 'tx']];
        $this->assertFalse(WC_Stellar_Checker::cancel_expired_order($order, $checker, 1001));
        $this->assertSame(1, $checker->marked);
        $this->assertSame(0, $order->cancellations);
    }

    public function test_unrelated_order_or_missing_memo_is_untouched(): void {
        $order = new StellarExpiryOrder();
        $order->meta['_stellar_memo'] = '';
        $checker = new FakeExpiryChecker();
        $this->assertFalse(WC_Stellar_Checker::cancel_expired_order($order, $checker, 1001));
        $order->meta['_stellar_memo'] = 'WOO-21-TEST';
        $order->status = 'cancelled';
        $this->assertFalse(WC_Stellar_Checker::cancel_expired_order($order, $checker, 1001));
        $this->assertSame(0, $checker->checked);
    }
}
