<?php
defined('ABSPATH') || exit;

class Fullmetrix_Webhook_Sender {

    const ACTION_HOOK = 'fullmetrix_send_webhooks';
    const ACTION_GROUP = 'fullmetrix';
    const MAX_ACTION_ARGS_LENGTH = 180;
    const FALLBACK_LIMIT = 50;
    const BACKGROUND_TIMEOUT = 3;
    const RETRY_DELAY = 300;
    const MAX_RETRIES = 12;
    const BACKLOG_OPTION = 'fullmetrix_webhook_backlog';
    const BACKLOG_LIMIT = 2000;

    /** @var array<string, true> */
    private static $queue = array();

    /** @var bool */
    private static $shutdown_registered = false;

    public static function init() {
        add_action(self::ACTION_HOOK, array(__CLASS__, 'process_scheduled'), 10, 2);

        if (!self::is_active()) {
            return;
        }

        add_action('woocommerce_new_order', array(__CLASS__, 'on_order'), 10, 1);
        add_action('woocommerce_update_order', array(__CLASS__, 'on_order'), 10, 1);
        add_action('woocommerce_order_status_changed', array(__CLASS__, 'on_order_status'), 10, 1);

        add_action('profile_update', array(__CLASS__, 'on_customer'), 10, 1);
        add_action('user_register', array(__CLASS__, 'on_customer'), 10, 1);
        add_action('woocommerce_customer_save_address', array(__CLASS__, 'on_customer_address'), 10, 1);

        add_action('woocommerce_update_product', array(__CLASS__, 'on_product'), 10, 1);
        add_action('woocommerce_new_product', array(__CLASS__, 'on_product'), 10, 1);
        add_action('woocommerce_update_product_variation', array(__CLASS__, 'on_product'), 10, 1);
        add_action('woocommerce_new_product_variation', array(__CLASS__, 'on_product'), 10, 1);

        add_action('woocommerce_new_coupon', array(__CLASS__, 'on_coupon'), 10, 1);
        add_action('woocommerce_update_coupon', array(__CLASS__, 'on_coupon'), 10, 1);

        add_action('woocommerce_order_refunded', array(__CLASS__, 'on_refund'), 10, 2);

        add_action('created_product_cat', array(__CLASS__, 'on_category'), 10, 1);
        add_action('edited_product_cat', array(__CLASS__, 'on_category'), 10, 1);

        add_action('woocommerce_subscription_status_updated', array(__CLASS__, 'on_subscription'), 10, 1);
    }

    public static function on_order($order_id) {
        self::enqueue('order', $order_id);
    }

    public static function on_order_status($order_id) {
        self::enqueue('order', $order_id);
    }

    public static function on_customer($user_id) {
        self::enqueue('customer', $user_id);
    }

    public static function on_customer_address($user_id) {
        self::enqueue('customer', $user_id);
    }

    public static function on_product($product_id) {
        self::enqueue('product', $product_id);
    }

    public static function on_coupon($coupon_id) {
        self::enqueue('coupon', $coupon_id);
    }

    public static function on_refund($order_id, $refund_id = null) {
        self::enqueue('refund', $refund_id);
    }

    public static function on_category($term_id) {
        self::enqueue('category', $term_id);
    }

    public static function on_subscription($subscription) {
        try {
            $sub_id = is_object($subscription) && method_exists($subscription, 'get_id') ? $subscription->get_id() : $subscription;
            self::enqueue('subscription', $sub_id);
        } catch (\Throwable $e) {
            return;
        }
    }

    public static function enqueue($entity_type, $id) {
        if (!is_scalar($id) || (int) $id <= 0) {
            return;
        }

        self::$queue[$entity_type . ':' . (int) $id] = true;

        if (!self::$shutdown_registered) {
            add_action('shutdown', array(__CLASS__, 'flush_queue'), 100);
            self::$shutdown_registered = true;
        }
    }

    public static function flush_queue() {
        try {
            $backlog = get_option(self::BACKLOG_OPTION, array());
            $backlog = is_array($backlog) ? $backlog : array();
            if (empty(self::$queue) && empty($backlog)) {
                return;
            }

            $keys = array_values(array_unique(array_merge($backlog, array_keys(self::$queue))));
            self::$queue = array();
            if (!empty($backlog)) {
                update_option(self::BACKLOG_OPTION, array(), true);
            }

            $unscheduled = self::schedule($keys);
            if (empty($unscheduled)) {
                return;
            }

            if (Fullmetrix_Http::is_open()) {
                self::store_backlog($unscheduled);
                return;
            }

            Fullmetrix_Http::after_response(
                array(__CLASS__, 'send_after_response'),
                array(array_slice($unscheduled, 0, self::FALLBACK_LIMIT))
            );
            self::store_backlog(array_slice($unscheduled, self::FALLBACK_LIMIT));
        } catch (\Throwable $e) {
            self::$queue = array();
        }
    }

    public static function process_scheduled($keys, $attempt = 0) {
        try {
            if (!is_array($keys) || !self::is_active()) {
                return;
            }

            $remaining = self::send($keys, self::BACKGROUND_TIMEOUT, true, Fullmetrix_Http::JOBS);
            if (!empty($remaining) && (int) $attempt < self::MAX_RETRIES) {
                self::reschedule($remaining, (int) $attempt + 1);
            }
        } catch (\Throwable $e) {
            return;
        }
    }

    public static function send_after_response($keys) {
        self::store_backlog(self::send($keys, Fullmetrix_Http::client_timeout(), false, Fullmetrix_Http::CLIENT));
    }

    public static function install() {
        add_option(self::BACKLOG_OPTION, array(), '', true);
    }

    private static function store_backlog($keys) {
        if (empty($keys)) {
            return;
        }

        $backlog = get_option(self::BACKLOG_OPTION, array());
        $backlog = is_array($backlog) ? $backlog : array();
        $merged = array_slice(array_values(array_unique(array_merge($backlog, $keys))), 0, self::BACKLOG_LIMIT);
        update_option(self::BACKLOG_OPTION, $merged, true);
    }

    public static function unschedule_all() {
        try {
            if (function_exists('as_unschedule_all_actions')) {
                as_unschedule_all_actions(self::ACTION_HOOK);
            }
        } catch (\Throwable $e) {
            return;
        }
    }

    private static function is_active() {
        if (!get_option('fullmetrix_webhooks_enabled')) {
            return false;
        }

        $secret = get_option('fullmetrix_connection_secret');
        $code = get_option('fullmetrix_connection_code');
        if (empty($secret) || empty($code)) {
            return false;
        }

        return Fullmetrix_Connector::feature_enabled('webhooks');
    }

    private static function schedule($keys) {
        if (!function_exists('as_enqueue_async_action') || !did_action('init')) {
            return $keys;
        }

        $unscheduled = array();
        foreach (self::chunk($keys) as $chunk) {
            try {
                $action_id = as_enqueue_async_action(self::ACTION_HOOK, array($chunk), self::ACTION_GROUP);
            } catch (\Throwable $e) {
                $action_id = 0;
            }

            if ((int) $action_id <= 0) {
                $unscheduled = array_merge($unscheduled, $chunk);
            }
        }

        return $unscheduled;
    }

    private static function reschedule($keys, $attempt) {
        if (!function_exists('as_schedule_single_action')) {
            return;
        }

        foreach (self::chunk($keys) as $chunk) {
            try {
                as_schedule_single_action(time() + self::RETRY_DELAY, self::ACTION_HOOK, array($chunk, $attempt), self::ACTION_GROUP);
            } catch (\Throwable $e) {
                continue;
            }
        }
    }

    private static function chunk($keys) {
        $chunks = array();
        $current = array();

        foreach ($keys as $key) {
            $candidate = $current;
            $candidate[] = $key;
            if (!empty($current) && strlen(wp_json_encode(array($candidate, self::MAX_RETRIES))) > self::MAX_ACTION_ARGS_LENGTH) {
                $chunks[] = $current;
                $current = array($key);
                continue;
            }
            $current = $candidate;
        }

        if (!empty($current)) {
            $chunks[] = $current;
        }

        return $chunks;
    }

    private static function parse_key($key) {
        $parts = is_string($key) ? explode(':', $key, 2) : array();
        if (count($parts) !== 2 || (int) $parts[1] <= 0) {
            return null;
        }

        return array('type' => $parts[0], 'id' => (int) $parts[1]);
    }

    private static function expand_key($entry) {
        $entries = array($entry);
        if ($entry['type'] !== 'product' || !function_exists('wc_get_product')) {
            return $entries;
        }

        try {
            $product = wc_get_product($entry['id']);
            if ($product && $product->is_type('variable')) {
                foreach ($product->get_children() as $variation_id) {
                    $entries[] = array('type' => 'product', 'id' => (int) $variation_id);
                }
            }
        } catch (\Throwable $e) {
            return $entries;
        }

        return $entries;
    }

    private static function send($keys, $timeout, $blocking, $channel) {
        $secret = get_option('fullmetrix_connection_secret');
        $code = get_option('fullmetrix_connection_code');
        if (empty($secret) || empty($code)) {
            return array();
        }

        $keys = array_values($keys);
        $exporter = null;
        $api_url = str_replace('/api/plugin', '/api/webhooks/ecommerce', FULLMETRIX_API_BASE);
        $sent = array();

        foreach ($keys as $index => $key) {
            $entry = self::parse_key($key);
            if ($entry === null) {
                continue;
            }

            foreach (self::expand_key($entry) as $item) {
                $item_key = $item['type'] . ':' . $item['id'];
                if (isset($sent[$item_key])) {
                    continue;
                }

                if (Fullmetrix_Http::is_open($channel)) {
                    return array_slice($keys, $index);
                }

                $sent[$item_key] = true;
                if ($exporter === null) {
                    $exporter = new Fullmetrix_Fast_Stream_Exporter();
                }
                self::send_entity($exporter, $item, $api_url, $secret, $code, $timeout, $blocking, $channel);
            }
        }

        return array();
    }

    private static function send_entity($exporter, $item, $api_url, $secret, $code, $timeout, $blocking, $channel) {
        try {
            $data = $exporter->format_single_entity($item['type'], $item['id']);
            if ($data === null) {
                return;
            }

            $payload = wp_json_encode(array(
                'event' => $item['type'] . '.updated',
                'entity_type' => $item['type'],
                'data' => $data,
                'plugin_version' => FULLMETRIX_VERSION,
                'timestamp' => round(microtime(true) * 1000),
            ));

            if ($payload === false) {
                return;
            }

            $headers = Fullmetrix_Security::create_signed_headers($secret, $code, $payload);

            Fullmetrix_Http::request('POST', $api_url, array(
                'headers' => array_merge($headers, array(
                    'Content-Type' => 'application/json',
                )),
                'body' => $payload,
                'blocking' => $blocking,
                'sslverify' => true,
            ), $timeout, $channel);
        } catch (\Throwable $e) {
            return;
        }
    }
}
