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
