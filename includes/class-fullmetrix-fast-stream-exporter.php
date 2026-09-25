<?php
defined('ABSPATH') || exit;

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- All queries use $this->wpdb->prepare(), but PHPCS cannot trace $this->wpdb.

class Fullmetrix_Fast_Stream_Exporter {

    const TAX_LOCATION_PRIORITY = -1000;

    private $wpdb;
    private $related_tables_cache = array();
    private $has_hpos;
    private $hpos_detection_info = array();
    private $per_page = 1000;
    private $orders_per_page = 1000;
    private $memory_limit_bytes;
    private $start_time;
    private $hpos_tables_checked = false;
    private $hpos_addresses_exist = false;
    private $hpos_op_exist = false;

    /**
     * Convert MySQL datetime (Y-m-d H:i:s) to ISO 8601 without costly strtotime().
     * ~10x faster than gmdate('c', strtotime($date)) for high-volume loops.
     */
    private static function to_iso($mysql_date) {
        if ($mysql_date === null || $mysql_date === '' || $mysql_date === false) {
            return null;
        }
        $d = trim((string) $mysql_date);
        if ($d === '' || $d === '0000-00-00 00:00:00' || $d === '0000-00-00' || strlen($d) < 10) {
            return null;
        }
        if (strlen($d) === 10) {
            return $d . 'T00:00:00Z';
        }
        return str_replace(' ', 'T', $d) . 'Z';
    }

    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->has_hpos = $this->detect_hpos();
        $this->start_time = time();
        $this->memory_limit_bytes = $this->get_memory_limit_bytes();
    }

    private function get_memory_limit_bytes() {
        $limit = ini_get('memory_limit');
        if ($limit === '-1') {
            return PHP_INT_MAX;
        }
        $value = (int) $limit;
        $unit = strtolower(substr($limit, -1));
        switch ($unit) {
            case 'g': $value *= 1024;
            case 'm': $value *= 1024;
            case 'k': $value *= 1024;
        }
        return $value;
    }

    private function detect_hpos() {
        $orders_table = $this->wpdb->prefix . 'wc_orders';
        $addresses_table = $this->wpdb->prefix . 'wc_order_addresses';
        $op_table = $this->wpdb->prefix . 'wc_order_operational_data';
        $posts_table = $this->wpdb->posts;

        $wc_hpos_enabled = false;
        $wc_class_exists = class_exists(\Automattic\WooCommerce\Utilities\OrderUtil::class);
        $wc_exception = null;

        if ($wc_class_exists) {
            try {
                $wc_hpos_enabled = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
            } catch (Exception $e) {
                $wc_exception = $e->getMessage();
                $wc_hpos_enabled = false;
            }
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        $table_exists = (bool) $this->wpdb->get_var($this->wpdb->prepare("SHOW TABLES LIKE %s", $orders_table));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        $addresses_exists = (bool) $this->wpdb->get_var($this->wpdb->prepare("SHOW TABLES LIKE %s", $addresses_table));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        $op_exists = (bool) $this->wpdb->get_var($this->wpdb->prepare("SHOW TABLES LIKE %s", $op_table));

        $hpos_tables_complete = $table_exists && $addresses_exists && $op_exists;

        $wc_setting_known = $wc_class_exists && $wc_exception === null;

        $hpos_order_count = $wc_setting_known ? null : 0;
        $legacy_order_count = null;
        if ($table_exists && !$wc_setting_known) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $hpos_order_count = (int) $this->wpdb->get_var(
                $this->wpdb->prepare("SELECT COUNT(*) FROM %i WHERE type = 'shop_order'", $orders_table)
            );
        }

        if (!$wc_setting_known) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $legacy_order_count = (int) $this->wpdb->get_var(
                $this->wpdb->prepare("SELECT COUNT(*) FROM %i WHERE post_type = 'shop_order'", $posts_table)
            );
        }

        $use_hpos = $wc_setting_known
            ? $hpos_tables_complete && $wc_hpos_enabled
            : $hpos_tables_complete && (($hpos_order_count > 0) || $wc_hpos_enabled);

        $decision_reason = 'Unknown';
        if ($wc_setting_known && !$wc_hpos_enabled) {
            $decision_reason = 'WooCommerce stores orders in posts';
        } elseif ($use_hpos) {
            $decision_reason = 'HPOS tables complete and orders found';
        } elseif (!$hpos_tables_complete && $table_exists) {
            $decision_reason = 'HPOS tables incomplete (addresses or operational_data missing), using legacy';
        } elseif ($legacy_order_count > 0) {
            $decision_reason = 'Orders found in legacy table';
        } else {
            $decision_reason = 'No orders found anywhere';
        }

        $this->hpos_detection_info = array(
            'wc_class_exists' => $wc_class_exists,
            'wc_api_enabled' => $wc_hpos_enabled,
            'wc_exception' => $wc_exception,
            'orders_table' => $orders_table,
            'orders_table_exists' => $table_exists,
            'addresses_table' => $addresses_table,
            'addresses_table_exists' => $addresses_exists,
            'op_table' => $op_table,
            'op_table_exists' => $op_exists,
            'hpos_tables_complete' => $hpos_tables_complete,
            'hpos_order_count' => $hpos_order_count,
            'legacy_order_count' => $legacy_order_count,
            'detected' => $use_hpos,
            'decision_reason' => $decision_reason,
        );

        return $use_hpos;
    }

    public function stream_all($sync_type = 'full', $since = null, $from_id = 0) {
        // Une seule amorce ne peut pas servir six keysets differents : la reprise
        // se fait entite par entite, cette route l'ignore volontairement.
        $from_id = 0;
        // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged, WordPress.PHP.NoSilencedErrors.Discouraged
        @set_time_limit(0);
        // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged, WordPress.PHP.NoSilencedErrors.Discouraged
        @ini_set('memory_limit', '1G');
        // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged, WordPress.PHP.NoSilencedErrors.Discouraged
        @ini_set('output_buffering', 'off');
        // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged, WordPress.PHP.NoSilencedErrors.Discouraged
        @ini_set('zlib.output_compression', 'off');
        // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged, WordPress.PHP.NoSilencedErrors.Discouraged, PluginCheck.CodeAnalysis.PHPErrorReporting.IniDirectiveDisplay_errors
        @ini_set('display_errors', '0');

        while (ob_get_level()) {
            ob_end_clean();
        }

        header('Content-Type: application/x-ndjson');
        header('X-Accel-Buffering: no');
        header('Cache-Control: no-cache');
        // Let the web server (Apache/nginx) handle gzip via mod_deflate.
        // Doing it in PHP with ob_gzhandler truncates the tail of long streams.

        $diagnostic = $this->get_raw_order_diagnostic();

        $this->send_line(array(
            'type' => 'meta',
            'started_at' => gmdate('c'),
            'version' => FULLMETRIX_VERSION,
            'store_url' => home_url(),
            'mode' => 'fast_stream',
            'hpos' => $this->has_hpos,
            'hpos_detection' => $this->hpos_detection_info,
            'sync_type' => $sync_type,
            'since' => $since,
            'diagnostic' => $diagnostic,
            'php_version' => PHP_VERSION,
            'wp_version' => get_bloginfo('version'),
            'wc_version' => defined('WC_VERSION') ? WC_VERSION : 'unknown',
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
        ));

        $counts = array(
            'orders' => 0,
            'customers' => 0,
            'products' => 0,
            'categories' => 0,
            'coupons' => 0,
            'refunds' => 0,
        );

        $counts['orders'] = $this->stream_orders_fast($sync_type, $since, $from_id);
        $counts['refunds'] = $this->stream_refunds_fast($sync_type, $since, $from_id);
        $counts['customers'] = $this->stream_customers_fast($sync_type, $since, $from_id);
        $counts['products'] = $this->stream_products_fast($sync_type, $since, $from_id);
        $counts['categories'] = $this->stream_categories_fast($from_id);
        $counts['coupons'] = $this->stream_coupons_fast($sync_type, $since, $from_id);

        $this->send_line(array(
            'type' => 'done',
            'completed_at' => gmdate('c'),
            'counts' => $counts,
        ));

        exit;
    }

    public function stream_entity($entity, $sync_type = 'full', $since = null, $from_id = 0) {
        // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged, WordPress.PHP.NoSilencedErrors.Discouraged
        @set_time_limit(0);
        // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged, WordPress.PHP.NoSilencedErrors.Discouraged
        @ini_set('memory_limit', '1G');
        // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged, WordPress.PHP.NoSilencedErrors.Discouraged
        @ini_set('output_buffering', 'off');
        // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged, WordPress.PHP.NoSilencedErrors.Discouraged
        @ini_set('zlib.output_compression', 'off');
        // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged, WordPress.PHP.NoSilencedErrors.Discouraged, PluginCheck.CodeAnalysis.PHPErrorReporting.IniDirectiveDisplay_errors
        @ini_set('display_errors', '0');

        while (ob_get_level()) {
            ob_end_clean();
        }

        header('Content-Type: application/x-ndjson');
        header('X-Accel-Buffering: no');
        header('Cache-Control: no-cache');
        // Let the web server (Apache/nginx) handle gzip via mod_deflate.
        // Doing it in PHP with ob_gzhandler truncates the tail of long streams.

        $this->send_line(array(
            'type' => 'meta',
            'entity' => $entity,
            'started_at' => gmdate('c'),
            'mode' => 'fast_stream',
            'hpos' => $this->has_hpos,
            'hpos_detection' => $this->hpos_detection_info,
            'supports_from_id' => true,
        ));

        $count = 0;

        try {
            switch ($entity) {
                case 'orders':
                    $count = $this->stream_orders_fast($sync_type, $since, $from_id);
                    break;
                case 'refunds':
                    $count = $this->stream_refunds_fast($sync_type, $since, $from_id);
                    break;
                case 'customers':
                    $count = $this->stream_customers_fast($sync_type, $since, $from_id);
                    break;
                case 'products':
                    $count = $this->stream_products_fast($sync_type, $since, $from_id);
                    break;
                case 'categories':
                    $count = $this->stream_categories_fast($from_id);
                    break;
                case 'coupons':
                    $count = $this->stream_coupons_fast($sync_type, $since, $from_id);
                    break;
                case 'subscriptions':
                    $count = $this->stream_subscriptions_fast($sync_type, $since, $from_id);
                    break;
            }
        } catch (Exception $e) {
            $this->send_line(array('type' => 'error', 'entity' => $entity, 'message' => 'Exception: ' . $e->getMessage()));
        } catch (Error $e) {
            $this->send_line(array('type' => 'error', 'entity' => $entity, 'message' => 'Fatal: ' . $e->getMessage()));
        }

        $this->send_line(array(
            'type' => 'done',
            'completed_at' => gmdate('c'),
            'count' => $count,
        ));

        exit;
    }

    public function stream_orders_only($sync_type = 'full', $since = null, $from_id = 0) {
        // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged, WordPress.PHP.NoSilencedErrors.Discouraged
        @set_time_limit(0);
        // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged, WordPress.PHP.NoSilencedErrors.Discouraged
        @ini_set('memory_limit', '512M');

        while (ob_get_level()) {
            ob_end_clean();
        }

        header('Content-Type: application/x-ndjson');
        header('X-Accel-Buffering: no');
        header('Cache-Control: no-cache');

        $this->send_line(array(
            'type' => 'meta',
            'entity' => 'orders',
            'started_at' => gmdate('c'),
            'mode' => 'fast_stream',
        ));

        $count = $this->stream_orders_fast($sync_type, $since, $from_id);

        $this->send_line(array(
            'type' => 'done',
            'completed_at' => gmdate('c'),
            'count' => $count,
        ));

        exit;
    }

    // ─── ORDERS ───────────────────────────────────────────────────────

    private function stream_orders_fast($sync_type, $since, $from_id = 0) {
        $this->send_line(array(
            'type' => 'info',
            'message' => 'Starting orders export',
            'mode' => $this->has_hpos ? 'hpos' : 'legacy',
            'sync_type' => $sync_type,
            'since' => $since,
            'hpos_detection' => $this->hpos_detection_info,
                    ));

        if ($this->has_hpos) {
            try {
                $count = $this->stream_orders_hpos($sync_type, $since, $from_id);
                if ($this->wpdb->last_error) {
                    $this->send_line(array(
                        'type' => 'warning',
                        'message' => 'HPOS error, falling back to legacy mode',
                        'error' => $this->wpdb->last_error,
                    ));
                    $this->has_hpos = false;
                    return $this->stream_orders_legacy($sync_type, $since, $from_id);
                }
                return $count;
            } catch (Exception $e) {
                $this->send_line(array(
                    'type' => 'warning',
                    'message' => 'HPOS exception, falling back to legacy mode',
                    'error' => $e->getMessage(),
                ));
                $this->has_hpos = false;
                return $this->stream_orders_legacy($sync_type, $since, $from_id);
            } catch (Error $e) {
                $this->send_line(array(
                    'type' => 'warning',
                    'message' => 'HPOS fatal error, falling back to legacy mode',
                    'error' => $e->getMessage(),
                ));
                $this->has_hpos = false;
                return $this->stream_orders_legacy($sync_type, $since, $from_id);
            }
        }
        return $this->stream_orders_legacy($sync_type, $since, $from_id);
    }

    private function stream_orders_hpos($sync_type, $since, $from_id = 0) {
        $start_time = microtime(true);
        $count = 0;
        $last_id = (int) $from_id;
        $batch_number = 0;
        $batch_size = $this->orders_per_page;
        $orders_table = $this->wpdb->prefix . 'wc_orders';
        $addresses_table = $this->wpdb->prefix . 'wc_order_addresses';
        $op_table = $this->wpdb->prefix . 'wc_order_operational_data';

        // Cache HPOS table existence checks (run once per sync)
        if (!$this->hpos_tables_checked) {
            $this->hpos_tables_checked = true;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $this->hpos_addresses_exist = (bool) $this->wpdb->get_var(
                $this->wpdb->prepare("SHOW TABLES LIKE %s", $addresses_table)
            );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $this->hpos_op_exist = (bool) $this->wpdb->get_var(
                $this->wpdb->prepare("SHOW TABLES LIKE %s", $op_table)
            );
        }

        if (!$this->hpos_addresses_exist || !$this->hpos_op_exist) {
            $this->send_line(array(
                'type' => 'warning',
                'message' => 'HPOS tables incomplete',
            ));
            return 0;
        }

        $base_where = "WHERE o.type = 'shop_order' AND o.status NOT IN ('trash', 'auto-draft')";
        if ($sync_type === 'incremental' && $since) {
            $base_where .= $this->wpdb->prepare(" AND o.date_updated_gmt > %s", $since);
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        $total_orders = (int) $this->wpdb->get_var(
            $this->wpdb->prepare("SELECT COUNT(*) FROM %i o ", $orders_table) . $base_where
        );

        $this->send_line(array(
            'type' => 'info',
            'message' => 'hpos_orders_start',
            'total' => $total_orders,
            'batch_size' => $batch_size,
            'memory_mb' => round(memory_get_usage(true) / 1024 / 1024, 1),
        ));

        if ($total_orders === 0) {
            return 0;
        }

        do {
            $batch_number++;
            $batch_start = microtime(true);

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $rows = $this->wpdb->get_results(
                $this->wpdb->prepare(
                    "SELECT
                        o.*,
                        op.discount_total_amount, op.shipping_total_amount,
                        op.date_paid_gmt, op.date_completed_gmt, op.prices_include_tax,
                        ba.first_name as billing_first_name, ba.last_name as billing_last_name,
                        ba.company as billing_company, ba.address_1 as billing_address_1,
                        ba.address_2 as billing_address_2, ba.city as billing_city,
                        ba.state as billing_state, ba.postcode as billing_postcode,
                        ba.country as billing_country, ba.email as billing_email, ba.phone as billing_phone,
                        sa.first_name as shipping_first_name, sa.last_name as shipping_last_name,
                        sa.company as shipping_company, sa.address_1 as shipping_address_1,
                        sa.address_2 as shipping_address_2, sa.city as shipping_city,
                        sa.state as shipping_state, sa.postcode as shipping_postcode,
                        sa.country as shipping_country, sa.phone as shipping_phone
                    FROM %i o
                    LEFT JOIN %i op ON o.id = op.order_id
                    LEFT JOIN %i ba ON o.id = ba.order_id AND ba.address_type = 'billing'
                    LEFT JOIN %i sa ON o.id = sa.order_id AND sa.address_type = 'shipping'
                    " . $base_where . " AND o.id > %d
                    ORDER BY o.id ASC
                    LIMIT %d",
                    $orders_table, $op_table, $addresses_table, $addresses_table,
                    $last_id, $batch_size
                )
            );

            if ($this->wpdb->last_error) {
                $this->send_line(array('type' => 'error', 'entity' => 'orders', 'message' => 'SQL error: ' . $this->wpdb->last_error));
            }

            if ($rows === null) {
                $last_id = $this->find_next_id_asc($orders_table, 'id', $last_id, " AND type = 'shop_order'");
                // `find_next_id_asc` rend PHP_INT_MAX quand la table est epuisee, et
                // 0 n'est plus une sentinelle depuis le passage en parcours croissant :
                // sans ce test la boucle repart indefiniment sur une base injoignable.
                if ($last_id <= 0 || $last_id === PHP_INT_MAX) break;
                continue;
            }
            if (empty($rows)) break;

            $order_ids = wp_list_pluck($rows, 'id');
            $all_items = $this->get_order_items_batch($order_ids);
            $all_meta = $this->get_order_meta_batch_hpos($order_ids);
            $all_extra = $this->order_extra_meta_batch($order_ids);

            foreach ($rows as $row) {
                $order_id = (int) $row->id;
                $items = $all_items[$order_id] ?? array();
                $meta = array_merge(
                    $all_meta[$order_id] ?? array(),
                    $all_extra[$order_id] ?? array()
                );

                $this->send_line(array(
                    'type' => 'order',
                    '_cursor' => $order_id,
                    'data' => array(
                        'id' => $order_id,
                        'number' => (string) $order_id,
                        'status' => str_replace('wc-', '', $row->status),
                        'currency' => $row->currency,
                        'total' => (string) $row->total_amount,
                        'discount_total' => (string) ($row->discount_total_amount ?? '0'),
                        'shipping_total' => (string) ($row->shipping_total_amount ?? '0'),
                        'total_tax' => (string) ($row->tax_amount ?? '0'),
                        'date_created' => self::to_iso($row->date_created_gmt),
                        'date_modified' => self::to_iso($row->date_updated_gmt),
                        'date_paid' => self::to_iso($row->date_paid_gmt),
                        'date_completed' => self::to_iso($row->date_completed_gmt),
                        'payment_method' => $row->payment_method ?: null,
                        'payment_method_title' => $row->payment_method_title ?: null,
                        'created_via' => $meta['_created_via'] ?? null,
                        'customer_id' => (int) $row->customer_id,
                        'customer_ip_address' => $row->ip_address ?: null,
                        'customer_user_agent' => $row->user_agent ?: null,
                        'transaction_id' => $row->transaction_id ?: null,
                        'customer_note' => $row->customer_note ?: null,
                        'parent_id' => (int) $row->parent_order_id ?: null,
                        'prices_include_tax' => (bool) ($row->prices_include_tax ?? false),
                        'billing' => array(
                            'first_name' => $row->billing_first_name ?? '',
                            'last_name' => $row->billing_last_name ?? '',
                            'company' => $row->billing_company ?? '',
                            'address_1' => $row->billing_address_1 ?? '',
                            'address_2' => $row->billing_address_2 ?? '',
                            'city' => $row->billing_city ?? '',
                            'state' => $row->billing_state ?? '',
                            'postcode' => $row->billing_postcode ?? '',
                            'country' => $row->billing_country ?? '',
                            'email' => $row->billing_email ?? '',
                            'phone' => $row->billing_phone ?? '',
                        ),
                        'shipping' => array(
                            'first_name' => $row->shipping_first_name ?? '',
                            'last_name' => $row->shipping_last_name ?? '',
                            'company' => $row->shipping_company ?? '',
                            'address_1' => $row->shipping_address_1 ?? '',
                            'address_2' => $row->shipping_address_2 ?? '',
                            'city' => $row->shipping_city ?? '',
                            'state' => $row->shipping_state ?? '',
                            'postcode' => $row->shipping_postcode ?? '',
                            'country' => $row->shipping_country ?? '',
                            'phone' => $row->shipping_phone ?? '',
                        ),
                        'line_items' => $items['line_items'] ?? array(),
                        'shipping_lines' => $items['shipping_lines'] ?? array(),
                        'fee_lines' => $items['fee_lines'] ?? array(),
                        'coupon_lines' => $items['coupon_lines'] ?? array(),
                        'tax_lines' => $items['tax_lines'] ?? array(),
                        'meta_data' => $meta,
                    ),
                ));

                $count++;
            }

            $last_id = (int) end($rows)->id;
            $batch_sec = round(microtime(true) - $batch_start, 2);
            unset($rows, $all_items, $all_meta);

            $mem_pct = memory_get_usage(true) / $this->memory_limit_bytes;
            if ($mem_pct > 0.7 && $batch_size > 100) {
                $batch_size = max(100, (int) ($batch_size * 0.5));
            } elseif ($mem_pct < 0.4 && $batch_size < 1000) {
                $batch_size = min(1000, (int) ($batch_size * 1.5));
            }

            $this->maybe_gc();
        } while (true);

        $elapsed = round(microtime(true) - $start_time, 2);
        $this->send_line(array(
            'type' => 'entity_complete',
            'entity' => 'orders',
            'mode' => 'hpos',
            'count' => $count,
            'batches' => $batch_number,
            'elapsed_seconds' => $elapsed,
        ));

        return $count;
    }

    private function stream_orders_legacy($sync_type, $since, $from_id = 0) {
        $start_time = microtime(true);
        $count = 0;
        $last_id = (int) $from_id;
        $batch_number = 0;
        $batch_size = $this->orders_per_page;

        $base_where = "WHERE p.post_type = 'shop_order' AND p.post_status NOT IN ('trash', 'auto-draft')";
        if ($sync_type === 'incremental' && $since) {
            $base_where .= $this->wpdb->prepare(" AND p.post_modified_gmt > %s", $since);
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        $total_orders = (int) $this->wpdb->get_var(
            $this->wpdb->prepare("SELECT COUNT(*) FROM %i p ", $this->wpdb->posts) . $base_where
        );

        $this->send_line(array(
            'type' => 'info',
            'message' => 'legacy_orders_start',
            'total' => $total_orders,
            'batch_size' => $batch_size,
        ));

        if ($total_orders === 0) {
            return 0;
        }

        $known_keys = array(
            '_order_total', '_order_currency', '_cart_discount', '_order_shipping', '_order_tax',
            '_billing_first_name', '_billing_last_name', '_billing_company', '_billing_address_1',
            '_billing_address_2', '_billing_city', '_billing_state', '_billing_postcode',
            '_billing_country', '_billing_email', '_billing_phone',
            '_shipping_first_name', '_shipping_last_name', '_shipping_company', '_shipping_address_1',
            '_shipping_address_2', '_shipping_city', '_shipping_state', '_shipping_postcode',
            '_shipping_country', '_shipping_phone',
            '_payment_method', '_payment_method_title', '_date_paid', '_date_completed',
            '_customer_ip_address', '_customer_user_agent', '_transaction_id',
            '_customer_user', '_order_number', '_created_via', '_prices_include_tax',
        );

        do {
            $batch_number++;

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $rows = $this->wpdb->get_results(
                $this->wpdb->prepare(
                    "SELECT p.ID, p.post_status, p.post_date_gmt, p.post_modified_gmt
                    FROM %i p
                    " . $base_where . " AND p.ID > %d
                    ORDER BY p.ID ASC
                    LIMIT %d",
                    $this->wpdb->posts, $last_id, $batch_size
                )
            );

            if ($rows === null && $this->wpdb->last_error) {
                $this->send_line(array('type' => 'error', 'entity' => 'orders', 'message' => 'SQL error: ' . $this->wpdb->last_error));
                $last_id = $this->find_next_id_asc($this->wpdb->posts, 'ID', $last_id, " AND post_type = 'shop_order'");
                // `find_next_id_asc` rend PHP_INT_MAX quand la table est epuisee, et
                // 0 n'est plus une sentinelle depuis le passage en parcours croissant :
                // sans ce test la boucle repart indefiniment sur une base injoignable.
                if ($last_id <= 0 || $last_id === PHP_INT_MAX) break;
                continue;
            }
            if (empty($rows)) break;

            $order_ids = wp_list_pluck($rows, 'ID');
            $all_postmeta = $this->get_all_postmeta_batch($order_ids);
            $all_items = $this->get_order_items_batch($order_ids);

            foreach ($rows as $row) {
                $order_id = (int) $row->ID;
                $meta = $all_postmeta[$order_id] ?? array();
                $items = $all_items[$order_id] ?? array();

                $meta_data = self::meta_pairs($meta, $known_keys);

                $this->send_line(array(
                    'type' => 'order',
                    '_cursor' => $order_id,
                    'data' => array(
                        'id' => $order_id,
                        'number' => $meta['_order_number'] ?? (string) $order_id,
                        'status' => str_replace('wc-', '', $row->post_status),
                        'currency' => $meta['_order_currency'] ?? 'EUR',
                        'total' => (string) ($meta['_order_total'] ?? '0'),
                        'discount_total' => (string) ($meta['_cart_discount'] ?? '0'),
                        'shipping_total' => (string) ($meta['_order_shipping'] ?? '0'),
                        'total_tax' => (string) ($meta['_order_tax'] ?? '0'),
                        'date_created' => self::to_iso($row->post_date_gmt),
                        'date_modified' => self::to_iso($row->post_modified_gmt),
                        'date_paid' => isset($meta['_date_paid']) ? gmdate('c', (int) $meta['_date_paid']) : null,
                        'date_completed' => isset($meta['_date_completed']) ? gmdate('c', (int) $meta['_date_completed']) : null,
                        'payment_method' => $meta['_payment_method'] ?? null,
                        'payment_method_title' => $meta['_payment_method_title'] ?? null,
                        'customer_id' => (int) ($meta['_customer_user'] ?? 0),
                        'customer_ip_address' => $meta['_customer_ip_address'] ?? null,
                        'customer_user_agent' => $meta['_customer_user_agent'] ?? null,
                        'transaction_id' => $meta['_transaction_id'] ?? null,
                        'customer_note' => null,
                        'created_via' => $meta['_created_via'] ?? null,
                        'parent_id' => null,
                        'prices_include_tax' => ($meta['_prices_include_tax'] ?? 'no') === 'yes',
                        'billing' => array(
                            'first_name' => $meta['_billing_first_name'] ?? '',
                            'last_name' => $meta['_billing_last_name'] ?? '',
                            'company' => $meta['_billing_company'] ?? '',
                            'address_1' => $meta['_billing_address_1'] ?? '',
                            'address_2' => $meta['_billing_address_2'] ?? '',
                            'city' => $meta['_billing_city'] ?? '',
                            'state' => $meta['_billing_state'] ?? '',
                            'postcode' => $meta['_billing_postcode'] ?? '',
                            'country' => $meta['_billing_country'] ?? '',
                            'email' => $meta['_billing_email'] ?? '',
                            'phone' => $meta['_billing_phone'] ?? '',
                        ),
                        'shipping' => array(
                            'first_name' => $meta['_shipping_first_name'] ?? '',
                            'last_name' => $meta['_shipping_last_name'] ?? '',
                            'company' => $meta['_shipping_company'] ?? '',
                            'address_1' => $meta['_shipping_address_1'] ?? '',
                            'address_2' => $meta['_shipping_address_2'] ?? '',
                            'city' => $meta['_shipping_city'] ?? '',
                            'state' => $meta['_shipping_state'] ?? '',
                            'postcode' => $meta['_shipping_postcode'] ?? '',
                            'country' => $meta['_shipping_country'] ?? '',
                            'phone' => $meta['_shipping_phone'] ?? '',
                        ),
                        'line_items' => $items['line_items'] ?? array(),
                        'shipping_lines' => $items['shipping_lines'] ?? array(),
                        'fee_lines' => $items['fee_lines'] ?? array(),
                        'coupon_lines' => $items['coupon_lines'] ?? array(),
                        'tax_lines' => $items['tax_lines'] ?? array(),
                        'meta_data' => $meta_data,
                    ),
                ));

                $count++;
            }

            $last_id = (int) end($rows)->ID;
            unset($rows, $all_postmeta, $all_items);

            $mem_pct = memory_get_usage(true) / $this->memory_limit_bytes;
            if ($mem_pct > 0.7 && $batch_size > 100) {
                $batch_size = max(100, (int) ($batch_size * 0.5));
            } elseif ($mem_pct < 0.4 && $batch_size < 1000) {
                $batch_size = min(1000, (int) ($batch_size * 1.5));
            }

            $this->maybe_gc();
        } while (true);

        $elapsed = round(microtime(true) - $start_time, 2);
        $this->send_line(array(
            'type' => 'entity_complete',
            'entity' => 'orders',
            'mode' => 'legacy',
            'count' => $count,
            'batches' => $batch_number,
            'elapsed_seconds' => $elapsed,
        ));

        return $count;
    }

    private function get_all_postmeta_batch($post_ids) {
        if (empty($post_ids)) {
            return array();
        }

        $placeholders = implode(',', array_fill(0, count($post_ids), '%d'));
        $query = $this->wpdb->prepare(
            "SELECT post_id, meta_key, meta_value
             FROM %i
             WHERE post_id IN (" . $placeholders . ")
             AND meta_key NOT IN ('_edit_lock', '_edit_last')",
            array_merge(array($this->wpdb->postmeta), $post_ids)
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        $rows = $this->wpdb->get_results($query);
        $grouped = array();

        foreach ($rows as $row) {
            if (!self::keep_meta($row->meta_key, $row->meta_value) || self::is_sensitive_key($row->meta_key)) {
                continue;
            }
            $grouped[(int) $row->post_id][$row->meta_key] = $row->meta_value;
        }

        return $grouped;
    }

    /**
     * Anything a shop or a plugin stores on its own keys reaches the platform.
     * Only bulky values from known-noisy plugins are dropped. No size cap here:
     * this map also feeds real fields, some of them serialized arrays that
     * would not survive being shortened.
     */
    private static function keep_meta($key, $value) {
        if (strlen((string) $value) <= 1000) {
            return true;
        }
        foreach (self::$skip_meta_prefixes as $prefix) {
            if (strpos($key, $prefix) === 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * Values that only ever reach meta_data are shortened rather than dropped,
     * so a field never silently disappears from the platform.
     */
    private static $line_item_mapped_meta = array(
        '_product_id', '_variation_id', '_qty',
        '_line_subtotal', '_line_subtotal_tax', '_line_tax', '_line_total',
    );

    /**
     * Motifs de cles a ne jamais exporter. La meta WordPress en contient peu,
     * mais la decouverte des tables tierces peut tomber sur une table de
     * module qui stocke des jetons ou des empreintes de mot de passe.
     */
    private static $sensitive_key_patterns = array(
        'password', 'passwd', 'secret', 'token', 'api_key', 'apikey',
        'private_key', 'salt', 'nonce', 'auth_key', 'credential',
        // order_key donne acces a la page de commande sans authentification
        'order_key',
        // Un module francais nomme ses colonnes en francais: jeton_api ou
        // mot_de_passe ne contiennent aucun des motifs anglais.
        'jeton', 'mot_de_passe', 'motdepasse', 'cle_api', 'cle_secrete',
        'cle_privee', 'empreinte', 'signature',
    );

    const META_VALUE_MAX_LENGTH = 20000;
    const META_TOTAL_MAX_LENGTH = 65536;
    const META_SHORT_VALUE_LENGTH = 512;
    const META_KEY_MAX_LENGTH = 80;

    /**
     * Turn a key/value map into meta_data pairs. Same contract as the other
     * connectors: short values first so a bulky one cannot evict an
     * identifier, values cut on a character boundary, badly encoded values
     * dropped, and a bounded total per entity.
     */
    private static function meta_pairs($map, $mapped_keys, $prefix = '') {
        if (!is_array($map)) {
            return array();
        }

        $short = array();
        $long = array();
        $skip = array_flip($mapped_keys);
        foreach ($map as $key => $value) {
            if (isset($skip[$key]) || self::is_sensitive_key($key)) {
                continue;
            }
            if ($value === null || !is_scalar($value)) {
                continue;
            }
            $text = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
            // L'ASCII est toujours de l'UTF-8 valide: on ne paie la validation
            // complete que si un octet haut est present.
            if ($text === '' || (preg_match('/[\x80-\xFF]/', $text) === 1 && preg_match('//u', $text) !== 1)) {
                continue;
            }
            $pair_key = self::truncate_utf8($prefix . $key, self::META_KEY_MAX_LENGTH);
            if (strlen($text) <= self::META_SHORT_VALUE_LENGTH) {
                $short[] = array('key' => $pair_key, 'value' => $text);
                continue;
            }
            $long[] = array('key' => $pair_key, 'value' => self::truncate_utf8($text, self::META_VALUE_MAX_LENGTH));
        }

        return self::bound_pairs($short, $long);
    }

    /**
     * Shared by every path that produces meta_data, including the raw key/value
     * meta tables, so a single contract applies everywhere.
     */
    private static function bound_pairs($short, $long) {
        $pairs = $short;
        $budget = self::META_TOTAL_MAX_LENGTH;
        foreach ($short as $pair) {
            $budget -= strlen($pair['value']);
        }
        foreach ($long as $pair) {
            if ($budget <= 0) {
                break;
            }
            $pairs[] = array(
                'key' => $pair['key'],
                'value' => strlen($pair['value']) > $budget ? self::truncate_utf8($pair['value'], $budget) : $pair['value'],
            );
            $budget -= strlen($pair['value']);
        }

        return $pairs;
    }

    const MAX_RELATED_TABLES = 20;

    private static $related_table_denylist = array(
        'wc_orders', 'wc_orders_meta', 'wc_order_addresses', 'wc_order_operational_data',
        'wc_order_stats', 'wc_order_product_lookup', 'wc_order_tax_lookup',
        'wc_order_coupon_lookup', 'wc_customer_lookup', 'wc_download_log',
        'woocommerce_order_items', 'woocommerce_order_itemmeta',
        'posts', 'postmeta', 'users', 'usermeta', 'comments', 'commentmeta',
        'actionscheduler_actions', 'actionscheduler_claims',
        'actionscheduler_groups', 'actionscheduler_logs',
        'wc_admin_notes', 'wc_admin_note_actions', 'wc_webhooks',
        'wc_reserved_stock', 'wc_rate_limits', 'term_relationships',
    );

    /**
     * Any table carrying the entity foreign key, discovered at runtime, so a
     * plugin storing its data in its own table is picked up without touching
     * this connector. The primary key is detected with it: one row per entity
     * is then selected in SQL, so a plugin log table is never loaded whole.
     */
    private function detect_related_tables($fk_column) {
        if (isset($this->related_tables_cache[$fk_column])) {
            return $this->related_tables_cache[$fk_column];
        }

        $prefix = $this->wpdb->prefix;
        $placeholders = implode(',', array_fill(0, count(self::$related_table_denylist), '%s'));
        // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT c.TABLE_NAME AS table_name, MIN(k.COLUMN_NAME) AS pk_column,
                    GROUP_CONCAT(
                        CASE WHEN c.DATA_TYPE IN ('blob','mediumblob','longblob','tinyblob','binary','varbinary')
                             THEN NULL ELSE c.COLUMN_NAME END
                    ) AS safe_columns
             FROM information_schema.COLUMNS c
             LEFT JOIN information_schema.KEY_COLUMN_USAGE k
                    ON (k.TABLE_SCHEMA = c.TABLE_SCHEMA AND k.TABLE_NAME = c.TABLE_NAME
                        AND k.CONSTRAINT_NAME = 'PRIMARY')
             WHERE c.TABLE_SCHEMA = DATABASE()
               AND c.TABLE_NAME LIKE %s
               AND EXISTS (
                   SELECT 1 FROM information_schema.COLUMNS f
                   WHERE f.TABLE_SCHEMA = c.TABLE_SCHEMA AND f.TABLE_NAME = c.TABLE_NAME
                     AND f.COLUMN_NAME = %s
               )
               AND SUBSTRING(c.TABLE_NAME, %d) NOT IN (" . $placeholders . ")
             GROUP BY c.TABLE_NAME
             HAVING COUNT(*) > 1
             ORDER BY c.TABLE_NAME
             LIMIT " . (int) self::MAX_RELATED_TABLES,
            array_merge(
                array($this->wpdb->esc_like($prefix) . '%', $fk_column, strlen($prefix) + 1),
                self::$related_table_denylist
            )
        ), ARRAY_A);

        $tables = array();
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $tables[$row['table_name']] = array(
                    'pk' => (string) ($row['pk_column'] ?? ''),
                    'columns' => array_values(array_filter(explode(',', (string) ($row['safe_columns'] ?? '')))),
                );
            }
        }
        if (count($tables) >= self::MAX_RELATED_TABLES) {
            // Surtout pas d'echo ni de send_line ici: cette detection tourne
            // aussi depuis le hook shutdown d'une requete de boutique, ou toute
            // sortie collerait du contenu dans la page rendue au client.
            Fullmetrix_Logger::log('related_tables_capped', $fk_column);
        }

        $this->related_tables_cache[$fk_column] = $tables;

        return $tables;
    }

    /**
     * Tout ce qui complete la meta d'une commande hors table de meta: colonnes
     * operationnelles, adresses, tables tierces. Partage par la boucle de
     * synchronisation et par le chemin unitaire des webhooks, sans quoi une
     * mise a jour renverrait un payload plus pauvre et effacerait ces champs.
     */
    private function order_extra_meta_batch($order_ids) {
        $operational = $this->table_meta_by_order('wc_order_operational_data', $order_ids, array(
            'discount_total_amount', 'shipping_total_amount',
            'date_paid_gmt', 'date_completed_gmt', 'prices_include_tax',
        ));
        $addresses = $this->table_meta_by_order('wc_order_addresses', $order_ids, array(
            'address_type', 'first_name', 'last_name', 'company',
            'address_1', 'address_2', 'city', 'state', 'postcode',
            'country', 'email', 'phone',
        ), '', 'address_type');
        $related = $this->related_tables_meta('order_id', $order_ids);

        $merged = array();
        foreach ($order_ids as $id) {
            $id = (int) $id;
            $merged[$id] = array_merge(
                $operational[$id] ?? array(),
                $addresses[$id] ?? array(),
                $related[$id] ?? array()
            );
        }

        return $merged;
    }

    /**
     * WordPress n'a pas de convention unique: un module lie ses lignes a un
     * client par user_id ou par customer_id selon l'auteur. Les deux sont
     * suivies et fusionnees.
     */
    private function related_tables_meta_multi($fk_columns, $ids) {
        $merged = array();
        foreach ($fk_columns as $fk) {
            foreach ($this->related_tables_meta($fk, $ids) as $id => $pairs) {
                $merged[$id] = array_merge($merged[$id] ?? array(), $pairs);
            }
        }

        return $merged;
    }

    private function related_tables_meta($fk_column, $ids) {
        if (empty($ids)) {
            return array();
        }

        $id_list = implode(',', array_map('intval', $ids));
        $grouped = array();
        foreach ($this->detect_related_tables($fk_column) as $table => $meta) {
            $pk_column = $meta['pk'];
            if (empty($meta['columns'])) {
                continue;
            }
            // Colonnes listees: une colonne binaire serait lue en memoire pour
            // chaque ligne avant d'etre ecartee par le filtre.
            $select = self::escape_identifier_list($meta['columns']);
            if ($select === null) {
                continue;
            }
            $safe_table = self::escape_identifier($table);
            if ($safe_table === null) {
                continue;
            }
            $short = substr($table, strlen($this->wpdb->prefix));
            $where = "`{$fk_column}` IN ({$id_list})";
            if ($pk_column !== '' && $pk_column !== $fk_column) {
                $where = "(`{$fk_column}`, `{$pk_column}`) IN ("
                    . "SELECT `{$fk_column}`, MAX(`{$pk_column}`) FROM `{$safe_table}`"
                    . " WHERE `{$fk_column}` IN ({$id_list}) GROUP BY `{$fk_column}`)";
            }
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- identifiants valides par escape_identifier, liste blanche [A-Za-z0-9_], et identifiants d'entites entiers
            $rows = $this->wpdb->get_results("SELECT {$select} FROM `{$safe_table}` WHERE {$where}", ARRAY_A);
            if (!is_array($rows)) {
                continue;
            }
            foreach ($rows as $row) {
                $id = (int) $row[$fk_column];
                $grouped[$id] = array_merge(
                    $grouped[$id] ?? array(),
                    self::meta_pairs($row, array('id', $fk_column), $short . '_')
                );
            }
        }

        return $grouped;
    }

    /**
     * All columns of a table keyed by order id, as meta pairs. Kept as its own
     * query rather than joined: two tables both carrying id and order_id would
     * collide in an associative fetch and silently overwrite the order id.
     */
    private function table_meta_by_order($table, $order_ids, $mapped_columns = array(), $prefix = '', $prefix_column = '') {
        if (empty($order_ids)) {
            return array();
        }

        $placeholders = implode(',', array_fill(0, count($order_ids), '%d'));
        // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.NotPrepared
        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT * FROM %i WHERE order_id IN (" . $placeholders . ")",
            array_merge(array($this->wpdb->prefix . $table), $order_ids)
        ), ARRAY_A);

        if (!is_array($rows)) {
            return array();
        }

        $skip = array_merge(array('id', 'order_id'), $mapped_columns);
        $grouped = array();
        foreach ($rows as $row) {
            $oid = (int) $row['order_id'];
            // Several rows can share one order, billing and shipping for
            // instance: without a per-row prefix their keys would collide.
            $row_prefix = $prefix;
            if ($prefix_column !== '' && isset($row[$prefix_column]) && $row[$prefix_column] !== '') {
                $row_prefix .= $row[$prefix_column] . '_';
            }
            $grouped[$oid] = array_merge(
                $grouped[$oid] ?? array(),
                self::meta_pairs($row, $skip, $row_prefix)
            );
        }

        return $grouped;
    }

    private static function is_sensitive_key($key) {
        static $cache = array();
        if (isset($cache[$key])) {
            return $cache[$key];
        }

        // Les attributs de produit sont de la donnee metier, jamais un secret:
        // une boutique de formations vend des creneaux via pa_session, un
        // parfumeur a un attribut Signature.
        if (strpos($key, 'pa_') === 0 || strpos($key, 'attribute_') === 0) {
            return $cache[$key] = false;
        }

        $lower = strtolower((string) $key);
        $sensible = false;
        foreach (self::$sensitive_key_patterns as $pattern) {
            if (strpos($lower, $pattern) !== false) {
                $sensible = true;
                break;
            }
        }

        return $cache[$key] = $sensible;
    }

    /**
     * Les noms viennent d'information_schema, jamais d'une entree utilisateur,
     * mais un identifiant hors de cette liste blanche est refuse plutot
     * qu'echappe: aucun nom legitime n'en sort.
     */
    private static function escape_identifier($name) {
        return preg_match('/^[A-Za-z0-9_]+$/', (string) $name) === 1 ? $name : null;
    }

    private static function escape_identifier_list($names) {
        $safe = array();
        foreach ($names as $name) {
            $clean = self::escape_identifier($name);
            if ($clean === null) {
                return null;
            }
            $safe[] = $clean;
        }

        return empty($safe) ? null : '`' . implode('`, `', $safe) . '`';
    }

    private static function truncate_utf8($text, $max_bytes) {
        if ($max_bytes <= 0) {
            return '';
        }
        if (strlen($text) <= $max_bytes) {
            return $text;
        }
        if (function_exists('mb_strcut')) {
            return mb_strcut($text, 0, $max_bytes, 'UTF-8');
        }

        $cut = substr($text, 0, $max_bytes);
        while ($cut !== '' && preg_match('//u', $cut) !== 1) {
            $cut = substr($cut, 0, -1);
        }

        return $cut;
    }

    private static function clamp_meta_value($value) {
        $text = (string) $value;
        if (strlen($text) <= 20000) {
            return $text;
        }

        // Cut on a character boundary: a byte-level cut splits a multibyte
        // character in two, and wp_json_encode then mangles the value.
        if (function_exists('mb_strcut')) {
            return mb_strcut($text, 0, 20000, 'UTF-8');
        }

        $cut = substr($text, 0, 20000);
        while ($cut !== '' && preg_match('//u', $cut) !== 1) {
            $cut = substr($cut, 0, -1);
        }

        return $cut;
    }

    private static $skip_meta_prefixes = array(
        '_wc_', '_download_', '_edit_lock', '_edit_last',
        '_aw_', '_metorik_', '_woocommerce_persistent_cart',
        '_yoast_', 'omnisend_', '_automatewoo_',
    );

    private function get_order_meta_batch_hpos($order_ids) {
        if (empty($order_ids)) {
            return array();
        }

        $meta_table = $this->wpdb->prefix . 'wc_orders_meta';
        $placeholders = implode(',', array_fill(0, count($order_ids), '%d'));
        $query = $this->wpdb->prepare(
            "SELECT order_id, meta_key, meta_value
             FROM %i
             WHERE order_id IN (" . $placeholders . ")",
            array_merge(array($meta_table), $order_ids)
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        $rows = $this->wpdb->get_results($query);
        $grouped = array();

        $short = array();
        $long = array();
        foreach ($rows as $row) {
            if (!self::keep_meta($row->meta_key, $row->meta_value)) {
                continue;
            }
            $value = (string) $row->meta_value;
            if ($value === '' || (preg_match('/[\x80-\xFF]/', $value) === 1 && preg_match('//u', $value) !== 1)) {
                continue;
            }

            // Rows are kept as a list, not a map: WordPress allows the same key
            // several times on one order and both values matter.
            $oid = (int) $row->order_id;
            $pair = array('key' => self::truncate_utf8($row->meta_key, self::META_KEY_MAX_LENGTH), 'value' => $value);
            if (strlen($value) <= self::META_SHORT_VALUE_LENGTH) {
                $short[$oid][] = $pair;
            } else {
                $pair['value'] = self::truncate_utf8($value, self::META_VALUE_MAX_LENGTH);
                $long[$oid][] = $pair;
            }
        }

        foreach (array_keys($short + $long) as $oid) {
            $grouped[$oid] = self::bound_pairs($short[$oid] ?? array(), $long[$oid] ?? array());
        }

        return $grouped;
    }

    private function get_order_items_batch($order_ids) {
        if (empty($order_ids)) {
            return array();
        }

        $placeholders = implode(',', array_fill(0, count($order_ids), '%d'));
        $oi_table = $this->wpdb->prefix . 'woocommerce_order_items';
        $oim_table = $this->wpdb->prefix . 'woocommerce_order_itemmeta';

        // Query 1: All order items (all types)
        $items_query = $this->wpdb->prepare(
            "SELECT order_item_id, order_id, order_item_name, order_item_type
             FROM %i
             WHERE order_id IN (" . $placeholders . ")",
            array_merge(array($oi_table), $order_ids)
        );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        $items_rows = $this->wpdb->get_results($items_query);

        if (empty($items_rows)) {
            return array();
        }

        $item_ids = wp_list_pluck($items_rows, 'order_item_id');

        // Query 2: All item meta
        $im_placeholders = implode(',', array_fill(0, count($item_ids), '%d'));
        $meta_query = $this->wpdb->prepare(
            "SELECT order_item_id, meta_key, meta_value
             FROM %i
             WHERE order_item_id IN (" . $im_placeholders . ")",
            array_merge(array($oim_table), $item_ids)
        );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        $meta_rows = $this->wpdb->get_results($meta_query);

        // Group meta by item_id
        $item_meta = array();
        foreach ($meta_rows as $row) {
            $item_meta[(int) $row->order_item_id][$row->meta_key] = $row->meta_value;
        }

        // Query 3: SKUs for products referenced in line items
        $product_ids = array();
        foreach ($items_rows as $row) {
            if ($row->order_item_type === 'line_item') {
                $iid = (int) $row->order_item_id;
                $m = $item_meta[$iid] ?? array();
                $pid = (int) ($m['_product_id'] ?? 0);
                $vid = (int) ($m['_variation_id'] ?? 0);
                if ($vid > 0) $product_ids[$vid] = true;
                if ($pid > 0) $product_ids[$pid] = true;
            }
        }

        $sku_map = array();
        if (!empty($product_ids)) {
            $pids = array_keys($product_ids);
            $sku_ph = implode(',', array_fill(0, count($pids), '%d'));
            $sku_query = $this->wpdb->prepare(
                "SELECT post_id, meta_value FROM %i
                 WHERE post_id IN (" . $sku_ph . ") AND meta_key = '_sku'",
                array_merge(array($this->wpdb->postmeta), $pids)
            );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $sku_rows = $this->wpdb->get_results($sku_query);
            foreach ($sku_rows as $row) {
                $sku_map[(int) $row->post_id] = $row->meta_value;
            }
        }

        // Build grouped results by order_id and item type
        $grouped = array();
        foreach ($items_rows as $row) {
            $order_id = (int) $row->order_id;
            $iid = (int) $row->order_item_id;
            $m = $item_meta[$iid] ?? array();

            if (!isset($grouped[$order_id])) {
                $grouped[$order_id] = array(
                    'line_items' => array(),
                    'shipping_lines' => array(),
                    'fee_lines' => array(),
                    'coupon_lines' => array(),
                    'tax_lines' => array(),
                );
            }

            switch ($row->order_item_type) {
                case 'line_item':
                    $pid = (int) ($m['_product_id'] ?? 0);
                    $vid = (int) ($m['_variation_id'] ?? 0);
                    $qty = max(1, (int) ($m['_qty'] ?? 1));
                    $subtotal = (float) ($m['_line_subtotal'] ?? 0);
                    $subtotal_tax = (float) ($m['_line_subtotal_tax'] ?? 0);
                    $total_tax = (float) ($m['_line_tax'] ?? 0);

                    $sku = '';
                    if ($vid > 0 && isset($sku_map[$vid])) {
                        $sku = $sku_map[$vid];
                    } elseif ($pid > 0 && isset($sku_map[$pid])) {
                        $sku = $sku_map[$pid];
                    }

                    $li_meta = self::meta_pairs($m, self::$line_item_mapped_meta);

                    $grouped[$order_id]['line_items'][] = array(
                        'id' => $iid,
                        'name' => $row->order_item_name,
                        'product_id' => $pid ?: null,
                        'variation_id' => $vid ?: null,
                        'quantity' => $qty,
                        'subtotal' => (string) round($subtotal, 2),
                        'subtotal_tax' => (string) round($subtotal_tax, 2),
                        'total' => (string) round((float) ($m['_line_total'] ?? 0), 2),
                        'price' => (string) ($qty > 0 ? round($subtotal / $qty, 2) : 0),
                        'display_price' => (string) ($qty > 0 ? round(($subtotal + $subtotal_tax) / $qty, wc_get_price_decimals()) : 0),
                        'total_tax' => (string) round($total_tax, 2),
                        'tax_rate' => (string) ($subtotal > 0 ? round(($subtotal_tax / $subtotal) * 100, 4) : 0),
                        'sku' => $sku,
                        'meta_data' => $li_meta,
                    );
                    break;

                case 'shipping':
                    $grouped[$order_id]['shipping_lines'][] = array(
                        'id' => $iid,
                        'method_title' => $row->order_item_name,
                        'method_id' => $m['method_id'] ?? '',
                        'total' => (string) ($m['cost'] ?? '0'),
                        'meta_data' => self::meta_pairs($m, array('method_id', 'cost')),
                    );
                    break;

                case 'fee':
                    $grouped[$order_id]['fee_lines'][] = array(
                        'id' => $iid,
                        'name' => $row->order_item_name,
                        'total' => (string) ($m['_line_total'] ?? '0'),
                        'meta_data' => self::meta_pairs($m, array('_line_total')),
                    );
                    break;

                case 'coupon':
                    $grouped[$order_id]['coupon_lines'][] = array(
                        'id' => $iid,
                        'code' => $row->order_item_name,
                        'discount' => (string) ($m['discount_amount'] ?? '0'),
                        'meta_data' => self::meta_pairs($m, array('discount_amount')),
                    );
                    break;

                case 'tax':
                    $grouped[$order_id]['tax_lines'][] = array(
                        'id' => $iid,
                        'label' => $row->order_item_name,
                        'tax_total' => (string) ($m['tax_amount'] ?? '0'),
                        'shipping_tax_total' => (string) ($m['shipping_tax_amount'] ?? '0'),
                        'meta_data' => self::meta_pairs($m, array('tax_amount', 'shipping_tax_amount')),
                    );
                    break;
            }
        }

        return $grouped;
    }

    // ─── REFUNDS ──────────────────────────────────────────────────────

    private function stream_refunds_fast($sync_type = 'full', $since = null, $from_id = 0) {
        if ($this->has_hpos) {
            try {
                $count = $this->stream_refunds_hpos($sync_type, $since, $from_id);
                if ($this->wpdb->last_error) {
                    $this->has_hpos = false;
                    return $this->stream_refunds_legacy($sync_type, $since, $from_id);
                }
                return $count;
            } catch (Exception $e) {
                $this->has_hpos = false;
                return $this->stream_refunds_legacy($sync_type, $since, $from_id);
            } catch (Error $e) {
                $this->has_hpos = false;
                return $this->stream_refunds_legacy($sync_type, $since, $from_id);
            }
        }
        return $this->stream_refunds_legacy($sync_type, $since, $from_id);
    }

    private function stream_refunds_hpos($sync_type = 'full', $since = null, $from_id = 0) {
        $count = 0;
        $last_id = (int) $from_id;
        $orders_table = $this->wpdb->prefix . 'wc_orders';

        $since_where = '';
        if ($sync_type === 'incremental' && $since) {
            $since_where = $this->wpdb->prepare(" AND date_created_gmt > %s", $since);
        }

        do {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $rows = $this->wpdb->get_results(
                $this->wpdb->prepare(
                    "SELECT id, parent_order_id, total_amount, date_created_gmt, status
                    FROM %i
                    WHERE type = 'shop_order_refund'
                    " . $since_where . "
                    AND id > %d
                    ORDER BY id ASC
                    LIMIT %d",
                    $orders_table, $last_id, $this->per_page
                )
            );

            if ($rows === null && $this->wpdb->last_error) {
                $this->send_line(array('type' => 'error', 'entity' => 'refunds', 'message' => 'SQL error: ' . $this->wpdb->last_error));
                $last_id = $this->find_next_id_asc($orders_table, 'id', $last_id, " AND type = 'shop_order_refund'");
                // `find_next_id_asc` rend PHP_INT_MAX quand la table est epuisee, et
                // 0 n'est plus une sentinelle depuis le passage en parcours croissant :
                // sans ce test la boucle repart indefiniment sur une base injoignable.
                if ($last_id <= 0 || $last_id === PHP_INT_MAX) break;
                continue;
            }
            if (empty($rows)) {
                break;
            }

            // Get refund reasons from meta
            $refund_ids = wp_list_pluck($rows, 'id');
            $meta_table = $this->wpdb->prefix . 'wc_orders_meta';
            $ph = implode(',', array_fill(0, count($refund_ids), '%d'));
            $meta_query = $this->wpdb->prepare(
                "SELECT order_id, meta_key, meta_value
                 FROM %i
                 WHERE order_id IN (" . $ph . ")
                 AND meta_key IN ('_refund_reason', '_refunded_by')",
                array_merge(array($meta_table), $refund_ids)
            );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $meta_rows = $this->wpdb->get_results($meta_query);
            $refund_meta = array();
            foreach ($meta_rows as $mr) {
                $refund_meta[(int) $mr->order_id][$mr->meta_key] = $mr->meta_value;
            }

            foreach ($rows as $row) {
                $rid = (int) $row->id;
                $m = $refund_meta[$rid] ?? array();

                $this->send_line(array(
                    'type' => 'refund',
                    '_cursor' => $rid,
                    'data' => array(
                        'id' => $rid,
                        'parent_id' => (int) $row->parent_order_id,
                        'amount' => (string) abs((float) $row->total_amount),
                        'reason' => $m['_refund_reason'] ?? '',
                        'date_created' => self::to_iso($row->date_created_gmt),
                        'refunded_by' => (int) ($m['_refunded_by'] ?? 0),
                    ),
                ));

                $count++;
            }

            $last_id = (int) end($rows)->id;
            unset($rows, $meta_rows, $refund_meta);
            $this->maybe_gc();
        } while (true);

        $this->send_line(array(
            'type' => 'entity_complete',
            'entity' => 'refunds',
            'count' => $count,
        ));

        return $count;
    }

    private function stream_refunds_legacy($sync_type = 'full', $since = null, $from_id = 0) {
        $count = 0;
        $last_id = (int) $from_id;

        $since_where = '';
        if ($sync_type === 'incremental' && $since) {
            $since_where = $this->wpdb->prepare(" AND post_date_gmt > %s", $since);
        }

        do {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $rows = $this->wpdb->get_results(
                $this->wpdb->prepare(
                    "SELECT ID, post_parent, post_date_gmt, post_excerpt
                    FROM %i
                    WHERE post_type = 'shop_order_refund'
                    " . $since_where . "
                    AND ID > %d
                    ORDER BY ID ASC
                    LIMIT %d",
                    $this->wpdb->posts, $last_id, $this->per_page
                )
            );

            if ($rows === null && $this->wpdb->last_error) {
                $this->send_line(array('type' => 'error', 'entity' => 'refunds', 'message' => 'SQL error: ' . $this->wpdb->last_error));
                $last_id = $this->find_next_id_asc($this->wpdb->posts, 'ID', $last_id, " AND post_type = 'shop_order_refund'");
                // `find_next_id_asc` rend PHP_INT_MAX quand la table est epuisee, et
                // 0 n'est plus une sentinelle depuis le passage en parcours croissant :
                // sans ce test la boucle repart indefiniment sur une base injoignable.
                if ($last_id <= 0 || $last_id === PHP_INT_MAX) break;
                continue;
            }
            if (empty($rows)) {
                break;
            }

            $refund_ids = wp_list_pluck($rows, 'ID');
            $ph = implode(',', array_fill(0, count($refund_ids), '%d'));
            $meta_query = $this->wpdb->prepare(
                "SELECT post_id, meta_key, meta_value
                 FROM %i
                 WHERE post_id IN (" . $ph . ")
                 AND meta_key IN ('_refund_amount', '_refunded_by')",
                array_merge(array($this->wpdb->postmeta), $refund_ids)
            );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $meta_rows = $this->wpdb->get_results($meta_query);
            $refund_meta = array();
            foreach ($meta_rows as $mr) {
                $refund_meta[(int) $mr->post_id][$mr->meta_key] = $mr->meta_value;
            }

            foreach ($rows as $row) {
                $rid = (int) $row->ID;
                $m = $refund_meta[$rid] ?? array();

                $this->send_line(array(
                    'type' => 'refund',
                    '_cursor' => $rid,
                    'data' => array(
                        'id' => $rid,
                        'parent_id' => (int) $row->post_parent,
                        'amount' => (string) abs((float) ($m['_refund_amount'] ?? 0)),
                        'reason' => $row->post_excerpt ?: '',
                        'date_created' => self::to_iso($row->post_date_gmt),
                        'refunded_by' => (int) ($m['_refunded_by'] ?? 0),
                    ),
                ));

                $count++;
            }

            $last_id = (int) end($rows)->ID;
            unset($rows, $meta_rows, $refund_meta);
            $this->maybe_gc();
        } while (true);

        $this->send_line(array(
            'type' => 'entity_complete',
            'entity' => 'refunds',
            'count' => $count,
        ));

        return $count;
    }

    // ─── CUSTOMERS ────────────────────────────────────────────────────

    private function stream_customers_fast($sync_type = 'full', $since = null, $from_id = 0) {
        $count = 0;
        $last_id = (int) $from_id;

        $since_where = '';
        if ($sync_type === 'incremental' && $since) {
            $since_where = $this->wpdb->prepare(" AND u.user_registered > %s", $since);
        }

        do {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $rows = $this->wpdb->get_results(
                $this->wpdb->prepare(
                    "SELECT u.ID, u.user_email, u.display_name, u.user_registered
                    FROM %i u
                    WHERE u.ID > %d
                    " . $since_where . "
                    ORDER BY u.ID ASC
                    LIMIT %d",
                    $this->wpdb->users, $last_id, $this->per_page
                )
            );

            if ($rows === null && $this->wpdb->last_error) {
                $this->send_line(array('type' => 'error', 'entity' => 'customers', 'message' => 'SQL error: ' . $this->wpdb->last_error));
                $last_id = $this->find_next_id_asc($this->wpdb->users, 'ID', $last_id);
                // Base injoignable : sans sortie, la meme requete repartirait sans fin.
                if ($last_id <= 0 || $last_id === PHP_INT_MAX) break;
                continue;
            }
            if (empty($rows)) {
                break;
            }

            $user_ids = wp_list_pluck($rows, 'ID');
            $all_usermeta = $this->get_all_usermeta_batch($user_ids);
            $all_roles = $this->get_customer_roles_batch($user_ids);
            $all_related = $this->related_tables_meta_multi(array('user_id', 'customer_id'), $user_ids);

            foreach ($rows as $row) {
                $uid = (int) $row->ID;
                $m = $all_usermeta[$uid] ?? array();
                $user_roles = $all_roles[$uid] ?? array();

                // Build meta_data from non-standard keys
                $known_keys = array(
                    'first_name', 'last_name',
                    'billing_first_name', 'billing_last_name', 'billing_company',
                    'billing_address_1', 'billing_address_2', 'billing_city',
                    'billing_state', 'billing_postcode', 'billing_country',
                    'billing_email', 'billing_phone',
                    'shipping_first_name', 'shipping_last_name', 'shipping_company',
                    'shipping_address_1', 'shipping_address_2', 'shipping_city',
                    'shipping_state', 'shipping_postcode', 'shipping_country',
                    'shipping_phone',
                );
                $meta_data = array_merge(
                    self::meta_pairs($m, $known_keys),
                    $all_related[$uid] ?? array()
                );

                $this->send_line(array(
                    'type' => 'customer',
                    '_cursor' => $uid,
                    'data' => array(
                        'id' => $uid,
                        'email' => $row->user_email,
                        'display_name' => $row->display_name,
                        'first_name' => $m['first_name'] ?? '',
                        'last_name' => $m['last_name'] ?? '',
                        'date_created' => self::to_iso($row->user_registered),
                        'role' => $user_roles[0] ?? '',
                        'roles' => $user_roles,
                        // Keep flat keys for backward compat
                        'company' => $m['billing_company'] ?? '',
                        'phone' => $m['billing_phone'] ?? '',
                        'city' => $m['billing_city'] ?? '',
                        'country' => $m['billing_country'] ?? '',
                        'billing' => array(
                            'first_name' => $m['billing_first_name'] ?? '',
                            'last_name' => $m['billing_last_name'] ?? '',
                            'company' => $m['billing_company'] ?? '',
                            'address_1' => $m['billing_address_1'] ?? '',
                            'address_2' => $m['billing_address_2'] ?? '',
                            'city' => $m['billing_city'] ?? '',
                            'state' => $m['billing_state'] ?? '',
                            'postcode' => $m['billing_postcode'] ?? '',
                            'country' => $m['billing_country'] ?? '',
                            'email' => $m['billing_email'] ?? '',
                            'phone' => $m['billing_phone'] ?? '',
                        ),
                        'shipping' => array(
                            'first_name' => $m['shipping_first_name'] ?? '',
                            'last_name' => $m['shipping_last_name'] ?? '',
                            'company' => $m['shipping_company'] ?? '',
                            'address_1' => $m['shipping_address_1'] ?? '',
                            'address_2' => $m['shipping_address_2'] ?? '',
                            'city' => $m['shipping_city'] ?? '',
                            'state' => $m['shipping_state'] ?? '',
                            'postcode' => $m['shipping_postcode'] ?? '',
                            'country' => $m['shipping_country'] ?? '',
                            'phone' => $m['shipping_phone'] ?? '',
                        ),
                        'meta_data' => $meta_data,
                    ),
                ));

                $count++;
            }

            $last_id = (int) end($rows)->ID;
            unset($rows, $all_usermeta, $all_roles);
            $this->maybe_gc();
        } while (true);

        $this->send_line(array(
            'type' => 'entity_complete',
            'entity' => 'customers',
            'count' => $count,
        ));

        return $count;
    }

    private function get_all_usermeta_batch($user_ids) {
        if (empty($user_ids)) {
            return array();
        }

        $placeholders = implode(',', array_fill(0, count($user_ids), '%d'));
        $prefix_like = $this->wpdb->esc_like($this->wpdb->prefix) . '%';
        // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.NotPrepared
        $query = $this->wpdb->prepare(
            "SELECT user_id, meta_key, meta_value
             FROM %i
             WHERE user_id IN (" . $placeholders . ")
             AND meta_key NOT LIKE %s
             AND meta_key NOT IN (
                'session_tokens', 'rich_editing', 'syntax_highlighting',
                'comment_shortcuts', 'admin_color', 'use_ssl', 'show_admin_bar_front',
                'locale', 'dismissed_wp_pointers', 'show_welcome_panel',
                'managenav-menuscolumnshidden', 'metaboxhidden_nav-menus',
                'nav_menu_recently_edited', 'closedpostboxes_nav-menus'
             )",
            array_merge(array($this->wpdb->usermeta), $user_ids, array($prefix_like))
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        $rows = $this->wpdb->get_results($query);
        $grouped = array();

        foreach ($rows as $row) {
            $grouped[(int) $row->user_id][$row->meta_key] = $row->meta_value;
        }

        return $grouped;
    }

    private function get_customer_roles_batch($user_ids) {
        if (empty($user_ids)) {
            return array();
        }

        $placeholders = implode(',', array_fill(0, count($user_ids), '%d'));
        $capabilities_key = $this->wpdb->prefix . 'capabilities';
        // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.NotPrepared
        $query = $this->wpdb->prepare(
            "SELECT user_id, meta_value
             FROM %i
             WHERE user_id IN (" . $placeholders . ")
             AND meta_key = %s",
            array_merge(array($this->wpdb->usermeta), $user_ids, array($capabilities_key))
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        $rows = $this->wpdb->get_results($query);
        $grouped = array();

        foreach ($rows as $row) {
            $capabilities = maybe_unserialize($row->meta_value);
            if (!is_array($capabilities)) {
                continue;
            }
            $user_roles = array();
            foreach ($capabilities as $role => $enabled) {
                if ($enabled && is_string($role) && $role !== '') {
                    $user_roles[] = $role;
                }
            }
            if (!empty($user_roles)) {
                $grouped[(int) $row->user_id] = $user_roles;
            }
        }

        return $grouped;
    }

    // ─── PRODUCTS ─────────────────────────────────────────────────────

    private function stream_products_fast($sync_type = 'full', $since = null, $from_id = 0) {
        $count = 0;
        $last_id = (int) $from_id;

        $since_where = '';
        if ($sync_type === 'incremental' && $since) {
            $since_where = $this->wpdb->prepare(" AND p.post_modified_gmt > %s", $since);
        }

        do {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $rows = $this->wpdb->get_results(
                $this->wpdb->prepare(
                    "SELECT p.ID, p.post_title, p.post_type, p.post_status, p.post_parent,
                           p.post_content, p.post_excerpt, p.post_name,
                           p.post_date_gmt, p.post_modified_gmt
                    FROM %i p
                    WHERE p.post_type IN ('product', 'product_variation')
                    AND p.post_status IN ('publish', 'draft', 'private')
                    " . $since_where . "
                    AND p.ID > %d
                    ORDER BY p.ID ASC
                    LIMIT %d",
                    $this->wpdb->posts, $last_id, $this->per_page
                )
            );

            if ($rows === null && $this->wpdb->last_error) {
                $this->send_line(array('type' => 'error', 'entity' => 'products', 'message' => 'SQL error: ' . $this->wpdb->last_error));
                $last_id = $this->find_next_id_asc($this->wpdb->posts, 'ID', $last_id, " AND post_type IN ('product','product_variation')");
                // Base injoignable : sans sortie, la meme requete repartirait sans fin.
                if ($last_id <= 0 || $last_id === PHP_INT_MAX) break;
                continue;
            }
            if (empty($rows)) {
                break;
            }

            $product_ids = wp_list_pluck($rows, 'ID');
            $parent_ids_for_brand = array();
            foreach ($rows as $row_for_parent) {
                if ((int) $row_for_parent->post_parent > 0) {
                    $parent_ids_for_brand[] = (int) $row_for_parent->post_parent;
                }
            }
            $brand_lookup_ids = array_values(array_unique(array_merge($product_ids, $parent_ids_for_brand)));

            $all_meta = $this->get_all_postmeta_batch($product_ids);
            $all_related = $this->related_tables_meta('product_id', $product_ids);
            $categories = $this->get_products_categories_batch($product_ids);
            $product_types = $this->get_products_types_batch($product_ids);
            $tags = $this->get_products_tags_batch($product_ids);
            $images = $this->get_products_images_batch($product_ids, $all_meta);
            $brands = $this->get_products_brands_batch($brand_lookup_ids);

            foreach ($rows as $row) {
                $product_id = (int) $row->ID;
                $meta = $all_meta[$product_id] ?? array();
                $type = $row->post_type === 'product_variation' ? 'variation' : ($product_types[$product_id] ?? 'simple');
                $parent_id = (int) $row->post_parent;
                $display_prices = $this->get_display_price_values(
                    $product_id,
                    $meta['_price'] ?? null,
                    $meta['_regular_price'] ?? null,
                    $meta['_sale_price'] ?? null
                );

                $image_url = $images[$product_id]['thumbnail'] ?? null;
                if (!$image_url && $parent_id > 0) {
                    $image_url = $images[$parent_id]['thumbnail'] ?? null;
                }

                $gallery = $images[$product_id]['gallery'] ?? array();

                // Build meta_data from non-standard keys
                $known_keys = array(
                    '_sku', '_price', '_regular_price', '_sale_price',
                    '_stock_status', '_stock', '_product_type', '_thumbnail_id',
                    '_product_image_gallery', '_manage_stock',
                    '_weight', '_length', '_width', '_height',
                    '_tax_status', '_tax_class',
                    '_edit_lock', '_edit_last',
                );
                $meta_data = array_merge(
                    self::meta_pairs($meta, $known_keys),
                    $all_related[$product_id] ?? array()
                );

                $brand = $brands[$product_id] ?? null;
                if (!$brand && $parent_id > 0) {
                    $brand = $brands[$parent_id] ?? null;
                }
                if (!$brand) {
                    foreach (array('_brand', 'pa_marque', 'brand', 'marque') as $brand_key) {
                        if (!empty($meta[$brand_key]) && is_string($meta[$brand_key])) {
                            $brand = $meta[$brand_key];
                            break;
                        }
                    }
                }

                $this->send_line(array(
                    'type' => 'product',
                    '_cursor' => $product_id,
                    'data' => array(
                        'id' => $product_id,
                        'name' => $row->post_title,
                        'slug' => $row->post_name,
                        'permalink' => get_permalink($product_id),
                        'type' => $type,
                        'status' => $row->post_status,
                        'description' => $row->post_content ?: '',
                        'short_description' => $row->post_excerpt ?: '',
                        'sku' => $meta['_sku'] ?? '',
                        'price' => $meta['_price'] ?? null,
                        'regular_price' => $meta['_regular_price'] ?? null,
                        'sale_price' => $meta['_sale_price'] ?? null,
                        'display_price' => $display_prices['price'],
                        'display_regular_price' => $display_prices['regular_price'],
                        'display_sale_price' => $display_prices['sale_price'],
                        'display_price_includes_tax' => $display_prices['includes_tax'],
                        'stock_status' => $meta['_stock_status'] ?? 'instock',
                        'stock_quantity' => isset($meta['_stock']) ? (int) $meta['_stock'] : null,
                        'manage_stock' => ($meta['_manage_stock'] ?? 'no') === 'yes',
                        'weight' => $meta['_weight'] ?? null,
                        'length' => $meta['_length'] ?? null,
                        'width' => $meta['_width'] ?? null,
                        'height' => $meta['_height'] ?? null,
                        'tax_status' => $meta['_tax_status'] ?? 'taxable',
                        'tax_class' => $meta['_tax_class'] ?? '',
                        'category_ids' => $categories[$product_id] ?? array(),
                        'tags' => $tags[$product_id] ?? array(),
                        'parent_id' => $parent_id ?: null,
                        'image_url' => $image_url,
                        'images' => $gallery,
                        'brand' => $brand,
                        'date_created' => self::to_iso($row->post_date_gmt),
                        'date_modified' => self::to_iso($row->post_modified_gmt),
                        'meta_data' => $meta_data,
                    ),
                ));

                $count++;
            }

            $last_id = (int) end($rows)->ID;
            unset($rows, $all_meta, $categories, $tags, $images);
            $this->maybe_gc();
        } while (true);

        $this->send_line(array(
            'type' => 'entity_complete',
            'entity' => 'products',
            'count' => $count,
        ));

        return $count;
    }

    private function get_products_categories_batch($product_ids) {
        if (empty($product_ids)) {
            return array();
        }

        $placeholders = implode(',', array_fill(0, count($product_ids), '%d'));
        // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.NotPrepared
        $query = $this->wpdb->prepare(
            "SELECT tr.object_id, tr.term_taxonomy_id
             FROM %i tr
             INNER JOIN %i tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
             WHERE tr.object_id IN (" . $placeholders . ") AND tt.taxonomy = 'product_cat'",
            array_merge(array($this->wpdb->term_relationships, $this->wpdb->term_taxonomy), $product_ids)
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        $rows = $this->wpdb->get_results($query);
        $grouped = array();

        foreach ($rows as $row) {
            $grouped[(int) $row->object_id][] = (int) $row->term_taxonomy_id;
        }

        return $grouped;
    }

    private function get_products_types_batch($product_ids) {
        if (empty($product_ids)) {
            return array();
        }

        $placeholders = implode(',', array_fill(0, count($product_ids), '%d'));
        // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.NotPrepared
        $query = $this->wpdb->prepare(
            "SELECT tr.object_id, t.slug
             FROM %i tr
             INNER JOIN %i tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
             INNER JOIN %i t ON tt.term_id = t.term_id
             WHERE tr.object_id IN (" . $placeholders . ") AND tt.taxonomy = 'product_type'",
            array_merge(array($this->wpdb->term_relationships, $this->wpdb->term_taxonomy, $this->wpdb->terms), $product_ids)
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        $rows = $this->wpdb->get_results($query);
        $types = array();

        foreach ($rows as $row) {
            $types[(int) $row->object_id] = $row->slug;
        }

        return $types;
    }

    private function get_products_brands_batch($product_ids) {
        if (empty($product_ids)) {
            return array();
        }

        $placeholders = implode(',', array_fill(0, count($product_ids), '%d'));
        // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.NotPrepared
        $query = $this->wpdb->prepare(
            "SELECT tr.object_id, t.name, tt.taxonomy
             FROM %i tr
             JOIN %i tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
             JOIN %i t ON tt.term_id = t.term_id
             WHERE tr.object_id IN (" . $placeholders . ")
               AND tt.taxonomy IN ('product_brand', 'pwb-brand', 'yith_product_brand')
             ORDER BY tr.object_id",
            array_merge(array($this->wpdb->term_relationships, $this->wpdb->term_taxonomy, $this->wpdb->terms), $product_ids)
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        $rows = $this->wpdb->get_results($query);
        $brands = array();

        if (is_array($rows)) {
            foreach ($rows as $row) {
                $obj_id = (int) $row->object_id;
                if (isset($brands[$obj_id])) {
                    continue;
                }
                if (isset($row->name) && $row->name !== '') {
                    $brands[$obj_id] = (string) $row->name;
                }
            }
        }

        return $brands;
    }

    private function get_products_tags_batch($product_ids) {
        if (empty($product_ids)) {
            return array();
        }

        $placeholders = implode(',', array_fill(0, count($product_ids), '%d'));
        // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.NotPrepared
        $query = $this->wpdb->prepare(
            "SELECT tr.object_id, t.term_id, t.name, t.slug
             FROM %i tr
             JOIN %i tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
             JOIN %i t ON tt.term_id = t.term_id
             WHERE tr.object_id IN (" . $placeholders . ") AND tt.taxonomy = 'product_tag'",
            array_merge(array($this->wpdb->term_relationships, $this->wpdb->term_taxonomy, $this->wpdb->terms), $product_ids)
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        $rows = $this->wpdb->get_results($query);
        $grouped = array();

        foreach ($rows as $row) {
            $grouped[(int) $row->object_id][] = array(
                'id' => (int) $row->term_id,
                'name' => $row->name,
                'slug' => $row->slug,
            );
        }

        return $grouped;
    }

    private function get_products_images_batch($product_ids, $all_meta) {
        // Collect all attachment IDs from thumbnails and galleries
        $attachment_ids = array();
        foreach ($product_ids as $pid) {
            $meta = $all_meta[$pid] ?? array();
            $thumb_id = (int) ($meta['_thumbnail_id'] ?? 0);
            if ($thumb_id > 0) {
                $attachment_ids[$thumb_id] = true;
            }
            $gallery_str = $meta['_product_image_gallery'] ?? '';
            if ($gallery_str) {
                foreach (explode(',', $gallery_str) as $gid) {
                    $gid = (int) trim($gid);
                    if ($gid > 0) {
                        $attachment_ids[$gid] = true;
                    }
                }
            }
        }

        if (empty($attachment_ids)) {
            return array();
        }

        // Resolve GUIDs
        $aids = array_keys($attachment_ids);
        $ph = implode(',', array_fill(0, count($aids), '%d'));
        $query = $this->wpdb->prepare(
            "SELECT ID, guid FROM %i WHERE ID IN (" . $ph . ")",
            array_merge(array($this->wpdb->posts), $aids)
        );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        $att_rows = $this->wpdb->get_results($query);
        $guid_map = array();
        foreach ($att_rows as $row) {
            $guid_map[(int) $row->ID] = $row->guid;
        }

        // Build result per product
        $result = array();
        foreach ($product_ids as $pid) {
            $meta = $all_meta[$pid] ?? array();
            $thumb_id = (int) ($meta['_thumbnail_id'] ?? 0);
            $result[$pid] = array(
                'thumbnail' => ($thumb_id > 0 && isset($guid_map[$thumb_id])) ? $guid_map[$thumb_id] : null,
                'gallery' => array(),
            );

            $gallery_str = $meta['_product_image_gallery'] ?? '';
            if ($gallery_str) {
                foreach (explode(',', $gallery_str) as $gid) {
                    $gid = (int) trim($gid);
                    if ($gid > 0 && isset($guid_map[$gid])) {
                        $result[$pid]['gallery'][] = array('src' => $guid_map[$gid]);
                    }
                }
            }
        }

        return $result;
    }

    // ─── CATEGORIES ───────────────────────────────────────────────────

    private function stream_categories_fast($from_id = 0) {
        $count = 0;
        $last_id = (int) $from_id;

        do {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $rows = $this->wpdb->get_results(
                $this->wpdb->prepare(
                    "SELECT t.term_id, t.name, t.slug, tt.parent, tt.description, tt.count
                    FROM %i t
                    INNER JOIN %i tt ON t.term_id = tt.term_id
                    WHERE tt.taxonomy = 'product_cat'
                    AND t.term_id > %d
                    ORDER BY t.term_id ASC
                    LIMIT %d",
                    $this->wpdb->terms, $this->wpdb->term_taxonomy, $last_id, $this->per_page
                )
            );

            if ($rows === null && $this->wpdb->last_error) {
                $this->send_line(array('type' => 'error', 'entity' => 'categories', 'message' => 'SQL error: ' . $this->wpdb->last_error));
                $last_id = $this->find_next_id_asc($this->wpdb->terms, 'term_id', $last_id);
                // Base injoignable : sans sortie, la meme requete repartirait sans fin.
                if ($last_id <= 0 || $last_id === PHP_INT_MAX) break;
                continue;
            }
            if (empty($rows)) {
                break;
            }

            $term_ids = wp_list_pluck($rows, 'term_id');
            $images = $this->get_term_images_batch($term_ids);

            foreach ($rows as $row) {
                $term_id = (int) $row->term_id;

                $this->send_line(array(
                    'type' => 'category',
                    '_cursor' => $term_id,
                    'data' => array(
                        'id' => $term_id,
                        'name' => $row->name,
                        'slug' => $row->slug,
                        'parent_id' => (int) $row->parent ?: null,
                        'description' => $row->description ?? '',
                        'count' => (int) $row->count,
                        'image_url' => $images[$term_id] ?? null,
                    ),
                ));

                $count++;
            }

            $last_id = (int) end($rows)->term_id;
            unset($rows, $images);
            $this->maybe_gc();
        } while (true);

        $this->send_line(array(
            'type' => 'entity_complete',
            'entity' => 'categories',
            'count' => $count,
        ));

        return $count;
    }

    private function get_term_images_batch($term_ids) {
        if (empty($term_ids)) {
            return array();
        }

        $placeholders = implode(',', array_fill(0, count($term_ids), '%d'));
        // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.NotPrepared
        $query = $this->wpdb->prepare(
            "SELECT tm.term_id, p.guid
             FROM %i tm
             INNER JOIN %i p ON tm.meta_value = p.ID
             WHERE tm.term_id IN (" . $placeholders . ") AND tm.meta_key = 'thumbnail_id'",
            array_merge(array($this->wpdb->termmeta, $this->wpdb->posts), $term_ids)
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        $rows = $this->wpdb->get_results($query);
        $images = array();

        foreach ($rows as $row) {
            $images[(int) $row->term_id] = $row->guid;
        }

        return $images;
    }

    // ─── COUPONS ──────────────────────────────────────────────────────

    private function stream_coupons_fast($sync_type = 'full', $since = null, $from_id = 0) {
        $count = 0;
        $last_id = (int) $from_id;

        $since_where = '';
        if ($sync_type === 'incremental' && $since) {
            $since_where = $this->wpdb->prepare(" AND p.post_modified_gmt > %s", $since);
        }

        do {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $rows = $this->wpdb->get_results(
                $this->wpdb->prepare(
                    "SELECT p.ID, p.post_title as code, p.post_excerpt as description, p.post_date_gmt
                    FROM %i p
                    WHERE p.post_type = 'shop_coupon'
                    " . $since_where . "
                    AND p.ID > %d
                    ORDER BY p.ID ASC
                    LIMIT %d",
                    $this->wpdb->posts, $last_id, $this->per_page
                )
            );

            if ($rows === null && $this->wpdb->last_error) {
                $this->send_line(array('type' => 'error', 'entity' => 'coupons', 'message' => 'SQL error: ' . $this->wpdb->last_error));
                $last_id = $this->find_next_id_asc($this->wpdb->posts, 'ID', $last_id, " AND post_type = 'shop_coupon'");
                // Base injoignable : sans sortie, la meme requete repartirait sans fin.
                if ($last_id <= 0 || $last_id === PHP_INT_MAX) break;
                continue;
            }
            if (empty($rows)) {
                break;
            }

            $coupon_ids = wp_list_pluck($rows, 'ID');
            $all_meta = $this->get_all_postmeta_batch($coupon_ids);

            foreach ($rows as $row) {
                $coupon_id = (int) $row->ID;
                $meta = $all_meta[$coupon_id] ?? array();

                // Build meta_data from non-standard keys
                $known_keys = array(
                    'discount_type', 'coupon_amount', 'usage_count', 'usage_limit',
                    'usage_limit_per_user', 'limit_usage_to_x_items',
                    'date_expires', 'minimum_amount', 'maximum_amount',
                    'free_shipping', 'individual_use', 'exclude_sale_items',
                    'product_ids', 'exclude_product_ids',
                    'product_categories', 'exclude_product_categories',
                    'customer_email',
                );
                $meta_data = self::meta_pairs($meta, $known_keys);

                // Parse serialized arrays safely
                $product_ids = $this->maybe_unserialize_ids($meta['product_ids'] ?? '');
                $excluded_product_ids = $this->maybe_unserialize_ids($meta['exclude_product_ids'] ?? '');
                $product_categories = $this->maybe_unserialize_ids($meta['product_categories'] ?? '');
                $excluded_product_categories = $this->maybe_unserialize_ids($meta['exclude_product_categories'] ?? '');
                $email_restrictions = $this->maybe_unserialize_array($meta['customer_email'] ?? '');

                $this->send_line(array(
                    'type' => 'coupon',
                    '_cursor' => $coupon_id,
                    'data' => array(
                        'id' => $coupon_id,
                        'code' => $row->code,
                        'description' => $row->description ?: '',
                        'discount_type' => $meta['discount_type'] ?? 'fixed_cart',
                        'amount' => (string) ($meta['coupon_amount'] ?? '0'),
                        'usage_count' => (int) ($meta['usage_count'] ?? 0),
                        'usage_limit' => isset($meta['usage_limit']) && $meta['usage_limit'] !== '' ? (int) $meta['usage_limit'] : null,
                        'usage_limit_per_user' => isset($meta['usage_limit_per_user']) && $meta['usage_limit_per_user'] !== '' ? (int) $meta['usage_limit_per_user'] : null,
                        'individual_use' => ($meta['individual_use'] ?? 'no') === 'yes',
                        'exclude_sale_items' => ($meta['exclude_sale_items'] ?? 'no') === 'yes',
                        'free_shipping' => ($meta['free_shipping'] ?? 'no') === 'yes',
                        'product_ids' => $product_ids,
                        'excluded_product_ids' => $excluded_product_ids,
                        'product_categories' => $product_categories,
                        'excluded_product_categories' => $excluded_product_categories,
                        'email_restrictions' => $email_restrictions,
                        'minimum_amount' => isset($meta['minimum_amount']) && $meta['minimum_amount'] !== '' ? (string) $meta['minimum_amount'] : null,
                        'maximum_amount' => isset($meta['maximum_amount']) && $meta['maximum_amount'] !== '' ? (string) $meta['maximum_amount'] : null,
                        'date_created' => self::to_iso($row->post_date_gmt),
                        'date_expires' => isset($meta['date_expires']) && $meta['date_expires'] !== '' ? gmdate('c', (int) $meta['date_expires']) : null,
                        'meta_data' => $meta_data,
                    ),
                ));

                $count++;
            }

            $last_id = (int) end($rows)->ID;
            unset($rows, $all_meta);
            $this->maybe_gc();
        } while (true);

        $this->send_line(array(
            'type' => 'entity_complete',
            'entity' => 'coupons',
            'count' => $count,
        ));

        return $count;
    }

    // ─── CARTS ───────────────────────────────────────────────────────

    // ─── SUBSCRIPTIONS ───────────────────────────────────────────────

    private function stream_subscriptions_fast($sync_type = 'full', $since = null, $from_id = 0) {
        if ($this->has_hpos) {
            try {
                $count = $this->stream_subscriptions_hpos($sync_type, $since, $from_id);
                if ($this->wpdb->last_error) {
                    $this->send_line(array(
                        'type' => 'warning',
                        'message' => 'HPOS subscriptions error, falling back to legacy',
                        'error' => $this->wpdb->last_error,
                    ));
                    return $this->stream_subscriptions_legacy($sync_type, $since, $from_id);
                }
                return $count;
            } catch (Exception $e) {
                $this->send_line(array(
                    'type' => 'warning',
                    'message' => 'HPOS subscriptions exception, falling back to legacy',
                    'error' => $e->getMessage(),
                ));
                return $this->stream_subscriptions_legacy($sync_type, $since, $from_id);
            } catch (Error $e) {
                $this->send_line(array(
                    'type' => 'warning',
                    'message' => 'HPOS subscriptions fatal error, falling back to legacy',
                    'error' => $e->getMessage(),
                ));
                return $this->stream_subscriptions_legacy($sync_type, $since, $from_id);
            }
        }
        return $this->stream_subscriptions_legacy($sync_type, $since, $from_id);
    }

    private function stream_subscriptions_hpos($sync_type, $since, $from_id = 0) {
        $count = 0;
        $last_id = (int) $from_id;
        $batch_size = $this->orders_per_page;
        $orders_table = $this->wpdb->prefix . 'wc_orders';
        $addresses_table = $this->wpdb->prefix . 'wc_order_addresses';
        $op_table = $this->wpdb->prefix . 'wc_order_operational_data';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        $addresses_exists = $this->wpdb->get_var(
            $this->wpdb->prepare("SHOW TABLES LIKE %s", $addresses_table)
        );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        $op_exists = $this->wpdb->get_var(
            $this->wpdb->prepare("SHOW TABLES LIKE %s", $op_table)
        );

        if (!$addresses_exists || !$op_exists) {
            $this->send_line(array(
                'type' => 'entity_complete',
                'entity' => 'subscriptions',
                'count' => 0,
            ));
            return 0;
        }

        $base_where = "WHERE o.type = 'shop_subscription' AND o.status NOT IN ('trash', 'auto-draft')";
        if ($sync_type === 'incremental' && $since) {
            $base_where .= $this->wpdb->prepare(" AND o.date_updated_gmt > %s", $since);
        }

        do {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $rows = $this->wpdb->get_results(
                $this->wpdb->prepare(
                    "SELECT
                        o.id, o.status, o.currency, o.total_amount, o.tax_amount,
                        o.date_created_gmt, o.date_updated_gmt, o.customer_id,
                        o.payment_method, o.payment_method_title, o.parent_order_id,
                        op.discount_total_amount, op.shipping_total_amount,
                        ba.first_name as billing_first_name, ba.last_name as billing_last_name,
                        ba.company as billing_company, ba.address_1 as billing_address_1,
                        ba.address_2 as billing_address_2, ba.city as billing_city,
                        ba.state as billing_state, ba.postcode as billing_postcode,
                        ba.country as billing_country, ba.email as billing_email, ba.phone as billing_phone,
                        sa.first_name as shipping_first_name, sa.last_name as shipping_last_name,
                        sa.company as shipping_company, sa.address_1 as shipping_address_1,
                        sa.address_2 as shipping_address_2, sa.city as shipping_city,
                        sa.state as shipping_state, sa.postcode as shipping_postcode,
                        sa.country as shipping_country, sa.phone as shipping_phone
                    FROM %i o
                    LEFT JOIN %i op ON o.id = op.order_id
                    LEFT JOIN %i ba ON o.id = ba.order_id AND ba.address_type = 'billing'
                    LEFT JOIN %i sa ON o.id = sa.order_id AND sa.address_type = 'shipping'
                    " . $base_where . " AND o.id > %d
                    ORDER BY o.id ASC
                    LIMIT %d",
                    $orders_table, $op_table, $addresses_table, $addresses_table,
                    $last_id, $batch_size
                )
            );

            if ($rows === null && $this->wpdb->last_error) {
                $this->send_line(array('type' => 'error', 'entity' => 'subscriptions', 'message' => 'SQL error: ' . $this->wpdb->last_error));
                $last_id = $this->find_next_id_asc($orders_table, 'id', $last_id, " AND type = 'shop_subscription'");
                // `find_next_id_asc` rend PHP_INT_MAX quand la table est epuisee, et
                // 0 n'est plus une sentinelle depuis le passage en parcours croissant :
                // sans ce test la boucle repart indefiniment sur une base injoignable.
                if ($last_id <= 0 || $last_id === PHP_INT_MAX) break;
                continue;
            }
            if (empty($rows)) break;

            $sub_ids = wp_list_pluck($rows, 'id');
            $all_items = $this->get_order_items_batch($sub_ids);
            $all_meta = $this->get_order_meta_batch_hpos($sub_ids);

            foreach ($rows as $row) {
                $sub_id = (int) $row->id;
                $items = $all_items[$sub_id] ?? array();
                $meta = $all_meta[$sub_id] ?? array();

                $sub_meta = $this->extract_subscription_meta($meta);

                $this->send_line(array(
                    'type' => 'subscription',
                    '_cursor' => $sub_id,
                    'data' => array(
                        'id' => $sub_id,
                        'number' => (string) $sub_id,
                        'status' => str_replace('wc-', '', $row->status),
                        'currency' => $row->currency,
                        'total' => (string) $row->total_amount,
                        'discount_total' => (string) ($row->discount_total_amount ?? '0'),
                        'shipping_total' => (string) ($row->shipping_total_amount ?? '0'),
                        'total_tax' => (string) ($row->tax_amount ?? '0'),
                        'date_created' => self::to_iso($row->date_created_gmt),
                        'date_modified' => self::to_iso($row->date_updated_gmt),
                        'payment_method' => $row->payment_method ?: null,
                        'payment_method_title' => $row->payment_method_title ?: null,
                        'customer_id' => (int) $row->customer_id,
                        'parent_id' => (int) $row->parent_order_id ?: null,
                        'billing_period' => $sub_meta['billing_period'],
                        'billing_interval' => $sub_meta['billing_interval'],
                        'start_date' => $sub_meta['start_date'],
                        'trial_end_date' => $sub_meta['trial_end_date'],
                        'next_payment_date' => $sub_meta['next_payment_date'],
                        'end_date' => $sub_meta['end_date'],
                        'requires_manual_renewal' => $sub_meta['requires_manual_renewal'],
                        'billing' => array(
                            'first_name' => $row->billing_first_name ?? '',
                            'last_name' => $row->billing_last_name ?? '',
                            'company' => $row->billing_company ?? '',
                            'address_1' => $row->billing_address_1 ?? '',
                            'address_2' => $row->billing_address_2 ?? '',
                            'city' => $row->billing_city ?? '',
                            'state' => $row->billing_state ?? '',
                            'postcode' => $row->billing_postcode ?? '',
                            'country' => $row->billing_country ?? '',
                            'email' => $row->billing_email ?? '',
                            'phone' => $row->billing_phone ?? '',
                        ),
                        'shipping' => array(
                            'first_name' => $row->shipping_first_name ?? '',
                            'last_name' => $row->shipping_last_name ?? '',
                            'company' => $row->shipping_company ?? '',
                            'address_1' => $row->shipping_address_1 ?? '',
                            'address_2' => $row->shipping_address_2 ?? '',
                            'city' => $row->shipping_city ?? '',
                            'state' => $row->shipping_state ?? '',
                            'postcode' => $row->shipping_postcode ?? '',
                            'country' => $row->shipping_country ?? '',
                            'phone' => $row->shipping_phone ?? '',
                        ),
                        'line_items' => $items['line_items'] ?? array(),
                        'shipping_lines' => $items['shipping_lines'] ?? array(),
                        'fee_lines' => $items['fee_lines'] ?? array(),
                        'tax_lines' => $items['tax_lines'] ?? array(),
                        'meta_data' => $meta,
                    ),
                ));

                $count++;
            }

            $last_id = (int) end($rows)->id;
            unset($rows, $all_items, $all_meta);

            $mem_pct = memory_get_usage(true) / $this->memory_limit_bytes;
            if ($mem_pct > 0.7 && $batch_size > 100) {
                $batch_size = max(100, (int) ($batch_size * 0.5));
            } elseif ($mem_pct < 0.4 && $batch_size < 1000) {
                $batch_size = min(1000, (int) ($batch_size * 1.5));
            }

            $this->maybe_gc();
        } while (true);

        $this->send_line(array(
            'type' => 'entity_complete',
            'entity' => 'subscriptions',
            'count' => $count,
        ));

        return $count;
    }

    private function stream_subscriptions_legacy($sync_type, $since, $from_id = 0) {
        $count = 0;
        $last_id = (int) $from_id;
        $batch_size = $this->orders_per_page;

        $base_where = "WHERE p.post_type = 'shop_subscription' AND p.post_status NOT IN ('trash', 'auto-draft')";
        if ($sync_type === 'incremental' && $since) {
            $base_where .= $this->wpdb->prepare(" AND p.post_modified_gmt > %s", $since);
        }

        $known_keys = array(
            '_order_total', '_order_currency', '_cart_discount', '_order_shipping', '_order_tax',
            '_billing_first_name', '_billing_last_name', '_billing_company', '_billing_address_1',
            '_billing_address_2', '_billing_city', '_billing_state', '_billing_postcode',
            '_billing_country', '_billing_email', '_billing_phone',
            '_shipping_first_name', '_shipping_last_name', '_shipping_company', '_shipping_address_1',
            '_shipping_address_2', '_shipping_city', '_shipping_state', '_shipping_postcode',
            '_shipping_country', '_shipping_phone',
            '_payment_method', '_payment_method_title', '_customer_user',
            '_billing_period', '_billing_interval',
            '_schedule_start', '_schedule_trial_end', '_schedule_next_payment', '_schedule_end',
            '_requires_manual_renewal',
        );

        do {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $rows = $this->wpdb->get_results(
                $this->wpdb->prepare(
                    "SELECT p.ID, p.post_status, p.post_date_gmt, p.post_modified_gmt
                    FROM %i p
                    " . $base_where . " AND p.ID > %d
                    ORDER BY p.ID ASC
                    LIMIT %d",
                    $this->wpdb->posts, $last_id, $batch_size
                )
            );

            if ($rows === null && $this->wpdb->last_error) {
                $this->send_line(array('type' => 'error', 'entity' => 'subscriptions', 'message' => 'SQL error: ' . $this->wpdb->last_error));
                $last_id = $this->find_next_id_asc($this->wpdb->posts, 'ID', $last_id, " AND post_type = 'shop_subscription'");
                // `find_next_id_asc` rend PHP_INT_MAX quand la table est epuisee, et
                // 0 n'est plus une sentinelle depuis le passage en parcours croissant :
                // sans ce test la boucle repart indefiniment sur une base injoignable.
                if ($last_id <= 0 || $last_id === PHP_INT_MAX) break;
                continue;
            }
            if (empty($rows)) break;

            $sub_ids = wp_list_pluck($rows, 'ID');
            $all_postmeta = $this->get_all_postmeta_batch($sub_ids);
            $all_items = $this->get_order_items_batch($sub_ids);

            foreach ($rows as $row) {
                $sub_id = (int) $row->ID;
                $meta = $all_postmeta[$sub_id] ?? array();
                $items = $all_items[$sub_id] ?? array();

                $meta_data = self::meta_pairs($meta, $known_keys);

                $billing_period = $meta['_billing_period'] ?? null;
                $billing_interval = isset($meta['_billing_interval']) ? (int) $meta['_billing_interval'] : 1;
                $start_date = isset($meta['_schedule_start']) && $meta['_schedule_start']
                    ? self::to_iso($meta['_schedule_start']) : null;
                $trial_end_date = isset($meta['_schedule_trial_end']) && $meta['_schedule_trial_end']
                    ? self::to_iso($meta['_schedule_trial_end']) : null;
                $next_payment_date = isset($meta['_schedule_next_payment']) && $meta['_schedule_next_payment']
                    ? self::to_iso($meta['_schedule_next_payment']) : null;
                $end_date = isset($meta['_schedule_end']) && $meta['_schedule_end']
                    ? self::to_iso($meta['_schedule_end']) : null;
                $requires_manual = ($meta['_requires_manual_renewal'] ?? 'false') === 'true';

                $this->send_line(array(
                    'type' => 'subscription',
                    '_cursor' => $sub_id,
                    'data' => array(
                        'id' => $sub_id,
                        'number' => (string) $sub_id,
                        'status' => str_replace('wc-', '', $row->post_status),
                        'currency' => $meta['_order_currency'] ?? 'EUR',
                        'total' => (string) ($meta['_order_total'] ?? '0'),
                        'discount_total' => (string) ($meta['_cart_discount'] ?? '0'),
                        'shipping_total' => (string) ($meta['_order_shipping'] ?? '0'),
                        'total_tax' => (string) ($meta['_order_tax'] ?? '0'),
                        'date_created' => self::to_iso($row->post_date_gmt),
                        'date_modified' => self::to_iso($row->post_modified_gmt),
                        'payment_method' => $meta['_payment_method'] ?? null,
                        'payment_method_title' => $meta['_payment_method_title'] ?? null,
                        'customer_id' => (int) ($meta['_customer_user'] ?? 0),
                        'parent_id' => null,
                        'billing_period' => $billing_period,
                        'billing_interval' => $billing_interval,
                        'start_date' => $start_date,
                        'trial_end_date' => $trial_end_date,
                        'next_payment_date' => $next_payment_date,
                        'end_date' => $end_date,
                        'requires_manual_renewal' => $requires_manual,
                        'billing' => array(
                            'first_name' => $meta['_billing_first_name'] ?? '',
                            'last_name' => $meta['_billing_last_name'] ?? '',
                            'company' => $meta['_billing_company'] ?? '',
                            'address_1' => $meta['_billing_address_1'] ?? '',
                            'address_2' => $meta['_billing_address_2'] ?? '',
                            'city' => $meta['_billing_city'] ?? '',
                            'state' => $meta['_billing_state'] ?? '',
                            'postcode' => $meta['_billing_postcode'] ?? '',
                            'country' => $meta['_billing_country'] ?? '',
                            'email' => $meta['_billing_email'] ?? '',
                            'phone' => $meta['_billing_phone'] ?? '',
                        ),
                        'shipping' => array(
                            'first_name' => $meta['_shipping_first_name'] ?? '',
                            'last_name' => $meta['_shipping_last_name'] ?? '',
                            'company' => $meta['_shipping_company'] ?? '',
                            'address_1' => $meta['_shipping_address_1'] ?? '',
                            'address_2' => $meta['_shipping_address_2'] ?? '',
                            'city' => $meta['_shipping_city'] ?? '',
                            'state' => $meta['_shipping_state'] ?? '',
                            'postcode' => $meta['_shipping_postcode'] ?? '',
                            'country' => $meta['_shipping_country'] ?? '',
                            'phone' => $meta['_shipping_phone'] ?? '',
                        ),
                        'line_items' => $items['line_items'] ?? array(),
                        'shipping_lines' => $items['shipping_lines'] ?? array(),
                        'fee_lines' => $items['fee_lines'] ?? array(),
                        'tax_lines' => $items['tax_lines'] ?? array(),
                        'meta_data' => $meta_data,
                    ),
                ));

                $count++;
            }

            $last_id = (int) end($rows)->ID;
            unset($rows, $all_postmeta, $all_items);

            $mem_pct = memory_get_usage(true) / $this->memory_limit_bytes;
            if ($mem_pct > 0.7 && $batch_size > 100) {
                $batch_size = max(100, (int) ($batch_size * 0.5));
            } elseif ($mem_pct < 0.4 && $batch_size < 1000) {
                $batch_size = min(1000, (int) ($batch_size * 1.5));
            }

            $this->maybe_gc();
        } while (true);

        $this->send_line(array(
            'type' => 'entity_complete',
            'entity' => 'subscriptions',
            'count' => $count,
        ));

        return $count;
    }

    private function extract_subscription_meta($meta) {
        $result = array(
            'billing_period' => null,
            'billing_interval' => 1,
            'start_date' => null,
            'trial_end_date' => null,
            'next_payment_date' => null,
            'end_date' => null,
            'requires_manual_renewal' => false,
        );

        // HPOS stores meta as array of {key, value}
        foreach ($meta as $entry) {
            if (!is_array($entry) || !isset($entry['key'])) continue;
            $key = $entry['key'];
            $value = $entry['value'];

            switch ($key) {
                case '_billing_period':
                    $result['billing_period'] = $value;
                    break;
                case '_billing_interval':
                    $result['billing_interval'] = (int) $value;
                    break;
                case '_schedule_start':
                    $result['start_date'] = self::to_iso($value);
                    break;
                case '_schedule_trial_end':
                    $result['trial_end_date'] = self::to_iso($value);
                    break;
                case '_schedule_next_payment':
                    $result['next_payment_date'] = self::to_iso($value);
                    break;
                case '_schedule_end':
                    $result['end_date'] = self::to_iso($value);
                    break;
                case '_requires_manual_renewal':
                    $result['requires_manual_renewal'] = $value === 'true';
                    break;
            }
        }

        return $result;
    }

    // ─── HELPERS ──────────────────────────────────────────────────────

    private function get_raw_order_diagnostic() {
        $orders_table = $this->wpdb->prefix . 'wc_orders';
        $posts_table = $this->wpdb->posts;

        $result = array(
            'timestamp' => gmdate('c'),
        );

        // Check HPOS table
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        $hpos_exists = (bool) $this->wpdb->get_var(
            $this->wpdb->prepare("SHOW TABLES LIKE %s", $orders_table)
        );
        $result['hpos_table_exists'] = $hpos_exists;

        if ($hpos_exists) {
            // Total count with all types
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $result['hpos_all_types'] = $this->wpdb->get_results(
                $this->wpdb->prepare("SELECT type, status, COUNT(*) as cnt FROM %i GROUP BY type, status ORDER BY cnt DESC LIMIT 20", $orders_table)
            );

            // Sample order IDs from HPOS
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $sample_hpos = $this->wpdb->get_results(
                $this->wpdb->prepare("SELECT id, type, status, date_created_gmt FROM %i WHERE type = 'shop_order' ORDER BY id DESC LIMIT 5", $orders_table)
            );
            $result['hpos_sample_orders'] = $sample_hpos;

            // Raw count without any filter
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $result['hpos_raw_shop_order_count'] = (int) $this->wpdb->get_var(
                $this->wpdb->prepare("SELECT COUNT(*) FROM %i WHERE type = 'shop_order'", $orders_table)
            );
            $result['hpos_query_error'] = $this->wpdb->last_error ?: null;
        }

        // Check Legacy table (wp_posts)
        $order_like = '%' . $this->wpdb->esc_like('order') . '%';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        $result['legacy_all_types'] = $this->wpdb->get_results(
            $this->wpdb->prepare("SELECT post_type, post_status, COUNT(*) as cnt FROM %i WHERE post_type LIKE %s OR post_type = 'shop_order' GROUP BY post_type, post_status ORDER BY cnt DESC LIMIT 20", $posts_table, $order_like)
        );

        // Sample order IDs from Legacy
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        $sample_legacy = $this->wpdb->get_results(
            $this->wpdb->prepare("SELECT ID, post_type, post_status, post_date_gmt FROM %i WHERE post_type = 'shop_order' ORDER BY ID DESC LIMIT 5", $posts_table)
        );
        $result['legacy_sample_orders'] = $sample_legacy;

        // Raw count without any filter
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        $result['legacy_raw_shop_order_count'] = (int) $this->wpdb->get_var(
            $this->wpdb->prepare("SELECT COUNT(*) FROM %i WHERE post_type = 'shop_order'", $posts_table)
        );
        $result['legacy_query_error'] = $this->wpdb->last_error ?: null;

        // Check what WooCommerce thinks about HPOS
        $result['wc_hpos_setting'] = get_option('woocommerce_custom_orders_table_enabled', 'not_set');
        $result['wc_orders_data_store'] = get_option('woocommerce_custom_orders_table_data_store', 'not_set');

        // Check if there are any post_type registrations for orders
        global $wp_post_types;
        $result['shop_order_post_type_registered'] = isset($wp_post_types['shop_order']);

        return $result;
    }

    private function maybe_unserialize_ids($value) {
        if (empty($value)) {
            return array();
        }
        $unserialized = maybe_unserialize($value);
        if (is_array($unserialized)) {
            return array_map('intval', $unserialized);
        }
        // Comma-separated string fallback
        return array_map('intval', array_filter(explode(',', $value)));
    }

    private function maybe_unserialize_array($value) {
        if (empty($value)) {
            return array();
        }
        $unserialized = maybe_unserialize($value);
        if (is_array($unserialized)) {
            return $unserialized;
        }
        return array_filter(explode(',', $value));
    }

    /**
     * Find the next valid ID for cursor recovery after a failed query.
     * For DESC order (orders/refunds/subscriptions): find MAX(id) < current_last_id
     * For ASC order (customers/products/categories/coupons): find MIN(id) > current_last_id
     */

    private function find_next_id_asc($table, $id_column, $after_id, $extra_where = '') {
        $safe_column = esc_sql($id_column);
        $safe_table = esc_sql($table);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        $row = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT MIN({$safe_column}) FROM {$safe_table} WHERE {$safe_column} > %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $after_id
            ) . $extra_where
        );
        return $row !== null ? (int) $row - 1 : PHP_INT_MAX;
    }

    private function send_line($data) {
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- NDJSON streaming endpoint, not HTML
        echo wp_json_encode($data, JSON_UNESCAPED_UNICODE) . "\n";
        if (ob_get_level()) {
            @ob_flush(); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- ob_flush may warn if no buffer active
        }
        flush();
    }

    private function maybe_gc() {
        static $counter = 0;
        $counter++;

        if ($counter % 3 === 0 && function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }

        $memory_usage = memory_get_usage(true);
        $memory_threshold = $this->memory_limit_bytes * 0.8;

        if ($memory_usage > $memory_threshold) {
            if ($this->per_page > 100) {
                $this->per_page = max(100, (int) ($this->per_page * 0.5));
            }
            if ($this->orders_per_page > 100) {
                $this->orders_per_page = max(100, (int) ($this->orders_per_page * 0.5));
            }
            $this->send_line(array(
                'type' => 'info',
                'message' => 'Batch size reduced due to memory pressure',
                'new_batch_size' => $this->per_page,
                'new_orders_batch_size' => $this->orders_per_page,
                'memory_used_mb' => round($memory_usage / 1024 / 1024, 1),
            ));
        } elseif ($memory_usage < $this->memory_limit_bytes * 0.4) {
            if ($this->per_page < 2000) {
                $this->per_page = min(2000, (int) ($this->per_page * 1.5));
            }
            if ($this->orders_per_page < 2000) {
                $this->orders_per_page = min(2000, (int) ($this->orders_per_page * 1.5));
            }
        }
    }

    // ─── SINGLE ENTITY FORMAT (for webhooks) ─────────────────────────

    /**
     * Format a single entity by type and ID, returning the same data shape
     * as the NDJSON stream. Returns null if entity not found.
     *
     * @param string     $entity_type order|customer|product|category|coupon|refund|subscription
     * @param int|string $id          Entity ID
     * @return array|null
     */
    public function format_single_entity($entity_type, $id) {
        $id = (int) $id;
        if ($id <= 0) {
            return null;
        }

        switch ($entity_type) {
            case 'order':
                return $this->format_single_order($id);
            case 'customer':
                return $this->format_single_customer($id);
            case 'product':
                return $this->format_single_product($id);
            case 'category':
                return $this->format_single_category($id);
            case 'coupon':
                return $this->format_single_coupon($id);
            case 'refund':
                return $this->format_single_refund($id);
            case 'subscription':
                return $this->format_single_subscription($id);
            default:
                return null;
        }
    }

    private function format_single_order($order_id) {
        if ($this->has_hpos) {
            $orders_table = $this->wpdb->prefix . 'wc_orders';
            $op_table = $this->wpdb->prefix . 'wc_order_operational_data';
            $addresses_table = $this->wpdb->prefix . 'wc_order_addresses';

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $row = $this->wpdb->get_row(
                $this->wpdb->prepare(
                    "SELECT o.id, o.status, o.currency, o.total_amount, o.tax_amount,
                        o.date_created_gmt, o.date_updated_gmt, o.customer_id,
                        o.payment_method, o.payment_method_title, o.ip_address, o.user_agent,
                        o.transaction_id, o.customer_note, o.parent_order_id,
                        op.discount_total_amount, op.shipping_total_amount,
                        op.date_paid_gmt, op.date_completed_gmt, op.prices_include_tax,
                        ba.first_name as billing_first_name, ba.last_name as billing_last_name,
                        ba.company as billing_company, ba.address_1 as billing_address_1,
                        ba.address_2 as billing_address_2, ba.city as billing_city,
                        ba.state as billing_state, ba.postcode as billing_postcode,
                        ba.country as billing_country, ba.email as billing_email, ba.phone as billing_phone,
                        sa.first_name as shipping_first_name, sa.last_name as shipping_last_name,
                        sa.company as shipping_company, sa.address_1 as shipping_address_1,
                        sa.address_2 as shipping_address_2, sa.city as shipping_city,
                        sa.state as shipping_state, sa.postcode as shipping_postcode,
                        sa.country as shipping_country, sa.phone as shipping_phone
                    FROM %i o
                    LEFT JOIN %i op ON o.id = op.order_id
                    LEFT JOIN %i ba ON o.id = ba.order_id AND ba.address_type = 'billing'
                    LEFT JOIN %i sa ON o.id = sa.order_id AND sa.address_type = 'shipping'
                    WHERE o.id = %d AND o.type = 'shop_order'",
                    $orders_table, $op_table, $addresses_table, $addresses_table, $order_id
                )
            );

            if (!$row) {
                return null;
            }

            $items = $this->get_order_items_batch(array($order_id));
            $meta = $this->get_order_meta_batch_hpos(array($order_id));
            $order_items = $items[$order_id] ?? array();
            $order_meta = array_merge(
                $meta[$order_id] ?? array(),
                $this->order_extra_meta_batch(array($order_id))[$order_id] ?? array()
            );

            return array(
                'id' => (int) $row->id,
                'number' => (string) $row->id,
                'status' => str_replace('wc-', '', $row->status),
                'currency' => $row->currency,
                'total' => (string) $row->total_amount,
                'discount_total' => (string) ($row->discount_total_amount ?? '0'),
                'shipping_total' => (string) ($row->shipping_total_amount ?? '0'),
                'total_tax' => (string) ($row->tax_amount ?? '0'),
                'date_created' => self::to_iso($row->date_created_gmt),
                'date_modified' => self::to_iso($row->date_updated_gmt),
                'date_paid' => self::to_iso($row->date_paid_gmt),
                'date_completed' => self::to_iso($row->date_completed_gmt),
                'payment_method' => $row->payment_method ?: null,
                'payment_method_title' => $row->payment_method_title ?: null,
                'customer_id' => (int) $row->customer_id,
                'customer_ip_address' => $row->ip_address ?: null,
                'customer_user_agent' => $row->user_agent ?: null,
                'transaction_id' => $row->transaction_id ?: null,
                'customer_note' => $row->customer_note ?: null,
                'parent_id' => (int) $row->parent_order_id ?: null,
                'prices_include_tax' => (bool) ($row->prices_include_tax ?? false),
                'billing' => array(
                    'first_name' => $row->billing_first_name ?? '',
                    'last_name' => $row->billing_last_name ?? '',
                    'company' => $row->billing_company ?? '',
                    'address_1' => $row->billing_address_1 ?? '',
                    'address_2' => $row->billing_address_2 ?? '',
                    'city' => $row->billing_city ?? '',
                    'state' => $row->billing_state ?? '',
                    'postcode' => $row->billing_postcode ?? '',
                    'country' => $row->billing_country ?? '',
                    'email' => $row->billing_email ?? '',
                    'phone' => $row->billing_phone ?? '',
                ),
                'shipping' => array(
                    'first_name' => $row->shipping_first_name ?? '',
                    'last_name' => $row->shipping_last_name ?? '',
                    'company' => $row->shipping_company ?? '',
                    'address_1' => $row->shipping_address_1 ?? '',
                    'address_2' => $row->shipping_address_2 ?? '',
                    'city' => $row->shipping_city ?? '',
                    'state' => $row->shipping_state ?? '',
                    'postcode' => $row->shipping_postcode ?? '',
                    'country' => $row->shipping_country ?? '',
                    'phone' => $row->shipping_phone ?? '',
                ),
                'line_items' => $order_items['line_items'] ?? array(),
                'shipping_lines' => $order_items['shipping_lines'] ?? array(),
                'fee_lines' => $order_items['fee_lines'] ?? array(),
                'coupon_lines' => $order_items['coupon_lines'] ?? array(),
                'tax_lines' => $order_items['tax_lines'] ?? array(),
                'meta_data' => $order_meta,
            );
        }

        // Legacy (wp_posts)
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT ID, post_status, post_date_gmt, post_modified_gmt
                 FROM %i WHERE ID = %d AND post_type = 'shop_order'",
                $this->wpdb->posts, $order_id
            )
        );
        if (!$row) {
            return null;
        }

        $meta_map = $this->get_all_postmeta_batch(array($order_id));
        $m = $meta_map[$order_id] ?? array();
        $items = $this->get_order_items_batch(array($order_id));
        $order_items = $items[$order_id] ?? array();

        $known_keys = array(
            '_order_total', '_order_currency', '_cart_discount', '_order_shipping',
            '_order_tax', '_billing_first_name', '_billing_last_name', '_billing_company',
            '_billing_address_1', '_billing_address_2', '_billing_city', '_billing_state',
            '_billing_postcode', '_billing_country', '_billing_email', '_billing_phone',
            '_shipping_first_name', '_shipping_last_name', '_shipping_company',
            '_shipping_address_1', '_shipping_address_2', '_shipping_city', '_shipping_state',
            '_shipping_postcode', '_shipping_country', '_shipping_phone',
            '_payment_method', '_payment_method_title', '_customer_user', '_customer_ip_address',
            '_customer_user_agent', '_transaction_id', '_date_paid', '_date_completed',
            '_prices_include_tax', '_order_key',
        );
        $meta_data = self::meta_pairs($m, $known_keys);

        return array(
            'id' => $order_id,
            'number' => (string) $order_id,
            'status' => str_replace('wc-', '', $row->post_status),
            'currency' => $m['_order_currency'] ?? 'EUR',
            'total' => (string) ($m['_order_total'] ?? '0'),
            'discount_total' => (string) ($m['_cart_discount'] ?? '0'),
            'shipping_total' => (string) ($m['_order_shipping'] ?? '0'),
            'total_tax' => (string) ($m['_order_tax'] ?? '0'),
            'date_created' => self::to_iso($row->post_date_gmt),
            'date_modified' => self::to_iso($row->post_modified_gmt),
            'date_paid' => isset($m['_date_paid']) && $m['_date_paid'] ? gmdate('c', (int) $m['_date_paid']) : null,
            'date_completed' => isset($m['_date_completed']) && $m['_date_completed'] ? gmdate('c', (int) $m['_date_completed']) : null,
            'payment_method' => $m['_payment_method'] ?? null,
            'payment_method_title' => $m['_payment_method_title'] ?? null,
            'customer_id' => (int) ($m['_customer_user'] ?? 0),
            'customer_ip_address' => $m['_customer_ip_address'] ?? null,
            'customer_user_agent' => $m['_customer_user_agent'] ?? null,
            'transaction_id' => $m['_transaction_id'] ?? null,
            'customer_note' => null,
            'parent_id' => null,
            'prices_include_tax' => ($m['_prices_include_tax'] ?? 'no') === 'yes',
            'billing' => array(
                'first_name' => $m['_billing_first_name'] ?? '',
                'last_name' => $m['_billing_last_name'] ?? '',
                'company' => $m['_billing_company'] ?? '',
                'address_1' => $m['_billing_address_1'] ?? '',
                'address_2' => $m['_billing_address_2'] ?? '',
                'city' => $m['_billing_city'] ?? '',
                'state' => $m['_billing_state'] ?? '',
                'postcode' => $m['_billing_postcode'] ?? '',
                'country' => $m['_billing_country'] ?? '',
                'email' => $m['_billing_email'] ?? '',
                'phone' => $m['_billing_phone'] ?? '',
            ),
            'shipping' => array(
                'first_name' => $m['_shipping_first_name'] ?? '',
                'last_name' => $m['_shipping_last_name'] ?? '',
                'company' => $m['_shipping_company'] ?? '',
                'address_1' => $m['_shipping_address_1'] ?? '',
                'address_2' => $m['_shipping_address_2'] ?? '',
                'city' => $m['_shipping_city'] ?? '',
                'state' => $m['_shipping_state'] ?? '',
                'postcode' => $m['_shipping_postcode'] ?? '',
                'country' => $m['_shipping_country'] ?? '',
                'phone' => $m['_shipping_phone'] ?? '',
            ),
            'line_items' => $order_items['line_items'] ?? array(),
            'shipping_lines' => $order_items['shipping_lines'] ?? array(),
            'fee_lines' => $order_items['fee_lines'] ?? array(),
            'coupon_lines' => $order_items['coupon_lines'] ?? array(),
            'tax_lines' => $order_items['tax_lines'] ?? array(),
            'meta_data' => $meta_data,
        );
    }

    private function format_single_customer($user_id) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT ID, user_email, display_name, user_registered FROM %i WHERE ID = %d",
                $this->wpdb->users, $user_id
            )
        );
        if (!$row) {
            return null;
        }

        $meta_map = $this->get_all_usermeta_batch(array($user_id));
        $m = $meta_map[$user_id] ?? array();
        $roles_map = $this->get_customer_roles_batch(array((int) $row->ID));
        $user_roles = $roles_map[(int) $row->ID] ?? array();

        $known_keys = array(
            'first_name', 'last_name',
            'billing_first_name', 'billing_last_name', 'billing_company',
            'billing_address_1', 'billing_address_2', 'billing_city',
            'billing_state', 'billing_postcode', 'billing_country',
            'billing_email', 'billing_phone',
            'shipping_first_name', 'shipping_last_name', 'shipping_company',
            'shipping_address_1', 'shipping_address_2', 'shipping_city',
            'shipping_state', 'shipping_postcode', 'shipping_country',
            'shipping_phone',
        );
        $meta_data = self::meta_pairs($m, $known_keys);

        return array(
            'id' => (int) $row->ID,
            'email' => $row->user_email,
            'display_name' => $row->display_name,
            'first_name' => $m['first_name'] ?? '',
            'last_name' => $m['last_name'] ?? '',
            'date_created' => self::to_iso($row->user_registered),
            'role' => $user_roles[0] ?? '',
            'roles' => $user_roles,
            'company' => $m['billing_company'] ?? '',
            'phone' => $m['billing_phone'] ?? '',
            'city' => $m['billing_city'] ?? '',
            'country' => $m['billing_country'] ?? '',
            'billing' => array(
                'first_name' => $m['billing_first_name'] ?? '',
                'last_name' => $m['billing_last_name'] ?? '',
                'company' => $m['billing_company'] ?? '',
                'address_1' => $m['billing_address_1'] ?? '',
                'address_2' => $m['billing_address_2'] ?? '',
                'city' => $m['billing_city'] ?? '',
                'state' => $m['billing_state'] ?? '',
                'postcode' => $m['billing_postcode'] ?? '',
                'country' => $m['billing_country'] ?? '',
                'email' => $m['billing_email'] ?? '',
                'phone' => $m['billing_phone'] ?? '',
            ),
            'shipping' => array(
                'first_name' => $m['shipping_first_name'] ?? '',
                'last_name' => $m['shipping_last_name'] ?? '',
                'company' => $m['shipping_company'] ?? '',
                'address_1' => $m['shipping_address_1'] ?? '',
                'address_2' => $m['shipping_address_2'] ?? '',
                'city' => $m['shipping_city'] ?? '',
                'state' => $m['shipping_state'] ?? '',
                'postcode' => $m['shipping_postcode'] ?? '',
                'country' => $m['shipping_country'] ?? '',
                'phone' => $m['shipping_phone'] ?? '',
            ),
            'meta_data' => $meta_data,
        );
    }

    private function format_single_product($product_id) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT ID, post_title, post_type, post_status, post_parent,
                        post_content, post_excerpt, post_name,
                        post_date_gmt, post_modified_gmt
                 FROM %i WHERE ID = %d AND post_type IN ('product', 'product_variation')",
                $this->wpdb->posts, $product_id
            )
        );
        if (!$row) {
            return null;
        }

        $all_meta = $this->get_all_postmeta_batch(array($product_id));
        $meta = $all_meta[$product_id] ?? array();
        $categories = $this->get_products_categories_batch(array($product_id));
        $tags = $this->get_products_tags_batch(array($product_id));
        $images = $this->get_products_images_batch(array($product_id), $all_meta);

        $product_types = $this->get_products_types_batch(array($product_id));
        $type = $row->post_type === 'product_variation' ? 'variation' : ($product_types[$product_id] ?? 'simple');
        $parent_id = (int) $row->post_parent;
        $display_prices = $this->get_display_price_values(
            $product_id,
            $meta['_price'] ?? null,
            $meta['_regular_price'] ?? null,
            $meta['_sale_price'] ?? null
        );
        $image_url = $images[$product_id]['thumbnail'] ?? null;
        if (!$image_url && $parent_id > 0) {
            $parent_images = $this->get_products_images_batch(array($parent_id), $this->get_all_postmeta_batch(array($parent_id)));
            $image_url = $parent_images[$parent_id]['thumbnail'] ?? null;
        }

        $brand_lookup_ids = $parent_id > 0 ? array($product_id, $parent_id) : array($product_id);
        $brands = $this->get_products_brands_batch($brand_lookup_ids);
        $brand = $brands[$product_id] ?? null;
        if (!$brand && $parent_id > 0) {
            $brand = $brands[$parent_id] ?? null;
        }
        if (!$brand) {
            foreach (array('_brand', 'pa_marque', 'brand', 'marque') as $brand_key) {
                if (!empty($meta[$brand_key]) && is_string($meta[$brand_key])) {
                    $brand = $meta[$brand_key];
                    break;
                }
            }
        }
        $gallery = $images[$product_id]['gallery'] ?? array();

        $known_keys = array(
            '_sku', '_price', '_regular_price', '_sale_price',
            '_stock_status', '_stock', '_product_type', '_thumbnail_id',
            '_product_image_gallery', '_manage_stock',
            '_weight', '_length', '_width', '_height',
            '_tax_status', '_tax_class',
            '_edit_lock', '_edit_last',
        );
        $meta_data = self::meta_pairs($meta, $known_keys);

        return array(
            'id' => $product_id,
            'name' => $row->post_title,
            'slug' => $row->post_name,
            'type' => $type,
            'status' => $row->post_status,
            'description' => $row->post_content ?: '',
            'short_description' => $row->post_excerpt ?: '',
            'sku' => $meta['_sku'] ?? '',
            'price' => $meta['_price'] ?? null,
            'regular_price' => $meta['_regular_price'] ?? null,
            'sale_price' => $meta['_sale_price'] ?? null,
            'display_price' => $display_prices['price'],
            'display_regular_price' => $display_prices['regular_price'],
            'display_sale_price' => $display_prices['sale_price'],
            'display_price_includes_tax' => $display_prices['includes_tax'],
            'stock_status' => $meta['_stock_status'] ?? 'instock',
            'stock_quantity' => isset($meta['_stock']) ? (int) $meta['_stock'] : null,
            'manage_stock' => ($meta['_manage_stock'] ?? 'no') === 'yes',
            'weight' => $meta['_weight'] ?? null,
            'length' => $meta['_length'] ?? null,
            'width' => $meta['_width'] ?? null,
            'height' => $meta['_height'] ?? null,
            'tax_status' => $meta['_tax_status'] ?? 'taxable',
            'tax_class' => $meta['_tax_class'] ?? '',
            'category_ids' => $categories[$product_id] ?? array(),
            'tags' => $tags[$product_id] ?? array(),
            'parent_id' => $parent_id ?: null,
            'image_url' => $image_url,
            'images' => $gallery,
            'brand' => $brand,
            'date_created' => self::to_iso($row->post_date_gmt),
            'date_modified' => self::to_iso($row->post_modified_gmt),
            'meta_data' => $meta_data,
        );
    }

    private function get_display_price_values($product_id, $price, $regular_price, $sale_price) {
        $product = function_exists('wc_get_product') ? wc_get_product($product_id) : null;
        $includes_tax = function_exists('wc_tax_enabled')
            && wc_tax_enabled()
            && get_option('woocommerce_tax_display_shop', 'excl') === 'incl';

        $format = function($value) use ($product) {
            if ($value === null || $value === '') {
                return null;
            }
            $amount = (float) $value;
            if ($product && function_exists('wc_get_price_to_display')) {
                $amount = (float) wc_get_price_to_display($product, array('qty' => 1, 'price' => $amount));
            }
            return function_exists('wc_format_decimal')
                ? wc_format_decimal($amount, wc_get_price_decimals())
                : (string) round($amount, 2);
        };

        $pinned = !has_filter('woocommerce_get_tax_location', array(__CLASS__, 'shop_tax_location'));
        if ($pinned) {
            add_filter('woocommerce_get_tax_location', array(__CLASS__, 'shop_tax_location'), self::TAX_LOCATION_PRIORITY, 3);
        }

        try {
            return array(
                'price' => $format($price),
                'regular_price' => $format($regular_price),
                'sale_price' => $format($sale_price),
                'includes_tax' => $includes_tax,
            );
        } finally {
            if ($pinned) {
                remove_filter('woocommerce_get_tax_location', array(__CLASS__, 'shop_tax_location'), self::TAX_LOCATION_PRIORITY);
            }
        }
    }

    public static function shop_tax_location($location, $tax_class = '', $customer = null) {
        $uses_base = (function_exists('wc_prices_include_tax') && wc_prices_include_tax())
            || get_option('woocommerce_default_customer_address') === 'base'
            || get_option('woocommerce_tax_based_on') === 'base';

        if (!$uses_base || !function_exists('WC') || !WC()->countries) {
            return array();
        }

        return array(
            WC()->countries->get_base_country(),
            WC()->countries->get_base_state(),
            WC()->countries->get_base_postcode(),
            WC()->countries->get_base_city(),
        );
    }

    private function format_single_category($term_id) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT t.term_id, t.name, t.slug, tt.parent, tt.description, tt.count
                 FROM %i t
                 INNER JOIN %i tt ON t.term_id = tt.term_id
                 WHERE tt.taxonomy = 'product_cat' AND t.term_id = %d",
                $this->wpdb->terms, $this->wpdb->term_taxonomy, $term_id
            )
        );
        if (!$row) {
            return null;
        }

        $images = $this->get_term_images_batch(array($term_id));

        return array(
            'id' => (int) $row->term_id,
            'name' => $row->name,
            'slug' => $row->slug,
            'parent_id' => (int) $row->parent ?: null,
            'description' => $row->description ?: '',
            'count' => (int) $row->count,
            'image_url' => $images[$term_id] ?? null,
        );
    }

    private function format_single_coupon($coupon_id) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT ID, post_title, post_excerpt, post_date_gmt
                 FROM %i WHERE ID = %d AND post_type = 'shop_coupon'",
                $this->wpdb->posts, $coupon_id
            )
        );
        if (!$row) {
            return null;
        }

        $all_meta = $this->get_all_postmeta_batch(array($coupon_id));
        $m = $all_meta[$coupon_id] ?? array();

        return array(
            'id' => (int) $row->ID,
            'code' => strtolower($row->post_title),
            'description' => $row->post_excerpt ?: '',
            'discount_type' => $m['discount_type'] ?? 'fixed_cart',
            'amount' => (string) ($m['coupon_amount'] ?? '0'),
            'usage_count' => (int) ($m['usage_count'] ?? 0),
            'usage_limit' => (int) ($m['usage_limit'] ?? 0),
            'usage_limit_per_user' => (int) ($m['usage_limit_per_user'] ?? 0),
            'individual_use' => ($m['individual_use'] ?? 'no') === 'yes',
            'exclude_sale_items' => ($m['exclude_sale_items'] ?? 'no') === 'yes',
            'free_shipping' => ($m['free_shipping'] ?? 'no') === 'yes',
            'product_ids' => $this->maybe_unserialize_ids($m['product_ids'] ?? ''),
            'excluded_product_ids' => $this->maybe_unserialize_ids($m['exclude_product_ids'] ?? ''),
            'product_categories' => $this->maybe_unserialize_ids($m['product_categories'] ?? ''),
            'excluded_product_categories' => $this->maybe_unserialize_ids($m['exclude_product_categories'] ?? ''),
            'email_restrictions' => $this->maybe_unserialize_array($m['customer_email'] ?? ''),
            'minimum_amount' => $m['minimum_amount'] ?? null,
            'maximum_amount' => $m['maximum_amount'] ?? null,
            'date_created' => self::to_iso($row->post_date_gmt),
            'date_expires' => isset($m['date_expires']) && (int) $m['date_expires'] > 0
                ? gmdate('c', (int) $m['date_expires']) : null,
        );
    }

    private function format_single_refund($refund_id) {
        if ($this->has_hpos) {
            $orders_table = $this->wpdb->prefix . 'wc_orders';
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $row = $this->wpdb->get_row(
                $this->wpdb->prepare(
                    "SELECT id, parent_order_id, total_amount, date_created_gmt
                     FROM %i WHERE id = %d AND type = 'shop_order_refund'",
                    $orders_table, $refund_id
                )
            );
            if (!$row) {
                return null;
            }

            $meta_table = $this->wpdb->prefix . 'wc_orders_meta';
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $meta_rows = $this->wpdb->get_results(
                $this->wpdb->prepare(
                    "SELECT meta_key, meta_value FROM %i
                     WHERE order_id = %d AND meta_key IN ('_refund_reason', '_refunded_by')",
                    $meta_table, $refund_id
                )
            );
            $m = array();
            foreach ($meta_rows as $mr) {
                $m[$mr->meta_key] = $mr->meta_value;
            }

            return array(
                'id' => (int) $row->id,
                'parent_id' => (int) $row->parent_order_id,
                'amount' => (string) abs((float) $row->total_amount),
                'reason' => $m['_refund_reason'] ?? '',
                'date_created' => self::to_iso($row->date_created_gmt),
                'refunded_by' => (int) ($m['_refunded_by'] ?? 0),
            );
        }

        // Legacy
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT ID, post_parent, post_date_gmt, post_excerpt
                 FROM %i WHERE ID = %d AND post_type = 'shop_order_refund'",
                $this->wpdb->posts, $refund_id
            )
        );
        if (!$row) {
            return null;
        }

        $meta_map = $this->get_all_postmeta_batch(array($refund_id));
        $m = $meta_map[$refund_id] ?? array();

        return array(
            'id' => (int) $row->ID,
            'parent_id' => (int) $row->post_parent,
            'amount' => (string) abs((float) ($m['_refund_amount'] ?? 0)),
            'reason' => $row->post_excerpt ?: '',
            'date_created' => self::to_iso($row->post_date_gmt),
            'refunded_by' => (int) ($m['_refunded_by'] ?? 0),
        );
    }

    private function format_single_subscription($sub_id) {
        if (!class_exists('WC_Subscriptions')) {
            return null;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT ID, post_status, post_date_gmt, post_modified_gmt
                 FROM %i WHERE ID = %d AND post_type = 'shop_subscription'",
                $this->wpdb->posts, $sub_id
            )
        );
        if (!$row) {
            return null;
        }

        $meta_map = $this->get_all_postmeta_batch(array($sub_id));
        $m = $meta_map[$sub_id] ?? array();
        $items = $this->get_order_items_batch(array($sub_id));
        $sub_items = $items[$sub_id] ?? array();

        $known_keys = array(
            '_order_total', '_order_currency', '_cart_discount', '_order_shipping',
            '_order_tax', '_billing_first_name', '_billing_last_name', '_billing_company',
            '_billing_address_1', '_billing_address_2', '_billing_city', '_billing_state',
            '_billing_postcode', '_billing_country', '_billing_email', '_billing_phone',
            '_shipping_first_name', '_shipping_last_name', '_shipping_company',
            '_shipping_address_1', '_shipping_address_2', '_shipping_city', '_shipping_state',
            '_shipping_postcode', '_shipping_country', '_shipping_phone',
            '_payment_method', '_payment_method_title', '_customer_user',
            '_billing_period', '_billing_interval',
            '_schedule_start', '_schedule_trial_end', '_schedule_next_payment', '_schedule_end',
            '_requires_manual_renewal',
        );
        $meta_data = self::meta_pairs($m, $known_keys);

        $start_date = isset($m['_schedule_start']) && $m['_schedule_start']
            ? self::to_iso($m['_schedule_start']) : null;
        $trial_end = isset($m['_schedule_trial_end']) && $m['_schedule_trial_end']
            ? self::to_iso($m['_schedule_trial_end']) : null;
        $next_payment = isset($m['_schedule_next_payment']) && $m['_schedule_next_payment']
            ? self::to_iso($m['_schedule_next_payment']) : null;
        $end_date = isset($m['_schedule_end']) && $m['_schedule_end']
            ? self::to_iso($m['_schedule_end']) : null;

        return array(
            'id' => (int) $row->ID,
            'number' => (string) $row->ID,
            'status' => str_replace('wc-', '', $row->post_status),
            'currency' => $m['_order_currency'] ?? 'EUR',
            'total' => (string) ($m['_order_total'] ?? '0'),
            'discount_total' => (string) ($m['_cart_discount'] ?? '0'),
            'shipping_total' => (string) ($m['_order_shipping'] ?? '0'),
            'total_tax' => (string) ($m['_order_tax'] ?? '0'),
            'date_created' => self::to_iso($row->post_date_gmt),
            'date_modified' => self::to_iso($row->post_modified_gmt),
            'payment_method' => $m['_payment_method'] ?? null,
            'payment_method_title' => $m['_payment_method_title'] ?? null,
            'customer_id' => (int) ($m['_customer_user'] ?? 0),
            'parent_id' => null,
            'billing_period' => $m['_billing_period'] ?? null,
            'billing_interval' => isset($m['_billing_interval']) ? (int) $m['_billing_interval'] : 1,
            'start_date' => $start_date,
            'trial_end_date' => $trial_end,
            'next_payment_date' => $next_payment,
            'end_date' => $end_date,
            'requires_manual_renewal' => ($m['_requires_manual_renewal'] ?? 'false') === 'true',
            'billing' => array(
                'first_name' => $m['_billing_first_name'] ?? '',
                'last_name' => $m['_billing_last_name'] ?? '',
                'company' => $m['_billing_company'] ?? '',
                'address_1' => $m['_billing_address_1'] ?? '',
                'address_2' => $m['_billing_address_2'] ?? '',
                'city' => $m['_billing_city'] ?? '',
                'state' => $m['_billing_state'] ?? '',
                'postcode' => $m['_billing_postcode'] ?? '',
                'country' => $m['_billing_country'] ?? '',
                'email' => $m['_billing_email'] ?? '',
                'phone' => $m['_billing_phone'] ?? '',
            ),
            'shipping' => array(
                'first_name' => $m['_shipping_first_name'] ?? '',
                'last_name' => $m['_shipping_last_name'] ?? '',
                'company' => $m['_shipping_company'] ?? '',
                'address_1' => $m['_shipping_address_1'] ?? '',
                'address_2' => $m['_shipping_address_2'] ?? '',
                'city' => $m['_shipping_city'] ?? '',
                'state' => $m['_shipping_state'] ?? '',
                'postcode' => $m['_shipping_postcode'] ?? '',
                'country' => $m['_shipping_country'] ?? '',
                'phone' => $m['_shipping_phone'] ?? '',
            ),
            'line_items' => $sub_items['line_items'] ?? array(),
            'shipping_lines' => $sub_items['shipping_lines'] ?? array(),
            'fee_lines' => $sub_items['fee_lines'] ?? array(),
            'tax_lines' => $sub_items['tax_lines'] ?? array(),
            'meta_data' => $meta_data,
        );
    }
}
