<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

// The payment gateway is normally initialized inside WooCommerce.
class WC_Payment_Gateway
{
    public function get_option($key, $default = '')
    {
        return 'previously-saved-wallet';
    }
}

class WC_Admin_Settings
{
    public static array $errors = [];

    public static function add_error($message): void
    {
        self::$errors[] = $message;
    }
}

require_once __DIR__ . '/../includes/class-wc-gateway-stellar.php';

final class WcGatewayStellarTest extends TestCase
{
    protected function setUp(): void
    {
        WC_Admin_Settings::$errors = [];
    }

    public function test_invalid_address_shows_error_and_preserves_existing_value(): void
    {
        $gateway = (new ReflectionClass(WC_Gateway_Stellar::class))->newInstanceWithoutConstructor();

        $result = $gateway->validate_wallet_address_field('wallet_address', 'not-an-address');

        $this->assertSame('previously-saved-wallet', $result);
        $this->assertCount(1, WC_Admin_Settings::$errors);
        $this->assertStringContainsString('Invalid Stellar wallet address', WC_Admin_Settings::$errors[0]);
    }

    public function test_valid_address_is_accepted_without_error(): void
    {
        $gateway = (new ReflectionClass(WC_Gateway_Stellar::class))->newInstanceWithoutConstructor();
        $valid = 'GAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAWHF';

        $this->assertSame($valid, $gateway->validate_wallet_address_field('wallet_address', $valid));
        $this->assertSame([], WC_Admin_Settings::$errors);
    }
}
