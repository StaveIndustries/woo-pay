<?php
declare(strict_types=1);
// PHPUnit bootstrap: nothing fancy, just ensure includes are loadable.

// WordPress is not loaded in tests, so stand in for its translate function:
// return the English text unchanged. That is all WC_Stellar_Checker needs.
if (!function_exists('__')) {
    function __(string $text, string $domain = 'default'): string
    {
        return $text;
    }
}

require_once __DIR__ . '/../includes/class-stellar-utils.php';
require_once __DIR__ . '/../includes/class-wc-stellar-checker.php';

// WooCommerce email dependencies are replaced with side-effect-free shims.
if (!function_exists('get_option')) {
    function get_option($name, $default = false)
    {
        if ($name === 'woocommerce_stellar_settings') {
            return $GLOBALS['woo_pay_test_settings'] ?? [];
        }
        return $default;
    }
}
if (!function_exists('wp_mail')) {
    function wp_mail($recipient, $subject, $body, $headers = [])
    {
        $GLOBALS['woo_pay_sent_emails'][] = compact('recipient', 'subject', 'body', 'headers');
        return true;
    }
}
