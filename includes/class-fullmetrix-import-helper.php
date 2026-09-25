<?php
defined('ABSPATH') || exit;

class Fullmetrix_Import_Helper {

    private static $instance = null;

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('rest_api_init', array($this, 'maybe_filter_expensive_queries'), 5);
    }

    public function maybe_filter_expensive_queries($server) {
        if (!is_object($server)) {
            return;
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- passed directly to WP_REST_Server::get_headers() which handles sanitization
        $headers = $server->get_headers($_SERVER);

        if ($this->is_fullmetrix_request($headers)) {
            add_filter('get_user_metadata', array($this, 'filter_user_metadata'), 10, 4);
            add_filter('woocommerce_customer_get_order_count', array($this, 'skip_order_count'), 10, 2);
            add_filter('woocommerce_customer_get_total_spent', array($this, 'skip_total_spent'), 10, 2);
        }
    }

    private function is_fullmetrix_request($headers) {
        if (isset($headers['X_FULLMETRIX_SIGNATURE']) || isset($headers['HTTP_X_FULLMETRIX_SIGNATURE'])) {
            return true;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only detection of Fullmetrix export flag in REST API context
        if (isset($_GET['fullmetrix_export']) && sanitize_text_field(wp_unslash($_GET['fullmetrix_export'])) === '1') {
            return true;
        }

        return false;
    }

    public function filter_user_metadata($value, $object_id, $meta_key, $single) {
        if (in_array($meta_key, array('_money_spent', '_order_count'), true)) {
            return 0;
        }
        return $value;
    }

    public function skip_order_count($count, $customer) {
        return 0;
    }

    public function skip_total_spent($spent, $customer) {
        return 0;
    }
}

Fullmetrix_Import_Helper::instance();
