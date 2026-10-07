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


function qep_ip_in_cidr($ip, $cidr)
{
    if (!is_string($ip) || !is_string($cidr)) return false;
    $parts = explode('/', $cidr, 2);
    $address = @inet_pton($ip);
    $network = @inet_pton($parts[0]);
    if ($address === false || $network === false || strlen($address) !== strlen($network)) return false;
    $bits = isset($parts[1]) ? $parts[1] : (string) (strlen($address) * 8);
    if (!preg_match('/^\d+$/D', $bits) || (int) $bits > strlen($address) * 8) return false;
    $bits = (int) $bits;
    $bytes = intdiv($bits, 8);
    if (substr($address, 0, $bytes) !== substr($network, 0, $bytes)) return false;
    $remaining = $bits % 8;
    return !$remaining || ((ord($address[$bytes]) & (255 << (8 - $remaining))) === (ord($network[$bytes]) & (255 << (8 - $remaining))));
}

function qep_cloudflare_proxy_ranges()
{
    return array('173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
        '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
        '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
        '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22', '2400:cb00::/32',
        '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32',
        '2a06:98c0::/29', '2c0f:f248::/32');
}

if (!function_exists('qp_get_user_ip')) {
    function qp_get_user_ip(bool $publicOnly = false): string
    {
        $peer = isset($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR']) ? trim($_SERVER['REMOTE_ADDR']) : '';
        if (!filter_var($peer, FILTER_VALIDATE_IP)) return '127.0.0.1';
        $cloudflare = qep_cloudflare_proxy_ranges();
        $trusted = apply_filters('qep_trusted_proxy_ranges', $cloudflare);
        $trusted = is_array($trusted) ? $trusted : $cloudflare;
        $matches = function ($ip, $ranges) {
            foreach ($ranges as $range) if (qep_ip_in_cidr($ip, $range)) return true;
            return false;
        };
        $candidate = $peer;
        $cf = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '';
        if ($matches($peer, $cloudflare) && is_string($cf) && filter_var($cf, FILTER_VALIDATE_IP)) {
            $candidate = $cf;
        } elseif ($matches($peer, $trusted)) {
            $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
            if (is_string($forwarded) && strlen($forwarded) <= 4096 && $forwarded !== '') {
                $chain = array_map('trim', explode(',', $forwarded));
                $valid = count($chain) <= 32;
                foreach ($chain as $ip) if (!filter_var($ip, FILTER_VALIDATE_IP)) $valid = false;
                if ($valid) {
                    for ($index = count($chain) - 1; $index >= 0 && $matches($candidate, $trusted); $index--) {
                        $candidate = $chain[$index];
                    }
                }
            }
        }
        if ($publicOnly && !filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return '127.0.0.1';
        return $candidate;
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

if (!function_exists('qp_event_safe_path')) {
    function qp_event_safe_path($data, $path, $default = null)
    {
        if (!is_array($data)) {
            return $default;
        }

        $current = $data;

        foreach (explode('.', $path) as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return $default;
            }

            $current = $current[$segment];
        }

        if (is_array($current) || is_object($current)) {
            return $default; 
        }

        return sanitize_text_field((string) $current);
    }
}

function qep_safe_error_text($value, $field = '')
{
    if (!is_scalar($value)) return '';
    $text = trim(sanitize_textarea_field((string) $value));
    $text = preg_replace('/([\'\"])[^\'\"\r\n]*\1/', '[redacted value]', $text);
    $text = preg_replace('/(?<!\d)(?:\d[ -]?){13,19}(?!\d)/', '[redacted card number]', $text);
    $text = preg_replace('/\bBearer\s+\S+/i', 'Bearer [redacted]', $text);
    $text = preg_replace('/\b(?:card_security_code|cvv|cvc|expiry(?:_month|_year)?|expiration|client_secret|access_token|terminal_key)\s*[:=]\s*[^\s,;]+/i', '[redacted sensitive value]', $text);
    $text = preg_replace('/\beyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\b/', '[redacted token]', $text);
    if (defined('QP_GATEWAY_ID')) {
        $settings = get_option('woocommerce_' . QP_GATEWAY_ID . '_settings', array());
        foreach (array('client_secret', 'test_client_secret', 'terminal_key', 'test_terminal_key') as $key) {
            $secret = is_array($settings) ? ($settings[$key] ?? '') : '';
            if (is_string($secret) && strlen($secret) >= 6) $text = str_replace($secret, '[redacted credential]', $text);
        }
    }
    if (preg_match('/card_security_code|cvc|cvv|expiry|expiration|client_secret|access_token|terminal_key/i', $field)) {
        $text = preg_replace('/\d+/', '[redacted]', $text);
    }
    return $text;
}

function qep_customer_error_message($field, $code)
{
    $required = $code === 'required_field';
    switch ($field) {
        case 'account.card_number': return $required ? 'Please enter your card number.' : 'Please check your card number and try again.';
        case 'account.card_security_code': return $required ? 'Please enter your card security code.' : 'Please check your card security code and try again.';
        case 'account.expiry_month':
        case 'account.expiry_year': return 'Please check your card expiration date and try again.';
        case 'phone_number': return 'Please enter a valid phone number.';
        case 'email': return 'Please enter a valid email address.';
        case 'account.first_name':
        case 'account.last_name': return 'Please enter the name on your card.';
    }
    if (strpos($field, 'billing_address') !== false) return 'Please check your billing address and ZIP code.';
    if ($code === 'avs_code_not_permitted') return 'Please check your billing address and ZIP code.';
    if ($code === 'insufficient_funds') return 'Your payment was declined due to insufficient funds. Please use another payment method.';
    if ($code === 'invalid_card_number') return 'Please check your card number and try again.';
    return '';
}

function qep_parse_gateway_response($response, $operation = 'sale')
{
    $http = is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);
    $body = is_wp_error($response) ? null : json_decode(wp_remote_retrieve_body($response), true);
    $body = is_array($body) ? $body : array();
    $result = array('outcome' => 'unknown', 'http_status' => $http, 'status' => '', 'errors' => array(),
        'gateway_code' => '', 'processor_code' => '', 'payment_id' => '', 'transaction_id' => '', 'trace_id' => '',
        'message' => '', 'error_code' => '', 'customer_message' => '', 'operation' => $operation);
    $scalar = function ($value) { return is_scalar($value) ? trim((string) $value) : ''; };
    $result['status'] = $scalar($body['status'] ?? '');
    $result['gateway_code'] = $scalar($body['code'] ?? ($body['error'] ?? ''));
    $processor = isset($body['processor']) && is_array($body['processor']) ? $body['processor'] : array();
    $result['processor_code'] = $scalar($processor['code'] ?? '');
    $result['payment_id'] = $scalar($body['payment_id'] ?? '');
    $result['transaction_id'] = $scalar($body['transaction_id'] ?? '');
    $result['trace_id'] = $scalar($body['traceId'] ?? '');
    $messages = array('Operation: ' . $operation, 'HTTP: ' . $http);
    $codes = array();
    foreach (array('gateway_code' => 'Gateway', 'processor_code' => 'Processor') as $key => $label) {
        if ($result[$key] !== '') $codes[] = $label . ': ' . sanitize_text_field($result[$key]);
    }
    foreach (array('Gateway' => $body['message'] ?? '', 'Processor' => $processor['message'] ?? '',
        'Title' => $body['title'] ?? '', 'Detail' => $body['detail'] ?? '', 'Authentication' => $body['error_description'] ?? '') as $label => $text) {
        $text = qep_safe_error_text($text);
        if ($text !== '') $messages[] = $label . ': ' . $text;
    }
    $errors = isset($body['errors']) && is_array($body['errors']) ? $body['errors'] : array();
    foreach ($errors as $key => $entry) {
        if (is_array($entry) && (isset($entry['code']) || isset($entry['message']) || isset($entry['field']))) {
            $result['errors'][] = array('field' => $scalar($entry['field'] ?? ''), 'code' => $scalar($entry['code'] ?? ''),
                'message' => qep_safe_error_text($entry['message'] ?? '', $scalar($entry['field'] ?? '')));
        } else {
            $field = is_string($key) ? $key : '';
            foreach (is_array($entry) ? $entry : array($entry) as $text) {
                $result['errors'][] = array('field' => $field, 'code' => '', 'message' => qep_safe_error_text($text, $field));
            }
        }
    }
    $customer = array();
    foreach ($result['errors'] as $error) {
        $messages[] = ($error['field'] !== '' ? $error['field'] . ': ' : '') . ($error['code'] !== '' ? $error['code'] . ' - ' : '') . $error['message'];
        if ($error['code'] !== '') $codes[] = $error['code'];
        $friendly = qep_customer_error_message($error['field'], $error['code']);
        if ($friendly !== '') $customer[] = $friendly;
    }
    $status = strtolower($result['status']);
    $code = strtolower($result['gateway_code']);
    $declined = $status === 'declined' || $code === 'declined_by_processor' || $code === 'insufficient_funds' || $code === 'avs_code_not_permitted';
    $processor_failed = $result['processor_code'] !== '' && $result['processor_code'] !== '00';
    if (is_wp_error($response)) {
        $codes[] = $response->get_error_code();
        $messages[] = 'Connection: ' . qep_safe_error_text($response->get_error_message());
        $result['status'] = 'connection_error';
        if ($operation === 'authenticate') $result['outcome'] = 'failed';
    } elseif (($http >= 500 || in_array($http, array(408, 409, 429), true)) && !$declined) {
        $result['outcome'] = 'unknown';
    } elseif ($declined || $result['errors'] || $code === 'invalid_client' || !empty($body['error'])) {
        $result['outcome'] = 'failed';
    } elseif ($http >= 400 && $http < 500 && !in_array($http, array(408, 409, 429), true)) {
        $result['outcome'] = 'failed';
    } elseif ($http >= 200 && $http < 300 && !$processor_failed) {
        $approved = $code === 'approval' || ($body['message'] ?? '') === 'approved or completed';
        if ($operation === 'reversal') $approved = $status === 'reversed';
        if ($operation === 'lookup') $approved = $result['status'] !== '';
        if ($operation === 'authenticate') $approved = !empty($body['access_token']) && is_string($body['access_token']);
        if ($approved && !in_array($status, array('failed', 'error', 'pending', 'cancelled'), true)) $result['outcome'] = 'approved';
    }
    if ($processor_failed) $result['outcome'] = 'failed';
    if ($operation === 'authenticate' && $result['outcome'] !== 'approved') $result['outcome'] = 'failed';
    if (!$codes && $result['outcome'] !== 'approved') $codes[] = $http ? 'http_' . $http : 'connection_error';
    if (count($messages) === 2 && $result['outcome'] !== 'approved') $messages[] = 'Gateway returned no usable error description.';
    if ($result['trace_id'] !== '') $messages[] = 'Trace ID: ' . sanitize_text_field($result['trace_id']);
    if ($codes) $messages[] = 'Codes: ' . implode(' | ', array_unique($codes));
    $result['message'] = implode("\n", $messages);
    $result['error_code'] = implode(' | ', array_unique($codes));
    $friendly = qep_customer_error_message('', $code);
    if ($friendly !== '') $customer[] = $friendly;
    if ($operation === 'authenticate') $customer = array('We could not connect to the payment gateway. Please contact the store or try again later.');
    elseif (!$customer && $result['outcome'] === 'failed') $customer[] = $declined || $processor_failed
        ? 'Your payment was declined. Please check your card details or use another payment method.'
        : 'Your payment could not be processed. Please check your payment details or contact the store.';
    elseif (!$customer && $result['outcome'] === 'unknown') $customer[] = 'We could not confirm your payment result. Please contact the store before trying again.';
    $result['customer_message'] = implode(' ', array_unique($customer));
    return $result;
}

function qep_log_gateway_result($result, $context = array())
{
    $event = 'gateway_error';
    if ($result['outcome'] === 'approved') $event = 'payment_success';
    elseif ($result['operation'] === 'authenticate') $event = 'gateway_error';
    elseif ($result['status'] === 'connection_error') $event = 'api_connection_failed';
    elseif ($result['status'] === 'declined' || $result['gateway_code'] === 'declined_by_processor') $event = 'payment_failed';
    $data = array_merge($context, array('payment_id' => $result['payment_id'], 'transaction_id' => $result['transaction_id'],
        'status' => $result['status'] ?: 'http_' . $result['http_status'],
        'message' => $result['message'], 'error_code' => $result['error_code']));
    return qp_send_plugin_event(qp_normalize_plugin_event_type($event, $data), $data);
}

function qp_send_plugin_event_from_gateway_response($response, $post_fields = array())
{
    $result = qep_parse_gateway_response($response);
    return qep_log_gateway_result($result, array(
        'order_id' => qp_event_safe_value($post_fields, 'order_id') ?: qp_event_safe_path($post_fields, 'order.order_id'),
        'amount' => qp_event_safe_value($post_fields, 'amount'),
        'currency' => qp_event_safe_value($post_fields, 'currency', 'USD'),
        'customer_first_name' => qp_event_safe_path($post_fields, 'account.first_name'),
        'customer_last_name' => qp_event_safe_path($post_fields, 'account.last_name'),
        'customer_email' => qp_event_safe_value($post_fields, 'email')));
}

if (!function_exists('qp_get_event_order')) {
    function qp_get_event_order($data)
    {
        $order_id = qp_event_safe_value($data, 'order_id');

        if (empty($order_id) || !function_exists('wc_get_order')) {
            return null;
        }

        $order = wc_get_order($order_id);

        return $order ?: null;
    }
}

if (!function_exists('qp_get_event_customer_first_name')) {
    function qp_get_event_customer_first_name($data)
    {
        $value = qp_event_safe_value($data, 'customer_first_name');

        if (!empty($value)) {
            return $value;
        }

        $value = qp_event_safe_value($data, 'first_name');

        if (!empty($value)) {
            return $value;
        }

        $order = qp_get_event_order($data);

        if ($order && method_exists($order, 'get_billing_first_name')) {
            return sanitize_text_field($order->get_billing_first_name());
        }

        return null;
    }
}

if (!function_exists('qp_get_event_customer_last_name')) {
    function qp_get_event_customer_last_name($data)
    {
        $value = qp_event_safe_value($data, 'customer_last_name');

        if (!empty($value)) {
            return $value;
        }

        $value = qp_event_safe_value($data, 'last_name');

        if (!empty($value)) {
            return $value;
        }

        $order = qp_get_event_order($data);

        if ($order && method_exists($order, 'get_billing_last_name')) {
            return sanitize_text_field($order->get_billing_last_name());
        }

        return null;
    }
}

if (!function_exists('qp_get_event_customer_email')) {
    function qp_get_event_customer_email($data)
    {
        $value = qp_event_safe_value($data, 'customer_email');

        if (!empty($value) && is_email($value)) {
            return sanitize_email($value);
        }

        $value = qp_event_safe_value($data, 'email');

        if (!empty($value) && is_email($value)) {
            return sanitize_email($value);
        }

        $order = qp_get_event_order($data);

        if ($order && method_exists($order, 'get_billing_email')) {
            $email = $order->get_billing_email();

            return is_email($email) ? sanitize_email($email) : null;
        }

        return null;
    }
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
            'site_url'            => home_url(),
            'event_type'          => $event_type,
            'order_id'            => qp_event_safe_value($data, 'order_id') ?: qp_event_safe_path($data, 'order.order_id'),
            'transaction_id'      => qp_event_safe_value($data, 'transaction_id'),
            'payment_id'          => qp_event_safe_value($data, 'payment_id'),
            'customer_first_name' => qp_event_safe_value($data, 'customer_first_name') ?: qp_event_safe_path($data, 'account.first_name'),
            'customer_last_name'  => qp_event_safe_value($data, 'customer_last_name') ?: qp_event_safe_path($data, 'account.last_name'),
            'customer_email'      => qp_event_safe_value($data, 'customer_email') ?: qp_event_safe_value($data, 'email'),
            'amount'              => qp_event_safe_value($data, 'amount'),
            'currency'            => qp_event_safe_value($data, 'currency', 'USD'),
            'status'              => qp_event_safe_value($data, 'status'),
            'message'             => isset($data['message']) && is_scalar($data['message']) ? qep_safe_error_text($data['message']) : '',
            'error_code'          => isset($data['error_code']) && is_scalar($data['error_code']) ? substr(qep_safe_error_text($data['error_code']), 0, 200) : '',
            'plugin_version'      => qp_get_plugin_version(),
        );

        // Refund and lookup requests may carry only the order ID.
        $order_id = isset($payload['order_id']) ? (string) $payload['order_id'] : '';
        if ($order_id !== '' && preg_match('/^[0-9]+$/D', $order_id) && function_exists('wc_get_order')
            && (empty($payload['customer_first_name']) || empty($payload['customer_last_name']) || empty($payload['customer_email']))) {
            $order = wc_get_order((int) $order_id);
            if ($order) {
                $customer_fields = array('customer_first_name' => 'get_billing_first_name',
                    'customer_last_name' => 'get_billing_last_name', 'customer_email' => 'get_billing_email');
                foreach ($customer_fields as $field => $getter) {
                    if (empty($payload[$field]) && is_callable(array($order, $getter))) {
                        $payload[$field] = qp_event_safe_value(array($field => $order->$getter()), $field);
                    }
                }
            }
        }

        $payload = array_filter($payload, function ($value) {
            return $value !== null && $value !== '';
        });

        return qep_enqueue_plugin_event($payload);
    }
}

if (!function_exists('qp_get_dashboard_api_url')) {
    function qp_get_dashboard_api_url()
    {
        return 'https://qoin-logs.quantumepay.com/';
    }
}

function qep_write_local_event($payload, $notice = '')
{
    if (!function_exists('wc_get_logger')) return false;
    $safe = array();
    foreach (array('event_type', 'order_id', 'transaction_id', 'amount', 'currency', 'status', 'message', 'error_code', 'plugin_version') as $key) {
        if (isset($payload[$key]) && is_scalar($payload[$key])) $safe[$key] = qep_safe_error_text($payload[$key]);
    }
    if (isset($payload['payment_id']) && is_scalar($payload['payment_id'])) {
        $safe['payment_id'] = substr(sanitize_text_field((string) $payload['payment_id']), 0, 255);
    }
    try {
        wc_get_logger()->log(($payload['event_type'] ?? '') === 'payment_success' ? 'info' : 'error',
            ($notice !== '' ? $notice . "\n" : '') . wp_json_encode($safe), array('source' => 'quantumepay-events'));
        return true;
    } catch (\Throwable $error) {
        return false;
    }
}

function qep_queue_has_capacity()
{
    global $wpdb;
    if (!isset($wpdb) || !is_callable(array($wpdb, 'get_var'))) return true;
    $count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s AND CHAR_LENGTH(option_name) = %d",
        $wpdb->esc_like('qep_log_event_') . '%', strlen('qep_log_event_') + 36));
    return $count !== null && (int) $count < 1000;
}

function qep_deliver_plugin_event($event_id)
{
    $key = 'qep_log_event_' . sanitize_key($event_id);
    $entry = get_option($key);
    if (!is_array($entry) || empty($entry['payload'])) return false;
    foreach (array('message', 'error_code') as $field) {
        if (isset($entry['payload'][$field])) $entry['payload'][$field] = qep_safe_error_text($entry['payload'][$field]);
    }
    if ((int) ($entry['attempts'] ?? 0) >= 100 || (int) ($entry['created_at'] ?? time()) <= time() - 7 * 86400) {
        qep_write_local_event($entry['payload'], 'Remote delivery stopped: retry limit reached.');
        delete_option($key);
        wp_clear_scheduled_hook('qep_retry_plugin_event', array($event_id));
        return false;
    }
    $lock = $key . '_lock';
    if (!add_option($lock, time(), '', false)) {
        if ((int) get_option($lock) < time() - 60) delete_option($lock);
        wp_schedule_single_event(time() + 60, 'qep_retry_plugin_event', array($event_id));
        return false;
    }
    try {
        $response = wp_remote_post(trailingslashit(qp_get_dashboard_api_url()) . 'api/plugin-events', array(
            'timeout' => 3, 'redirection' => 0, 'blocking' => true,
            'headers' => array('Content-Type' => 'application/json'),
            'body' => wp_json_encode($entry['payload'])));
        $status = is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);
        if ($status >= 200 && $status < 300) {
            delete_option($key);
            wp_clear_scheduled_hook('qep_retry_plugin_event', array($event_id));
            return true;
        }
        $entry['attempts'] = (int) ($entry['attempts'] ?? 0) + 1;
        $entry['last_http_status'] = $status;
        update_option($key, $entry, false);
        wp_clear_scheduled_hook('qep_retry_plugin_event', array($event_id));
        wp_schedule_single_event(time() + min(3600, 60 * pow(2, min(6, $entry['attempts']))), 'qep_retry_plugin_event', array($event_id));
        return false;
    } catch (\Throwable $error) {
        $entry['attempts'] = (int) ($entry['attempts'] ?? 0) + 1;
        update_option($key, $entry, false);
        wp_schedule_single_event(time() + 120, 'qep_retry_plugin_event', array($event_id));
        return false;
    } finally {
        delete_option($lock);
    }
}
add_action('qep_retry_plugin_event', 'qep_deliver_plugin_event');

function qep_enqueue_plugin_event($payload)
{
    $message = (string) ($payload['message'] ?? '');
    $chunks = array();
    // Keep each message within the existing receiver's field size without dropping later errors.
    while ($message !== '') {
        $chunk = function_exists('mb_substr') ? mb_substr($message, 0, 900) : substr($message, 0, 900);
        $chunks[] = $chunk;
        $message = function_exists('mb_substr') ? mb_substr($message, 900) : substr($message, 900);
    }
    if (!$chunks) $chunks[] = '';
    $queued = true;
    foreach ($chunks as $index => $chunk) {
        $part = $payload;
        $part['message'] = count($chunks) > 1 ? 'Error detail part ' . ($index + 1) . '/' . count($chunks) . ":\n" . $chunk : $chunk;
        qep_write_local_event($part);
        $queue_lock = 'qep_event_queue_capacity_lock';
        if (!add_option($queue_lock, time(), '', false)) {
            if ((int) get_option($queue_lock) < time() - 60) delete_option($queue_lock);
            qep_write_local_event($part, 'Remote delivery not queued: queue is busy; event retained locally.');
            $queued = false;
            continue;
        }
        $id = wp_generate_uuid4();
        try {
            if (!qep_queue_has_capacity()) {
                qep_write_local_event($part, 'Remote delivery not queued: queue limit reached; event retained locally.');
                $queued = false;
                continue;
            }
            $entry = array('payload' => $part, 'attempts' => 0, 'created_at' => time());
            if (!add_option('qep_log_event_' . $id, $entry, '', false)) { $queued = false; continue; }
            wp_schedule_single_event(time() + 60, 'qep_retry_plugin_event', array($id));
        } finally {
            delete_option($queue_lock);
        }
        qep_deliver_plugin_event($id);
    }
    return $queued;
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


// Phone bug


/**
 * Configure the WooCommerce billing phone field.
 */
add_filter('woocommerce_checkout_fields', function (array $fields): array {
    if (!isset($fields['billing']['billing_phone'])) {
        return $fields;
    }

    $fields['billing']['billing_phone']['label']       = 'Phone number';
    $fields['billing']['billing_phone']['placeholder'] = '(949) 555-1234';
    $fields['billing']['billing_phone']['required']    = true;
    $fields['billing']['billing_phone']['type']        = 'tel';

    $fields['billing']['billing_phone']['custom_attributes'] = [
        'inputmode'    => 'numeric',
        'autocomplete' => 'tel-national',
        'maxlength'    => '14',
    ];

    return $fields;
});


/**
 * Add the US phone prefix and formatting mask.
 */
add_action('wp_footer', function (): void {
    if (!is_checkout() || is_order_received_page()) {
        return;
    }
    ?>
    <style>
        #billing_phone_field .woocommerce-input-wrapper {
            position: relative;
            display: block;
        }

        #billing_phone_field .qep-phone-prefix {
            position: absolute;
            top: 50%;
            left: 14px;
            z-index: 2;
            transform: translateY(-50%);
            pointer-events: none;
            color: #475467;
            font-size: 14px;
            line-height: 1;
        }

        #billing_phone_field #billing_phone {
            padding-left: 42px;
        }
    </style>

    <script>
        (function ($) {
            function getDigits(value) {
                let digits = String(value || '').replace(/\D/g, '');

                /*
                 * Convert 1XXXXXXXXXX into XXXXXXXXXX.
                 */
                if (digits.length === 11 && digits.charAt(0) === '1') {
                    digits = digits.substring(1);
                }

                return digits.substring(0, 10);
            }

            function formatPhone(value) {
                const digits = getDigits(value);

                if (digits.length < 4) {
                    return digits;
                }

                if (digits.length < 7) {
                    return '(' + digits.substring(0, 3) + ') ' +
                        digits.substring(3);
                }

                return '(' + digits.substring(0, 3) + ') ' +
                    digits.substring(3, 6) + '-' +
                    digits.substring(6, 10);
            }

            function initializePhoneField() {
                const $phone = $('#billing_phone');

                if (!$phone.length) {
                    return;
                }

                const $wrapper = $phone.closest('.woocommerce-input-wrapper');

                if (!$wrapper.find('.qep-phone-prefix').length) {
                    $wrapper.prepend(
                        '<span class="qep-phone-prefix" aria-hidden="true">+1</span>'
                    );
                }

                $phone.val(formatPhone($phone.val()));
            }

            $(document.body).on('input', '#billing_phone', function () {
                const cursorPosition = this.selectionStart;
                const oldLength = this.value.length;

                this.value = formatPhone(this.value);

                const newLength = this.value.length;
                const nextPosition = Math.max(
                    0,
                    cursorPosition + (newLength - oldLength)
                );

                this.setSelectionRange(nextPosition, nextPosition);
            });

            $(document.body).on('blur change', '#billing_phone', function () {
                this.value = formatPhone(this.value);
            });

            /*
             * WooCommerce may redraw the checkout fields after AJAX updates.
             */
            $(document.body).on('updated_checkout', initializePhoneField);

            $(initializePhoneField);
        })(jQuery);
    </script>
    <?php
});


/**
 * Normalize the phone before WooCommerce creates the order.
 */
add_filter(
    'woocommerce_checkout_posted_data',
    function (array $data): array {
        if (empty($data['billing_phone'])) {
            return $data;
        }

        $digits = preg_replace(
            '/\D+/',
            '',
            (string) $data['billing_phone']
        );

        if (strlen($digits) === 11 && substr($digits, 0, 1) === '1') {
            $digits = substr($digits, 1);
        }

        $data['billing_phone'] = substr($digits, 0, 10);

        return $data;
    }
);


function qep_normalize_us_phone(string $phone): string
{
    $digits = preg_replace('/\D+/', '', $phone);

    if (strlen($digits) === 11 && substr($digits, 0, 1) === '1') {
        $digits = substr($digits, 1);
    }

    return substr($digits, 0, 10);
}