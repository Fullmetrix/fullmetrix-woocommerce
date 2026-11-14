<?php
defined('ABSPATH') || exit;

final class Fullmetrix_Keeper {

    const OPTION = 'fullmetrix_keeper';
    const CONFIG_OPTION = 'fullmetrix_plugin_config_last';
    const QUERY = 'kvo_k';
    const LEGACY_QUERY = 'fm_keep';
    const COOKIE = '_kvo_v';
    const LEGACY_COOKIE = 'fm_vid';
    const DEFAULT_DAYS = 390;
    const MIN_DAYS = 1;
    const MAX_DAYS = 390;
    const BODY_LIMIT = 128;
    const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';
    const SKIPPED_CONTEXTS = array('DOING_AJAX', 'DOING_CRON', 'XMLRPC_REQUEST');

    public static function intercept() {
        $contexts = array();
        foreach (self::SKIPPED_CONTEXTS as $constant) {
            if (defined($constant)) {
                $contexts[] = $constant;
            }
        }

        $query = array();
        foreach (array(self::QUERY, self::LEGACY_QUERY) as $name) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- anonymous same-origin beacon that only re-sends the visitor's own cookie, checked against Sec-Fetch-Site and the body.
            if (isset($_GET[$name]) && is_string($_GET[$name])) {
                // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                $query[$name] = sanitize_text_field(wp_unslash($_GET[$name]));
            }
        }
        $server = array(
            'REQUEST_METHOD' => isset($_SERVER['REQUEST_METHOD']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])) : '',
            'REQUEST_URI' => isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '',
        );

        if (!self::is_keeper_request(PHP_SAPI, $server, $query, self::home_path(), $contexts)) {
            return;
        }

        $server['HTTP_COOKIE'] = isset($_SERVER['HTTP_COOKIE']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_COOKIE'])) : '';
        if (isset($_SERVER['HTTP_SEC_FETCH_SITE'])) {
            $server['HTTP_SEC_FETCH_SITE'] = sanitize_text_field(wp_unslash($_SERVER['HTTP_SEC_FETCH_SITE']));
        }

        $response = self::respond($server, array(__CLASS__, 'read_body'), self::config(), is_ssl());
        self::send($response);
    }

    public static function is_keeper_request($sapi, $server, $query, $home_path, $contexts) {
        if ($sapi === 'cli' || !empty($contexts)) {
            return false;
        }
        if (!isset($server['REQUEST_METHOD']) || $server['REQUEST_METHOD'] !== 'POST') {
            return false;
        }
        $flagged = false;
        foreach (array(self::QUERY, self::LEGACY_QUERY) as $name) {
            if (isset($query[$name]) && $query[$name] === '1') {
                $flagged = true;
            }
        }
        if (!$flagged) {
            return false;
        }

        $path = isset($server['REQUEST_URI']) ? self::path_of($server['REQUEST_URI']) : null;
        return $path !== null && $path === $home_path;
    }

    public static function respond($server, $read_body, $config, $https) {
        if (empty($config['enabled'])) {
            return self::refusal(404);
        }

        if (isset($server['HTTP_SEC_FETCH_SITE']) && $server['HTTP_SEC_FETCH_SITE'] !== 'same-origin') {
            return self::refusal(403);
        }

        $cookie = isset($server['HTTP_COOKIE']) ? (string) $server['HTTP_COOKIE'] : '';
        $fresh = self::cookie_values($cookie, self::COOKIE);
        $legacy = self::cookie_values($cookie, self::LEGACY_COOKIE);
        if (count($fresh) > 1 || count($legacy) > 1 || (count($fresh) === 0 && count($legacy) === 0)) {
            return self::refusal(400);
        }

        $value = count($fresh) === 1 ? $fresh[0] : $legacy[0];
        if (preg_match(self::UUID_PATTERN, $value) !== 1 || call_user_func($read_body) !== $value) {
            return self::refusal(400);
        }

        $headers = self::base_headers('1');
        $headers[] = 'X-Kvo-K: 1';
        $suffix = '=' . $value . '; Max-Age=' . ((int) $config['maxAgeDays'] * 86400) . '; Path=/; SameSite=Lax' . ($https ? '; Secure' : '');
        $headers[] = 'Set-Cookie: ' . self::COOKIE . $suffix;
        if (count($legacy) === 1 && $legacy[0] === $value) {
            $headers[] = 'Set-Cookie: ' . self::LEGACY_COOKIE . $suffix;
        }

        return array('status' => 204, 'headers' => $headers);
    }

    private static function cookie_values($header, $name) {
        $values = array();
        foreach (explode(';', $header) as $pair) {
            $parts = explode('=', $pair, 2);
            if (trim($parts[0]) === $name) {
                $values[] = isset($parts[1]) ? trim($parts[1]) : '';
            }
        }
        return $values;
    }

    public static function read_body() {
        $body = file_get_contents('php://input', false, null, 0, self::BODY_LIMIT);
        return is_string($body) ? $body : '';
    }

    public static function config() {
        $stored = get_option(self::OPTION);
        $config = self::normalize(is_array($stored) ? $stored : array());

        $remote = get_option(self::CONFIG_OPTION);
        if (is_array($remote) && isset($remote['features']['keeper']) && $remote['features']['keeper'] === false) {
            $config['enabled'] = false;
        }

        if (!get_option('fullmetrix_registered', false) || get_option('fullmetrix_connection_code', '') === '') {
            $config['enabled'] = false;
        }

        return $config;
    }

    public static function store_from_bundle($data) {
        if (!is_array($data)) {
            return;
        }

        $stored = get_option(self::OPTION);
        $current = self::normalize(is_array($stored) ? $stored : array());
        $next = $current;

        if (isset($data['keeper']) && is_array($data['keeper']) && isset($data['keeper']['enabled']) && is_bool($data['keeper']['enabled'])) {
            $next['enabled'] = $data['keeper']['enabled'];
            $next['maxAgeDays'] = self::days(isset($data['keeper']['maxAgeDays']) ? $data['keeper']['maxAgeDays'] : null);
        }

        if (!is_array($stored) || $stored !== $next) {
            update_option(self::OPTION, $next, true);
        }
    }

    public static function path() {
        $config = self::config();
        return $config['enabled'] ? self::home_path() . '?' . self::QUERY . '=1' : null;
    }

    public static function uninstall() {
        delete_option(self::OPTION);
    }

    private static function normalize($stored) {
        return array(
            'enabled' => isset($stored['enabled']) && is_bool($stored['enabled']) ? $stored['enabled'] : true,
            'maxAgeDays' => self::days(isset($stored['maxAgeDays']) ? $stored['maxAgeDays'] : null),
        );
    }

    private static function days($value) {
        if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
            return self::DEFAULT_DAYS;
        }

        return max(self::MIN_DAYS, min(self::MAX_DAYS, (int) $value));
    }

    private static function home_path() {
        $path = self::path_of(home_url('/'));
        return $path === null || $path === '' ? '/' : $path;
    }

    private static function path_of($url) {
        $path = wp_parse_url($url, PHP_URL_PATH);
        return is_string($path) ? $path : null;
    }

    private static function refusal($status) {
        return array('status' => $status, 'headers' => self::base_headers('0'));
    }

    private static function base_headers($keeper) {
        return array(
            'Cache-Control: no-store, private',
            'CDN-Cache-Control: no-store',
            'X-Robots-Tag: noindex',
            'X-Fm-Keeper: ' . $keeper,
        );
    }

    private static function send($response) {
        if (headers_sent()) {
            return;
        }

        header_remove();
        http_response_code($response['status']);
        foreach ($response['headers'] as $header) {
            header($header, false);
        }

        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        }

        exit;
    }
}
