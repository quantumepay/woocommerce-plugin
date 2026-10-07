<?php

namespace WooQuantum\Application\APIs;

class BaseApi
{
    private $environment_test;

    public $xterminal_key,
        $client_id,
        $client_secret,
        $base_url,
        $token_url;

    public function __construct(
        $terminal_key,
        $environment_test = true,
        $client_id = '',
        $client_secret = '',
        $test_terminal_key = '',
        $test_client_id = '',
        $test_client_secret = ''
    ) {
        $this->environment_test = (bool) $environment_test;
        if ($environment_test) {
            $this->xterminal_key = !empty($test_terminal_key)
                ? $test_terminal_key
                : TESTING_XTERMINAL_KEY;

            $this->client_id = !empty($test_client_id)
                ? $test_client_id
                : TESTING_CLIENT_ID;

            $this->client_secret = !empty($test_client_secret)
                ? $test_client_secret
                : TESTING_CLIENT_SECRET;

            $this->base_url = TEST_API_URL;
            $this->token_url = TEST_API_URL_IDENTITY;
        } else {
            $this->xterminal_key = $terminal_key;
            $this->client_id = $client_id;
            $this->client_secret = $client_secret;
            $this->base_url = LIVE_API_URL;
            $this->token_url = LIVE_API_URL_IDENTITY;
        }
    }

    public function authenticate()
    {
        foreach (array($this->client_id, $this->client_secret, $this->xterminal_key) as $credential) {
            if (!is_string($credential) || trim($credential) === '' || preg_match('/[\r\n\x00]/', $credential)) {
                return $this->authentication_error('invalid_gateway_configuration', 'The payment gateway credentials are missing or invalid.');
            }
        }
        $key = $this->token_cache_key();
        $token = get_transient($key);
        if ($this->valid_access_token($token)) return $token;
        if ($token !== false) delete_transient($key);
        $response = wp_remote_post($this->token_url, array(
            'timeout' => 60, 'redirection' => 0, 'blocking' => true, 'sslverify' => true, 'limit_response_size' => 262144,
            'headers' => array('Content-Type' => 'application/x-www-form-urlencoded'),
            'body' => http_build_query(array('client_id' => $this->client_id,
                'client_secret' => $this->client_secret, 'grant_type' => 'client_credentials'))));
        $result = qep_parse_gateway_response($response, 'authenticate');
        if ($result['outcome'] !== 'approved') {
            return new \WP_Error('qep_authentication_failed', $result['customer_message'], $result);
        }
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!$this->valid_access_token($body['access_token'] ?? null)) {
            return $this->authentication_error('invalid_authentication_response', 'The authentication server returned an invalid token.');
        }
        if (isset($body['token_type']) && (!is_string($body['token_type']) || strcasecmp($body['token_type'], 'Bearer') !== 0)) {
            return $this->authentication_error('invalid_authentication_response', 'The authentication server returned an unsupported token type.');
        }
        $expiry = $body['expires_in'] ?? 600;
        if (!is_numeric($expiry) || !is_finite((float) $expiry) || (float) $expiry <= 0) {
            return $this->authentication_error('invalid_authentication_response', 'The authentication server returned an invalid token lifetime.');
        }
        $ttl = max(1, (int) min(3600, (float) $expiry) - 30);
        set_transient($key, $body['access_token'], $ttl);
        return $body['access_token'];
    }

    private function token_cache_key()
    {
        return 'qp_token_' . hash('sha256', $this->token_url . '|' . $this->client_id . '|' . $this->client_secret);
    }

    private function valid_access_token($token)
    {
        return is_string($token) && strlen($token) <= 16384 && preg_match('/^[A-Za-z0-9._~+\/-]+=*$/D', $token) === 1;
    }

    private function authentication_error($code, $message)
    {
        $result = qep_parse_gateway_response(array('response' => array('code' => 400),
            'body' => wp_json_encode(array('error' => $code, 'error_description' => $message))), 'authenticate');
        return new \WP_Error('qep_authentication_failed', $result['customer_message'], $result);
    }

    private function request($method, $end_point, $fields = array(), $context = array(), $operation = 'sale')
    {
        $token = $this->authenticate();
        if (is_wp_error($token)) {
            $result = $token->get_error_data();
            $result = $this->with_request_details($result, 'POST', $this->token_url, array('grant_type' => 'client_credentials'), $operation);
            qep_log_gateway_result($result, $context);
            return array('body' => '', 'qep_result' => $result);
        }
        $args = array('timeout' => 60, 'redirection' => 0, 'blocking' => true, 'sslverify' => true, 'limit_response_size' => 262144,
            'headers' => array('Content-Type' => 'application/json', 'X-TERMINAL-KEY' => $this->xterminal_key,
                'Authorization' => 'Bearer ' . $token));
        if ($method === 'POST') $args['body'] = qp_arr_to_json($fields);
        $response = $method === 'POST' ? wp_remote_post($this->base_url . $end_point, $args)
            : wp_remote_get($this->base_url . $end_point, $args);
        if (!is_wp_error($response) && (int) wp_remote_retrieve_response_code($response) === 401) {
            delete_transient($this->token_cache_key());
        }
        $result = qep_parse_gateway_response($response, $operation);
        $result = $this->with_request_details($result, $method, $this->base_url . $end_point, $fields, $operation);
        qep_log_gateway_result($result, $context);
        if (is_wp_error($response)) {
            $response = array('body' => '', 'qp_wp_error_code' => $response->get_error_code(),
                'qp_wp_error_message' => $response->get_error_message());
        }
        $response['qep_result'] = $result;
        return $response;
    }

    private function with_request_details($result, $method, $url, $fields, $requested_operation)
    {
        // Build a summary from permitted fields; never copy the raw request or headers.
        $summary = array();
        foreach (array('amount', 'currency', 'service_fee', 'surcharge', 'tip', 'discount', 'sales_tax', 'grant_type') as $key) {
            if (isset($fields[$key]) && is_scalar($fields[$key])) {
                $summary[$key] = substr(sanitize_text_field((string) $fields[$key]), 0, 100);
            }
        }
        $order_id = qp_event_safe_value($fields, 'order_id') ?: qp_event_safe_path($fields, 'order.order_id');
        if ($order_id !== null && $order_id !== '') $summary['order_id'] = substr((string) $order_id, 0, 100);
        $parts = parse_url($url);
        $endpoint = is_array($parts) ? ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '') . ($parts['path'] ?? '') : '';
        $details = array('Environment: ' . ($this->environment_test ? 'test' : 'live'),
            'Request method: ' . $method, 'Endpoint: ' . $endpoint,
            'Requested operation: ' . $requested_operation);
        if ($result['operation'] === 'authenticate') $details[] = 'Payment request sent: no';
        foreach ($summary as $key => $value) $details[] = 'Request ' . $key . ': ' . $value;
        $result['message'] .= "\n" . implode("\n", $details);
        return $result;
    }

    public function getData($end_point, $context = array())
    {
        $response = $this->request('GET', $end_point, array(), $context, 'lookup');
        if ($response['qep_result']['outcome'] !== 'approved') {
            return new \WP_Error('qep_lookup_failed', $response['qep_result']['message'], $response['qep_result']);
        }
        return json_decode($response['body'], true);
    }

    public function postData($post_fields, $end_point, $context = array())
    {
        $operation = substr($end_point, strrpos($end_point, '/') + 1);
        $context = array_merge(array(
            'order_id' => qp_event_safe_value($post_fields, 'order_id') ?: qp_event_safe_path($post_fields, 'order.order_id'),
            'amount' => qp_event_safe_value($post_fields, 'amount'),
            'currency' => qp_event_safe_value($post_fields, 'currency', 'USD'),
            'customer_first_name' => qp_event_safe_path($post_fields, 'account.first_name'),
            'customer_last_name' => qp_event_safe_path($post_fields, 'account.last_name'),
            'customer_email' => qp_event_safe_value($post_fields, 'email')), $context);
        return $this->request('POST', $end_point, $post_fields, $context, $operation);
    }
}
