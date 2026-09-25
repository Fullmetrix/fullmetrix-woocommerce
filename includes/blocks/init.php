<?php
defined('ABSPATH') || exit;

add_action('woocommerce_blocks_loaded', function () {
    if (
        !class_exists('\Automattic\WooCommerce\Blocks\Package') ||
        !interface_exists('\Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface')
    ) {
        return;
    }

    require_once __DIR__ . '/class-fullmetrix-checkout-block-integration.php';
    require_once __DIR__ . '/class-fullmetrix-checkout-block-extend-woo-core.php';

    add_action('woocommerce_blocks_checkout_block_registration', function ($integration_registry) {
        $integration_registry->register(new Fullmetrix_Checkout_Block_Integration());
    });

    Fullmetrix_Checkout_Block_Extend_Woo_Core::init();
});
