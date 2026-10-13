<?php
/**
 * Settings definition for the Stellar gateway.
 *
 * Beginners: WooCommerce gateways define a $form_fields array.
 * WooCommerce renders the Settings page automatically from it.
 * This helper just returns that array so the gateway stays tidy.
 */

declare(strict_types=1);

class WC_Stellar_Settings
{
    /**
     * Get default values (used for fresh installs + .env.example docs).
     *
     * @return array<string,mixed>
     */
    public static function defaults(): array
    {
        return [
            'enabled'        => 'yes',
            'title'          => 'Pay with Stellar',
            'description'    => 'Pay with XLM or USDC on the Stellar network. Send the exact amount with the memo below so we can match your order.',
            'wallet_address' => '',
            'asset'          => 'XLM',
            'network'        => 'testnet',
            'usdc_issuer'    => 'GBBD47IF6LWK7P7MDEVSCWR7DPUWV3NY3DTQEVFL4NAT4AQH3ZLLFLA5',
            'confirmations'  => '1',
        ];
    }

    /**
     * Get the form fields shown in WooCommerce → Settings → Payments → Pay with Stellar.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function form_fields(): array
    {
        return [
            'enabled'        => [
                'title'   => __('Enable/Disable', 'woo-pay'),
                'type'    => 'checkbox',
                'label'   => __('Enable Pay with Stellar', 'woo-pay'),
                'default' => 'yes',
            ],
            'title'          => [
                'title'       => __('Title', 'woo-pay'),
                'type'        => 'text',
                'description' => __('Shown to customers at checkout.', 'woo-pay'),
                'default'     => 'Pay with Stellar',
                'desc_tip'    => true,
            ],
            'description'    => [
                'title'   => __('Description', 'woo-pay'),
                'type'    => 'textarea',
                'default' => 'Pay with XLM or USDC on the Stellar network. Send the exact amount with the memo below so we can match your order.',
            ],
            'wallet_address' => [
                'title'       => __('Wallet address', 'woo-pay'),
                'type'        => 'text',
                'description' => __('Your Stellar public key (starts with G, 56 chars). Funds are sent here.', 'woo-pay'),
                'default'     => '',
                'desc_tip'    => true,
            ],
            'asset'          => [
                'title'   => __('Asset', 'woo-pay'),
                'type'    => 'select',
                'options' => [
                    'XLM'  => __('XLM (native Lumens)', 'woo-pay'),
                    'USDC' => __('USDC (Stellar-issued)', 'woo-pay'),
                ],
                'default'     => 'XLM',
                'description' => __('Which asset customers should send.', 'woo-pay'),
                'desc_tip'    => true,
            ],
            'network'        => [
                'title'   => __('Payment network (Testnet / Mainnet)', 'woo-pay'),
                'type'    => 'select',
                'options' => [
                    'testnet' => __('Testnet (for testing)', 'woo-pay'),
                    'public'  => __('Mainnet / Public (real money)', 'woo-pay'),
                ],
                'default'     => 'testnet',
                'description' => __('Testnet uses worthless test tokens. Select Mainnet / Public to accept real Stellar payments.', 'woo-pay'),
                'desc_tip'    => true,
            ],
            'usdc_issuer'    => [
                'title'       => __('USDC issuer (mainnet)', 'woo-pay'),
                'type'        => 'text',
                'description' => __('Official Circle USDC issuer. Only change for test anchors.', 'woo-pay'),
                'default'     => 'GBBD47IF6LWK7P7MDEVSCWR7DPUWV3NY3DTQEVFL4NAT4AQH3ZLLFLA5',
                'desc_tip'    => true,
            ],
            'confirmations'  => [
                'title'       => __('Required payments to check', 'woo-pay'),
                'type'        => 'number',
                'description' => __('How many recent Horizon payments to scan (1–200).', 'woo-pay'),
                'default'     => '50',
                'desc_tip'    => true,
            ],
        ];
    }
}
