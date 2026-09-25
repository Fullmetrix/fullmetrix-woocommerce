<?php
defined('ABSPATH') || exit;

class Fullmetrix_Security {

    const TIMESTAMP_TOLERANCE = 300000;

    public static function verify_signature($secret, $body, $signature, $timestamp) {
        $now = round(microtime(true) * 1000);

        if (abs($now - $timestamp) > self::TIMESTAMP_TOLERANCE) {
            return false;
        }

        $expected = self::sign_request($secret, $body, $timestamp);

        return hash_equals($expected, $signature);
    }

    public static function sign_request($secret, $body, $timestamp) {
        $message = $timestamp . '.' . $body;
        return hash_hmac('sha256', $message, $secret);
    }

    public static function create_signed_headers($secret, $connection_code, $body = '') {
        $timestamp = round(microtime(true) * 1000);
        $signature = self::sign_request($secret, $body, $timestamp);

        return array(
            'X-Fullmetrix-Connection-Code' => $connection_code,
            'X-Fullmetrix-Signature' => $signature,
            'X-Fullmetrix-Timestamp' => strval($timestamp),
        );
    }
}
