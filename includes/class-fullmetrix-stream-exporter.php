<?php
defined('ABSPATH') || exit;

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

class Fullmetrix_Stream_Exporter {

    private $per_page = 1000;
    private $image_url_cache = array();

    public function stream_all($sync_type = 'full', $since = null, $from_id = 0) {
        // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged, WordPress.PHP.NoSilencedErrors.Discouraged
        @set_time_limit(0);
        // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged, WordPress.PHP.NoSilencedErrors.Discouraged
        @ini_set('memory_limit', '256M');

        while (ob_get_level()) {
            ob_end_clean();
        }

        header('Content-Type: application/x-ndjson');
        header('Transfer-Encoding: chunked');
        header('X-Accel-Buffering: no');
        header('Cache-Control: no-cache');

        $this->send_line(array(
            'type' => 'meta',
            'started_at' => gmdate('c'),
            'version' => FULLMETRIX_VERSION,
            'store_url' => home_url(),
        ));

        $this->stream_orders($sync_type, $since);
        $this->stream_customers();
        $this->stream_products();
        $this->stream_categories();
        $this->stream_coupons();

        $this->send_line(array(
            'type' => 'done',
            'completed_at' => gmdate('c'),
        ));

        exit;
    }

    public function stream_orders_only($sync_type = 'full', $since = null, $from_id = 0) {
        // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged, WordPress.PHP.NoSilencedErrors.Discouraged
        @set_time_limit(0);
        // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged, WordPress.PHP.NoSilencedErrors.Discouraged
        @ini_set('memory_limit', '256M');

        while (ob_get_level()) {
            ob_end_clean();
        }

        header('Content-Type: application/x-ndjson');
        header('Transfer-Encoding: chunked');
        header('X-Accel-Buffering: no');

        $this->send_line(array(
            'type' => 'meta',
            'entity' => 'orders',
            'started_at' => gmdate('c'),
        ));

        $this->stream_orders($sync_type, $since);

        $this->send_line(array(
            'type' => 'done',
            'completed_at' => gmdate('c'),
        ));

        exit;
    }

    private function stream_orders($sync_type, $since) {
        $page = 1;
        $count = 0;

        do {
            $query_args = array(
                'limit' => $this->per_page,
                'page' => $page,
                'status' => 'any',
                'orderby' => 'ID',
                'order' => 'ASC',
                'paginate' => true,
                'type' => 'shop_order',
            );

            if ($sync_type === 'incremental' && $since) {
                $query_args['date_modified'] = '>' . $since;
            }

            $results = wc_get_orders($query_args);
            $total_pages = $results->max_num_pages;

            foreach ($results->orders as $order) {
                if ($order->get_type() === 'shop_order_refund') {
                    continue;
                }

                $this->send_line(array(
                    'type' => 'order',
                    'data' => $this->format_order_compact($order),
                ));

                $count++;
                unset($order);
            }

            unset($results);

            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }

            $page++;
        } while ($page <= $total_pages);

        $this->send_line(array(
            'type' => 'entity_complete',
            'entity' => 'orders',
            'count' => $count,
        ));
    }

    private function stream_customers() {
        $page = 1;
        $count = 0;

        do {
            $offset = ($page - 1) * $this->per_page;

            $query = new WP_User_Query(array(
                'number' => $this->per_page,
                'offset' => $offset,
                'orderby' => 'ID',
                'order' => 'ASC',
            ));

            $total = $query->get_total();
            $total_pages = $total > 0 ? (int) ceil($total / $this->per_page) : 1;

            foreach ($query->get_results() as $user) {
                try {
                    $wc_customer = new WC_Customer($user->ID);
                    $this->send_line(array(
                        'type' => 'customer',
                        'data' => $this->format_customer_compact($wc_customer),
                    ));
                    $count++;
                } catch (Exception $e) {
                    continue;
                }
                unset($user, $wc_customer);
            }

            unset($query);

            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }

            $page++;
        } while ($page <= $total_pages);

        $this->send_line(array(
            'type' => 'entity_complete',
            'entity' => 'customers',
            'count' => $count,
        ));
    }

    private function stream_products() {
        $page = 1;
        $count = 0;

        do {
            $results = wc_get_products(array(
                'limit' => $this->per_page,
                'page' => $page,
                'paginate' => true,
                'orderby' => 'ID',
                'order' => 'ASC',
                'status' => array('publish', 'draft', 'private'),
                'type' => array('simple', 'grouped', 'external', 'variable', 'variation'),
            ));

            $total_pages = $results->max_num_pages;

            $image_ids = array();
            $parent_ids = array();

            foreach ($results->products as $product) {
                $img_id = $product->get_image_id();
                if ($img_id) {
                    $image_ids[] = $img_id;
                }

                $parent_id = $product->get_parent_id();
                if ($parent_id && !$img_id) {
                    $parent_ids[] = $parent_id;
                }
            }

            if (!empty($parent_ids)) {
                $parents = wc_get_products(array(
                    'include' => $parent_ids,
                    'limit' => count($parent_ids),
                ));

                foreach ($parents as $parent) {
                    $parent_img_id = $parent->get_image_id();
                    if ($parent_img_id) {
                        $image_ids[] = $parent_img_id;
                    }
                }

                unset($parents);
            }

            $this->preload_image_urls($image_ids);

            foreach ($results->products as $product) {
                $this->send_line(array(
                    'type' => 'product',
                    'data' => $this->format_product_compact($product),
                ));
                $count++;
                unset($product);
            }

            unset($results);
            $this->image_url_cache = array();

            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }

            $page++;
        } while ($page <= $total_pages);

        $this->send_line(array(
            'type' => 'entity_complete',
            'entity' => 'products',
            'count' => $count,
        ));
    }

    private function stream_categories() {
        $page = 1;
        $count = 0;

        do {
            $offset = ($page - 1) * $this->per_page;

            $terms = get_terms(array(
                'taxonomy' => 'product_cat',
                'number' => $this->per_page,
                'offset' => $offset,
                'orderby' => 'term_id',
                'order' => 'ASC',
                'hide_empty' => false,
            ));

            $total_count = (int) wp_count_terms(array(
                'taxonomy' => 'product_cat',
                'hide_empty' => false,
            ));
            $total_pages = $total_count > 0 ? (int) ceil($total_count / $this->per_page) : 1;

            if (is_array($terms)) {
                $image_ids = array();
                foreach ($terms as $term) {
                    $thumbnail_id = get_term_meta($term->term_id, 'thumbnail_id', true);
                    if ($thumbnail_id) {
                        $image_ids[] = (int) $thumbnail_id;
                    }
                }

                $this->preload_image_urls($image_ids);

                foreach ($terms as $term) {
                    $this->send_line(array(
                        'type' => 'category',
                        'data' => $this->format_category_compact($term),
                    ));
                    $count++;
                    unset($term);
                }
            }

            unset($terms);
            $this->image_url_cache = array();

            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }

            $page++;
        } while ($page <= $total_pages);

        $this->send_line(array(
            'type' => 'entity_complete',
            'entity' => 'categories',
            'count' => $count,
        ));
    }

    private function stream_coupons() {
        $page = 1;
        $count = 0;

        do {
            $query = new WP_Query(array(
                'post_type' => 'shop_coupon',
                'posts_per_page' => $this->per_page,
                'paged' => $page,
                'orderby' => 'ID',
                'order' => 'ASC',
                'post_status' => 'any',
            ));

            $total_pages = $query->max_num_pages ?: 1;

            foreach ($query->posts as $post) {
                try {
                    $coupon = new WC_Coupon($post->ID);
                    $this->send_line(array(
                        'type' => 'coupon',
                        'data' => $this->format_coupon_compact($coupon),
                    ));
                    $count++;
                } catch (Exception $e) {
                    continue;
                }
                unset($post, $coupon);
            }

            unset($query);

            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }

            $page++;
        } while ($page <= $total_pages);

        $this->send_line(array(
            'type' => 'entity_complete',
            'entity' => 'coupons',
            'count' => $count,
        ));
    }

    private function format_order_compact($order) {
        $line_items = array();

        foreach ($order->get_items() as $item_id => $item) {
            $sku = '';
            $product = $item->get_product();
            if ($product) {
                $sku = $product->get_sku();
            }

            $qty = $item->get_quantity();
            $subtotal = $item->get_subtotal();

            $line_items[] = array(
                'id' => $item_id,
                'name' => $item->get_name(),
                'qty' => $qty,
                'price' => $qty > 0 ? round($subtotal / $qty, 2) : 0,
                'total' => round($item->get_total(), 2),
                'pid' => $item->get_product_id() ?: null,
                'vid' => $item->get_variation_id() ?: null,
                'sku' => $sku,
            );
        }

        return array(
            'id' => $order->get_id(),
            'num' => $order->get_order_number(),
            'st' => $order->get_status(),
            'cur' => $order->get_currency(),
            'tot' => $order->get_total(),
            'disc' => $order->get_discount_total(),
            'ship' => $order->get_shipping_total(),
            'tax' => $order->get_total_tax(),
            'dc' => $order->get_date_created() ? $order->get_date_created()->format('c') : null,
            'dm' => $order->get_date_modified() ? $order->get_date_modified()->format('c') : null,
            'dp' => $order->get_date_paid() ? $order->get_date_paid()->format('c') : null,
            'em' => $order->get_billing_email(),
            'fn' => $order->get_billing_first_name(),
            'ln' => $order->get_billing_last_name(),
            'ph' => $order->get_billing_phone(),
            'city' => $order->get_billing_city(),
            'co' => $order->get_billing_country(),
            'pm' => $order->get_payment_method(),
            'li' => $line_items,
        );
    }

    private function format_customer_compact($customer) {
        return array(
            'id' => $customer->get_id(),
            'email' => $customer->get_email(),
            'first_name' => $customer->get_first_name(),
            'last_name' => $customer->get_last_name(),
            'company' => $customer->get_billing_company(),
            'phone' => $customer->get_billing_phone(),
            'city' => $customer->get_billing_city(),
            'country' => $customer->get_billing_country(),
            'date_created' => $customer->get_date_created() ? $customer->get_date_created()->format('c') : null,
        );
    }

    private function format_product_compact($product) {
        $image_id = $product->get_image_id();
        $image_url = $this->get_cached_image_url($image_id);

        if (!$image_url && $product->get_parent_id()) {
            $parent = wc_get_product($product->get_parent_id());
            if ($parent) {
                $parent_image_id = $parent->get_image_id();
                $image_url = $this->get_cached_image_url($parent_image_id);
            }
        }

        return array(
            'id' => $product->get_id(),
            'name' => $product->get_name(),
            'slug' => $product->get_slug(),
            'permalink' => get_permalink($product->get_id()),
            'sku' => $product->get_sku(),
            'type' => $product->get_type(),
            'status' => $product->get_status(),
            'price' => $product->get_price(),
            'regular_price' => $product->get_regular_price(),
            'sale_price' => $product->get_sale_price(),
            'stock_status' => $product->get_stock_status(),
            'stock_quantity' => $product->get_stock_quantity(),
            'category_ids' => $product->get_category_ids(),
            'parent_id' => $product->get_parent_id() ?: null,
            'image_url' => $image_url,
            'brand' => $this->extract_product_brand($product),
            'date_created' => $product->get_date_created() ? $product->get_date_created()->format('c') : null,
            'date_modified' => $product->get_date_modified() ? $product->get_date_modified()->format('c') : null,
        );
    }

    private function extract_product_brand($product) {
        if (!$product) {
            return null;
        }

        $product_id = $product->get_id();
        if (!$product_id) {
            return null;
        }

        $lookup_id = $product_id;
        if ($product->get_parent_id()) {
            $lookup_id = $product->get_parent_id();
        }

        $taxonomies = array('product_brand', 'pwb-brand', 'yith_product_brand');
        foreach ($taxonomies as $taxonomy) {
            if (!taxonomy_exists($taxonomy)) {
                continue;
            }
            $terms = get_the_terms($lookup_id, $taxonomy);
            if (is_wp_error($terms) || empty($terms) || !is_array($terms)) {
                continue;
            }
            foreach ($terms as $term) {
                if (isset($term->name) && $term->name !== '') {
                    return (string) $term->name;
                }
            }
        }

        $meta_keys = array('_brand', 'pa_marque', 'brand', 'marque');
        foreach ($meta_keys as $key) {
            $value = get_post_meta($lookup_id, $key, true);
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        if (method_exists($product, 'get_attribute')) {
            foreach (array('brand', 'marque', 'pa_brand', 'pa_marque') as $attr) {
                $value = $product->get_attribute($attr);
                if (is_string($value) && $value !== '') {
                    return $value;
                }
            }
        }

        return null;
    }

    private function format_category_compact($term) {
        $thumbnail_id = get_term_meta($term->term_id, 'thumbnail_id', true);
        $image_url = $this->get_cached_image_url($thumbnail_id);

        return array(
            'id' => $term->term_id,
            'name' => $term->name,
            'slug' => $term->slug,
            'parent_id' => $term->parent ?: null,
            'description' => $term->description,
            'count' => $term->count,
            'image_url' => $image_url,
        );
    }

    private function format_coupon_compact($coupon) {
        return array(
            'id' => $coupon->get_id(),
            'code' => $coupon->get_code(),
            'discount_type' => $coupon->get_discount_type(),
            'amount' => $coupon->get_amount(),
            'usage_count' => $coupon->get_usage_count(),
            'usage_limit' => $coupon->get_usage_limit(),
            'date_created' => $coupon->get_date_created() ? $coupon->get_date_created()->format('c') : null,
            'date_expires' => $coupon->get_date_expires() ? $coupon->get_date_expires()->format('c') : null,
            'minimum_amount' => $coupon->get_minimum_amount(),
            'maximum_amount' => $coupon->get_maximum_amount(),
            'free_shipping' => $coupon->get_free_shipping(),
        );
    }

    private function send_line($data) {
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- NDJSON streaming endpoint, not HTML
        echo wp_json_encode($data, JSON_UNESCAPED_UNICODE) . "\n";
        flush();
    }

    private function preload_image_urls($image_ids) {
        if (empty($image_ids)) {
            return;
        }

        $image_ids = array_filter(array_unique($image_ids));
        $ids_to_fetch = array_diff($image_ids, array_keys($this->image_url_cache));

        if (empty($ids_to_fetch)) {
            return;
        }

        global $wpdb;

        $placeholders = implode(',', array_fill(0, count($ids_to_fetch), '%d'));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT ID, guid FROM %i WHERE ID IN (" . $placeholders . ") AND post_type = 'attachment'",
            array_merge(array($wpdb->posts), $ids_to_fetch)
        ));

        foreach ($results as $row) {
            $this->image_url_cache[(int) $row->ID] = $row->guid;
        }

        foreach ($ids_to_fetch as $id) {
            if (!isset($this->image_url_cache[(int) $id])) {
                $this->image_url_cache[(int) $id] = null;
            }
        }
    }

    private function get_cached_image_url($image_id) {
        if (empty($image_id)) {
            return null;
        }

        $image_id = (int) $image_id;

        if (isset($this->image_url_cache[$image_id])) {
            return $this->image_url_cache[$image_id];
        }

        $url = wp_get_attachment_url($image_id);
        $this->image_url_cache[$image_id] = $url ?: null;

        return $this->image_url_cache[$image_id];
    }
}
