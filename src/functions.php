<?php
// define any global functions here



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

if (!function_exists('qp_add_notices')) {
    function qp_add_notices($notice_arr)
    {
        $key = QP_NOTICES;
        if (is_user_logged_in()) {
            $user_id = get_current_user_id();
            $key = $key . $user_id;
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
            $key = $key . $user_id;
        }
        $notice_arr = get_transient($key);
        delete_transient($key);
        return $notice_arr;
    }
}


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



if (!function_exists('qp_plugin_log')) {
    function qp_plugin_log($entry, $mode = 'a', $file = 'quantumepay')
    {
        return false;
    }
}


if (!function_exists('qp_change_order_status')) {
    function qp_change_order_status($order_id, $status)
    {
        $order = new WC_Order($order_id);
        $orderNote = "Payment result: cancelled. \r\n payment id: ### <br>\r\n Transaction_id: #### ";
        //     update_post_meta($order_id,  '_refund_api_payment', json_encode($responseBody, JSON_PRETTY_PRINT));

        qp_plugin_log("Change Status ************-===>>>");
        qp_plugin_log($orderNote);
        $order_detail_object = wc_get_order($order_id);
        $order_detail_object->add_order_note($orderNote);
        qp_plugin_log('######### ORDER CANCELED ############');
        qp_plugin_log($order_detail_object);
        $order_detail_object->update_status($status, 'order_note'); // order note is optional, if you want to  add a note to order

    }
}

if (!function_exists('qp_normalize_plugin_event_type')) {
    function qp_normalize_plugin_event_type($event_type, $data = array())
    {
        $event_type = sanitize_key($event_type);

        $error_code = isset($data['error_code']) && !is_array($data['error_code'])
            ? strtolower((string) $data['error_code'])
            : '';

        $message = isset($data['message']) && !is_array($data['message'])
            ? strtolower((string) $data['message'])
            : '';

        $status = isset($data['status']) && !is_array($data['status'])
            ? strtolower((string) $data['status'])
            : '';

        $haystack = $error_code . ' ' . $message . ' ' . $status;

        if (
            strpos($haystack, 'timeout') !== false ||
            strpos($haystack, 'timed out') !== false ||
            strpos($haystack, 'curl error 28') !== false ||
            strpos($haystack, 'operation timed out') !== false
        ) {
            return 'payment_timeout';
        }

        return $event_type;
    }
}


function qp_send_plugin_event_from_gateway_response($response, $post_fields = array())
{
    if (is_wp_error($response)) {
        return qp_send_plugin_event('api_connection_failed', array(
            'order_id'   => qp_event_safe_value($post_fields, 'order_id'),
            'amount'     => qp_event_safe_value($post_fields, 'amount'),
            'currency'   => qp_event_safe_value($post_fields, 'currency', 'USD'),
            'status'     => 'wp_remote_error',
            'message'    => $response->get_error_message(),
            'error_code' => $response->get_error_code(),
        ));
    }

    $response_code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    $decoded = json_decode($body, true);

    if (!is_array($decoded)) {
        if ($response_code >= 400) {
            return qp_send_plugin_event('gateway_error', array(
                'order_id'   => qp_event_safe_value($post_fields, 'order_id'),
                'amount'     => qp_event_safe_value($post_fields, 'amount'),
                'currency'   => qp_event_safe_value($post_fields, 'currency', 'USD'),
                'status'     => 'http_' . $response_code,
                'message'    => 'Gateway returned HTTP ' . $response_code . '.',
                'error_code' => 'http_' . $response_code,
            ));
        }

        return false;
    }

    $processor_code = isset($decoded['processor']['code']) ? sanitize_text_field($decoded['processor']['code']) : null;
    $processor_message = isset($decoded['processor']['message']) ? sanitize_textarea_field($decoded['processor']['message']) : null;
    $gateway_code = isset($decoded['code']) ? sanitize_key($decoded['code']) : null;
    $gateway_status = isset($decoded['status']) ? sanitize_key($decoded['status']) : null;

    $event_type = 'gateway_error';

    if ($processor_code === '00' || $gateway_code === 'approval' || $gateway_status === 'pending_settlement') {
        $event_type = 'payment_success';
    } elseif ($gateway_status === 'declined' || $gateway_code === 'declined_by_processor') {
        $event_type = 'payment_failed';
    } elseif ($gateway_status === 'pending') {
        $event_type = 'payment_pending';
    }

    return qp_send_plugin_event($event_type, array(
        'order_id'       => qp_event_safe_value($post_fields, 'order_id'),
        'amount'         => qp_event_safe_value($post_fields, 'amount'),
        'currency'       => qp_event_safe_value($post_fields, 'currency', 'USD'),
        'transaction_id' => isset($decoded['transaction_id']) ? $decoded['transaction_id'] : null,
        'status'         => $gateway_status ?: 'http_' . $response_code,
        'message'        => $processor_message ?: ($decoded['message'] ?? null),
        'error_code'     => $processor_code ?: $gateway_code,
    ));
}


if (!function_exists('qp_send_plugin_event')) {
    function qp_send_plugin_event($event_or_response, $data = array())
    {
        $dashboard_url = qp_get_dashboard_api_url();

        if (empty($dashboard_url)) {
            return false;
        }

        if (is_wp_error($event_or_response) || is_array($event_or_response)) {
            return qp_send_plugin_event_from_gateway_response($event_or_response, $data);
        }

        $event_type = $event_or_response;

        $event_type = sanitize_key($event_type);

        if (empty($event_type)) {
            return false;
        }

        $payload = array(
            'site_url'       => home_url(),
            'event_type'     => $event_type,
            'order_id'       => qp_event_safe_value($data, 'order_id'),
            'transaction_id' => qp_event_safe_value($data, 'transaction_id'),
            'amount'         => qp_event_safe_value($data, 'amount'),
            'currency'       => qp_event_safe_value($data, 'currency', 'USD'),
            'status'         => qp_event_safe_value($data, 'status'),
            'message'        => qp_event_safe_message($data, 'message'),
            'error_code'     => qp_event_safe_value($data, 'error_code'),
            'plugin_version' => qp_get_plugin_version(),
        );

        $payload = array_filter($payload, function ($value) {
            return $value !== null && $value !== '';
        });

        $endpoint = trailingslashit($dashboard_url) . 'api/plugin-events';

        $response = wp_remote_post($endpoint, array(
            'method'      => 'POST',
            'timeout'     => 1,
            'redirection' => 0,
            'blocking'    => false,
            'headers'     => array(
                'Content-Type' => 'application/json',
            ),
            'body' => wp_json_encode($payload),
        ));

        return !is_wp_error($response);
    }
}

if (!function_exists('qp_get_dashboard_api_url')) {
    function qp_get_dashboard_api_url()
    {
        return 'https://phplaravel-1633779-6468974.cloudwaysapps.com/';
    }
}

if (!function_exists('qp_normalize_plugin_event_type')) {
    function qp_normalize_plugin_event_type($event_type, $data = array())
    {
        $event_type = sanitize_key($event_type);

        $error_code = isset($data['error_code']) && !is_array($data['error_code'])
            ? strtolower((string) $data['error_code'])
            : '';

        $message = isset($data['message']) && !is_array($data['message'])
            ? strtolower((string) $data['message'])
            : '';

        $status = isset($data['status']) && !is_array($data['status'])
            ? strtolower((string) $data['status'])
            : '';

        $haystack = $error_code . ' ' . $message . ' ' . $status;

        if (
            strpos($haystack, 'timeout') !== false ||
            strpos($haystack, 'timed out') !== false ||
            strpos($haystack, 'curl error 28') !== false ||
            strpos($haystack, 'operation timed out') !== false
        ) {
            return 'payment_timeout';
        }

        return $event_type;
    }
}

if (!function_exists('qp_event_safe_value')) {
    function qp_event_safe_value($data, $key, $default = null)
    {
        if (!is_array($data) || !array_key_exists($key, $data)) {
            return $default;
        }

        if (is_array($data[$key]) || is_object($data[$key])) {
            return $default;
        }

        return sanitize_text_field((string) $data[$key]);
    }
}

if (!function_exists('qp_event_safe_message')) {
    function qp_event_safe_message($data, $key)
    {
        if (!is_array($data) || !array_key_exists($key, $data)) {
            return null;
        }

        if (is_array($data[$key]) || is_object($data[$key])) {
            return null;
        }

        return mb_substr(
            sanitize_textarea_field((string) $data[$key]),
            0,
            1000
        );
    }
}

if (!function_exists('qp_get_plugin_version')) {
    function qp_get_plugin_version()
    {
        if (defined('WC_QUANTUMEPAY_VERSION')) {
            return WC_QUANTUMEPAY_VERSION;
        }

        if (defined('WC_QUANTUMEPAY_PLUGIN_VERSION')) {
            return WC_QUANTUMEPAY_PLUGIN_VERSION;
        }

        return null;
    }
}