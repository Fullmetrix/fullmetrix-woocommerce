<?php
defined('ABSPATH') || exit;

class Fullmetrix_Tracking_Sender {

    const UUID_V4 = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

    private static $events = array();
    private static $flush_scheduled = false;

    public static function init() {
        if (is_admin()) {
            return;
        }

        if (!Fullmetrix_Connector::is_configured() || !Fullmetrix_Connector::feature_enabled('serverEvents')) {
            return;
        }

        add_action('wp_login', array(__CLASS__, 'on_login'), 20, 2);
        add_action('woocommerce_checkout_order_processed', array(__CLASS__, 'on_checkout_processed'), 20, 3);
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

    private static function read_cookie($fresh, $legacy) {
        foreach (array($fresh, $legacy) as $name) {
            if (!isset($_COOKIE[$name])) {
                continue;
            }
            $value = sanitize_text_field(wp_unslash($_COOKIE[$name]));
            if (is_string($value) && preg_match(self::UUID_V4, $value) === 1) {
                return $value;
            }
        }
        return null;
    }

    private static function read_visitor_id() {
        return self::read_cookie('_kvo_v', 'fm_vid');
    }

    private static function read_session_id() {
        return self::read_cookie('_kvo_s', 'fm_sid');
    }

    private static function read_contact() {
        foreach (array('_kvo_c', 'fm_cid') as $name) {
            if (!isset($_COOKIE[$name])) {
                continue;
            }
            $raw = sanitize_text_field(wp_unslash($_COOKIE[$name]));
            $data = json_decode($raw, true);
            if (is_array($data)) {
                return $data;
            }
        }
        return null;
    }

    public static function enqueue_event($event_type, $properties = array(), $contact = null) {
        $visitor_id = self::read_visitor_id();
        $session_id = self::read_session_id();
        if (!$visitor_id || !$session_id) {
            return;
        }

        $contact_data = $contact ? $contact : self::read_contact();

        $event = array(
            'event_id' => 'srv_' . wp_generate_uuid4(),
            'event_type' => $event_type,
            'properties' => $properties,
            'occurred_at' => round(microtime(true) * 1000),
            'contact' => $contact_data,
        );
        $page = self::referer();
        if ($page !== null) {
            $event['page'] = array('url' => $page);
        }
        self::$events[] = $event;

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

    private static function referer() {
        if (!isset($_SERVER['HTTP_REFERER'])) {
            return null;
        }
        $referer = sanitize_text_field(wp_unslash($_SERVER['HTTP_REFERER']));
        return is_string($referer) && preg_match('#^https?://#', $referer) === 1 ? substr($referer, 0, 2048) : null;
    }
}
