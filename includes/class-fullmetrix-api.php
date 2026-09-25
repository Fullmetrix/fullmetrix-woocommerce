<?php
defined('ABSPATH') || exit;

class Fullmetrix_API {

    public static function register_routes() {
        register_rest_route('fullmetrix/v1', '/export', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'handle_export'),
            'permission_callback' => array(__CLASS__, 'verify_request'),
        ));

        register_rest_route('fullmetrix/v1', '/stream', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'handle_stream'),
            'permission_callback' => array(__CLASS__, 'verify_request'),
        ));

        register_rest_route('fullmetrix/v1', '/updated', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'handle_updated'),
            'permission_callback' => array(__CLASS__, 'verify_request'),
        ));

        register_rest_route('fullmetrix/v1', '/stream/(?P<entity>[a-z]+)', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'handle_stream_entity'),
            'permission_callback' => array(__CLASS__, 'verify_request'),
        ));

        register_rest_route('fullmetrix/v1', '/counts', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'handle_counts'),
            'permission_callback' => array(__CLASS__, 'verify_request'),
        ));

        register_rest_route('fullmetrix/v1', '/command', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'handle_command'),
            'permission_callback' => array(__CLASS__, 'verify_command_request'),
        ));
    }

    public static function verify_request($request) {
        $secret = get_option('fullmetrix_connection_secret', '');

        if (empty($secret)) {
            return new WP_Error(
                'not_configured',
                __('Plugin not configured', 'fullmetrix'),
                array('status' => 401)
            );
        }

        $signature = $request->get_header('X-Fullmetrix-Signature');
        $timestamp = $request->get_header('X-Fullmetrix-Timestamp');
        $code = $request->get_header('X-Fullmetrix-Connection-Code');

        if (empty($signature) || empty($timestamp) || empty($code)) {
            return new WP_Error(
                'missing_headers',
                __('Missing authentication headers', 'fullmetrix'),
                array('status' => 401)
            );
        }

        $stored_code = get_option('fullmetrix_connection_code', '');
        if ($code !== $stored_code) {
            return new WP_Error(
                'invalid_code',
                __('Invalid connection code', 'fullmetrix'),
                array('status' => 401)
            );
        }

        $is_valid = Fullmetrix_Security::verify_signature($secret, '', $signature, intval($timestamp));

        if (!$is_valid) {
            return new WP_Error(
                'invalid_signature',
                __('Invalid or expired signature', 'fullmetrix'),
                array('status' => 401)
            );
        }

        return true;
    }

    public static function verify_command_request($request) {
        $secret = get_option('fullmetrix_connection_secret', '');

        if (empty($secret)) {
            return new WP_Error('not_configured', __('Plugin not configured', 'fullmetrix'), array('status' => 401));
        }

        $signature = $request->get_header('X-Fullmetrix-Signature');
        $timestamp = $request->get_header('X-Fullmetrix-Timestamp');
        $code = $request->get_header('X-Fullmetrix-Connection-Code');

        if (empty($signature) || empty($timestamp) || empty($code)) {
            return new WP_Error('missing_headers', __('Missing authentication headers', 'fullmetrix'), array('status' => 401));
        }

        $stored_code = get_option('fullmetrix_connection_code', '');
        if ($code !== $stored_code) {
            return new WP_Error('invalid_code', __('Invalid connection code', 'fullmetrix'), array('status' => 401));
        }

        // POST commands sign the body
        $body = $request->get_body();
        $is_valid = Fullmetrix_Security::verify_signature($secret, $body, $signature, intval($timestamp));

        if (!$is_valid) {
            return new WP_Error('invalid_signature', __('Invalid or expired signature', 'fullmetrix'), array('status' => 401));
        }

        return true;
    }

    public static function handle_command($request) {
        try {
            $body = json_decode($request->get_body(), true);

            if (!is_array($body) || empty($body['action'])) {
                return new WP_Error('invalid_body', 'Missing action', array('status' => 400));
            }

            $action = $body['action'];
            $payload = isset($body['payload']) ? $body['payload'] : array();

            switch ($action) {
                case 'coupon.create':
                    return rest_ensure_response(self::command_coupon_create($payload));
                case 'coupon.update':
                    return rest_ensure_response(self::command_coupon_update($payload));
                case 'coupon.delete':
                    return rest_ensure_response(self::command_coupon_delete($payload));
                default:
                    return new WP_Error('unknown_action', 'Unknown action: ' . $action, array('status' => 400));
            }
        } catch (Exception $e) {
            return new WP_Error('command_error', $e->getMessage(), array('status' => 500));
        }
    }

    private static function command_coupon_create($payload) {
        if (empty($payload['code'])) {
            throw new Exception('Missing coupon code');
        }

        $coupon = new WC_Coupon();
        $coupon->set_code(wc_sanitize_coupon_code($payload['code']));

        self::apply_coupon_fields($coupon, $payload);
        $coupon->save();

        if (!$coupon->get_id()) {
            throw new Exception('Failed to create coupon');
        }

        return array(
            'success' => true,
            'data' => array(
                'id' => $coupon->get_id(),
                'code' => $coupon->get_code(),
            ),
        );
    }

    private static function command_coupon_update($payload) {
        if (empty($payload['id'])) {
            throw new Exception('Missing coupon id');
        }

        $coupon = new WC_Coupon(intval($payload['id']));
        if (!$coupon->get_id()) {
            throw new Exception('Coupon not found');
        }

        self::apply_coupon_fields($coupon, $payload);
        $coupon->save();

        return array(
            'success' => true,
            'data' => array(
                'id' => $coupon->get_id(),
                'code' => $coupon->get_code(),
            ),
        );
    }

    private static function command_coupon_delete($payload) {
        if (empty($payload['id'])) {
            throw new Exception('Missing coupon id');
        }

        $coupon = new WC_Coupon(intval($payload['id']));
        if (!$coupon->get_id()) {
            throw new Exception('Coupon not found');
        }

        $coupon->delete(true);

        return array(
            'success' => true,
            'data' => array('id' => intval($payload['id'])),
        );
    }

    private static function apply_coupon_fields($coupon, $payload) {
        // discountType: percentage, fixed_cart, fixed_product
        if (isset($payload['discountType'])) {
            $type_map = array(
                'percentage' => 'percent',
                'fixed_cart' => 'fixed_cart',
                'fixed_product' => 'fixed_product',
                'free_shipping' => 'percent', // free shipping is a flag, not a type
            );
            $wc_type = isset($type_map[$payload['discountType']]) ? $type_map[$payload['discountType']] : 'percent';
            $coupon->set_discount_type($wc_type);
        }

        if (isset($payload['amount'])) {
            $coupon->set_amount(floatval($payload['amount']));
        }

        if (isset($payload['description'])) {
            $coupon->set_description(sanitize_text_field($payload['description']));
        }

        if (isset($payload['usageLimit'])) {
            $coupon->set_usage_limit($payload['usageLimit'] === null ? 0 : intval($payload['usageLimit']));
        }

        if (isset($payload['usageLimitPerUser'])) {
            $coupon->set_usage_limit_per_user($payload['usageLimitPerUser'] === null ? 0 : intval($payload['usageLimitPerUser']));
        }

        if (isset($payload['minimumAmount'])) {
            $coupon->set_minimum_amount($payload['minimumAmount'] === null ? '' : floatval($payload['minimumAmount']));
        }

        if (isset($payload['maximumAmount'])) {
            $coupon->set_maximum_amount($payload['maximumAmount'] === null ? '' : floatval($payload['maximumAmount']));
        }

        if (array_key_exists('expiresAt', $payload)) {
            $coupon->set_date_expires($payload['expiresAt'] ? strtotime($payload['expiresAt']) : null);
        }

        if (isset($payload['freeShipping'])) {
            $coupon->set_free_shipping((bool) $payload['freeShipping']);
        }

        if (isset($payload['individualUse'])) {
            $coupon->set_individual_use((bool) $payload['individualUse']);
        }

        if (isset($payload['emailRestrictions']) && is_array($payload['emailRestrictions'])) {
            $coupon->set_email_restrictions(array_map('sanitize_email', $payload['emailRestrictions']));
        }

        $isGiftCoupon = isset($payload['giftProduct']) && (bool) $payload['giftProduct']
            && isset($payload['productIds']) && is_array($payload['productIds'])
            && !empty($payload['productIds']);

        if (isset($payload['productIds']) && is_array($payload['productIds']) && !$isGiftCoupon) {
            $coupon->set_product_ids(array_map('intval', $payload['productIds']));
        } elseif ($isGiftCoupon) {
            $coupon->set_product_ids(array());
        }

        if (isset($payload['excludeSaleItems'])) {
            $coupon->set_exclude_sale_items((bool) $payload['excludeSaleItems']);
        }

        if ($isGiftCoupon) {
            $gift_id = (int) $payload['productIds'][0];
            if ($gift_id > 0) {
                $coupon->update_meta_data(Fullmetrix_Gift_Coupon::META_KEY, $gift_id);
            }
        } elseif (array_key_exists('giftProduct', $payload) && !$payload['giftProduct']) {
            $coupon->delete_meta_data(Fullmetrix_Gift_Coupon::META_KEY);
        }

        if (isset($payload['code'])) {
            $coupon->set_code(wc_sanitize_coupon_code($payload['code']));
        }
    }

    public static function handle_export($request) {
        try {
            $type = $request->get_param('type') ?: 'orders';
            $sync_type = $request->get_param('sync_type') ?: 'full';
            $since = $request->get_param('since');
            $page = intval($request->get_param('page')) ?: 1;
            $per_page = intval($request->get_param('per_page')) ?: 100;

            $per_page = min($per_page, 500);

            self::track_sync_start($type, $sync_type);

            require_once FULLMETRIX_PLUGIN_DIR . 'includes/class-fullmetrix-fast-exporter.php';
            $exporter = new Fullmetrix_Fast_Exporter();

            switch ($type) {
                case 'settings':
                    $result = self::export_store_settings();
                    self::track_sync_complete($type, $result);
                    return rest_ensure_response($result);
                case 'customers':
                    $result = $exporter->export_customers_fast($page, $per_page);
                    break;
                case 'products':
                    $result = $exporter->export_products_fast($page, $per_page);
                    break;
                case 'categories':
                    $result = $exporter->export_categories_fast($page, $per_page);
                    break;
                case 'coupons':
                    $result = $exporter->export_coupons_fast($page, $per_page);
                    break;
                default:
                    $result = $exporter->export_orders_fast($page, $per_page, $since ?: null);
                    break;
            }

            self::track_sync_complete($type, $result);

            return rest_ensure_response($result);
        } catch (Exception $e) {
            delete_transient('fullmetrix_sync_in_progress');
            return new WP_Error(
                'export_error',
                $e->getMessage(),
                array('status' => 500)
            );
        } catch (Error $e) {
            delete_transient('fullmetrix_sync_in_progress');
            return new WP_Error(
                'fatal_error',
                $e->getMessage(),
                array('status' => 500)
            );
        }
    }

    private static function track_sync_start($type, $sync_type) {
        $count = (int) get_option('fullmetrix_export_count', 0);
        update_option('fullmetrix_export_count', $count + 1, false);

        set_transient('fullmetrix_sync_in_progress', array(
            'type' => $type,
            'sync_type' => $sync_type,
            'started_at' => time(),
        ), 600);
    }

    private static function track_sync_complete($type, $result) {
        if (!is_array($result) || empty($result['success'])) {
            return;
        }

        $entity_labels = array(
            'orders' => 'Orders',
            'products' => 'Products',
            'categories' => 'Categories',
            'customers' => 'Customers',
            'coupons' => 'Coupons',
        );

        $stats = array(
            'completed_at' => time(),
            'type' => $type === 'bulk' ? 'bulk' : 'paginated',
            'entities' => array(),
        );

        if ($type === 'bulk') {
            // Bulk: use meta.counts
            if (isset($result['meta']['counts']) && is_array($result['meta']['counts'])) {
                foreach ($result['meta']['counts'] as $key => $count) {
                    if ($count > 0 && isset($entity_labels[$key])) {
                        $stats['entities'][$entity_labels[$key]] = (int) $count;
                    }
                }
            }
            delete_transient('fullmetrix_sync_in_progress');
        } else {
            // Paginated: accumulate entity totals across requests
            $existing = get_option('fullmetrix_last_sync', array());
            if (isset($existing['entities']) && is_array($existing['entities'])) {
                $stats['entities'] = $existing['entities'];
            }

            // Get total from meta
            $total = 0;
            if (isset($result['meta']['total'])) {
                $total = (int) $result['meta']['total'];
            } elseif (isset($result['meta']['totalOrders'])) {
                $total = (int) $result['meta']['totalOrders'];
            }

            if ($total > 0 && isset($entity_labels[$type])) {
                $stats['entities'][$entity_labels[$type]] = $total;
            }
            // Transient auto-expires after 10 min when no more requests come
        }

        update_option('fullmetrix_last_sync', $stats, false);
    }

    public static function handle_stream($request) {
        try {
            $type = $request->get_param('type') ?: 'all';
            $sync_type = $request->get_param('sync_type') ?: 'full';
            $since = $request->get_param('since');
            $from_id = max(0, (int) $request->get_param('from_id'));
            $mode = $request->get_param('mode') ?: 'fast';

            self::track_sync_start($type, $sync_type);

            if ($mode === 'fast') {
                require_once FULLMETRIX_PLUGIN_DIR . 'includes/class-fullmetrix-fast-stream-exporter.php';
                $exporter = new Fullmetrix_Fast_Stream_Exporter();
            } else {
                require_once FULLMETRIX_PLUGIN_DIR . 'includes/class-fullmetrix-stream-exporter.php';
                $exporter = new Fullmetrix_Stream_Exporter();
            }

            if ($type === 'orders') {
                $exporter->stream_orders_only($sync_type, $since, $from_id);
            } else {
                $exporter->stream_all($sync_type, $since, $from_id);
            }
        } catch (Exception $e) {
            delete_transient('fullmetrix_sync_in_progress');
            return new WP_Error(
                'stream_error',
                $e->getMessage(),
                array('status' => 500)
            );
        } catch (Error $e) {
            delete_transient('fullmetrix_sync_in_progress');
            return new WP_Error(
                'fatal_error',
                $e->getMessage(),
                array('status' => 500)
            );
        }
    }

    public static function handle_stream_entity($request) {
        try {
            $entity = $request->get_param('entity');
            $sync_type = $request->get_param('sync_type') ?: 'full';
            $since = $request->get_param('since');
            $from_id = max(0, (int) $request->get_param('from_id'));

            $valid_entities = array('orders', 'customers', 'products', 'categories', 'coupons', 'refunds', 'carts', 'subscriptions');
            if (!in_array($entity, $valid_entities, true)) {
                return new WP_Error(
                    'invalid_entity',
                    'Entity invalide. Valeurs possibles: ' . implode(', ', $valid_entities),
                    array('status' => 400)
                );
            }

            self::track_sync_start($entity, $sync_type);

            require_once FULLMETRIX_PLUGIN_DIR . 'includes/class-fullmetrix-fast-stream-exporter.php';
            $exporter = new Fullmetrix_Fast_Stream_Exporter();
            $exporter->stream_entity($entity, $sync_type, $since, $from_id);
        } catch (Exception $e) {
            delete_transient('fullmetrix_sync_in_progress');
            return new WP_Error(
                'stream_error',
                $e->getMessage(),
                array('status' => 500)
            );
        } catch (Error $e) {
            delete_transient('fullmetrix_sync_in_progress');
            return new WP_Error(
                'fatal_error',
                $e->getMessage(),
                array('status' => 500)
            );
        }
    }

    public static function handle_counts($request) {
        global $wpdb;

        try {
            $has_hpos = class_exists(\Automattic\WooCommerce\Utilities\OrderUtil::class)
                && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

            if ($has_hpos) {
                $orders_table = \Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore::get_orders_table_name();
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $orders = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM %i WHERE type = 'shop_order' AND status NOT IN ('trash', 'draft', 'auto-draft', 'wc-checkout-draft')",
                    $orders_table
                )); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            } else {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $orders = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM %i WHERE post_type = 'shop_order' AND post_status NOT IN ('trash', 'draft', 'auto-draft', 'checkout-draft', 'wc-checkout-draft')",
                    $wpdb->posts
                )); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            }

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $customers = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(DISTINCT u.ID) FROM %i u INNER JOIN %i um ON u.ID = um.user_id WHERE um.meta_key = %s",
                $wpdb->users, $wpdb->usermeta, 'wp_capabilities'
            )); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $products = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM %i WHERE post_type IN ('product', 'product_variation') AND post_status IN ('publish', 'draft', 'private')",
                $wpdb->posts
            )); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $categories = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM %i tt INNER JOIN %i t ON tt.term_id = t.term_id WHERE tt.taxonomy = %s",
                $wpdb->term_taxonomy, $wpdb->terms, 'product_cat'
            )); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $coupons = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM %i WHERE post_type = 'shop_coupon' AND post_status != 'trash'",
                $wpdb->posts
            )); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

            if ($has_hpos) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $refunds = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM %i WHERE type = 'shop_order_refund'",
                    $orders_table
                )); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            } else {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $refunds = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM %i WHERE post_type = 'shop_order_refund' AND post_status != 'trash'",
                    $wpdb->posts
                )); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            }

            $counts = array(
                'orders' => $orders,
                'customers' => $customers,
                'products' => $products,
                'categories' => $categories,
                'coupons' => $coupons,
                'refunds' => $refunds,
            );

            // Check if WooCommerce Subscriptions is active
            if (class_exists('WC_Subscriptions')) {
                if ($has_hpos) {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                    $subscriptions = (int) $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*) FROM %i WHERE type = 'shop_subscription'",
                        $orders_table
                    )); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                } else {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                    $subscriptions = (int) $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*) FROM %i WHERE post_type = 'shop_subscription' AND post_status != 'trash'",
                        $wpdb->posts
                    )); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                }
                $counts['subscriptions'] = $subscriptions;
            }

            return rest_ensure_response(array(
                'success' => true,
                'counts' => $counts,
            ));
        } catch (Exception $e) {
            return new WP_Error('counts_error', $e->getMessage(), array('status' => 500));
        }
    }

    public static function handle_updated($request) {
        global $wpdb;

        try {
            $type = $request->get_param('type') ?: 'orders';
            $days = intval($request->get_param('days')) ?: 30;
            $hours = intval($request->get_param('hours')) ?: 0;
            $limit = min(intval($request->get_param('limit')) ?: 200000, 500000);
            $offset = intval($request->get_param('offset')) ?: 0;

            $time = strtotime('-' . $days . ' days');
            if ($hours > 0) {
                $time = $time - (60 * 60 * $hours);
            }
            $from = gmdate('Y-m-d H:i:s', $time);

            $has_hpos = class_exists(\Automattic\WooCommerce\Utilities\OrderUtil::class)
                && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

            $results = array();

            switch ($type) {
                case 'orders':
                    $results = self::get_updated_orders($wpdb, $from, $limit, $offset, $has_hpos);
                    break;
                case 'products':
                    $results = self::get_updated_products($wpdb, $from, $limit, $offset);
                    break;
                case 'customers':
                    $results = self::get_updated_customers($wpdb, $from, $limit, $offset);
                    break;
                default:
                    $results = self::get_updated_orders($wpdb, $from, $limit, $offset, $has_hpos);
            }

            return rest_ensure_response(array(
                'success' => true,
                'type' => $type,
                'hpos' => $has_hpos,
                'from' => $from,
                'count' => count($results),
                'items' => $results,
            ));
        } catch (Exception $e) {
            return new WP_Error('updated_error', $e->getMessage(), array('status' => 500));
        }
    }

    private static function get_updated_orders($wpdb, $from, $limit, $offset, $has_hpos) {
        if ($has_hpos) {
            $orders_table = \Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore::get_orders_table_name();
            $query = $wpdb->prepare(
                "SELECT id, UNIX_TIMESTAMP(date_updated_gmt) as last_updated
                FROM %i
                WHERE date_updated_gmt > %s
                    AND status NOT IN ('trash', 'draft', 'auto-draft', 'wc-checkout-draft')
                    AND type = 'shop_order'
                ORDER BY date_updated_gmt DESC
                LIMIT %d OFFSET %d",
                $orders_table, $from, $limit, $offset
            );
        } else {
            $query = $wpdb->prepare(
                "SELECT ID as id, UNIX_TIMESTAMP(post_modified_gmt) as last_updated
                FROM %i
                WHERE post_type = 'shop_order'
                    AND post_modified > %s
                    AND post_status NOT IN ('trash', 'draft', 'auto-draft', 'checkout-draft', 'wc-checkout-draft')
                ORDER BY post_modified_gmt DESC
                LIMIT %d OFFSET %d",
                $wpdb->posts, $from, $limit, $offset
            );
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
        return $wpdb->get_results($query);
    }

    private static function get_updated_products($wpdb, $from, $limit, $offset) {
        $query = $wpdb->prepare(
            "SELECT ID as id, UNIX_TIMESTAMP(post_modified_gmt) as last_updated
            FROM %i
            WHERE post_type IN ('product', 'product_variation')
                AND post_modified > %s
                AND post_status != 'trash'
            ORDER BY post_modified_gmt DESC
            LIMIT %d OFFSET %d",
            $wpdb->posts, $from, $limit, $offset
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
        return $wpdb->get_results($query);
    }

    private static function get_updated_customers($wpdb, $from, $limit, $offset) {
        $time = strtotime($from);

        $query = $wpdb->prepare(
            "SELECT user_id as id, meta_value as last_updated
            FROM %i
            WHERE meta_key = 'last_update'
                AND meta_value > %d
            ORDER BY meta_value DESC
            LIMIT %d OFFSET %d",
            $wpdb->usermeta, $time, $limit, $offset
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
        return $wpdb->get_results($query);
    }

    public static function get_store_settings() {
        $wc_pos = get_option('woocommerce_currency_pos', 'left');
        $position = in_array($wc_pos, array('left', 'left_space'), true) ? 'left' : 'right';

        return array(
            'currency'          => get_woocommerce_currency(),
            'timezone'          => wp_timezone_string(),
            'locale'            => get_locale(),
            'currencyPosition'  => $position,
            'thousandSeparator'  => get_option('woocommerce_price_thousand_sep', ','),
            'decimalSeparator'  => get_option('woocommerce_price_decimal_sep', '.'),
            'numDecimals'       => (int) get_option('woocommerce_price_num_decimals', 2),
        );
    }

    public static function export_store_settings() {
        return array(
            'success'  => true,
            'settings' => self::get_store_settings(),
        );
    }

    public static function register_with_fullmetrix() {
        $code = get_option('fullmetrix_connection_code', '');

        if (empty($code)) {
            return __('Connection code missing', 'fullmetrix');
        }

        Fullmetrix_Connector::forget_config();

        $store_canonical_id = get_option('fullmetrix_store_canonical_id', '');
        if (empty($store_canonical_id)) {
            $store_canonical_id = wp_generate_uuid4();
            update_option('fullmetrix_store_canonical_id', $store_canonical_id, false);
        }

        $data = array(
            'connectionCode' => $code,
            'siteUrl' => home_url(),
            'storeCanonicalId' => $store_canonical_id,
            'pluginVersion' => FULLMETRIX_VERSION,
            'platform' => 'woocommerce',
            'storeSettings' => self::get_store_settings(),
        );

        $response = wp_remote_post(FULLMETRIX_API_BASE . '/register', array(
            'headers' => array('Content-Type' => 'application/json'),
            'body' => wp_json_encode($data),
            'timeout' => 30,
        ));

        if (is_wp_error($response)) {
            return sprintf(
                /* translators: %s: error message */
                __('Connection error: %s', 'fullmetrix'),
                $response->get_error_message()
            );
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $result = json_decode($body, true);

        if ($status_code === 404) {
            return __('Connection code not found. Check your code in Fullmetrix.', 'fullmetrix');
        }

        if ($status_code === 409) {
            return __('This code is already associated with another site.', 'fullmetrix');
        }

        if ($status_code !== 200 || empty($result['success'])) {
            $error_message = isset($result['error']) ? $result['error'] : __('Unknown error', 'fullmetrix');
            /* translators: %s: error message */
            return sprintf(__('Registration failed: %s', 'fullmetrix'), $error_message);
        }

        if (!empty($result['connectionSecret'])) {
            update_option('fullmetrix_connection_secret', $result['connectionSecret']);
        }

        update_option('fullmetrix_registered', true);
        update_option('fullmetrix_webhooks_enabled', true);

        return true;
    }
}
