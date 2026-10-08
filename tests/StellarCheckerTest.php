<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-wc-stellar-checker.php';

/**
 * Beginners: these tests never touch the network. check_payment() accepts a
 * ready-made list of payments ($prefetched), so each test hands it a fake Horizon
 * answer and checks which failure reason + shopper message comes back.
 */
final class StellarCheckerTest extends TestCase
{
    private const WALLET = 'GBBD47IF6LWK7P7MDEVSCWR7DPUWV3NY3DTQEVFL4NAT4AQH3ZLLFLA5';
    private const OTHER  = 'GAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAWHF';
    private const MEMO   = 'WOO-7-ABC123';

    /**
     * A payment that fully pays a 25 XLM order, with optional overrides.
     *
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function payment(array $overrides = []): array
    {
        return array_merge([
            'type'       => 'payment',
            'to'         => self::WALLET,
            'amount'     => '25.0000000',
            'asset_type' => 'native',
            'memo'       => self::MEMO,
        ], $overrides);
    }

    /**
     * @param array<int,array<string,mixed>>|null $payments
     * @return array<string,mixed>
     */
    private function check(?array $payments, string $asset = 'XLM'): array
    {
        return (new WC_Stellar_Checker())->check_payment(self::WALLET, $asset, self::MEMO, 25.0, 'testnet', $payments);
    }

    public function test_matching_payment_is_paid(): void
    {
        $result = $this->check([$this->payment()]);

        $this->assertTrue($result['paid']);
        $this->assertNull($result['reason']);
        $this->assertSame(self::MEMO, $result['payment']['memo']);
    }

    public function test_no_payments_at_all(): void
    {
        $result = $this->check([]);

        $this->assertFalse($result['paid']);
        $this->assertNull($result['payment']);
        $this->assertSame(Stellar_Utils::REASON_NO_PAYMENT, $result['reason']);
    }

    public function test_other_orders_payments_are_not_blamed_on_this_shopper(): void
    {
        $result = $this->check([
            $this->payment(['memo' => 'WOO-8-ZZZZZZ']),            // another order
            $this->payment(['memo' => 'WOO-70-ABC123']),           // order 70 is not order 7
            $this->payment(['memo' => '']),                        // no memo
            $this->payment(['to' => self::OTHER]),                 // outgoing payment from our wallet
            $this->payment(['type' => 'create_account']),          // not a payment at all
        ]);

        $this->assertSame(Stellar_Utils::REASON_NO_PAYMENT, $result['reason']);
    }

    public function test_wrong_amount(): void
    {
        $result = $this->check([$this->payment(['amount' => '24.9900000'])]);

        $this->assertFalse($result['paid']);
        $this->assertSame(Stellar_Utils::REASON_WRONG_AMOUNT, $result['reason']);
        $this->assertSame('24.99', $result['context']['received_amount']);
        $this->assertSame('25', $result['context']['expected_amount']);
    }

    public function test_memo_mismatch_on_near_miss(): void
    {
        // Right order prefix, wrong random part.
        $typo = $this->check([$this->payment(['memo' => 'WOO-7-ABC124'])]);
        $this->assertSame(Stellar_Utils::REASON_MEMO_MISMATCH, $typo['reason']);
        $this->assertSame('WOO-7-ABC124', $typo['context']['received_memo']);

        // Stellar memos are case-sensitive, so lowercase does not match either.
        $lower = $this->check([$this->payment(['memo' => 'woo-7-abc123'])]);
        $this->assertSame(Stellar_Utils::REASON_MEMO_MISMATCH, $lower['reason']);
    }

    public function test_wrong_asset(): void
    {
        $usdc = $this->payment(['asset_type' => 'credit_alphanum4', 'asset_code' => 'USDC']);

        $result = $this->check([$usdc]);
        $this->assertSame(Stellar_Utils::REASON_WRONG_ASSET, $result['reason']);
        $this->assertSame('XLM', $result['context']['asset']);

        // And the other way round: XLM sent to a USDC order.
        $result = $this->check([$this->payment()], 'USDC');
        $this->assertSame(Stellar_Utils::REASON_WRONG_ASSET, $result['reason']);
    }

    public function test_network_error_when_horizon_cannot_be_reached(): void
    {
        // Subclass stands in for a failed HTTP call: request_payments() returns null.
        $offline = new class extends WC_Stellar_Checker {
            protected function request_payments(string $address, string $network = 'testnet', int $limit = 50): ?array
            {
                return null;
            }
        };

        $result = $offline->check_payment(self::WALLET, 'XLM', self::MEMO, 25.0);
        $this->assertFalse($result['paid']);
        $this->assertSame(Stellar_Utils::REASON_NETWORK_ERROR, $result['reason']);

        // The older helpers keep their old return types.
        $this->assertSame([], $offline->fetch_payments(self::WALLET));
        $this->assertNull($offline->find_matching_payment(self::WALLET, 'XLM', self::MEMO, 25.0));
    }

    public function test_most_specific_reason_wins(): void
    {
        // An underpayment with the exact memo tells us more than a near-miss memo.
        $result = $this->check([
            $this->payment(['memo' => 'WOO-7-ABC124']),
            $this->payment(['amount' => '10']),
        ]);
        $this->assertSame(Stellar_Utils::REASON_WRONG_AMOUNT, $result['reason']);
        $this->assertSame('10', $result['context']['received_amount']);
    }

    public function test_good_payment_wins_over_earlier_mistakes(): void
    {
        // Shopper underpaid first, then sent the full amount.
        $result = $this->check([
            $this->payment(['amount' => '10']),
            $this->payment(),
        ]);
        $this->assertTrue($result['paid']);

        $match = (new WC_Stellar_Checker())->find_matching_payment(self::WALLET, 'XLM', self::MEMO, 25.0, 'testnet', [
            $this->payment(['amount' => '10']),
            $this->payment(),
        ]);
        $this->assertSame('25.0000000', $match['amount']);
    }

    public function test_mark_order_paid_reports_missing_order(): void
    {
        // WooCommerce is not loaded here, so the order cannot be found.
        $checker = new WC_Stellar_Checker();

        $this->assertFalse($checker->mark_order_paid(123, $this->payment()));
        $this->assertSame(Stellar_Utils::REASON_ORDER_NOT_FOUND, $checker->get_last_error());
    }

    /**
     * Every reason code, with the text its message must contain.
     *
     * @return array<string,array{0:string,1:string[]}>
     */
    public function reasons(): array
    {
        return [
            'no payment'      => [Stellar_Utils::REASON_NO_PAYMENT, ['not received', 'memo', 'contact the shop']],
            'wrong amount'    => [Stellar_Utils::REASON_WRONG_AMOUNT, ['24.99 XLM', '25 XLM', 'contact the shop']],
            'memo mismatch'   => [Stellar_Utils::REASON_MEMO_MISMATCH, ['"WOO-7-ABC124"', '"WOO-7-ABC123"', 'do not pay again']],
            'wrong asset'     => [Stellar_Utils::REASON_WRONG_ASSET, ['wrong asset', 'paid in XLM', 'contact the shop']],
            'expired'         => [Stellar_Utils::REASON_EXPIRED, ['window', 'closed', 'place a new order']],
            'network error'   => [Stellar_Utils::REASON_NETWORK_ERROR, ['could not reach', 'no need to pay again']],
            'order not found' => [Stellar_Utils::REASON_ORDER_NOT_FOUND, ['could not find this order', 'contact the shop']],
        ];
    }

    /**
     * @dataProvider reasons
     * @param string[] $expected_parts
     */
    public function test_each_reason_has_an_actionable_message(string $reason, array $expected_parts): void
    {
        $message = WC_Stellar_Checker::failure_message($reason, [
            'asset'           => 'XLM',
            'expected_amount' => '25',
            'expected_memo'   => 'WOO-7-ABC123',
            'received_amount' => '24.99',
            'received_memo'   => 'WOO-7-ABC124',
        ]);

        foreach ($expected_parts as $part) {
            $this->assertStringContainsStringIgnoringCase($part, $message);
        }
    }

    public function test_messages_are_distinct(): void
    {
        $messages = [];
        foreach ($this->reasons() as $row) {
            $messages[] = WC_Stellar_Checker::failure_message($row[0]);
        }
        $messages[] = WC_Stellar_Checker::failure_message('something_unknown');

        $this->assertCount(count($messages), array_unique($messages), 'Two reasons share the same message.');
    }

    public function test_unknown_reason_still_tells_shopper_what_to_do(): void
    {
        $message = WC_Stellar_Checker::failure_message('something_unknown');

        $this->assertStringContainsStringIgnoringCase('contact the shop', $message);
    }
}
