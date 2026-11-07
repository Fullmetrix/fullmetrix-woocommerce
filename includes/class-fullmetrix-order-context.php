<?php
defined('ABSPATH') || exit;

class Fullmetrix_Order_Context {

    const META_KEY = '_fm_ctx';
    const FEATURE = 'orderContext';
    const MAX_LENGTH = 200;
    const UUID_V4 = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';
    const SAFE_VALUE = '/^[A-Za-z0-9._~:|=+\/%-]+$/';
    const CREATED_VIA = '/^[a-z0-9_-]{1,40}$/';

    private static $signal_cookies = array(
        'fbp' => '_fbp',
        'fbc' => '_fbc',
        'ga' => '_ga',
        'gclaw' => '_gcl_aw',
        'ttp' => '_ttp',
    );

    public static function init() {
        add_action('woocommerce_checkout_create_order', array(__CLASS__, 'capture_classic'), 10, 2);
        add_action('woocommerce_store_api_checkout_update_order_from_request', array(__CLASS__, 'capture_blocks'), 10, 2);
        add_action('woocommerce_privacy_remove_order_personal_data', array(__CLASS__, 'erase'), 10, 1);
    }

    public static function erase($order) {
        try {
            if (!is_object($order) || !method_exists($order, 'delete_meta_data')) {
                return;
            }
            $order->delete_meta_data(self::META_KEY);
            if (method_exists($order, 'save_meta_data')) {
                $order->save_meta_data();
            }
        } catch (\Throwable $e) {
            return;
        }
    }

    public static function capture_classic($order, $data = array()) {
        try {
            self::capture($order);
        } catch (\Throwable $e) {
            return;
        }
    }

    public static function capture_blocks($order, $request = null) {
        try {
            if (is_object($request) && method_exists($request, 'get_method') && strtoupper((string) $request->get_method()) !== 'POST') {
                return;
            }
            self::capture($order);
        } catch (\Throwable $e) {
            return;
        }
    }

    private static function capture($order) {
        if (!is_object($order) || !method_exists($order, 'update_meta_data')) {
            return;
        }
        if (!Fullmetrix_Connector::is_configured() || !Fullmetrix_Connector::feature_enabled(self::FEATURE)) {
            return;
        }
        if (self::placed_by_staff($order)) {
            return;
        }

        $context = self::context($order);
        if ($context === null) {
            return;
        }

        $order->update_meta_data(self::META_KEY, wp_json_encode($context));
    }

    private static function placed_by_staff($order) {
        if (!function_exists('get_current_user_id') || !function_exists('current_user_can')) {
            return false;
        }
        $user_id = (int) get_current_user_id();
        if ($user_id <= 0) {
            return false;
        }
        if (!current_user_can('manage_woocommerce') && !current_user_can('edit_shop_orders')) {
            return false;
        }
        $customer_id = method_exists($order, 'get_customer_id') ? (int) $order->get_customer_id() : 0;

        return $customer_id !== $user_id;
    }

    private static function context($order) {
        $visitor = self::cookie('fm_vid', self::UUID_V4);
        if ($visitor === null) {
            return null;
        }

        $context = array('v' => $visitor);
        $session = self::cookie('fm_sid', self::UUID_V4);
        if ($session !== null) {
            $context['s'] = $session;
        }
        foreach (self::$signal_cookies as $field => $name) {
            $value = self::cookie($name, self::SAFE_VALUE);
            if ($value !== null) {
                $context[$field] = $value;
            }
        }
        $created_via = method_exists($order, 'get_created_via') ? $order->get_created_via() : '';
        if (is_string($created_via) && preg_match(self::CREATED_VIA, $created_via) === 1) {
            $context['cv'] = $created_via;
        }
        $context['t'] = time();

        return $context;
    }

    private static function cookie($name, $pattern) {
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Kept verbatim and matched against a strict pattern, sanitize_text_field would strip the %xx octets.
        $value = isset($_COOKIE[$name]) ? wp_unslash($_COOKIE[$name]) : null;
        if (!is_string($value) || $value === '' || strlen($value) > self::MAX_LENGTH) {
            return null;
        }

        return preg_match($pattern, $value) === 1 ? $value : null;
    }
}
