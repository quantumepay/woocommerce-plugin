<?php
// ------------------------------
// Debugging helper
// ------------------------------
if (!function_exists('qp_dd')) {
    function qp_dd($data, $is_die = true)
    {
        echo '<pre>';
        var_dump($data);
        echo "</pre>";
        if ($is_die) {
            die;
        }
    }
}

// ------------------------------
// JSON helpers
// ------------------------------
if (!function_exists('qp_arr_to_json')) {
    function qp_arr_to_json($arr)
    {
        return json_encode($arr);
    }
}

if (!function_exists('qp_json_to_arr')) {
    function qp_json_to_arr($json_str, $is_actual_arr = false)
    {
        return json_decode($json_str, $is_actual_arr);
    }
}

// ------------------------------
// WooCommerce notices
// ------------------------------
if (!function_exists('qp_add_notices')) {
    function qp_add_notices($notice_arr)
    {
        $key = QP_NOTICES;
        if (is_user_logged_in()) {
            $user_id = get_current_user_id();
            $key .= $user_id;
        }
        set_transient($key, $notice_arr);
    }
}

if (!function_exists('qp_show_notices')) {
    function qp_show_notices()
    {
        $key = QP_NOTICES;
        if (is_user_logged_in()) {
            $user_id = get_current_user_id();
            $key .= $user_id;
        }
        $notice_arr = get_transient($key);
        delete_transient($key);
        return $notice_arr;
    }
}

// ------------------------------
// Get real user IP
// ------------------------------
if (!function_exists('qp_get_user_ip')) {

    function qp_get_user_ip(bool $publicOnly = false): string
    {
        $keys = ['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'];

        foreach ($keys as $key) {
            if (empty($_SERVER[$key])) {
                continue;
            }

            $parts = preg_split('/\s*,\s*/', (string) $_SERVER[$key], -1, PREG_SPLIT_NO_EMPTY) ?: [];

            foreach ($parts as $raw) {
                $ip = trim($raw);

                if ($ip !== '') {
                    $ip = preg_replace('/^\[|\]$/', '', $ip);
                    $ip = preg_replace('/%.+$/', '', $ip);
                }

                if (substr_count($ip, ':') <= 1) {
                    $ip = preg_replace('/:\d+$/', '', $ip);
                }

                if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                    continue;
                }

                if ($publicOnly) {
                    $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
                    if (!filter_var($ip, FILTER_VALIDATE_IP, $flags)) {
                        continue;
                    }
                }

                return $ip;
            }
        }

        return '127.0.0.1';
    }
}

// ------------------------------
// Logging function
// ------------------------------
if (!function_exists('qp_plugin_log')) {
    function qp_plugin_log($message, $data = null)
    {
        $log_file = WP_CONTENT_DIR . '/uploads/quantumepay.log';

        // Ensure directory is writable
        if (!is_writable(dirname($log_file))) {
            error_log("Quantum ePay log file directory is not writable: " . dirname($log_file));
            return;
        }

        // Format the log message
        $log = '[' . current_time('mysql') . '] ' . $message;

        // Handle different data types for logging
        if (!is_null($data)) {
            if (is_array($data) || is_object($data)) {
                $log .= ': ' . print_r($data, true);
            } elseif (is_scalar($data)) {
                $log .= ': ' . $data;
            } else {
                $log .= ': [Unsupported data type]';
            }
        }
        $log .= "\n";

        // Write to file with locking
        file_put_contents($log_file, $log, FILE_APPEND | LOCK_EX);
    }
}

// ------------------------------
// Change WooCommerce order status
// ------------------------------
if (!function_exists('qp_change_order_status')) {
    function qp_change_order_status($order_id, $status)
    {
        $order = new WC_Order($order_id);
        $orderNote = "Payment result: cancelled. \r\n payment id: ### <br>\r\n Transaction_id: #### ";

        qp_plugin_log("Change Status ************-===>>>");
        qp_plugin_log($orderNote);

        $order_detail_object = wc_get_order($order_id);
        $order_detail_object->add_order_note($orderNote);

        qp_plugin_log('######### ORDER CANCELED ############');
        qp_plugin_log($order_detail_object);

        // Update status
        $order_detail_object->update_status($status, 'order_note');
    }
}


if (!function_exists('qp_effective_checkout_type')) {
    function qp_effective_checkout_type()
    {
        static $cached_type = null;
        static $cached_source = null;

        if ($cached_type !== null && is_checkout()) {
            return $cached_type;
        }

        // Fallback if cart isn't initialized
        if (!did_action('wp_loaded') || is_null(WC()->cart)) {
            $settings = get_option('woocommerce_' . QP_GATEWAY_ID . '_settings', []);
            return $settings['global_checkout_type'] ?? 'standard';
        }

        $cart = WC()->cart->get_cart();
        if (empty($cart)) {
            $settings = get_option('woocommerce_' . QP_GATEWAY_ID . '_settings', []);
            $cached_type = $settings['global_checkout_type'] ?? 'standard';
            return $cached_type;
        }

        $valid_types = ['standard', 'pre_authorize', 'bypass'];
        $cart_types = [];

        foreach ($cart as $item) {
            $product_id = $item['product_id'];
            $product_type = null;

            // Check product-level setting
            $product_setting = get_post_meta($product_id, '_quantumepay_checkout_type', true);
            if (in_array($product_setting, $valid_types, true)) {
                $product_type = $product_setting;
            } else {
                // Check category-level settings (strictest first)
                $category_ids = wp_get_post_terms($product_id, 'product_cat', ['fields' => 'ids']);
                $category_types = [];
                foreach ($category_ids as $cat_id) {
                    $cat_setting = get_term_meta($cat_id, 'quantumepay_checkout_type', true);
                    if (in_array($cat_setting, $valid_types, true)) {
                        $category_types[] = $cat_setting;
                    }
                }
                // Determine strictest category type for this product
                if (in_array('standard', $category_types, true)) {
                    $product_type = 'standard';
                } elseif (in_array('pre_authorize', $category_types, true)) {
                    $product_type = 'pre_authorize';
                } elseif (in_array('bypass', $category_types, true)) {
                    $product_type = 'bypass';
                }
            }

            if ($product_type) {
                $cart_types[] = $product_type;
            }
        }

        // Determine strictest type across entire cart
        if (in_array('standard', $cart_types, true)) {
            $effective_type = 'standard';
        } elseif (in_array('pre_authorize', $cart_types, true)) {
            $effective_type = 'pre_authorize';
        } else {
            // Fallback to global setting if no product/category types
            $settings = get_option('woocommerce_' . QP_GATEWAY_ID . '_settings', []);
            $effective_type = $settings['global_checkout_type'] ?? 'standard';
        }

        $cached_type = $effective_type;
        return $effective_type;
    }
}

if (!function_exists('qp_checkout_types')) {
    function qp_checkout_types()
    {
        return [
            '' => 'Select Checkout Type',
            'standard' => 'Standard Checkout',
            'pre_authorize' => 'Pre-Authorize',
            'bypass' => 'Bypass Payment',
        ];
    }
}
