<?php
defined('ABSPATH') || exit;

class Fullmetrix_Signature {

    const TOLERANCE_MS = 300000;
    const NONCE_PREFIX = 'fullmetrix_nonce_';
    const NONCE_TTL = 600;
    const NONCE_PURGE_BATCH = 50;
    const NONCE_STORED = 'stored';
    const NONCE_REPLAYED = 'replayed';
    const NONCE_FAILED = 'failed';
    const V1_OPTION = 'fullmetrix_signature_v1';
    const OPERATION_PARAM = 'fm_op';
    const HEADER_VERSION = 'X-Fullmetrix-Sig-Version';
    const HEADER_CODE = 'X-Fullmetrix-Connection-Code';
    const HEADER_TIMESTAMP = 'X-Fullmetrix-Timestamp';
    const HEADER_NONCE = 'X-Fullmetrix-Nonce';
    const HEADER_SIGNATURE = 'X-Fullmetrix-Signature';
    const HEADER_BODY_SIGNATURE = 'X-Fullmetrix-Body-Signature';

    private static $excluded_params = array('fc', 'module', 'controller', 'isolang', 'id_lang', 'rest_route');

    public static function raw_query_string() {
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- the signature covers the exact bytes, sanitizing would change them
        $uri = isset($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '';
        $pos = strpos($uri, '?');
        if ($pos !== false) {
            return (string) substr($uri, $pos + 1);
        }
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- the signature covers the exact bytes, sanitizing would change them
        return isset($_SERVER['QUERY_STRING']) && is_string($_SERVER['QUERY_STRING']) ? wp_unslash($_SERVER['QUERY_STRING']) : '';
    }

    public static function query_pairs($query) {
        $query = (string) $query;
        if ($query !== '' && $query[0] === '?') {
            $query = (string) substr($query, 1);
        }
        $pairs = array();
        foreach (explode('&', $query) as $segment) {
            if ($segment === '') {
                continue;
            }
            $eq = strpos($segment, '=');
            $name = $eq === false ? $segment : substr($segment, 0, $eq);
            $value = $eq === false ? '' : substr($segment, $eq + 1);
            $pairs[] = array(urldecode($name), urldecode((string) $value));
        }
        return $pairs;
    }

    public static function query_value($query, $name) {
        foreach (self::query_pairs($query) as $pair) {
            if ($pair[0] === $name) {
                return $pair[1];
            }
        }
        return null;
    }

    public static function has_duplicate_names($query) {
        $seen = array();
        foreach (self::query_pairs($query) as $pair) {
            $bracket = strpos($pair[0], '[');
            $key = strtr($bracket === false ? $pair[0] : substr($pair[0], 0, $bracket), array('.' => '_', ' ' => '_'));
            if (isset($seen[$key])) {
                return true;
            }
            $seen[$key] = true;
        }
        return false;
    }

    public static function canonical_query($query) {
        $encoded = array();
        foreach (self::query_pairs($query) as $pair) {
            if (in_array($pair[0], self::$excluded_params, true)) {
                continue;
            }
            $encoded[] = array(rawurlencode($pair[0]), rawurlencode($pair[1]));
        }
        usort($encoded, array(__CLASS__, 'compare_pairs'));
        $parts = array();
        foreach ($encoded as $pair) {
            $parts[] = $pair[0] . '=' . $pair[1];
        }
        return implode('&', $parts);
    }

    public static function compare_pairs($a, $b) {
        $by_name = strcmp($a[0], $b[0]);
        return $by_name !== 0 ? $by_name : strcmp($a[1], $b[1]);
    }

    public static function string_to_sign($method, $query, $timestamp, $nonce, $body) {
        return "v2\n" . strtoupper((string) $method) . "\n" . self::canonical_query($query) . "\n"
            . $timestamp . "\n" . $nonce . "\n" . hash('sha256', (string) $body);
    }

    public static function sign_request($secret, $method, $query, $timestamp, $nonce, $body) {
        return hash_hmac('sha256', self::string_to_sign($method, $query, $timestamp, $nonce, $body), (string) $secret);
    }

    public static function sign_response($secret, $request_nonce, $timestamp, $body) {
        return hash_hmac('sha256', $request_nonce . '.' . $timestamp . '.' . $body, (string) $secret);
    }

    public static function now_ms() {
        return sprintf('%.0f', floor(microtime(true) * 1000));
    }

    public static function verify_request($secret, $code, $headers, $method, $query, $body, $operation, $now_ms = null) {
        if (empty($secret) || empty($code)) {
            return self::verdict(false, 'not_configured', null);
        }
        if (self::pick($headers, 'version') !== '2') {
            return self::verdict(false, 'version', null);
        }
        $received_code = self::pick($headers, 'code');
        $timestamp = self::pick($headers, 'timestamp');
        $nonce = self::pick($headers, 'nonce');
        $signature = self::pick($headers, 'signature');
        if ($received_code === null || !hash_equals((string) $code, $received_code)) {
            return self::verdict(false, 'code', null);
        }
        if ($timestamp === null || !preg_match('/^\d{1,16}$/', $timestamp)
            || $nonce === null || !preg_match('/^[a-f0-9]{32}$/', $nonce)
            || $signature === null || !preg_match('/^[a-f0-9]{64}$/', $signature)) {
            return self::verdict(false, 'format', null);
        }
        if (self::has_duplicate_names($query)) {
            return self::verdict(false, 'format', null);
        }
        $now = $now_ms === null ? (float) self::now_ms() : (float) $now_ms;
        if (abs($now - (float) $timestamp) > self::TOLERANCE_MS) {
            return self::verdict(false, 'expired', $nonce);
        }
        if (!hash_equals(self::sign_request($secret, $method, $query, $timestamp, $nonce, $body), $signature)) {
            return self::verdict(false, 'signature', $nonce);
        }
        if (self::query_value($query, self::OPERATION_PARAM) !== (string) $operation) {
            return self::verdict(false, 'operation', $nonce);
        }
        return self::verdict(true, null, $nonce);
    }

    public static function verify_response($secret, $request_nonce, $timestamp, $body_signature, $body) {
        if (!is_string($timestamp) || !preg_match('/^\d{1,16}$/', $timestamp)) {
            return false;
        }
        if (!is_string($body_signature) || !preg_match('/^[a-f0-9]{64}$/', $body_signature)) {
            return false;
        }
        return hash_equals(self::sign_response($secret, $request_nonce, $timestamp, (string) $body), $body_signature);
    }

    public static function response_headers($secret, $request_nonce, $body) {
        $timestamp = self::now_ms();
        return array(
            self::HEADER_TIMESTAMP => $timestamp,
            self::HEADER_BODY_SIGNATURE => self::sign_response($secret, $request_nonce, $timestamp, $body),
        );
    }

    public static function remember_nonce($nonce) {
        self::purge_nonces();
        $name = self::NONCE_PREFIX . $nonce;
        if (add_option($name, (string) time(), '', 'no')) {
            return self::NONCE_STORED;
        }
        return get_option($name, false) !== false ? self::NONCE_REPLAYED : self::NONCE_FAILED;
    }

    private static function purge_nonces() {
        global $wpdb;
        if (!is_object($wpdb)) {
            return;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- bounded cleanup of expired single-use nonces
        $expired = $wpdb->get_col($wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d LIMIT %d",
            $wpdb->esc_like(self::NONCE_PREFIX) . '%',
            time() - self::NONCE_TTL,
            self::NONCE_PURGE_BATCH
        ));
        if (!is_array($expired)) {
            return;
        }
        foreach ($expired as $name) {
            delete_option($name);
        }
    }

    public static function v1_allowed() {
        return get_option(self::V1_OPTION, '') === '1';
    }

    private static function pick($headers, $key) {
        return isset($headers[$key]) && is_string($headers[$key]) && $headers[$key] !== '' ? $headers[$key] : null;
    }

    private static function verdict($ok, $reason, $nonce) {
        return array('ok' => $ok, 'reason' => $reason, 'nonce' => $nonce);
    }
}
