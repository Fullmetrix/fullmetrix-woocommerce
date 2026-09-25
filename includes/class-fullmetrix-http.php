<?php
defined('ABSPATH') || exit;

final class Fullmetrix_Http {

    const CLIENT = 'client';
    const CART = 'cart';
    const JOBS = 'jobs';
    const SLOW_RATIO = 0.8;
    const CLIENT_TIMEOUT = 1;
    const RELEASED_TIMEOUT = 3;

    const BREAKERS = array(
        self::CLIENT => array('option' => 'fullmetrix_http_state', 'failures' => 3, 'window' => 300, 'open' => 300),
        self::CART => array('option' => 'fullmetrix_http_state_cart', 'failures' => 3, 'window' => 60, 'open' => 60),
        self::JOBS => array('option' => 'fullmetrix_http_state_jobs', 'failures' => 3, 'window' => 300, 'open' => 300),
    );

    private static $tasks = array();
    private static $shutdown_registered = false;
    private static $response_finished = false;

    public static function request($method, $url, $args, $timeout, $channel = self::CLIENT) {
        if (self::is_open($channel)) {
            return null;
        }

        $args['method'] = $method;
        $args['timeout'] = $timeout;
        $blocking = !isset($args['blocking']) || $args['blocking'] !== false;

        $started = microtime(true);
        $response = wp_remote_request($url, $args);
        $elapsed = microtime(true) - $started;

        $failed = is_wp_error($response)
            || $elapsed >= $timeout * self::SLOW_RATIO
            || ($blocking && (int) wp_remote_retrieve_response_code($response) >= 500);

        self::record($channel, !$failed);

        return $response;
    }

    public static function post_after_response($url, $args) {
        self::after_response(array(__CLASS__, 'post_now'), array($url, $args));
    }

    public static function post_now($url, $args) {
        $args['blocking'] = false;
        self::request('POST', $url, $args, self::client_timeout());
    }

    public static function after_response($callback, $args = array()) {
        self::$tasks[] = array($callback, $args);

        if (!self::$shutdown_registered) {
            add_action('shutdown', array(__CLASS__, 'run_after_response'), 1000);
            self::$shutdown_registered = true;
        }
    }

    public static function run_after_response() {
        $tasks = self::$tasks;
        self::$tasks = array();

        if (empty($tasks) || self::is_open()) {
            return;
        }

        self::finish_response();

        foreach ($tasks as $task) {
            try {
                call_user_func_array($task[0], $task[1]);
            } catch (\Throwable $e) {
                continue;
            }
        }
    }

    public static function client_timeout() {
        return self::$response_finished ? self::RELEASED_TIMEOUT : self::CLIENT_TIMEOUT;
    }

    public static function is_open($channel = self::CLIENT) {
        $state = self::state($channel);
        return $state['open_until'] > time();
    }

    public static function install() {
        foreach (self::BREAKERS as $breaker) {
            add_option($breaker['option'], self::closed_state(), '', true);
        }
    }

    private static function finish_response() {
        if (self::$response_finished || PHP_SAPI === 'cli') {
            return self::$response_finished;
        }

        if (self::can_call('fastcgi_finish_request')) {
            self::$response_finished = (bool) fastcgi_finish_request();
        } elseif (self::can_call('litespeed_finish_request')) {
            self::$response_finished = (bool) litespeed_finish_request();
        }

        return self::$response_finished;
    }

    private static function can_call($function) {
        if (!function_exists($function)) {
            return false;
        }

        $disabled = array_map('trim', explode(',', strtolower((string) ini_get('disable_functions'))));
        return !in_array($function, $disabled, true);
    }

    private static function record($channel, $ok) {
        $breaker = self::BREAKERS[$channel];
        $state = self::state($channel);

        if ($ok) {
            if ($state['failures'] > 0 || $state['open_until'] > 0) {
                update_option($breaker['option'], self::closed_state(), true);
            }
            return;
        }

        $now = time();
        $opened = array('failures' => 0, 'since' => $now, 'open_until' => $now + $breaker['open']);

        if ($state['open_until'] > 0) {
            update_option($breaker['option'], $opened, true);
            return;
        }

        if ($now - $state['since'] > $breaker['window']) {
            $state['failures'] = 0;
            $state['since'] = $now;
        }

        $state['failures']++;

        update_option($breaker['option'], $state['failures'] >= $breaker['failures'] ? $opened : $state, true);
    }

    private static function state($channel) {
        $state = get_option(self::BREAKERS[$channel]['option']);
        if (!is_array($state)) {
            return self::closed_state();
        }

        return array(
            'failures' => isset($state['failures']) ? (int) $state['failures'] : 0,
            'since' => isset($state['since']) ? (int) $state['since'] : 0,
            'open_until' => isset($state['open_until']) ? (int) $state['open_until'] : 0,
        );
    }

    private static function closed_state() {
        return array('failures' => 0, 'since' => 0, 'open_until' => 0);
    }
}
