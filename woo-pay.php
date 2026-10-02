<?php
/**
 * Plugin Name: Woo Pay – Stellar (XLM/USDC)
 * Plugin URI: https://github.com/StaveIndustries/woo-pay
 * Description: Accept XLM or USDC on Stellar in WooCommerce. Shows "Pay with Stellar" at checkout, generates a unique memo per order, and marks the order paid when the payment arrives via Horizon API.
 * Version: 1.0.0
 * Author: StaveIndustries
 * Author URI: https://github.com/StaveIndustries
 * License: MIT
 * License URI: https://opensource.org/licenses/MIT
 * Text Domain: woo-pay
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 * WC tested up to: 8.9
 */

declare(strict_types=1);

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

// Plugin constants – beginners: these let other files know where things live.
define('WOO_PAY_VERSION', '1.0.0');
define('WOO_PAY_FILE', __FILE__);
define('WOO_PAY_DIR', plugin_dir_path(__FILE__));
define('WOO_PAY_URL', plugin_dir_url(__FILE__));

// Load pure-PHP helper first (no WordPress dependency, unit-testable).
require_once WOO_PAY_DIR . 'includes/class-stellar-utils.php';

// Load checker (talks to Horizon API).
require_once WOO_PAY_DIR . 'includes/class-wc-stellar-checker.php';

// Load settings helper.
require_once WOO_PAY_DIR . 'includes/class-wc-stellar-settings.php';

// Load the WooCommerce gateway class.
require_once WOO_PAY_DIR . 'includes/class-wc-gateway-stellar.php';

/**
 * Register the gateway with WooCommerce.
 *
 * @param string[] $methods Existing gateway class names.
 * @return string[]
 */
function woo_pay_add_gateway(array $methods): array
{
    $methods[] = 'WC_Gateway_Stellar';
    return $methods;
}
add_filter('woocommerce_payment_gateways', 'woo_pay_add_gateway');

/**
 * Enqueue small checkout JS on checkout / pay-for-order pages.
 */
function woo_pay_enqueue_checkout_js(): void
{
    if (!function_exists('is_checkout') || (!is_checkout() && !is_wc_endpoint_url('order-pay'))) {
        return;
    }

    wp_enqueue_script(
        'woo-pay-checkout',
        WOO_PAY_URL . 'assets/checkout.js',
        ['jquery'],
        WOO_PAY_VERSION,
        true
    );

    // Pass translated strings to JS.
    wp_localize_script('woo-pay-checkout', 'WooPayStellar', [
        'copied'      => __('Copied!', 'woo-pay'),
        'copy'        => __('Copy', 'woo-pay'),
        'paid'        => __('Payment received. Thank you!', 'woo-pay'),
        'checkFailed' => __('We could not check your payment just now. We will try again shortly.', 'woo-pay'),
    ]);
}
add_action('wp_enqueue_scripts', 'woo_pay_enqueue_checkout_js');

/**
 * Show admin notice if WooCommerce is missing.
 */
function woo_pay_missing_wc_notice(): void
{
    if (class_exists('WooCommerce')) {
        return;
    }
    echo '<div class="error"><p>'
        . esc_html__('Woo Pay Stellar requires WooCommerce to be installed and active.', 'woo-pay')
        . '</p></div>';
}
add_action('admin_notices', 'woo_pay_missing_wc_notice');

/**
 * Declare HPOS (custom order tables) compatibility.
 */
function woo_pay_hpos_compat(): void
{
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, false);
    }
}
add_action('before_woocommerce_init', 'woo_pay_hpos_compat');
