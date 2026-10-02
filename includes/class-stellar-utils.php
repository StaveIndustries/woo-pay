<?php
/**
 * Pure-PHP Stellar helpers.
 *
 * Beginners: this file has NO WordPress code so PHPUnit can test it easily.
 * It handles: address validation, memo generation, payment matching.
 */

declare(strict_types=1);

class Stellar_Utils
{
    /**
     * Stellar public keys look like: G + 55 base32 chars (A-Z, 2-7), 56 total.
     * This is a format check (StrKey). Full checksum validation needs libsodium/crc16.
     */
    public const ADDRESS_REGEX = '/^G[A-Z2-7]{55}$/';

    /** Memo text max length on Stellar is 28 bytes. */
    public const MEMO_MAX_LENGTH = 28;

    /**
     * Reason codes for a payment check that did not end in "paid".
     * Each one maps to its own shopper message in WC_Stellar_Checker::failure_message().
     */
    public const REASON_NO_PAYMENT      = 'no_payment_found';
    public const REASON_WRONG_AMOUNT    = 'wrong_amount';
    public const REASON_MEMO_MISMATCH   = 'memo_mismatch';
    public const REASON_WRONG_ASSET     = 'wrong_asset';
    public const REASON_EXPIRED         = 'expired';
    public const REASON_NETWORK_ERROR   = 'network_error';
    public const REASON_ORDER_NOT_FOUND = 'order_not_found';

    /** How long an order waits for its payment before we call it expired. */
    public const PAYMENT_WINDOW_MINUTES = 60;

    /**
     * Validate a Stellar wallet address (StrKey format check).
     *
     * @param string $address Address to check.
     * @return bool True if it looks like G... 56 chars base32.
     */
    public static function is_valid_address(string $address): bool
    {
        $address = trim($address);
        if ($address === '') {
            return false;
        }
        // Must be exactly 56 chars, start with G.
        if (strlen($address) !== 56) {
            return false;
        }
        return (bool) preg_match(self::ADDRESS_REGEX, $address);
    }

    /**
     * Generate a unique memo per order for payment matching.
     *
     * Format: WOO-{orderId}-{6 random uppercase alphanumeric}
     * Example: WOO-123-AB12CD (always <= 28 chars).
     *
     * @param int $order_id WooCommerce order ID (must be > 0).
     * @return string Memo text to attach to the Stellar payment.
     */
    public static function generate_memo(int $order_id): string
    {
        if ($order_id <= 0) {
            throw new InvalidArgumentException('Order ID must be a positive integer.');
        }

        // 6 random chars from A-Z0-9 (Crockford-ish, no confusing chars needed but keep simple).
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $rand = '';
        for ($i = 0; $i < 6; $i++) {
            $rand .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        $memo = sprintf('WOO-%d-%s', $order_id, $rand);

        // Safety: truncate if order ID is huge (memo limit 28).
        if (strlen($memo) > self::MEMO_MAX_LENGTH) {
            // Keep prefix + tail of order id so it still fits.
            $memo = substr($memo, 0, self::MEMO_MAX_LENGTH);
        }

        return $memo;
    }

    /**
     * Check if a memo belongs to an order (prefix match).
     * Useful when random suffix differs but order id matches.
     *
     * @param string $memo Memo from Horizon.
     * @param int $order_id Expected order ID.
     * @return bool
     */
    public static function memo_matches_order(string $memo, int $order_id): bool
    {
        $prefix = sprintf('WOO-%d-', $order_id);
        return strpos($memo, $prefix) === 0;
    }

    /**
     * Decide if a Horizon payment record pays for this order.
     *
     * Beginners: Horizon returns JSON like:
     *   { "to": "G...", "from": "G...", "amount": "10.5",
     *     "asset_type": "native" | "credit_alphanum4",
     *     "asset_code": "USDC", "transaction": { "memo": "WOO-123-..." } }
     *
     * We check: destination == our wallet, memo == expected, amount >= expected,
     * and asset matches (XLM = native, USDC = credit with code USDC).
     *
     * @param array<string,mixed> $payment One Horizon payment record (decoded JSON).
     * @param string $expected_address Our shop wallet address.
     * @param string $expected_asset 'XLM' or 'USDC' (case-insensitive).
     * @param string $expected_memo Exact memo we gave this order.
     * @param float $expected_amount Minimum amount (order total).
     * @return bool True if this payment satisfies the order.
     */
    public static function payment_matches(
        array $payment,
        string $expected_address,
        string $expected_asset,
        string $expected_memo,
        float $expected_amount
    ): bool {
        return self::payment_mismatch_reason($payment, $expected_address, $expected_asset, $expected_memo, $expected_amount) === null;
    }

    /**
     * Same checks as payment_matches(), but says WHY a payment does not pay for the order.
     *
     * Beginners: a wallet receives payments for many orders, so most records will not
     * match and that is normal. The caller decides which reasons are worth showing.
     *
     * @param array<string,mixed> $payment One Horizon payment record (decoded JSON).
     * @param string $expected_address Our shop wallet address.
     * @param string $expected_asset 'XLM' or 'USDC' (case-insensitive).
     * @param string $expected_memo Exact memo we gave this order.
     * @param float $expected_amount Minimum amount (order total).
     * @return string|null Null if the payment matches, otherwise a REASON_* code.
     */
    public static function payment_mismatch_reason(
        array $payment,
        string $expected_address,
        string $expected_asset,
        string $expected_memo,
        float $expected_amount
    ): ?string {
        // Not sent to our wallet (e.g. an outgoing payment) → nothing to do with this order.
        $to = (string) ($payment['to'] ?? '');
        if (strcasecmp($to, $expected_address) !== 0) {
            return self::REASON_NO_PAYMENT;
        }

        if (self::payment_memo($payment) !== $expected_memo) {
            return self::REASON_MEMO_MISMATCH;
        }

        // Asset before amount: "5" of the wrong asset is not a short payment, it is the wrong coin.
        if (!self::asset_matches($payment, $expected_asset)) {
            return self::REASON_WRONG_ASSET;
        }

        $amount = (float) ($payment['amount'] ?? 0);
        // Allow tiny float dust (0.0000001 = 1 stroop). Require amount >= expected - epsilon.
        if ($amount + 0.0000001 < $expected_amount) {
            return self::REASON_WRONG_AMOUNT;
        }

        return null;
    }

    /**
     * Read the memo from a Horizon payment record.
     * Memo can be top-level or nested under transaction (Horizon embeds differently).
     *
     * @param array<string,mixed> $payment One Horizon payment record.
     * @return string Memo, or empty string if the payment has none.
     */
    public static function payment_memo(array $payment): string
    {
        if (isset($payment['memo'])) {
            return (string) $payment['memo'];
        }
        if (isset($payment['transaction']['memo'])) {
            return (string) $payment['transaction']['memo'];
        }
        if (isset($payment['transaction_attr']['memo'])) {
            return (string) $payment['transaction_attr']['memo'];
        }
        return '';
    }

    /**
     * Check the asset of a Horizon payment record against what the order expects.
     *
     * @param array<string,mixed> $payment One Horizon payment record.
     * @param string $expected_asset 'XLM' or 'USDC' (case-insensitive).
     * @return bool
     */
    public static function asset_matches(array $payment, string $expected_asset): bool
    {
        $asset = strtoupper(trim($expected_asset));
        $type  = (string) ($payment['asset_type'] ?? '');
        $code  = strtoupper((string) ($payment['asset_code'] ?? ''));

        if ($asset === 'XLM') {
            // Native XLM shows as asset_type=native and no asset_code.
            return $type === 'native';
        }

        if ($asset === 'USDC') {
            // USDC is an issued asset: credit_alphanum4 with code USDC.
            // We accept any USDC issuer here; strict issuer check happens in settings/checker.
            return ($type === 'credit_alphanum4' || $type === 'credit_alphanum12') && $code === 'USDC';
        }

        return false;
    }

    /**
     * Is this memo a failed attempt at the expected one?
     *
     * True when it carries the same "WOO-{orderId}-" prefix but is not an exact match
     * (typo in the random part, lowercase, stray spaces). Other orders' memos and
     * payments with no memo return false, so we never blame a shopper for someone else's payment.
     *
     * @param string $memo Memo from Horizon.
     * @param string $expected_memo Exact memo we gave this order.
     * @return bool
     */
    public static function memo_is_near_miss(string $memo, string $expected_memo): bool
    {
        if ($memo === $expected_memo) {
            return false;
        }
        $dash = strrpos($expected_memo, '-');
        if ($dash === false) {
            return false;
        }
        $prefix = substr($expected_memo, 0, $dash + 1);
        return stripos(trim($memo), $prefix) === 0;
    }

    /**
     * Has the payment window for an order closed?
     *
     * @param int $created_at Unix time the order was placed.
     * @param int $now Current Unix time.
     * @param int $window_minutes Window length; 0 or less means "never expires".
     * @return bool
     */
    public static function is_expired(int $created_at, int $now, int $window_minutes = self::PAYMENT_WINDOW_MINUTES): bool
    {
        if ($window_minutes <= 0) {
            return false;
        }
        return ($now - $created_at) > $window_minutes * 60;
    }

    /**
     * Format a Stellar amount for people: no float noise, no trailing zeros.
     * Stellar amounts have at most 7 decimals. Example: 24.9900000 → "24.99".
     *
     * @param float $amount
     * @return string
     */
    public static function format_amount(float $amount): string
    {
        $text = number_format($amount, 7, '.', '');
        return rtrim(rtrim($text, '0'), '.');
    }

    /**
     * Return the Horizon base URL for a network.
     *
     * @param string $network 'testnet' or 'public' (mainnet).
     * @return string Base URL without trailing slash.
     */
    public static function horizon_url(string $network): string
    {
        if (strtolower($network) === 'public' || strtolower($network) === 'mainnet') {
            return 'https://horizon.stellar.org';
        }
        return 'https://horizon-testnet.stellar.org';
    }
}
