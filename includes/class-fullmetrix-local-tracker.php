<?php
defined('ABSPATH') || exit;

final class Fullmetrix_Local_Tracker {

    const ACTION_HOOK = 'fullmetrix_refresh_tracker';
    const ACTION_GROUP = 'fullmetrix';
    const STATE_OPTION = 'fullmetrix_local_tracker_v2';
    const LEGACY_STATE_OPTION = 'fullmetrix_local_tracker';
    const SCHEDULE_CHECK_OPTION = 'fullmetrix_local_tracker_schedule_checked';
    const SCHEDULE_CHECK_EVERY = 3600;
    const KEEP_SECONDS = 604800;
    const TEMPORARY_SECONDS = 3600;
    const FEATURE = 'localTracker';
    const PLATFORM = 'wc';
    const HANDLE = 'kvo';
    const DIRECTORY = 'kvo';
    const LEGACY_DIRECTORY = 'fullmetrix';
    const INTERVAL = 600;
    const TIMEOUT = 3;
    const MAX_PAUSE = 21600;
    const MAX_BYTES = 2097152;
    const CONTRACT = 2;
    const DEFAULT_ORIGIN = 'https://fullmetrix.com';
    const FALLBACK_PATH = '/assets/js/s.js?k=';
    const TAG_KEY_PATTERN = '#^[A-Z2-7]{20}$#';
    const SEND_ORIGIN_PATTERN = '#^https://([a-z0-9-]{1,20})\.([a-z0-9.-]+)$#';
    const VERSION_PATTERN = '#^[0-9a-f]{12}$#';
    const FILE_PATTERN = '#^[0-9a-f]{12}\.js$#';
    const TEMPORARY_PATTERN = '#^\.[0-9a-f]{12}\.js\.[0-9a-f]{12}\.tmp$#';
    const LEGACY_FILE_PATTERN = '#^fm-[0-9a-f]{12}\.js$#';
    const LEGACY_TEMPORARY_PATTERN = '#^\.fm-[0-9a-f]{12}\.js\.[0-9a-f]{12}\.tmp$#';
    const INDEX_FILES = array('index.php' => "<?php\n", 'index.html' => '');
    const EXCLUSION_ATTRIBUTES = array(
        'data-no-optimize' => '1',
        'data-no-defer' => '1',
        'data-no-minify' => '1',
        'data-cfasync' => 'false',
        'nowprocket' => '1',
        'data-noptimize' => '1',
        'data-ao-skip' => '1',
        'data-pagespeed-no-defer' => '1',
        'data-wpfc-render' => 'false',
    );

    public static function init() {
        add_action(self::ACTION_HOOK, array(__CLASS__, 'refresh'));
        add_action('action_scheduler_before_process_queue', array(__CLASS__, 'ensure_scheduled'));
        add_action('admin_init', array(__CLASS__, 'ensure_scheduled_in_admin'));
    }

    public static function install() {
        add_option(self::STATE_OPTION, array(), '', true);
        add_option(self::SCHEDULE_CHECK_OPTION, 0, '', true);
    }

    public static function tag_key($code) {
        $state = self::state();
        return preg_match(self::TAG_KEY_PATTERN, $state['key']) === 1 ? $state['key'] : (string) $code;
    }

    public static function declared_domain() {
        $host = wp_parse_url(home_url('/'), PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return '';
        }
        $host = strtolower(rtrim($host, '.'));
        return strpos($host, 'www.') === 0 ? substr($host, 4) : $host;
    }

    public static function accepted_send_origin($candidate, $domain) {
        if (!is_string($candidate) || $candidate === self::DEFAULT_ORIGIN) {
            return self::DEFAULT_ORIGIN;
        }
        if (preg_match(self::SEND_ORIGIN_PATTERN, $candidate, $match) !== 1 || $domain === '' || $match[2] !== $domain) {
            return self::DEFAULT_ORIGIN;
        }
        return $candidate;
    }

    public static function send_origin() {
        return self::accepted_send_origin(self::state()['origin'], self::declared_domain());
    }

    public static function fallback_url($code) {
        return self::DEFAULT_ORIGIN . self::FALLBACK_PATH . rawurlencode(self::tag_key($code));
    }

    public static function tag($code) {
        $attributes = array('data-kvo' => self::tag_key($code), 'data-kvo-p' => self::PLATFORM, 'data-kvo-v' => FULLMETRIX_VERSION);
        $keeper = Fullmetrix_Keeper::path();
        if ($keeper !== null) {
            $attributes['data-kvo-k'] = $keeper;
        }
        $origin = self::send_origin();
        if ($origin !== self::DEFAULT_ORIGIN) {
            $attributes['data-kvo-o'] = $origin;
        }
        $attributes += self::EXCLUSION_ATTRIBUTES;

        return array('attributes' => $attributes, 'local' => self::current(), 'fallback' => self::fallback_url($code));
    }

    public static function fallback_script($tag) {
        $script = "var s=document.createElement('script');s.src='" . esc_js($tag['fallback']) . "';s.async=true;";
        foreach ($tag['attributes'] as $name => $value) {
            $script .= "s.setAttribute('" . esc_js($name) . "','" . esc_js($value) . "');";
        }
        return $script . 'document.head.appendChild(s)';
    }

    public static function current() {
        if (!Fullmetrix_Connector::feature_enabled(self::FEATURE)) {
            return null;
        }

        $state = self::state();
        if ($state['url'] === '' || $state['file'] === '') {
            return null;
        }

        return $state['url'];
    }

    public static function ensure_scheduled_in_admin() {
        if (function_exists('wp_doing_ajax') && wp_doing_ajax()) {
            return;
        }
        if ((int) get_option(self::SCHEDULE_CHECK_OPTION, 0) > time() - self::SCHEDULE_CHECK_EVERY) {
            return;
        }
        update_option(self::SCHEDULE_CHECK_OPTION, time(), true);
        self::ensure_scheduled();
    }

    public static function ensure_scheduled() {
        try {
            if (!function_exists('as_next_scheduled_action') || !function_exists('as_schedule_recurring_action') || !did_action('init')) {
                return;
            }
            self::finish_upgrade();
            if (!Fullmetrix_Connector::is_configured()) {
                return;
            }
            if (as_next_scheduled_action(self::ACTION_HOOK, array(), self::ACTION_GROUP) !== false) {
                return;
            }
            as_schedule_recurring_action(time() + 60, self::INTERVAL, self::ACTION_HOOK, array(), self::ACTION_GROUP);
        } catch (\Throwable $e) {
            return;
        }
    }

    private static function finish_upgrade() {
        if (get_option(FULLMETRIX_UPGRADE_PENDING_OPTION, '') === '') {
            return;
        }
        self::unschedule_consent();
        if (Fullmetrix_Connector::is_configured() && function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action(self::ACTION_HOOK, array(), self::ACTION_GROUP);
        }
        delete_option(FULLMETRIX_UPGRADE_PENDING_OPTION);
    }

    public static function unschedule_consent() {
        try {
            if (function_exists('as_unschedule_all_actions')) {
                as_unschedule_all_actions(FULLMETRIX_CONSENT_HOOK);
            }
        } catch (\Throwable $e) {
            return;
        }
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

    public static function uninstall() {
        self::unschedule_all();
        try {
            $filesystem = self::filesystem();
            $owned = array(
                self::DIRECTORY => array(self::FILE_PATTERN, self::TEMPORARY_PATTERN),
                self::LEGACY_DIRECTORY => array(self::LEGACY_FILE_PATTERN, self::LEGACY_TEMPORARY_PATTERN),
            );
            foreach ($owned as $name => $patterns) {
                $directory = self::directory($name);
                if ($directory !== null && $filesystem !== null && $filesystem->is_dir($directory['path'])) {
                    self::remove_owned($filesystem, $directory['path'], $patterns);
                }
            }
        } catch (\Throwable $e) {
            self::delete_options();
            return;
        }
        self::delete_options();
    }

    private static function delete_options() {
        delete_option(self::STATE_OPTION);
        delete_option(self::LEGACY_STATE_OPTION);
        delete_option(FULLMETRIX_UPGRADE_PENDING_OPTION);
        delete_option(self::SCHEDULE_CHECK_OPTION);
        if (class_exists('Fullmetrix_Keeper')) {
            Fullmetrix_Keeper::uninstall();
        }
    }

    public static function refresh() {
        try {
            self::run();
        } catch (\Throwable $e) {
            self::record_failure(self::state());
        }
    }

    private static function run() {
        if (!Fullmetrix_Connector::is_configured()) {
            return;
        }

        Fullmetrix_Connector::get_cached_config();

        $state = self::state();
        $local = Fullmetrix_Connector::feature_enabled(self::FEATURE);
        $directory = $local ? self::directory() : null;
        $filesystem = $local ? self::filesystem() : null;

        if ($directory !== null && $filesystem !== null && $state['file'] !== '' && !$filesystem->exists($directory['path'] . '/' . $state['file'])) {
            $state['url'] = '';
            $state['file'] = '';
            update_option(self::STATE_OPTION, $state, true);
        }

        if ($state['retry_at'] > time()) {
            return;
        }

        $data = self::fetch_response();
        if ($data === null) {
            self::record_failure($state);
            return;
        }

        Fullmetrix_Keeper::store_from_bundle($data);
        $state['key'] = isset($data['tagKey']) && is_string($data['tagKey']) && preg_match(self::TAG_KEY_PATTERN, $data['tagKey']) === 1 ? $data['tagKey'] : '';
        $state['origin'] = isset($data['origin']) && is_string($data['origin']) ? self::accepted_send_origin($data['origin'], self::declared_domain()) : self::DEFAULT_ORIGIN;

        if (!$local || (isset($data['local']) && $data['local'] === false)) {
            $state['url'] = '';
            $state['file'] = '';
            self::record_success($state);
            return;
        }

        $bundle = self::validate_bundle($data);
        if ($bundle === null || $directory === null || $filesystem === null) {
            self::record_failure($state);
            return;
        }

        $file = $bundle['version'] . '.js';
        $path = $directory['path'] . '/' . $file;

        if ($state['file'] === $file && $state['url'] !== '' && $filesystem->exists($path)) {
            self::record_success($state);
            return;
        }

        if (!self::prepare_directory($filesystem, $directory['path']) || !self::write_atomically($filesystem, $directory['path'], $file, $bundle['content'])) {
            self::record_failure($state);
            return;
        }

        $previous = $state['file'] !== $file ? $state['file'] : $state['previous'];
        $state['url'] = $directory['url'] . '/' . $file;
        $state['file'] = $file;
        $state['version'] = $bundle['version'];
        $state['previous'] = is_string($previous) ? $previous : '';
        self::record_success($state);
        self::prune($filesystem, $directory['path'], array($file, $state['previous']));
    }

    private static function fetch_response() {
        $code = get_option('fullmetrix_connection_code', '');
        if (empty($code)) {
            return null;
        }

        $base = rtrim(str_replace('/api/plugin', '', FULLMETRIX_API_BASE), '/');
        $response = Fullmetrix_Http::request('GET', $base . '/api/tracker/bundle?key=' . rawurlencode($code) . '&contract=' . self::CONTRACT, array(
            'headers' => array('Accept' => 'application/json', 'X-Fullmetrix-Plugin-Version' => FULLMETRIX_VERSION),
            'redirection' => 0,
        ), self::TIMEOUT, Fullmetrix_Http::JOBS);

        if ($response === null || is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        return is_array($data) ? $data : null;
    }

    private static function validate_bundle($data) {
        $version = isset($data['version']) && is_string($data['version']) ? $data['version'] : '';
        $sha256 = isset($data['sha256']) && is_string($data['sha256']) ? strtolower($data['sha256']) : '';
        $content = isset($data['content']) && is_string($data['content']) ? $data['content'] : '';

        if (preg_match(self::VERSION_PATTERN, $version) !== 1 || preg_match('#^[a-f0-9]{64}$#', $sha256) !== 1) {
            return null;
        }
        if ($content === '' || strlen($content) > self::MAX_BYTES || !hash_equals($sha256, hash('sha256', $content))) {
            return null;
        }

        return array('version' => $version, 'content' => $content);
    }

    private static function directory($name = self::DIRECTORY) {
        if (!function_exists('wp_upload_dir')) {
            return null;
        }

        $uploads = wp_upload_dir(null, false);
        if (!is_array($uploads) || !empty($uploads['error']) || empty($uploads['basedir']) || empty($uploads['baseurl'])) {
            return null;
        }

        $url = rtrim($uploads['baseurl'], '/') . '/' . $name;
        if (strpos(home_url('/'), 'https://') === 0 && strpos($url, 'http://') === 0) {
            $url = 'https://' . substr($url, 7);
        }

        return array('path' => rtrim($uploads['basedir'], '/\\') . '/' . $name, 'url' => $url);
    }

    private static function filesystem() {
        global $wp_filesystem;

        if (!function_exists('WP_Filesystem')) {
            $file = ABSPATH . 'wp-admin/includes/file.php';
            if (!is_readable($file)) {
                return null;
            }
            require_once $file;
        }

        if (!WP_Filesystem() || !is_object($wp_filesystem) || !isset($wp_filesystem->method) || $wp_filesystem->method !== 'direct') {
            return null;
        }

        return $wp_filesystem;
    }

    private static function prepare_directory($filesystem, $path) {
        if (!$filesystem->is_dir($path) && !$filesystem->mkdir($path, self::dir_mode())) {
            return false;
        }

        if (!$filesystem->is_writable($path)) {
            return false;
        }

        foreach (self::INDEX_FILES as $name => $body) {
            if (!$filesystem->exists($path . '/' . $name)) {
                $filesystem->put_contents($path . '/' . $name, $body, self::file_mode());
            }
        }

        return true;
    }

    private static function write_atomically($filesystem, $path, $file, $content) {
        $temporary = $path . '/.' . $file . '.' . substr(md5(uniqid('', true)), 0, 12) . '.tmp';

        if (!$filesystem->put_contents($temporary, $content, self::file_mode())) {
            $filesystem->delete($temporary);
            return false;
        }

        if (!$filesystem->move($temporary, $path . '/' . $file, true)) {
            $filesystem->delete($temporary);
            return false;
        }

        return $filesystem->exists($path . '/' . $file);
    }

    private static function prune($filesystem, $path, $keep) {
        $entries = $filesystem->dirlist($path, true, false);
        if (!is_array($entries)) {
            return;
        }

        $now = time();
        foreach ($entries as $name => $entry) {
            $name = (string) $name;
            $modified = is_array($entry) && isset($entry['lastmodunix']) ? (int) $entry['lastmodunix'] : 0;
            if ($modified <= 0) {
                continue;
            }
            $stale_temporary = preg_match(self::TEMPORARY_PATTERN, $name) === 1 && $modified < $now - self::TEMPORARY_SECONDS;
            $old_bundle = preg_match(self::FILE_PATTERN, $name) === 1 && !in_array($name, $keep, true) && $modified < $now - self::KEEP_SECONDS;
            if ($stale_temporary || $old_bundle) {
                $filesystem->delete($path . '/' . $name);
            }
        }
    }

    private static function remove_owned($filesystem, $path, $patterns) {
        $entries = $filesystem->dirlist($path, true, false);
        if (!is_array($entries)) {
            return;
        }

        $left = array();
        foreach (array_keys($entries) as $name) {
            $name = (string) $name;
            if (preg_match($patterns[0], $name) === 1 || preg_match($patterns[1], $name) === 1) {
                $filesystem->delete($path . '/' . $name);
            } else {
                $left[] = $name;
            }
        }

        $indexes = self::INDEX_FILES;
        if (array_diff($left, array_keys($indexes)) !== array()) {
            return;
        }
        foreach ($left as $name) {
            if ($filesystem->get_contents($path . '/' . $name) !== $indexes[$name]) {
                return;
            }
        }
        foreach ($left as $name) {
            $filesystem->delete($path . '/' . $name);
        }
        $filesystem->delete($path);
    }

    private static function state() {
        $state = get_option(self::STATE_OPTION);
        $state = is_array($state) ? $state : array();

        return array(
            'url' => isset($state['url']) && is_string($state['url']) ? $state['url'] : '',
            'file' => isset($state['file']) && is_string($state['file']) ? $state['file'] : '',
            'version' => isset($state['version']) && is_string($state['version']) ? $state['version'] : '',
            'origin' => isset($state['origin']) && is_string($state['origin']) ? $state['origin'] : self::DEFAULT_ORIGIN,
            'key' => isset($state['key']) && is_string($state['key']) ? $state['key'] : '',
            'previous' => isset($state['previous']) && is_string($state['previous']) ? $state['previous'] : '',
            'failures' => isset($state['failures']) ? (int) $state['failures'] : 0,
            'retry_at' => isset($state['retry_at']) ? (int) $state['retry_at'] : 0,
        );
    }

    private static function record_success($state) {
        $state['failures'] = 0;
        $state['retry_at'] = 0;
        update_option(self::STATE_OPTION, $state, true);
    }

    private static function record_failure($state) {
        $state['failures'] = min($state['failures'] + 1, 16);
        $state['retry_at'] = time() + min(self::INTERVAL * (int) pow(2, $state['failures'] - 1), self::MAX_PAUSE);
        update_option(self::STATE_OPTION, $state, true);
    }

    private static function file_mode() {
        return defined('FS_CHMOD_FILE') ? FS_CHMOD_FILE : 0644;
    }

    private static function dir_mode() {
        return defined('FS_CHMOD_DIR') ? FS_CHMOD_DIR : 0755;
    }
}
