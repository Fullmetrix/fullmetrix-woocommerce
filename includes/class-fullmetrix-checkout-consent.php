<?php
defined('ABSPATH') || exit;

class Fullmetrix_Checkout_Consent {

    const ORDER_META_KEY = '_fullmetrix_marketing_opt_in_consent';
    const FIELD_NAME = 'fullmetrix_marketing_consent';
    const CHANNELS_META_KEY = '_fullmetrix_consent_channels';
    const ACTION_HOOK = 'fullmetrix_send_consent';
    const ACTION_GROUP = 'fullmetrix';
    const JOB_TIMEOUT = 3;
    const RETRY_DELAY = 330;
    const MAX_RETRIES = 24;

    public static function register_jobs() {
        add_action(self::ACTION_HOOK, array(__CLASS__, 'process_scheduled'), 10, 2);
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

    public static function init() {
        add_action('woocommerce_after_checkout_billing_form', array(__CLASS__, 'render_field'));
        add_action('woocommerce_checkout_update_order_meta', array(__CLASS__, 'save_consent'), 10, 2);
    }

    public static function get_config() {
        if (!Fullmetrix_Connector::feature_enabled('consent')) {
            return null;
        }

        $config = Fullmetrix_Connector::get_cached_config();
        if (!is_array($config) || empty($config['checkoutConsent'])) {
            return null;
        }
        $cc = $config['checkoutConsent'];
        if (empty($cc['label'])) {
            return null;
        }
        return $cc;
    }

    public static function render_field($checkout) {
        $cfg = self::get_config();
        if (!$cfg) {
            return;
        }

        $default_checked = !empty($cfg['defaultChecked']);
        $current = $checkout->get_value(self::FIELD_NAME);
        $value = $current === null || $current === '' ? ($default_checked ? 1 : 0) : intval($current);

        woocommerce_form_field(
            self::FIELD_NAME,
            array(
                'type' => 'checkbox',
                'class' => array('fullmetrix-checkout-consent'),
                'label' => $cfg['label'],
                'required' => false,
                'default' => $default_checked ? 1 : 0,
            ),
            $value
        );
    }

    public static function save_consent($order_id, $data = array()) {
        try {
            self::save_classic_consent($order_id);
        } catch (\Throwable $e) {
            return;
        }
    }

    private static function save_classic_consent($order_id) {
        $cfg = self::get_config();
        if (!$cfg) {
            return;
        }

        // phpcs:disable WordPress.Security.NonceVerification.Missing
        $consent = isset($_POST[self::FIELD_NAME]) && !empty($_POST[self::FIELD_NAME]);
        // phpcs:enable

        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $order->update_meta_data(self::ORDER_META_KEY, $consent ? 'yes' : 'no');
        $order->update_meta_data(self::CHANNELS_META_KEY, is_array($cfg['channels']) ? implode(',', $cfg['channels']) : '');
        $order->save();

        self::forward_order_consent($order);
    }

    public static function forward_order_consent($order) {
        $email = $order->get_billing_email();
        $phone = $order->get_billing_phone();
        if (empty($email) && empty($phone)) {
            return;
        }

        if (self::schedule((int) $order->get_id(), 0, 0)) {
            return;
        }

        $payload = self::order_payload($order);
        if ($payload === null) {
            return;
        }
        $request = self::signed_request($payload);
        if ($request === null) {
            return;
        }
        Fullmetrix_Http::post_after_response($request[0], $request[1]);
    }

    public static function process_scheduled($order_id, $attempt = 0) {
        try {
            $order = wc_get_order((int) $order_id);
            if (!$order) {
                return;
            }
            $payload = self::order_payload($order);
            if ($payload === null) {
                return;
            }
            $request = self::signed_request($payload);
            if ($request === null) {
                return;
            }
            $response = Fullmetrix_Http::request('POST', $request[0], $request[1], self::JOB_TIMEOUT, Fullmetrix_Http::JOBS);
            $status = ($response === null || is_wp_error($response)) ? 0 : (int) wp_remote_retrieve_response_code($response);
            $delivered = $status >= 200 && $status < 500 && $status !== 429;
            if (!$delivered && (int) $attempt < self::MAX_RETRIES) {
                self::schedule((int) $order_id, (int) $attempt + 1, self::RETRY_DELAY);
            }
        } catch (\Throwable $e) {
            return;
        }
    }

    private static function schedule($order_id, $attempt, $delay) {
        if ($order_id <= 0 || !function_exists('as_enqueue_async_action') || !did_action('init')) {
            return false;
        }
        try {
            $args = array($order_id, $attempt);
            $action_id = $delay > 0
                ? as_schedule_single_action(time() + $delay, self::ACTION_HOOK, $args, self::ACTION_GROUP)
                : as_enqueue_async_action(self::ACTION_HOOK, $args, self::ACTION_GROUP);
        } catch (\Throwable $e) {
            return false;
        }

        return (int) $action_id > 0;
    }

    private static function order_payload($order) {
        $consent = $order->get_meta(self::ORDER_META_KEY);
        if ($consent !== 'yes' && $consent !== 'no') {
            return null;
        }
        $email = $order->get_billing_email();
        $phone = $order->get_billing_phone();
        if (empty($email) && empty($phone)) {
            return null;
        }
        $code = get_option('fullmetrix_connection_code', '');
        if (empty($code)) {
            return null;
        }
        $channels = array_values(array_filter(explode(',', (string) $order->get_meta(self::CHANNELS_META_KEY))));

        $payload = array(
            'key' => $code,
            'consent' => $consent === 'yes',
            'channels' => $channels,
            'orderId' => (string) $order->get_id(),
            'pageUrl' => home_url('/'),
        );
        if (!empty($email)) {
            $payload['email'] = $email;
        }
        if (!empty($phone)) {
            $payload['phone'] = $phone;
        }

        return $payload;
    }

    private static function signed_request($payload) {
        $body = wp_json_encode($payload);
        if ($body === false) {
            return null;
        }
        $headers = array('Content-Type' => 'application/json');
        $secret = get_option('fullmetrix_connection_secret', '');
        if (!empty($secret)) {
            $headers = array_merge($headers, Fullmetrix_Security::create_signed_headers($secret, $payload['key'], $body));
        }
        $api_origin = rtrim(str_replace('/api/plugin', '', FULLMETRIX_API_BASE), '/');

        return array($api_origin . '/api/checkout-consent', array(
            'headers' => $headers,
            'body' => $body,
            'sslverify' => true,
        ));
    }

}
