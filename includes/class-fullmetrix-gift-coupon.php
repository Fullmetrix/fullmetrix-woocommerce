<?php

defined('ABSPATH') || exit;

if (!class_exists('Fullmetrix_Gift_Coupon')) {

    final class Fullmetrix_Gift_Coupon {

        const META_KEY = '_fullmetrix_gift_product';
        const CART_ITEM_FLAG = '_fullmetrix_gift_added';

        private static $attempted = array();

        public static function init() {
            if (Fullmetrix_Connector::feature_enabled('giftCoupon')) {
                add_filter('woocommerce_coupon_is_valid', array(__CLASS__, 'ensure_gift_before_validation'), 10, 3);
                add_action('woocommerce_applied_coupon', array(__CLASS__, 'on_coupon_applied'), 20, 1);
            }
            add_action('woocommerce_removed_coupon', array(__CLASS__, 'on_coupon_removed'), 20, 1);
            add_filter('woocommerce_coupon_get_discount_amount', array(__CLASS__, 'restrict_discount_to_flagged_items'), 10, 5);
            add_filter('woocommerce_cart_item_quantity', array(__CLASS__, 'lock_gift_quantity_input'), 10, 3);
            add_filter('woocommerce_cart_item_remove_link', array(__CLASS__, 'hide_gift_remove_link'), 10, 2);
            add_action('woocommerce_before_calculate_totals', array(__CLASS__, 'enforce_gift_quantity_one'), 5, 1);
            add_action('woocommerce_checkout_create_order_line_item', array(__CLASS__, 'enforce_gift_on_order_line'), 10, 4);
        }

        public static function enforce_gift_on_order_line($item, $cart_item_key, $values, $order) {
            try {
                if (empty($values[self::CART_ITEM_FLAG])) return;
                if (method_exists($item, 'set_quantity')) {
                    $item->set_quantity(1);
                }
                if (method_exists($item, 'set_subtotal')) {
                    $item->set_subtotal(0);
                }
                if (method_exists($item, 'set_total')) {
                    $item->set_total(0);
                }
                if (method_exists($item, 'set_subtotal_tax')) {
                    $item->set_subtotal_tax(0);
                }
                if (method_exists($item, 'set_total_tax')) {
                    $item->set_total_tax(0);
                }
            } catch (\Throwable $e) {
                if (class_exists('Fullmetrix_Logger')) {
                    Fullmetrix_Logger::log('gift_coupon_order_line_error', $e->getMessage());
                }
            }
        }

        private static function get_gift_id_from_coupon($coupon) {
            if (!is_object($coupon) || !method_exists($coupon, 'get_meta')) {
                return 0;
            }
            return (int) $coupon->get_meta(self::META_KEY);
        }

        private static function add_gift_to_cart($gift_id) {
            try {
                if ($gift_id <= 0) return false;
                if (!function_exists('WC') || !WC() || !WC()->cart) return false;
                if (!function_exists('wc_get_product')) return false;

                $product = wc_get_product($gift_id);
                if (!$product) return false;
                if (method_exists($product, 'is_purchasable') && !$product->is_purchasable()) return false;

                $cart = WC()->cart;
                foreach ($cart->get_cart() as $item) {
                    if (empty($item[self::CART_ITEM_FLAG])) continue;
                    $item_product_id = isset($item['product_id']) ? (int) $item['product_id'] : 0;
                    $item_variation_id = isset($item['variation_id']) ? (int) $item['variation_id'] : 0;
                    if ($item_product_id === $gift_id || $item_variation_id === $gift_id) {
                        return true;
                    }
                }

                if (isset(self::$attempted[$gift_id])) return false;
                self::$attempted[$gift_id] = true;

                if (!self::has_stock_for_gift($product, $cart)) return false;

                $notices = function_exists('wc_get_notices') ? wc_get_notices() : null;
                $cart_item_data = array(self::CART_ITEM_FLAG => true);
                if (method_exists($product, 'is_type') && $product->is_type('variation')) {
                    $parent_id = method_exists($product, 'get_parent_id') ? (int) $product->get_parent_id() : 0;
                    if ($parent_id <= 0) return false;
                    $variation_attrs = method_exists($product, 'get_variation_attributes')
                        ? $product->get_variation_attributes()
                        : array();
                    $added = (bool) $cart->add_to_cart($parent_id, 1, $gift_id, $variation_attrs, $cart_item_data);
                } else {
                    $added = (bool) $cart->add_to_cart($gift_id, 1, 0, array(), $cart_item_data);
                }
                if (!$added && is_array($notices) && function_exists('wc_set_notices')) {
                    wc_set_notices($notices);
                }
                return $added;
            } catch (\Throwable $e) {
                if (class_exists('Fullmetrix_Logger')) {
                    Fullmetrix_Logger::log('gift_coupon_add_error', $e->getMessage());
                }
                return false;
            }
        }

        private static function has_stock_for_gift($product, $cart) {
            if (method_exists($product, 'is_in_stock') && !$product->is_in_stock()) return false;
            if (!method_exists($product, 'managing_stock') || !$product->managing_stock()) return true;
            if (method_exists($product, 'backorders_allowed') && $product->backorders_allowed()) return true;
            if (!method_exists($product, 'has_enough_stock')) return true;

            $in_cart = 0;
            if (method_exists($product, 'get_stock_managed_by_id') && method_exists($cart, 'get_cart_item_quantities')) {
                $quantities = $cart->get_cart_item_quantities();
                $stock_id = $product->get_stock_managed_by_id();
                $in_cart = isset($quantities[$stock_id]) ? (int) $quantities[$stock_id] : 0;
            }
            return $product->has_enough_stock($in_cart + 1);
        }

        public static function restrict_discount_to_flagged_items($discount, $discounting_amount, $cart_item, $single, $coupon) {
            try {
                if (!is_object($coupon) || !method_exists($coupon, 'get_meta')) return $discount;
                $gift_id = self::get_gift_id_from_coupon($coupon);
                if ($gift_id <= 0) return $discount;
                if (!is_array($cart_item)) return $discount;
                if (empty($cart_item[self::CART_ITEM_FLAG])) {
                    return 0;
                }
                return $discount;
            } catch (\Throwable $e) {
                return $discount;
            }
        }

        public static function lock_gift_quantity_input($product_quantity, $cart_item_key, $cart_item) {
            try {
                if (!is_array($cart_item) || empty($cart_item[self::CART_ITEM_FLAG])) {
                    return $product_quantity;
                }
                return '<span class="quantity">1</span>';
            } catch (\Throwable $e) {
                return $product_quantity;
            }
        }

        public static function hide_gift_remove_link($remove_link, $cart_item_key) {
            try {
                if (!function_exists('WC') || !WC() || !WC()->cart) return $remove_link;
                $cart = WC()->cart;
                $items = $cart->get_cart();
                if (!isset($items[$cart_item_key])) return $remove_link;
                $cart_item = $items[$cart_item_key];
                if (empty($cart_item[self::CART_ITEM_FLAG])) return $remove_link;
                return '';
            } catch (\Throwable $e) {
                return $remove_link;
            }
        }

        public static function enforce_gift_quantity_one($cart) {
            try {
                if (!is_object($cart) || !method_exists($cart, 'get_cart')) return;
                foreach ($cart->get_cart() as $cart_item_key => $item) {
                    if (empty($item[self::CART_ITEM_FLAG])) continue;
                    if (isset($item['quantity']) && (int) $item['quantity'] !== 1) {
                        $cart->set_quantity($cart_item_key, 1, false);
                    }
                    if (isset($item['data']) && is_object($item['data']) && method_exists($item['data'], 'set_price')) {
                        $item['data']->set_price(0);
                    }
                }
            } catch (\Throwable $e) {
                if (class_exists('Fullmetrix_Logger')) {
                    Fullmetrix_Logger::log('gift_coupon_qty_error', $e->getMessage());
                }
            }
        }

        public static function ensure_gift_before_validation($valid, $coupon, $discounts = null) {
            try {
                if (!class_exists('WC_Coupon')) return $valid;
                $gift_id = self::get_gift_id_from_coupon($coupon);
                if ($gift_id <= 0) return $valid;
                self::add_gift_to_cart($gift_id);
                if (!$valid) {
                    return true;
                }
                return $valid;
            } catch (\Throwable $e) {
                if (class_exists('Fullmetrix_Logger')) {
                    Fullmetrix_Logger::log('gift_coupon_validate_error', $e->getMessage());
                }
                return $valid;
            }
        }

        public static function on_coupon_applied($coupon_code) {
            try {
                if (!is_string($coupon_code) || $coupon_code === '') return;
                if (!class_exists('WC_Coupon')) return;
                $coupon = new WC_Coupon($coupon_code);
                if (!$coupon->get_id()) return;
                $gift_id = self::get_gift_id_from_coupon($coupon);
                if ($gift_id <= 0) return;
                self::add_gift_to_cart($gift_id);
            } catch (\Throwable $e) {
                if (class_exists('Fullmetrix_Logger')) {
                    Fullmetrix_Logger::log('gift_coupon_apply_error', $e->getMessage());
                }
            }
        }

        public static function on_coupon_removed($coupon_code) {
            try {
                if (!is_string($coupon_code) || $coupon_code === '') {
                    return;
                }

                if (!function_exists('WC') || !WC() || !WC()->cart) {
                    return;
                }

                if (!class_exists('WC_Coupon')) {
                    return;
                }

                $coupon = new WC_Coupon($coupon_code);
                if (!$coupon->get_id()) {
                    return;
                }

                $gift_id = (int) $coupon->get_meta(self::META_KEY);
                if ($gift_id <= 0) {
                    return;
                }

                $cart = WC()->cart;
                $items = $cart->get_cart();

                foreach ($items as $cart_item_key => $item) {
                    if (empty($item[self::CART_ITEM_FLAG])) {
                        continue;
                    }
                    $item_product_id = isset($item['product_id']) ? (int) $item['product_id'] : 0;
                    $item_variation_id = isset($item['variation_id']) ? (int) $item['variation_id'] : 0;
                    if ($item_product_id === $gift_id || $item_variation_id === $gift_id) {
                        $cart->remove_cart_item($cart_item_key);
                        break;
                    }
                }
            } catch (\Throwable $e) {
                if (class_exists('Fullmetrix_Logger')) {
                    Fullmetrix_Logger::log('gift_coupon_remove_error', $e->getMessage());
                }
            }
        }
    }
}
