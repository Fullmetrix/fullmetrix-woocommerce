<?php
defined('ABSPATH') || exit;

class Fullmetrix_Logger {

    const MAX_ENTRIES = 100;
    const OPTION_KEY = 'fullmetrix_logs';

    /**
     * @param string $type   registered|disconnected|sync_start|sync_complete|sync_error|webhook
     * @param string $message
     * @param array  $details
     */
    public static function log($type, $message, $details = array()) {
        $logs = get_option(self::OPTION_KEY, array());
        if (!is_array($logs)) {
            $logs = array();
        }

        array_unshift($logs, array(
            'type'    => $type,
            'message' => $message,
            'details' => $details,
            'time'    => time(),
        ));

        if (count($logs) > self::MAX_ENTRIES) {
            $logs = array_slice($logs, 0, self::MAX_ENTRIES);
        }

        update_option(self::OPTION_KEY, $logs, false);
    }

    /**
     * @return array
     */
    public static function getLogs() {
        $logs = get_option(self::OPTION_KEY, array());
        return is_array($logs) ? $logs : array();
    }

    public static function clear() {
        update_option(self::OPTION_KEY, array(), false);
    }
}
