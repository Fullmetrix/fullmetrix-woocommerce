<?php
defined('ABSPATH') || exit;

class Fullmetrix_Tracking_Sender {

    private static $events = array();
    private static $flush_scheduled = false;

    public static function init() {
        if (is_admin()) {
            return;
        }

        if (!Fullmetrix_Connector::is_configured() || !Fullmetrix_Connector::feature_enabled('serverEvents')) {
            return;
        }

        add_action('woocommerce_add_to_cart', array(__CLASS__, 'on_add_to_cart'), 30, 6);
        add_action('woocommerce_cart_item_removed', array(__CLASS__, 'on_cart_item_removed'), 30, 2);
        add_action('woocommerce_after_cart_item_quantity_update', array(__CLASS__, 'on_cart_quantity_update'), 30, 4);
        add_action('wp_login', array(__CLASS__, 'on_login'), 20, 2);
        add_action('woocommerce_checkout_order_processed', array(__CLASS__, 'on_checkout_processed'), 20, 3);
    }

    public static function on_add_to_cart($cart_item_key = null, $product_id = 0, $quantity = 1, $variation_id = 0, $variation = null, $cart_item_data = null) {
        try {
            self::track_add_to_cart($product_id, $quantity, $variation_id);
        } catch (\Throwable $e) {
            return;
        }
    }

    private static function track_add_to_cart($product_id, $quantity, $variation_id) {
        $product = wc_get_product($variation_id ? $variation_id : $product_id);
        if (!$product) {
            return;
        }

        $added_item = array(
            'product_id' => $product_id,
            'variation_id' => $variation_id ? $variation_id : null,
            'name' => $product->get_name(),
            'quantity' => $quantity,
            'price' => (float) $product->get_price(),
            'sku' => $product->get_sku() ? $product->get_sku() : null,
            'image_url' => wp_get_attachment_url($product->get_image_id()) ? wp_get_attachment_url($product->get_image_id()) : null,
            'url' => get_permalink($product_id),
        );

        self::enqueue_event('added_to_cart', array(
            'added_item' => $added_item,
            'cart' => self::build_wc_cart_snapshot(),
            'source' => 'server',
        ));
    }

    public static function on_cart_item_removed($cart_item_key = null, $cart = null) {
        try {
            self::track_cart_item_removed($cart_item_key, $cart);
        } catch (\Throwable $e) {
            return;
        }
    }

    private static function track_cart_item_removed($cart_item_key, $cart) {
        $removed = is_object($cart) && isset($cart->removed_cart_contents[$cart_item_key]) ? $cart->removed_cart_contents[$cart_item_key] : null;
        $removed_item = array();
        if ($removed) {
            $removed_item = array(
                'product_id' => $removed['product_id'],
                'variation_id' => isset($removed['variation_id']) ? $removed['variation_id'] : null,
                'quantity' => $removed['quantity'],
            );
        }

        self::enqueue_event('removed_from_cart', array(
            'removed_item' => $removed_item,
            'cart' => self::build_wc_cart_snapshot(),
            'source' => 'server',
        ));
    }

    public static function on_cart_quantity_update($cart_item_key = null, $quantity = null, $old_quantity = null, $cart = null) {
        try {
            if ($quantity === $old_quantity) {
                return;
            }

            self::enqueue_event('cart_updated', array(
                'cart' => self::build_wc_cart_snapshot(),
                'source' => 'server',
            ));
        } catch (\Throwable $e) {
            return;
        }
    }

    public static function on_login($user_login = null, $user = null) {
        try {
            self::track_login($user);
        } catch (\Throwable $e) {
            return;
        }
    }

    private static function track_login($user) {
        if (!is_object($user) || empty($user->user_email)) {
            return;
        }

        $contact = array('email' => $user->user_email);
        $first = get_user_meta($user->ID, 'first_name', true);
        $last = get_user_meta($user->ID, 'last_name', true);
        if ($first) {
            $contact['first_name'] = $first;
        }
        if ($last) {
            $contact['last_name'] = $last;
        }
        $phone = get_user_meta($user->ID, 'billing_phone', true);
        if ($phone) {
            $contact['phone'] = $phone;
        }
        $contact['customer_id'] = $user->ID;

        self::enqueue_event('identify', array(), $contact);
    }

    public static function on_checkout_processed($order_id = null, $posted_data = null, $order = null) {
        try {
            self::track_checkout($order instanceof \WC_Order || !$order_id ? $order : wc_get_order($order_id));
        } catch (\Throwable $e) {
            return;
        }
    }

    private static function track_checkout($order) {
        if (!$order instanceof \WC_Order) {
            return;
        }

        $email = $order->get_billing_email();
        if (empty($email)) {
            return;
        }

        $contact = array(
            'email' => $email,
            'phone' => $order->get_billing_phone() ? $order->get_billing_phone() : null,
            'first_name' => $order->get_billing_first_name() ? $order->get_billing_first_name() : null,
            'last_name' => $order->get_billing_last_name() ? $order->get_billing_last_name() : null,
            'customer_id' => $order->get_customer_id() ? $order->get_customer_id() : null,
            'country_code' => $order->get_billing_country() ? $order->get_billing_country() : null,
        );

        self::enqueue_event('identify', array(), $contact);
    }

    private static function read_visitor_id() {
        return isset($_COOKIE['fm_vid']) ? sanitize_text_field(wp_unslash($_COOKIE['fm_vid'])) : null;
    }

    private static function read_session_id() {
        return isset($_COOKIE['fm_sid']) ? sanitize_text_field(wp_unslash($_COOKIE['fm_sid'])) : null;
    }

    private static function read_contact() {
        if (!isset($_COOKIE['fm_cid'])) {
            return null;
        }
        $raw = sanitize_text_field(wp_unslash($_COOKIE['fm_cid']));
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return null;
        }
        return $data;
    }

    public static function enqueue_event($event_type, $properties = array(), $contact = null) {
        $visitor_id = self::read_visitor_id();
        $session_id = self::read_session_id();
        if (!$visitor_id || !$session_id) {
            return;
        }

        $contact_data = $contact ? $contact : self::read_contact();

        self::$events[] = array(
            'event_id' => 'srv_' . wp_generate_uuid4(),
            'event_type' => $event_type,
            'properties' => $properties,
            'occurred_at' => round(microtime(true) * 1000),
            'contact' => $contact_data,
            'page' => array(
                'url' => self::get_current_url(),
            ),
        );

        if (!self::$flush_scheduled) {
            Fullmetrix_Http::after_response(array(__CLASS__, 'flush'));
            self::$flush_scheduled = true;
        }
    }

    public static function flush() {
        if (empty(self::$events)) {
            return;
        }

        $visitor_id = self::read_visitor_id();
        $session_id = self::read_session_id();
        if (!$visitor_id || !$session_id) {
            self::$events = array();
            return;
        }

        $secret = get_option('fullmetrix_connection_secret');
        $code = get_option('fullmetrix_connection_code');
        if (empty($secret) || empty($code)) {
            self::$events = array();
            return;
        }

        $api_url = str_replace('/api/plugin', '/api/webhooks/events', FULLMETRIX_API_BASE);

        $payload = wp_json_encode(array(
            'events' => self::$events,
            'visitor_id' => $visitor_id,
            'session_id' => $session_id,
            'plugin_version' => 'server-' . FULLMETRIX_VERSION,
            'timestamp' => round(microtime(true) * 1000),
        ));

        if ($payload === false) {
            self::$events = array();
            return;
        }

        $headers = Fullmetrix_Security::create_signed_headers($secret, $code, $payload);

        self::$events = array();

        Fullmetrix_Http::post_now($api_url, array(
            'headers' => array_merge($headers, array(
                'Content-Type' => 'application/json',
            )),
            'body' => $payload,
            'sslverify' => true,
        ));
    }

    private static function build_wc_cart_snapshot() {
        if (!function_exists('WC') || !WC()->cart) {
            return array();
        }

        $cart = WC()->cart;
        $items = array();

        foreach ($cart->get_cart() as $item) {
            $product = $item['data'];
            if (!$product) {
                continue;
            }
            $image_id = $product->get_image_id();
            $items[] = array(
                'product_id' => $item['product_id'],
                'variation_id' => !empty($item['variation_id']) ? $item['variation_id'] : null,
                'name' => $product->get_name(),
                'quantity' => $item['quantity'],
                'price' => (float) $product->get_price(),
                'line_total' => (float) $item['line_total'],
                'sku' => $product->get_sku() ? $product->get_sku() : null,
                'image_url' => $image_id ? wp_get_attachment_url($image_id) : null,
                'url' => get_permalink($item['product_id']),
            );
        }

        return array(
            'currency' => get_woocommerce_currency(),
            'total' => (float) $cart->get_total('edit'),
            'subtotal' => (float) $cart->get_subtotal(),
            'discount_total' => (float) $cart->get_discount_total(),
            'shipping_total' => (float) $cart->get_shipping_total(),
            'tax_total' => (float) $cart->get_total_tax(),
            'coupon_codes' => $cart->get_applied_coupons(),
            'item_count' => $cart->get_cart_contents_count(),
            'items' => $items,
            'recovery_url' => self::build_recovery_url($cart),
        );
    }

    private static function build_recovery_url($cart) {
        $items_data = array();
        foreach ($cart->get_cart() as $item) {
            $items_data[] = array(
                'id' => $item['product_id'],
                'v' => !empty($item['variation_id']) ? $item['variation_id'] : 0,
                'q' => $item['quantity'],
            );
        }

        if (empty($items_data)) {
            return null;
        }

        $payload = array('items' => $items_data, 'c' => $cart->get_applied_coupons());
        $encoded = strtr(base64_encode(wp_json_encode($payload)), '+/', '-_');
        $secret = get_option('fullmetrix_connection_secret', '');
        $signature = hash_hmac('sha256', $encoded, $secret);
        return add_query_arg(array('fm_cart' => $encoded, 'fm_cart_sig' => $signature), home_url('/'));
    }

    private static function get_current_url() {
        $protocol = is_ssl() ? 'https' : 'http';
        $host = isset($_SERVER['HTTP_HOST']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'])) : '';
        $uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '';
        return $protocol . '://' . $host . $uri;
    }
}
