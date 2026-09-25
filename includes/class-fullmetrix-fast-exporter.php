<?php
defined('ABSPATH') || exit;

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- All queries use $this->wpdb->prepare(), but PHPCS cannot trace $this->wpdb.

class Fullmetrix_Fast_Exporter {

    private $wpdb;
    private $has_hpos;

    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->has_hpos = $this->detect_hpos();
    }

    private function detect_hpos() {
        return class_exists(\Automattic\WooCommerce\Utilities\OrderUtil::class)
            && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
    }

    public function export_orders_fast($page = 1, $per_page = 100, $since = null) {
        // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged, WordPress.PHP.NoSilencedErrors.Discouraged
        @set_time_limit(300);
        // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged, WordPress.PHP.NoSilencedErrors.Discouraged
        @ini_set('memory_limit', '256M');

        $offset = ($page - 1) * $per_page;

        if ($this->has_hpos) {
            return $this->export_orders_hpos($page, $per_page, $offset, $since);
        }

        return $this->export_orders_legacy($page, $per_page, $offset, $since);
    }

    private function export_orders_hpos($page, $per_page, $offset, $since) {
        $orders_table = $this->wpdb->prefix . 'wc_orders';
        $addresses_table = $this->wpdb->prefix . 'wc_order_addresses';
        $op_table = $this->wpdb->prefix . 'wc_order_operational_data';

        $where = "WHERE o.type = 'shop_order' AND o.status NOT IN ('trash', 'auto-draft')";
        if ($since) {
            $where .= $this->wpdb->prepare(" AND o.date_updated_gmt > %s", $since);
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        $total = (int) $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT COUNT(*) FROM %i o " . $where,
                $orders_table
            )
        );
        $total_pages = $total > 0 ? (int) ceil($total / $per_page) : 1;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT
                    o.id,
                    o.status,
                    o.currency,
                    o.total_amount,
                    o.tax_amount,
                    o.date_created_gmt,
                    o.date_updated_gmt,
                    o.customer_id,
                    o.payment_method,
                    o.payment_method_title,
                    o.customer_note,
                    op.discount_total_amount,
                    op.shipping_total_amount,
                    op.date_paid_gmt,
                    op.date_completed_gmt,
                    ba.first_name as billing_first_name,
                    ba.last_name as billing_last_name,
                    ba.company as billing_company,
                    ba.address_1 as billing_address_1,
                    ba.address_2 as billing_address_2,
                    ba.city as billing_city,
                    ba.state as billing_state,
                    ba.postcode as billing_postcode,
                    ba.country as billing_country,
                    ba.email as billing_email,
                    ba.phone as billing_phone,
                    sa.first_name as shipping_first_name,
                    sa.last_name as shipping_last_name,
                    sa.company as shipping_company,
                    sa.address_1 as shipping_address_1,
                    sa.address_2 as shipping_address_2,
                    sa.city as shipping_city,
                    sa.state as shipping_state,
                    sa.postcode as shipping_postcode,
                    sa.country as shipping_country
                FROM %i o
                LEFT JOIN %i op ON o.id = op.order_id
                LEFT JOIN %i ba ON o.id = ba.order_id AND ba.address_type = 'billing'
                LEFT JOIN %i sa ON o.id = sa.order_id AND sa.address_type = 'shipping'
                " . $where . "
                ORDER BY o.id ASC
                LIMIT %d OFFSET %d",
                $orders_table, $op_table, $addresses_table, $addresses_table, $per_page, $offset
            )
        );

        if (empty($rows)) {
            return $this->build_orders_response(array(), $total, $page, $total_pages, $per_page);
        }

        $order_ids = wp_list_pluck($rows, 'id');
        $line_items = $this->get_order_line_items($order_ids);
        $coupon_lines = $this->get_order_coupons($order_ids);

        $orders = array();
        foreach ($rows as $row) {
            $order_id = (int) $row->id;
            $orders[] = array(
                'id' => $order_id,
                'number' => (string) $order_id,
                'status' => str_replace('wc-', '', $row->status),
                'currency' => $row->currency,
                'total' => (float) $row->total_amount,
                'discount_total' => (float) ($row->discount_total_amount ?? 0),
                'shipping_total' => (float) ($row->shipping_total_amount ?? 0),
                'tax_total' => (float) ($row->tax_amount ?? 0),
                'date_created' => $row->date_created_gmt ? gmdate('c', strtotime($row->date_created_gmt)) : null,
                'date_modified' => $row->date_updated_gmt ? gmdate('c', strtotime($row->date_updated_gmt)) : null,
                'date_paid' => $row->date_paid_gmt ? gmdate('c', strtotime($row->date_paid_gmt)) : null,
                'date_completed' => $row->date_completed_gmt ? gmdate('c', strtotime($row->date_completed_gmt)) : null,
                'customer' => array(
                    'email' => $row->billing_email,
                    'first_name' => $row->billing_first_name,
                    'last_name' => $row->billing_last_name,
                ),
                'customer_id' => (int) $row->customer_id,
                'customer_note' => $row->customer_note ?? '',
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
                ),
                'payment' => array(
                    'method' => $row->payment_method ?? '',
                    'method_title' => $row->payment_method_title ?? '',
                ),
                'line_items' => $line_items[$order_id] ?? array(),
                'coupon_lines' => $coupon_lines[$order_id] ?? array(),
            );
        }

        return $this->build_orders_response($orders, $total, $page, $total_pages, $per_page);
    }

    private function export_orders_legacy($page, $per_page, $offset, $since) {
        $where = "WHERE p.post_type = 'shop_order' AND p.post_status NOT IN ('trash', 'auto-draft')";
        if ($since) {
            $where .= $this->wpdb->prepare(" AND p.post_modified_gmt > %s", $since);
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        $total = (int) $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT COUNT(*) FROM %i p " . $where,
                $this->wpdb->posts
            )
        );
        $total_pages = $total > 0 ? (int) ceil($total / $per_page) : 1;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT p.ID, p.post_status, p.post_date_gmt, p.post_modified_gmt
                FROM %i p
                " . $where . "
                ORDER BY p.ID ASC
                LIMIT %d OFFSET %d",
                $this->wpdb->posts, $per_page, $offset
            )
        );

        if (empty($rows)) {
            return $this->build_orders_response(array(), $total, $page, $total_pages, $per_page);
        }

        $order_ids = wp_list_pluck($rows, 'ID');
        $meta_data = $this->get_orders_meta_legacy($order_ids);
        $line_items = $this->get_order_line_items($order_ids);
        $coupon_lines = $this->get_order_coupons($order_ids);

        $orders = array();
        foreach ($rows as $row) {
            $order_id = (int) $row->ID;
            $meta = $meta_data[$order_id] ?? array();

            $orders[] = array(
                'id' => $order_id,
                'number' => $meta['_order_number'] ?? (string) $order_id,
                'status' => str_replace('wc-', '', $row->post_status),
                'currency' => $meta['_order_currency'] ?? 'EUR',
                'total' => (float) ($meta['_order_total'] ?? 0),
                'discount_total' => (float) ($meta['_cart_discount'] ?? 0),
                'shipping_total' => (float) ($meta['_order_shipping'] ?? 0),
                'tax_total' => (float) ($meta['_order_tax'] ?? 0),
                'date_created' => $row->post_date_gmt ? gmdate('c', strtotime($row->post_date_gmt)) : null,
                'date_modified' => $row->post_modified_gmt ? gmdate('c', strtotime($row->post_modified_gmt)) : null,
                'date_paid' => isset($meta['_date_paid']) ? gmdate('c', (int) $meta['_date_paid']) : null,
                'date_completed' => isset($meta['_date_completed']) ? gmdate('c', (int) $meta['_date_completed']) : null,
                'customer' => array(
                    'email' => $meta['_billing_email'] ?? '',
                    'first_name' => $meta['_billing_first_name'] ?? '',
                    'last_name' => $meta['_billing_last_name'] ?? '',
                ),
                'customer_id' => (int) ($meta['_customer_user'] ?? 0),
                'customer_note' => '',
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
                ),
                'payment' => array(
                    'method' => $meta['_payment_method'] ?? '',
                    'method_title' => $meta['_payment_method_title'] ?? '',
                ),
                'line_items' => $line_items[$order_id] ?? array(),
                'coupon_lines' => $coupon_lines[$order_id] ?? array(),
            );
        }

        return $this->build_orders_response($orders, $total, $page, $total_pages, $per_page);
    }

    private function get_orders_meta_legacy($order_ids) {
        if (empty($order_ids)) {
            return array();
        }

        $placeholders = implode(',', array_fill(0, count($order_ids), '%d'));
        $query = $this->wpdb->prepare(
            "SELECT post_id, meta_key, meta_value
             FROM %i
             WHERE post_id IN (" . $placeholders . ")
             AND meta_key IN (
                '_order_total', '_order_currency', '_cart_discount', '_order_shipping', '_order_tax',
                '_billing_first_name', '_billing_last_name', '_billing_company', '_billing_address_1',
                '_billing_address_2', '_billing_city', '_billing_state', '_billing_postcode',
                '_billing_country', '_billing_email', '_billing_phone',
                '_shipping_first_name', '_shipping_last_name', '_shipping_company', '_shipping_address_1',
                '_shipping_address_2', '_shipping_city', '_shipping_state', '_shipping_postcode', '_shipping_country',
                '_payment_method', '_payment_method_title', '_customer_user', '_date_paid', '_date_completed', '_order_number'
             )",
            array_merge(array($this->wpdb->postmeta), $order_ids)
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        $rows = $this->wpdb->get_results($query);
        $grouped = array();

        foreach ($rows as $row) {
            $grouped[(int) $row->post_id][$row->meta_key] = $row->meta_value;
        }

        return $grouped;
    }

    private function get_order_line_items($order_ids) {
        if (empty($order_ids)) {
            return array();
        }

        $items_table = $this->wpdb->prefix . 'woocommerce_order_items';
        $itemmeta_table = $this->wpdb->prefix . 'woocommerce_order_itemmeta';
        $placeholders = implode(',', array_fill(0, count($order_ids), '%d'));

        // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.NotPrepared
        $query = $this->wpdb->prepare(
            "SELECT oi.order_item_id, oi.order_id, oi.order_item_name,
                    MAX(CASE WHEN oim.meta_key = '_qty' THEN oim.meta_value END) as qty,
                    MAX(CASE WHEN oim.meta_key = '_line_subtotal' THEN oim.meta_value END) as subtotal,
                    MAX(CASE WHEN oim.meta_key = '_line_total' THEN oim.meta_value END) as total,
                    MAX(CASE WHEN oim.meta_key = '_product_id' THEN oim.meta_value END) as product_id,
                    MAX(CASE WHEN oim.meta_key = '_variation_id' THEN oim.meta_value END) as variation_id
             FROM %i oi
             LEFT JOIN %i oim ON oi.order_item_id = oim.order_item_id
             WHERE oi.order_id IN (" . $placeholders . ") AND oi.order_item_type = 'line_item'
             GROUP BY oi.order_item_id",
            array_merge(array($items_table, $itemmeta_table), $order_ids)
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        $rows = $this->wpdb->get_results($query);

        $product_ids = array();
        foreach ($rows as $row) {
            if ($row->variation_id > 0) {
                $product_ids[] = (int) $row->variation_id;
            } elseif ($row->product_id > 0) {
                $product_ids[] = (int) $row->product_id;
            }
        }

        $skus = $this->get_product_skus($product_ids);

        $grouped = array();
        foreach ($rows as $row) {
            $order_id = (int) $row->order_id;
            $qty = max(1, (int) $row->qty);
            $subtotal = (float) $row->subtotal;
            $prod_id = (int) $row->product_id;
            $var_id = (int) $row->variation_id;
            $sku_key = $var_id > 0 ? $var_id : $prod_id;

            $grouped[$order_id][] = array(
                'id' => (int) $row->order_item_id,
                'name' => $row->order_item_name,
                'quantity' => $qty,
                'price' => number_format($subtotal / $qty, 2, '.', ''),
                'total' => number_format((float) $row->total, 2, '.', ''),
                'product_id' => $prod_id,
                'variation_id' => $var_id ?: null,
                'sku' => $skus[$sku_key] ?? '',
            );
        }

        return $grouped;
    }

    private function get_product_skus($product_ids) {
        if (empty($product_ids)) {
            return array();
        }

        $product_ids = array_unique(array_filter($product_ids));
        $placeholders = implode(',', array_fill(0, count($product_ids), '%d'));

        $query = $this->wpdb->prepare(
            "SELECT post_id, meta_value FROM %i
             WHERE post_id IN (" . $placeholders . ") AND meta_key = '_sku'",
            array_merge(array($this->wpdb->postmeta), $product_ids)
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        $rows = $this->wpdb->get_results($query);
        $skus = array();

        foreach ($rows as $row) {
            $skus[(int) $row->post_id] = $row->meta_value;
        }

        return $skus;
    }

    private function get_order_coupons($order_ids) {
        if (empty($order_ids)) {
            return array();
        }

        $items_table = $this->wpdb->prefix . 'woocommerce_order_items';
        $itemmeta_table = $this->wpdb->prefix . 'woocommerce_order_itemmeta';
        $placeholders = implode(',', array_fill(0, count($order_ids), '%d'));

        // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.NotPrepared
        $query = $this->wpdb->prepare(
            "SELECT oi.order_id, oi.order_item_name as code,
                    MAX(CASE WHEN oim.meta_key = 'discount_amount' THEN oim.meta_value END) as discount,
                    MAX(CASE WHEN oim.meta_key = 'discount_amount_tax' THEN oim.meta_value END) as discount_tax
             FROM %i oi
             LEFT JOIN %i oim ON oi.order_item_id = oim.order_item_id
             WHERE oi.order_id IN (" . $placeholders . ") AND oi.order_item_type = 'coupon'
             GROUP BY oi.order_item_id",
            array_merge(array($items_table, $itemmeta_table), $order_ids)
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        $rows = $this->wpdb->get_results($query);
        $grouped = array();

        foreach ($rows as $row) {
            $grouped[(int) $row->order_id][] = array(
                'code' => $row->code,
                'discount' => (float) ($row->discount ?? 0),
                'discount_tax' => (float) ($row->discount_tax ?? 0),
            );
        }

        return $grouped;
    }

    public function export_customers_fast($page = 1, $per_page = 100) {
        // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged, WordPress.PHP.NoSilencedErrors.Discouraged
        @set_time_limit(300);
        // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged, WordPress.PHP.NoSilencedErrors.Discouraged
        @ini_set('memory_limit', '256M');

        $offset = ($page - 1) * $per_page;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        $total = (int) $this->wpdb->get_var(
            $this->wpdb->prepare("SELECT COUNT(*) FROM %i u", $this->wpdb->users)
        );
        $total_pages = $total > 0 ? (int) ceil($total / $per_page) : 1;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT u.ID, u.user_email, u.user_registered,
                       MAX(CASE WHEN um.meta_key = 'first_name' THEN um.meta_value END) as first_name,
                       MAX(CASE WHEN um.meta_key = 'last_name' THEN um.meta_value END) as last_name,
                       MAX(CASE WHEN um.meta_key = 'billing_company' THEN um.meta_value END) as company,
                       MAX(CASE WHEN um.meta_key = 'billing_phone' THEN um.meta_value END) as phone,
                       MAX(CASE WHEN um.meta_key = 'billing_city' THEN um.meta_value END) as city,
                       MAX(CASE WHEN um.meta_key = 'billing_country' THEN um.meta_value END) as country
                FROM %i u
                LEFT JOIN %i um ON u.ID = um.user_id
                GROUP BY u.ID
                ORDER BY u.ID ASC
                LIMIT %d OFFSET %d",
                $this->wpdb->users, $this->wpdb->usermeta, $per_page, $offset
            )
        );
        $customers = array();

        foreach ($rows as $row) {
            $customers[] = array(
                'id' => (int) $row->ID,
                'email' => $row->user_email,
                'first_name' => $row->first_name ?? '',
                'last_name' => $row->last_name ?? '',
                'company' => $row->company ?? '',
                'phone' => $row->phone ?? '',
                'city' => $row->city ?? '',
                'country' => $row->country ?? '',
                'date_created' => $row->user_registered ? gmdate('c', strtotime($row->user_registered)) : null,
            );
        }

        return array(
            'success' => true,
            'meta' => $this->build_meta($total, $page, $total_pages, $per_page),
            'customers' => $customers,
        );
    }

    public function export_products_fast($page = 1, $per_page = 100) {
        // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged, WordPress.PHP.NoSilencedErrors.Discouraged
        @set_time_limit(300);
        // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged, WordPress.PHP.NoSilencedErrors.Discouraged
        @ini_set('memory_limit', '256M');

        $offset = ($page - 1) * $per_page;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        $total = (int) $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT COUNT(*) FROM %i
                 WHERE post_type IN ('product', 'product_variation')
                 AND post_status IN ('publish', 'draft', 'private')",
                $this->wpdb->posts
            )
        );
        $total_pages = $total > 0 ? (int) ceil($total / $per_page) : 1;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT p.ID, p.post_title, p.post_type, p.post_status, p.post_parent,
                       p.post_date_gmt, p.post_modified_gmt
                FROM %i p
                WHERE p.post_type IN ('product', 'product_variation')
                AND p.post_status IN ('publish', 'draft', 'private')
                ORDER BY p.ID ASC
                LIMIT %d OFFSET %d",
                $this->wpdb->posts, $per_page, $offset
            )
        );

        if (empty($rows)) {
            return array(
                'success' => true,
                'meta' => $this->build_meta($total, $page, $total_pages, $per_page),
                'products' => array(),
            );
        }

        $product_ids = wp_list_pluck($rows, 'ID');
        $meta_data = $this->get_products_meta($product_ids);
        $categories = $this->get_products_categories($product_ids);
        $images = $this->get_products_images($product_ids);

        $products = array();
        foreach ($rows as $row) {
            $product_id = (int) $row->ID;
            $meta = $meta_data[$product_id] ?? array();
            $type = $row->post_type === 'product_variation' ? 'variation' : ($meta['_product_type'] ?? 'simple');

            $products[] = array(
                'id' => $product_id,
                'name' => $row->post_title,
                'sku' => $meta['_sku'] ?? '',
                'type' => $type === 'product_variation' ? 'variation' : $type,
                'status' => $row->post_status,
                'price' => $meta['_price'] ?? null,
                'regular_price' => $meta['_regular_price'] ?? null,
                'sale_price' => $meta['_sale_price'] ?? null,
                'stock_status' => $meta['_stock_status'] ?? 'instock',
                'stock_quantity' => isset($meta['_stock']) ? (int) $meta['_stock'] : null,
                'category_ids' => $categories[$product_id] ?? array(),
                'parent_id' => (int) $row->post_parent ?: null,
                'image_url' => $images[$product_id] ?? null,
                'date_created' => $row->post_date_gmt ? gmdate('c', strtotime($row->post_date_gmt)) : null,
                'date_modified' => $row->post_modified_gmt ? gmdate('c', strtotime($row->post_modified_gmt)) : null,
            );
        }

        return array(
            'success' => true,
            'meta' => $this->build_meta($total, $page, $total_pages, $per_page),
            'products' => $products,
        );
    }

    private function get_products_meta($product_ids) {
        if (empty($product_ids)) {
            return array();
        }

        $placeholders = implode(',', array_fill(0, count($product_ids), '%d'));
        $query = $this->wpdb->prepare(
            "SELECT post_id, meta_key, meta_value
             FROM %i
             WHERE post_id IN (" . $placeholders . ")
             AND meta_key IN ('_sku', '_price', '_regular_price', '_sale_price', '_stock_status', '_stock', '_product_type', '_thumbnail_id')",
            array_merge(array($this->wpdb->postmeta), $product_ids)
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        $rows = $this->wpdb->get_results($query);
        $grouped = array();

        foreach ($rows as $row) {
            $grouped[(int) $row->post_id][$row->meta_key] = $row->meta_value;
        }

        return $grouped;
    }

    private function get_products_categories($product_ids) {
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

    private function get_products_images($product_ids) {
        if (empty($product_ids)) {
            return array();
        }

        $placeholders = implode(',', array_fill(0, count($product_ids), '%d'));
        // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.NotPrepared
        $query = $this->wpdb->prepare(
            "SELECT pm.post_id, p.guid
             FROM %i pm
             INNER JOIN %i p ON pm.meta_value = p.ID
             WHERE pm.post_id IN (" . $placeholders . ") AND pm.meta_key = '_thumbnail_id'",
            array_merge(array($this->wpdb->postmeta, $this->wpdb->posts), $product_ids)
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        $rows = $this->wpdb->get_results($query);
        $images = array();

        foreach ($rows as $row) {
            $images[(int) $row->post_id] = $row->guid;
        }

        return $images;
    }

    public function export_categories_fast($page = 1, $per_page = 100) {
        // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged, WordPress.PHP.NoSilencedErrors.Discouraged
        @set_time_limit(300);
        // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged, WordPress.PHP.NoSilencedErrors.Discouraged
        @ini_set('memory_limit', '256M');

        $offset = ($page - 1) * $per_page;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        $total = (int) $this->wpdb->get_var(
            $this->wpdb->prepare("SELECT COUNT(*) FROM %i WHERE taxonomy = 'product_cat'", $this->wpdb->term_taxonomy)
        );
        $total_pages = $total > 0 ? (int) ceil($total / $per_page) : 1;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT t.term_id, t.name, t.slug, tt.parent, tt.description, tt.count
                FROM %i t
                INNER JOIN %i tt ON t.term_id = tt.term_id
                WHERE tt.taxonomy = 'product_cat'
                ORDER BY t.term_id ASC
                LIMIT %d OFFSET %d",
                $this->wpdb->terms, $this->wpdb->term_taxonomy, $per_page, $offset
            )
        );

        $term_ids = wp_list_pluck($rows, 'term_id');
        $images = $this->get_term_images($term_ids);

        $categories = array();
        foreach ($rows as $row) {
            $term_id = (int) $row->term_id;
            $categories[] = array(
                'id' => $term_id,
                'name' => $row->name,
                'slug' => $row->slug,
                'parent_id' => (int) $row->parent ?: null,
                'description' => $row->description ?? '',
                'count' => (int) $row->count,
                'image_url' => $images[$term_id] ?? null,
            );
        }

        return array(
            'success' => true,
            'meta' => $this->build_meta($total, $page, $total_pages, $per_page),
            'categories' => $categories,
        );
    }

    private function get_term_images($term_ids) {
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

    public function export_coupons_fast($page = 1, $per_page = 100) {
        // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged, WordPress.PHP.NoSilencedErrors.Discouraged
        @set_time_limit(300);
        // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged, WordPress.PHP.NoSilencedErrors.Discouraged
        @ini_set('memory_limit', '256M');

        $offset = ($page - 1) * $per_page;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        $total = (int) $this->wpdb->get_var(
            $this->wpdb->prepare("SELECT COUNT(*) FROM %i WHERE post_type = 'shop_coupon'", $this->wpdb->posts)
        );
        $total_pages = $total > 0 ? (int) ceil($total / $per_page) : 1;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT p.ID, p.post_title as code, p.post_date_gmt
                FROM %i p
                WHERE p.post_type = 'shop_coupon'
                ORDER BY p.ID ASC
                LIMIT %d OFFSET %d",
                $this->wpdb->posts, $per_page, $offset
            )
        );

        if (empty($rows)) {
            return array(
                'success' => true,
                'meta' => $this->build_meta($total, $page, $total_pages, $per_page),
                'coupons' => array(),
            );
        }

        $coupon_ids = wp_list_pluck($rows, 'ID');
        $meta_data = $this->get_coupons_meta($coupon_ids);

        $coupons = array();
        foreach ($rows as $row) {
            $coupon_id = (int) $row->ID;
            $meta = $meta_data[$coupon_id] ?? array();

            $coupons[] = array(
                'id' => $coupon_id,
                'code' => $row->code,
                'discount_type' => $meta['discount_type'] ?? 'fixed_cart',
                'amount' => (float) ($meta['coupon_amount'] ?? 0),
                'usage_count' => (int) ($meta['usage_count'] ?? 0),
                'usage_limit' => $meta['usage_limit'] ? (int) $meta['usage_limit'] : null,
                'date_created' => $row->post_date_gmt ? gmdate('c', strtotime($row->post_date_gmt)) : null,
                'date_expires' => $meta['date_expires'] ? gmdate('c', (int) $meta['date_expires']) : null,
                'minimum_amount' => $meta['minimum_amount'] ? (float) $meta['minimum_amount'] : null,
                'maximum_amount' => $meta['maximum_amount'] ? (float) $meta['maximum_amount'] : null,
                'free_shipping' => ($meta['free_shipping'] ?? 'no') === 'yes',
            );
        }

        return array(
            'success' => true,
            'meta' => $this->build_meta($total, $page, $total_pages, $per_page),
            'coupons' => $coupons,
        );
    }

    private function get_coupons_meta($coupon_ids) {
        if (empty($coupon_ids)) {
            return array();
        }

        $placeholders = implode(',', array_fill(0, count($coupon_ids), '%d'));
        $query = $this->wpdb->prepare(
            "SELECT post_id, meta_key, meta_value
             FROM %i
             WHERE post_id IN (" . $placeholders . ")
             AND meta_key IN ('discount_type', 'coupon_amount', 'usage_count', 'usage_limit', 'date_expires', 'minimum_amount', 'maximum_amount', 'free_shipping')",
            array_merge(array($this->wpdb->postmeta), $coupon_ids)
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        $rows = $this->wpdb->get_results($query);
        $grouped = array();

        foreach ($rows as $row) {
            $grouped[(int) $row->post_id][$row->meta_key] = $row->meta_value;
        }

        return $grouped;
    }

    private function build_orders_response($orders, $total, $page, $total_pages, $per_page) {
        return array(
            'success' => true,
            'meta' => array(
                'totalOrders' => $total,
                'currentPage' => $page,
                'totalPages' => $total_pages,
                'perPage' => $per_page,
                'storeUrl' => home_url(),
                'pluginVersion' => FULLMETRIX_VERSION,
                'exportedAt' => gmdate('c'),
                'mode' => 'fast',
                'hpos' => $this->has_hpos,
            ),
            'orders' => $orders,
        );
    }

    private function build_meta($total, $page, $total_pages, $per_page) {
        return array(
            'total' => $total,
            'currentPage' => $page,
            'totalPages' => $total_pages,
            'perPage' => $per_page,
            'storeUrl' => home_url(),
            'pluginVersion' => FULLMETRIX_VERSION,
            'exportedAt' => gmdate('c'),
            'mode' => 'fast',
        );
    }
}
