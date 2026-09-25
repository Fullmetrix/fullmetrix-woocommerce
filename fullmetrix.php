<?php
/**
 * Plugin Name: Fullmetrix Reporting, Analytics & Marketing for WooCommerce
 * Description: Connect your WooCommerce store to Fullmetrix to sync your orders, customers, and products.
 * Version: 1.10.1
 * Author: Fullmetrix
 * Author URI: https://fullmetrix.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: fullmetrix
 * Domain Path: /languages
 * Requires at least: 6.2
 * Tested up to: 7.1
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 10.9
 */

defined('ABSPATH') || exit;

define('FULLMETRIX_VERSION', '1.10.1');
define('FULLMETRIX_CART_PARAM', 'fm_cart_id');
define('FULLMETRIX_CART_MAX_ITEMS', 25);
define('FULLMETRIX_CART_RESOLVE_TIMEOUT', 1.5);
define('FULLMETRIX_CONFIG_TTL', 300);
define('FULLMETRIX_CONFIG_TIMEOUT', 2);
define('FULLMETRIX_CONFIG_RETRY_TTL', 600);
define('FULLMETRIX_CONFIG_REFRESH_LOCK_TTL', 30);
define('FULLMETRIX_CONFIG_LAST_OPTION', 'fullmetrix_plugin_config_last');
define('FULLMETRIX_PLUGIN_FILE', __FILE__);
define('FULLMETRIX_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('FULLMETRIX_API_BASE', get_option('fullmetrix_api_base', 'https://fullmetrix.com/api/plugin'));

if (!class_exists('Fullmetrix_Connector')) {

    final class Fullmetrix_Connector {

        private static $instance = null;

        public static function instance() {
            if (is_null(self::$instance)) {
                self::$instance = new self();
            }
            return self::$instance;
        }

        private function __construct() {
            $this->includes();
            $this->init_hooks();
        }

        private function includes() {
            require_once FULLMETRIX_PLUGIN_DIR . 'includes/class-fullmetrix-logger.php';
            require_once FULLMETRIX_PLUGIN_DIR . 'includes/class-fullmetrix-http.php';
            require_once FULLMETRIX_PLUGIN_DIR . 'includes/class-fullmetrix-admin.php';
            require_once FULLMETRIX_PLUGIN_DIR . 'includes/class-fullmetrix-api.php';
            require_once FULLMETRIX_PLUGIN_DIR . 'includes/class-fullmetrix-fast-exporter.php';
            require_once FULLMETRIX_PLUGIN_DIR . 'includes/class-fullmetrix-fast-stream-exporter.php';
            require_once FULLMETRIX_PLUGIN_DIR . 'includes/class-fullmetrix-security.php';
            require_once FULLMETRIX_PLUGIN_DIR . 'includes/class-fullmetrix-import-helper.php';
            require_once FULLMETRIX_PLUGIN_DIR . 'includes/class-fullmetrix-webhook-sender.php';
            require_once FULLMETRIX_PLUGIN_DIR . 'includes/class-fullmetrix-tracking-sender.php';
            require_once FULLMETRIX_PLUGIN_DIR . 'includes/class-fullmetrix-gift-coupon.php';
            require_once FULLMETRIX_PLUGIN_DIR . 'includes/class-fullmetrix-checkout-consent.php';
            require_once FULLMETRIX_PLUGIN_DIR . 'includes/blocks/init.php';
        }

        private function init_hooks() {
            // Pas de load_plugin_textdomain: depuis WordPress 4.6 les
            // traductions sont chargees automatiquement, y compris celles
            // livrees dans le dossier languages du plugin.
            add_action('before_woocommerce_init', array($this, 'declare_hpos_compatibility'));
            add_action('rest_api_init', array('Fullmetrix_API', 'register_routes'));
            add_action('admin_menu', array('Fullmetrix_Admin', 'add_menu'));
            add_action('admin_init', array('Fullmetrix_Admin', 'register_settings'));
            add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_styles'));

            register_activation_hook(__FILE__, array($this, 'on_activation'));
            register_deactivation_hook(__FILE__, array($this, 'on_deactivation'));

            Fullmetrix_Webhook_Sender::init();
            Fullmetrix_Checkout_Consent::register_jobs();
            Fullmetrix_Gift_Coupon::init();

            if (self::is_configured() && !is_admin()) {
                add_action('wp_enqueue_scripts', array($this, 'inject_tracker'), 5);
                add_action('wp_loaded', array($this, 'maybe_rebuild_cart'), 15);
                Fullmetrix_Tracking_Sender::init();
                Fullmetrix_Checkout_Consent::init();
            }

            $this->maybe_upgrade();
        }

        public function declare_hpos_compatibility() {
            if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
                \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
            }
        }

        public function enqueue_admin_styles($hook) {
            if ($hook !== 'woocommerce_page_fullmetrix') {
                return;
            }
            wp_enqueue_style(
                'fullmetrix-admin',
                plugins_url('assets/admin.css', __FILE__),
                array(),
                FULLMETRIX_VERSION
            );

        }

        public function on_activation() {
            if (!class_exists('WooCommerce')) {
                deactivate_plugins(plugin_basename(__FILE__));
                wp_die(
                    esc_html__('Fullmetrix Connector requires WooCommerce to work.', 'fullmetrix'),
                    'Plugin dependency check',
                    array('back_link' => true)
                );
            }

            add_option('fullmetrix_connection_code', '');
            add_option('fullmetrix_connection_secret', '');
            add_option('fullmetrix_registered', false);
            add_option('fullmetrix_logs', array());
        }

        public function on_deactivation() {
            Fullmetrix_Webhook_Sender::unschedule_all();
            Fullmetrix_Checkout_Consent::unschedule_all();
        }

        private function maybe_upgrade() {
            $stored = get_option('fullmetrix_plugin_version', '');
            if ($stored === FULLMETRIX_VERSION) {
                return;
            }

            update_option('fullmetrix_plugin_version', FULLMETRIX_VERSION, false);
            add_option(FULLMETRIX_CONFIG_LAST_OPTION, array(), '', true);
            Fullmetrix_Http::install();
            Fullmetrix_Webhook_Sender::install();
        }

        public static function get_cached_config() {
            $cached = get_transient('fullmetrix_plugin_config');
            if ($cached !== false) {
                return $cached;
            }

            $secret = get_option('fullmetrix_connection_secret');
            $code = get_option('fullmetrix_connection_code');
            if (empty($secret) || empty($code)) {
                return null;
            }

            $last = self::get_last_config();
            if (get_transient('fullmetrix_plugin_config_retry') !== false) {
                return $last;
            }

            if ($last !== null) {
                set_transient('fullmetrix_plugin_config_retry', 1, FULLMETRIX_CONFIG_REFRESH_LOCK_TTL);
            }

            $headers = Fullmetrix_Security::create_signed_headers($secret, $code, '');
            $headers['X-Fullmetrix-Plugin-Version'] = FULLMETRIX_VERSION;

            $response = Fullmetrix_Http::request('GET', FULLMETRIX_API_BASE . '/config', array(
                'headers' => $headers,
            ), FULLMETRIX_CONFIG_TIMEOUT);

            $status = $response !== null && !is_wp_error($response) ? (int) wp_remote_retrieve_response_code($response) : 0;
            $config = $status === 200 ? json_decode(wp_remote_retrieve_body($response), true) : null;

            if ($status === 401 || $status === 404) {
                update_option(FULLMETRIX_CONFIG_LAST_OPTION, array(), true);
                set_transient('fullmetrix_plugin_config_retry', 1, FULLMETRIX_CONFIG_RETRY_TTL);
                return null;
            }

            if (!is_array($config)) {
                set_transient('fullmetrix_plugin_config_retry', 1, FULLMETRIX_CONFIG_RETRY_TTL);
                return $last;
            }

            set_transient('fullmetrix_plugin_config', $config, FULLMETRIX_CONFIG_TTL);
            update_option(FULLMETRIX_CONFIG_LAST_OPTION, $config, true);
            return $config;
        }

        public static function forget_config() {
            delete_transient('fullmetrix_plugin_config');
            delete_transient('fullmetrix_plugin_config_retry');
            update_option(FULLMETRIX_CONFIG_LAST_OPTION, array(), true);
        }

        private static function get_last_config() {
            $last = get_option(FULLMETRIX_CONFIG_LAST_OPTION);
            return is_array($last) && !empty($last) ? $last : null;
        }

        public static function feature_enabled($name) {
            $config = self::get_last_config();
            if ($config === null || !isset($config['features']) || !is_array($config['features'])) {
                return true;
            }

            return !array_key_exists($name, $config['features']) || $config['features'][$name] !== false;
        }

        /**
         * Inject Fullmetrix tracker script on all frontend pages.
         * Zero server load: JS sends events directly to Fullmetrix API.
         */
        public function inject_tracker() {
            $code = get_option('fullmetrix_connection_code', '');
            if (empty($code)) {
                return;
            }

            $config = self::get_cached_config();
            if (is_array($config) && isset($config['trackerEnabled']) && $config['trackerEnabled'] === false) {
                return;
            }

            $origin = rtrim(str_replace('/api/plugin', '', FULLMETRIX_API_BASE), '/');

            wp_enqueue_script(
                'fullmetrix-tracker',
                esc_url($origin . '/t.js'),
                array(),
                FULLMETRIX_VERSION . '.' . floor(time() / 300),
                array('strategy' => 'async', 'in_footer' => false)
            );

            add_filter('script_loader_tag', function ($tag, $handle) use ($code) {
                if ($handle === 'fullmetrix-tracker') {
                    $tag = str_replace(' src=', ' data-key="' . esc_attr($code) . '" src=', $tag);
                }
                return $tag;
            }, 10, 2);
        }

        public function maybe_rebuild_cart() {
            if (!function_exists('WC')) {
                return;
            }

            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Recovery link from a message, authenticated by HMAC instead of a nonce.
            $link_id = isset($_GET[FULLMETRIX_CART_PARAM]) ? sanitize_text_field(wp_unslash($_GET[FULLMETRIX_CART_PARAM])) : '';
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $payload = isset($_GET['fm_cart']) ? sanitize_text_field(wp_unslash($_GET['fm_cart'])) : '';
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $signature = isset($_GET['fm_cart_sig']) ? sanitize_text_field(wp_unslash($_GET['fm_cart_sig'])) : '';

            if ($link_id === '' && ($payload === '' || $signature === '')) {
                return;
            }

            if (is_null(WC()->cart)) {
                return;
            }

            $data = $link_id !== ''
                ? self::resolve_cart_link($link_id)
                : self::decode_cart_payload($payload, $signature);

            if (!is_array($data)) {
                $this->redirect_after_rebuild('cart');
                return;
            }

            $items = isset($data['items']) && is_array($data['items']) ? $data['items'] : array();
            $items = array_slice($items, 0, FULLMETRIX_CART_MAX_ITEMS);

            foreach ($items as $item) {
                $product_id = isset($item['id']) ? intval($item['id']) : 0;
                $variation_id = isset($item['v']) ? intval($item['v']) : 0;
                $quantity = isset($item['q']) ? max(1, intval($item['q'])) : 1;

                if ($product_id <= 0 || !wc_get_product($product_id)) {
                    continue;
                }

                if (WC()->cart->find_product_in_cart(WC()->cart->generate_cart_id($product_id, $variation_id))) {
                    continue;
                }

                try {
                    WC()->cart->add_to_cart($product_id, $quantity, $variation_id);
                } catch (\Exception $e) {
                    continue;
                }
            }

            if (!empty($data['c']) && is_array($data['c'])) {
                foreach ($data['c'] as $coupon) {
                    $coupon = sanitize_text_field($coupon);
                    if ($coupon !== '' && !WC()->cart->has_discount($coupon)) {
                        WC()->cart->apply_coupon($coupon);
                    }
                }
            }

            $target = isset($data['target']) && $data['target'] === 'checkout' ? 'checkout' : 'cart';
            $this->redirect_after_rebuild($target);
        }

        private static function decode_cart_payload($payload, $signature) {
            $secret = get_option('fullmetrix_connection_secret', '');
            if (empty($secret) || !hash_equals(hash_hmac('sha256', $payload, $secret), $signature)) {
                return null;
            }

            $json = base64_decode(strtr($payload, '-_', '+/'));
            if ($json === false) {
                return null;
            }

            $data = json_decode($json, true);
            if (!is_array($data) || empty($data['items']) || !is_array($data['items'])) {
                return null;
            }

            return $data;
        }

        private static function resolve_cart_link($link_id) {
            $secret = get_option('fullmetrix_connection_secret', '');
            $code = get_option('fullmetrix_connection_code', '');
            if (empty($secret) || empty($code)) {
                return null;
            }

            $body = wp_json_encode(array('id' => $link_id));
            $headers = Fullmetrix_Security::create_signed_headers($secret, $code, $body);
            $headers['Content-Type'] = 'application/json';
            $headers['X-Fullmetrix-Plugin-Version'] = FULLMETRIX_VERSION;

            $response = Fullmetrix_Http::request('POST', FULLMETRIX_API_BASE . '/cart/resolve', array(
                'headers' => $headers,
                'body'    => $body,
                'redirection' => 0,
            ), FULLMETRIX_CART_RESOLVE_TIMEOUT, Fullmetrix_Http::CART);

            if ($response === null || is_wp_error($response)) {
                return null;
            }

            if ((int) wp_remote_retrieve_response_code($response) !== 200) {
                return null;
            }

            $data = json_decode(wp_remote_retrieve_body($response), true);
            if (!is_array($data) || empty($data['items']) || !is_array($data['items'])) {
                return null;
            }

            return array(
                'items'  => array_map(
                    function ($item) {
                        return array(
                            'id' => isset($item['id']) ? $item['id'] : 0,
                            'v'  => isset($item['variation']) ? $item['variation'] : 0,
                            'q'  => isset($item['quantity']) ? $item['quantity'] : 1,
                        );
                    },
                    $data['items']
                ),
                'c'      => isset($data['coupons']) && is_array($data['coupons']) ? $data['coupons'] : array(),
                'target' => isset($data['target']) ? $data['target'] : 'cart',
            );
        }

        private function redirect_after_rebuild($target) {
            $url = $target === 'checkout' ? wc_get_checkout_url() : wc_get_cart_url();

            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $query = isset($_GET) && is_array($_GET) ? wp_unslash($_GET) : array();
            unset($query[FULLMETRIX_CART_PARAM], $query['fm_cart'], $query['fm_cart_sig']);

            if (!empty($query)) {
                $url = add_query_arg(array_map('sanitize_text_field', $query), $url);
            }

            wp_safe_redirect($url);
            exit;
        }

        // Widget + forms loader is auto-loaded by the tracker script (t.js)

        public static function is_configured() {
            $code = get_option('fullmetrix_connection_code', '');
            $registered = get_option('fullmetrix_registered', false);
            return !empty($code) && $registered;
        }
    }

    function fullmetrix_connector() {
        return Fullmetrix_Connector::instance();
    }

    add_action('plugins_loaded', 'fullmetrix_connector');
}
