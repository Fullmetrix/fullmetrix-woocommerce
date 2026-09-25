<?php
defined('ABSPATH') || exit;

class Fullmetrix_Checkout_Block_Extend_Woo_Core {

    const NAMESPACE_NAME = 'fullmetrix-checkout';

    public static function init() {
        self::register_endpoint_data();
        add_action(
            'woocommerce_store_api_checkout_update_order_from_request',
            array(__CLASS__, 'persist_consent'),
            10,
            2
        );
    }

    public static function register_endpoint_data() {
        if (!function_exists('woocommerce_store_api_register_endpoint_data')) {
            return;
        }

        woocommerce_store_api_register_endpoint_data(array(
            'endpoint' => \Automattic\WooCommerce\StoreApi\Schemas\V1\CheckoutSchema::IDENTIFIER,
            'namespace' => self::NAMESPACE_NAME,
            'schema_callback' => array(__CLASS__, 'extend_checkout_schema'),
            'schema_type' => ARRAY_A,
        ));
    }

    public static function extend_checkout_schema() {
        return array(
            'consent' => array(
                'description' => __('Marketing consent given at checkout', 'fullmetrix'),
                'type' => array('boolean', 'null'),
                'readonly' => false,
            ),
        );
    }

    public static function persist_consent($order, $request = null) {
        try {
            self::persist_submitted_consent($order, $request);
        } catch (\Throwable $e) {
            return;
        }
    }

    private static function persist_submitted_consent($order, $request) {
        if (!$order instanceof \WC_Order || !$request instanceof \WP_REST_Request) {
            return;
        }

        if (strtoupper($request->get_method()) !== 'POST') {
            return;
        }

        $cfg = Fullmetrix_Checkout_Consent::get_config();
        if (!$cfg) {
            return;
        }

        $extensions = $request['extensions'] ?? array();
        $given = false;

        if (is_array($extensions) && isset($extensions[self::NAMESPACE_NAME])) {
            $ext = $extensions[self::NAMESPACE_NAME];
            if (isset($ext['consent'])) {
                $given = (bool) $ext['consent'];
            }
        }

        $order->update_meta_data(Fullmetrix_Checkout_Consent::ORDER_META_KEY, $given ? 'yes' : 'no');
        $order->update_meta_data(Fullmetrix_Checkout_Consent::CHANNELS_META_KEY, is_array($cfg['channels']) ? implode(',', $cfg['channels']) : '');

        Fullmetrix_Checkout_Consent::forward_order_consent($order);
    }
}
