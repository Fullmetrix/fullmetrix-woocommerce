<?php
defined('ABSPATH') || exit;

final class Fullmetrix_Keeper {

    const OPTION = 'fullmetrix_keeper';
    const CONFIG_OPTION = 'fullmetrix_plugin_config_last';
    const QUERY = 'fm_keep';
    const COOKIE = 'fm_vid';
    const DEFAULT_DAYS = 390;
    const MIN_DAYS = 1;
    const MAX_DAYS = 390;
    const BODY_LIMIT = 128;
    const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';
    const VALUE_PATTERN = '#^[A-Za-z0-9 _./-]{0,64}$#';
    const ATTRIBUTES = array('type', 'data-cookieconsent', 'data-category', 'data-cookiescript', 'data-cmp-ab');
    const SKIPPED_CONTEXTS = array('DOING_AJAX', 'DOING_CRON', 'XMLRPC_REQUEST');

    public static function intercept() {
        $contexts = array();
        foreach (self::SKIPPED_CONTEXTS as $constant) {
            if (defined($constant)) {
                $contexts[] = $constant;
            }
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- anonymous same-origin beacon that only re-sends the visitor's own cookie, checked against Sec-Fetch-Site and the body.
        $query = isset($_GET[self::QUERY]) && is_string($_GET[self::QUERY]) ? array(self::QUERY => sanitize_text_field(wp_unslash($_GET[self::QUERY]))) : array();
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
        if (!isset($query[self::QUERY]) || $query[self::QUERY] !== '1') {
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
        if (preg_match_all('/(?:^|;)\s*' . self::COOKIE . '=([^;]*)/', $cookie, $matches) !== 1) {
            return self::refusal(400);
        }

        $value = $matches[1][0];
        if (preg_match(self::UUID_PATTERN, $value) !== 1 || call_user_func($read_body) !== $value) {
            return self::refusal(400);
        }

        $headers = self::base_headers('1');
        $headers[] = 'Set-Cookie: ' . self::COOKIE . '=' . $value . '; Max-Age=' . ((int) $config['maxAgeDays'] * 86400) . '; Path=/; SameSite=Lax' . ($https ? '; Secure' : '');

        return array('status' => 204, 'headers' => $headers);
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

        if (isset($data['tagAttributes']) && is_array($data['tagAttributes'])) {
            $next['tagAttributes'] = self::attributes($data['tagAttributes']);
        }

        if ($next !== $current || !is_array($stored)) {
            update_option(self::OPTION, $next, true);
        }
    }

    public static function path() {
        $config = self::config();
        return $config['enabled'] ? self::home_path() . '?' . self::QUERY . '=1' : null;
    }

    public static function tag_attributes() {
        $config = self::config();
        $path = self::path();
        $attributes = $path !== null ? ' data-fm-keeper="' . esc_attr($path) . '"' : '';

        foreach ($config['tagAttributes'] as $name => $value) {
            $attributes .= ' ' . $name . '="' . esc_attr($value) . '"';
        }

        return $attributes;
    }

    public static function prepare_tag($tag) {
        $config = self::config();
        if (!isset($config['tagAttributes']['type'])) {
            return $tag;
        }

        return preg_replace('/\stype=(["\'])[^"\']*\1/', '', $tag, 1);
    }

    public static function uninstall() {
        delete_option(self::OPTION);
    }

    private static function normalize($stored) {
        return array(
            'enabled' => isset($stored['enabled']) && is_bool($stored['enabled']) ? $stored['enabled'] : true,
            'maxAgeDays' => self::days(isset($stored['maxAgeDays']) ? $stored['maxAgeDays'] : null),
            'tagAttributes' => self::attributes(isset($stored['tagAttributes']) && is_array($stored['tagAttributes']) ? $stored['tagAttributes'] : array()),
        );
    }

    private static function days($value) {
        if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
            return self::DEFAULT_DAYS;
        }

        return max(self::MIN_DAYS, min(self::MAX_DAYS, (int) $value));
    }

    private static function attributes($candidates) {
        $attributes = array();
        foreach (self::ATTRIBUTES as $name) {
            if (isset($candidates[$name]) && is_string($candidates[$name]) && preg_match(self::VALUE_PATTERN, $candidates[$name]) === 1) {
                $attributes[$name] = $candidates[$name];
            }
        }
        return $attributes;
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
