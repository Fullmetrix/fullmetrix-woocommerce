<?php
defined('WP_UNINSTALL_PLUGIN') || exit;

require_once __DIR__ . '/includes/class-fullmetrix-keeper.php';
require_once __DIR__ . '/includes/class-fullmetrix-local-tracker.php';

Fullmetrix_Local_Tracker::uninstall();
