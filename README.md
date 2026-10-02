# Woo Pay – Accept XLM / USDC on Stellar in WooCommerce

WordPress/WooCommerce plugin that adds **“Pay with Stellar”** at checkout.

- Settings: wallet address, asset choice (XLM/USDC), testnet/mainnet.
- Unique memo per order (e.g. `WOO-123-AB12CD`) for payment matching.
- Payment check via Stellar Horizon API marks the order paid when funds arrive.
- Small `assets/checkout.js` for copy buttons + auto-refresh.
- PHPUnit tests for memo generation, address validation, payment matching.

## Requirements

- PHP 7.4+
- WordPress 6.0+
- WooCommerce 7.0+ (HPOS compatible)
- A Stellar wallet (testnet for testing, mainnet for live)

## Install steps

1. **Download / clone:**
   ```bash
   git clone https://github.com/StaveIndustries/woo-pay.git
   ```
2. **Upload to WordPress:**
   - Copy the `woo-pay` folder to `wp-content/plugins/woo-pay/`, or
   - Zip it and upload via WP Admin → Plugins → Add New → Upload Plugin.
3. **Activate:** WP Admin → Plugins → activate “Woo Pay – Stellar”.
4. **Configure:** WooCommerce → Settings → Payments → “Pay with Stellar” → Manage:
   - `Enable` = checked
   - `Wallet address` = your Stellar public key (`G…`, 56 chars)
   - `Asset` = `XLM` or `USDC`
   - `Network` = `testnet` for testing, `public` for live money
   - For USDC mainnet the default issuer is Circle:
     `GBBD47IF6LWK7P7MDEVSCWR7DPUWV3NY3DTQEVFL4NAT4AQH3ZLLFLA5`
5. **Test on testnet:**
   - Set Network = testnet, create a test wallet at https://laboratory.stellar.org + fund with Friendbot.
   - Place a test order, send the exact amount + memo from another test account.
   - Order should move from On-hold → Processing/Completed automatically (cron + thank-you check + manual `check_payment_for_order()`).
6. **Go live:** switch Network to `public`, double-check wallet + asset.

## How payment matching works

1. `process_payment()` generates `WOO-{orderId}-{6 random}` via `Stellar_Utils::generate_memo()` and saves it as order meta `_stellar_memo`.
2. Customer sends XLM/USDC to your wallet **with that memo**.
3. `WC_Stellar_Checker::find_matching_payment()` polls Horizon:
   - `GET https://horizon(-testnet).stellar.org/accounts/{wallet}/payments?limit=50&order=desc`
   - Enriches each payment with its transaction memo.
   - `Stellar_Utils::payment_matches()` checks destination + exact memo + amount ≥ total + asset type.
4. On match, `mark_order_paid()` calls `$order->payment_complete($tx_hash)`.

Manual check endpoint (optional): `/wc-api/wc_gateway_stellar?order_id=123` returns `{"paid":true/false}`.

## When a payment is not confirmed

The thank-you page asks that endpoint every 30 seconds and shows the shopper what is wrong and what to do next. Each reason has its own message:

| Reason code | When | Shopper is told to |
|---|---|---|
| `no_payment_found` | No payment carries this order's memo yet | Send the exact amount with the memo; contact the shop if already paid |
| `wrong_amount` | Memo matches, amount is too low | Not send a top-up (one payment per order is matched); contact the shop |
| `memo_mismatch` | A payment has this order's `WOO-{id}-` prefix but not the exact memo | Not pay again; contact the shop to match it by hand |
| `wrong_asset` | Memo matches, wrong asset (e.g. USDC sent for an XLM order) | Contact the shop |
| `expired` | Nothing found and the order is older than the payment window | Place a new order; contact the shop if already paid |
| `network_error` | Horizon could not be reached | Wait, the page keeps trying; no need to pay again |
| `order_not_found` | Order missing or not a Stellar order | Contact the shop with the order number |

- The payment window is 60 minutes. Change it with the `woo_pay_stellar_payment_window_minutes` filter (return `0` to never expire). A correct payment that arrives late is still credited.
- The endpoint adds `reason` and `message` to its JSON only when the order key is passed too (`&key=wc_order_...`), because the message can contain amounts and memos. Without the key it still returns just `paid`.
- Messages live in `WC_Stellar_Checker::failure_message()`; `WC_Stellar_Checker::check_payment()` returns the reason.

## Config (.env.example)

This plugin stores settings in WooCommerce options, but `.env.example` documents the same values for staging scripts/CI:

```
STELLAR_WALLET_ADDRESS=G...
STELLAR_ASSET=XLM
STELLAR_NETWORK=testnet
STELLAR_USDC_ISSUER=GBBD47IF6LWK7P7MDEVSCWR7DPUWV3NY3DTQEVFL4NAT4AQH3ZLLFLA5
```

Copy to `.env` for local tooling only — never commit real keys (public keys are safe to share, secret keys are never needed by this plugin).

## Running tests

```bash
composer install
composer test
# or: vendor/bin/phpunit --testdox
```

Tests live in `tests/StellarUtilsTest.php` (memo format/uniqueness, StrKey address validation, XLM/USDC matching) and `tests/StellarCheckerTest.php` (one test per failure reason and its shopper message).

## File layout

```
woo-pay.php                              Main plugin file, registers gateway + JS
includes/class-stellar-utils.php         Pure-PHP: memo, address check, matching, Horizon URL
includes/class-wc-stellar-settings.php   Settings defaults + form_fields
includes/class-wc-gateway-stellar.php    WC_Payment_Gateway: checkout, process_payment, thank-you, checker hook
includes/class-wc-stellar-checker.php    Horizon API client + mark_order_paid
assets/checkout.js                       Copy buttons + thank-you payment status messages
tests/StellarUtilsTest.php               PHPUnit tests (pure helpers)
tests/StellarCheckerTest.php             PHPUnit tests (failure reasons + messages)
phpunit.xml / composer.json / tests/bootstrap.php
.env.example / README.md / CONTRIBUTING.md / LICENSE
```

## Security notes

- Only public keys (`G…`) are stored. Never ask customers for secret keys.
- Always validate address format (`/^G[A-Z2-7]{55}$/`, 56 chars) before saving/using.
- Amount check uses `>= expected - epsilon` to tolerate stroop rounding; adjust if you need strict FX conversion.
- For USDC on mainnet, verify the issuer matches Circle if you harden `payment_matches()`.

## License

MIT — see `LICENSE`.

## One-command local demo (Docker)

PHP cannot run on Vercel, so use Docker to try the plugin locally:

``bash
docker compose up -d
# open http://localhost:8080, finish the WP installer, install WooCommerce
# Plugins -> Add New -> Upload -> zip this repo -> Activate
# WooCommerce -> Settings -> Payments -> enable 'Pay with Stellar'
``n
The plugin source is mounted live, so code edits apply without rebuilding.
Testnet only. See docker-compose.yml for details.
