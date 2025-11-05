<?php

namespace WooQuantum\Application\APIs;

class BaseApi
{
    public $xterminal_key,
        $debug,
        $client_id,
        $client_secret,
        $base_url,
        $token_url;

    public function __construct($terminal_key, $debug, $client_id, $client_secret, $base_url, $token_url)
    {
        $this->xterminal_key = $terminal_key;
        $this->debug = $debug;
        $this->client_id = $client_id;
        $this->client_secret = $client_secret;
        $this->base_url = $base_url;
        $this->token_url = $token_url;
    }

    /**
     * Authenticates with the payment gateway to get an access token.
     *
     * This method fetches a new token if one is not already
     * cached in the WordPress transient API.
     *
     * @return string|null The access token on success, or null on failure.
     */
    public function authenticate()
    {
        // Check if debug mode is enabled
        if ($this->debug) {
            qp_plugin_log('Starting authentication process');
        }

        // Check if token is already cached using Transients API
        $qp_token = get_transient('qp_token');
        if (!empty($qp_token)) {
            if ($this->debug) {
                qp_plugin_log('Found cached token: ' . substr($qp_token, 0, 4) . '...');
            }
            return $qp_token;
        }

        // Validate required credentials
        if (empty($this->client_id) || empty($this->client_secret) || empty($this->token_url)) {
            $error_message = 'Missing required credentials or token URL';
            if ($this->debug) {
                qp_plugin_log('Authentication failed: ' . $error_message);
            }
            // Removed: wc_add_notice - API layer shouldn't handle user notifications
            return null;
        }

        // Prepare arguments for the token request
        $argsToken = array(
            'method' => 'POST',
            'timeout' => 15,
            'redirection' => 5,
            'blocking' => true,
            'headers' => array(
                'Content-Type' => 'application/x-www-form-urlencoded',
            ),
            'body' => http_build_query(array(
                'client_id' => $this->client_id,
                'client_secret' => $this->client_secret,
                'grant_type' => 'client_credentials',
            )),
        );

        if ($this->debug) {
            qp_plugin_log('Preparing token request to: ' . $this->token_url);
            qp_plugin_log('Token request payload: ' . json_encode([
                'client_id' => substr($this->client_id, 0, 4) . '...',
                'client_secret' => substr($this->client_secret, 0, 4) . '...',
                'grant_type' => 'client_credentials',
            ]));
        }

        // Make the remote request to the token endpoint
        $responseToken = wp_remote_post($this->token_url, $argsToken);

        // Handle WordPress HTTP API errors
        if (is_wp_error($responseToken)) {
            $error_message = $responseToken->get_error_message();
            if ($this->debug) {
                qp_plugin_log('WP Error while fetching token: ' . $error_message);
            }
            // Removed: wc_add_notice - API layer shouldn't handle user notifications
            return null;
        }

        // Retrieve response details
        $status_code = wp_remote_retrieve_response_code($responseToken);
        $body = wp_remote_retrieve_body($responseToken);

        if ($this->debug) {
            qp_plugin_log('Token response status: ' . $status_code);
            qp_plugin_log('Token response body: ' . (empty($body) ? 'Empty' : substr($body, 0, 100) . '...'));
        }

        // Decode the JSON response
        $data = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $error_message = 'Invalid JSON response from token endpoint: ' . json_last_error_msg();
            if ($this->debug) {
                qp_plugin_log('Authentication failed: ' . $error_message);
            }
            // Removed: wc_add_notice - API layer shouldn't handle user notifications
            return null;
        }

        // Check for a successful response and valid token
        if ($status_code !== 200 || empty($data['access_token'])) {
            $error_details = !empty($data['error_description']) ? $data['error_description'] : 'Unknown error';
            if ($this->debug) {
                qp_plugin_log("Token fetch failed. Status: {$status_code}. Error: " . $error_details);
                qp_plugin_log('Full response data: ' . json_encode($data));
            }
            // Removed: wc_add_notice - API layer shouldn't handle user notifications
            return null;
        }

        // Sanitize and cache the token
        $qp_token = sanitize_text_field($data['access_token']);
        $expires_in = !empty($data['expires_in']) ? max(60, intval($data['expires_in']) - 60) : 600; // Default to 10 minutes if expires_in is missing

        if ($this->debug) {
            qp_plugin_log('Token fetched successfully: ' . substr($qp_token, 0, 4) . '...');
            qp_plugin_log('Caching token for ' . $expires_in . ' seconds');
        }

        // Store token in transient
        set_transient('qp_token', $qp_token, $expires_in);

        return $qp_token;
    }

    /**
     * Fetches data from a specified API endpoint.
     *
     * This method first authenticates and then uses the access token
     * to make a secure GET request to the given endpoint.
     *
     * @param string $end_point The API endpoint to fetch data from.
     * @return array The decoded JSON response from the API, or an empty array on failure.
     */
    public function getData($end_point)
    {
        $access_token = $this->authenticate();
        if (empty($access_token)) {
            // Return empty array without user notification - let caller handle it
            return [];
        }

        $argsPayment = array(
            'method'      => 'GET',
            'timeout'     => 15, // Increase timeout for better stability
            'redirection' => 5,
            'blocking'    => true,
            'headers'     => array(
                'Content-Type'   => 'application/json',
                'X-TERMINAL-KEY' => $this->xterminal_key,
                'Authorization'  => 'Bearer ' . $access_token
            ),
        );

        $responsePayment = wp_remote_get($this->base_url . $end_point, $argsPayment);

        // Handle WordPress HTTP API errors.
        if (is_wp_error($responsePayment)) {
            $error_message = $responsePayment->get_error_message();
            if ($this->debug) {
                qp_plugin_log('GET request failed: ' . $error_message);
            }
            // Removed: wc_add_notice - API layer shouldn't handle user notifications
            return [];
        }

        $status_code = wp_remote_retrieve_response_code($responsePayment);
        $body        = wp_remote_retrieve_body($responsePayment);
        $responseBody = json_decode($body, true);

        if ($status_code !== 200 || empty($responseBody)) {
            if ($this->debug) {
                qp_plugin_log('GET request returned non-200 status: ' . $status_code);
                qp_plugin_log('Response body: ' . $body);
            }
            // Removed: wc_add_notice - API layer shouldn't handle user notifications
            return [];
        }

        return $responseBody;
    }

    /**
     * Posts data to a specified API endpoint.
     *
     * This method first authenticates and then uses the access token
     * to make a secure POST request with the given data.
     *
     * @param array  $post_fields The data to be sent in the request body.
     * @param string $end_point   The API endpoint to post data to.
     * @return array The complete response from wp_remote_post, or an error array on failure.
     */
    public function postData($post_fields, $end_point)
    {
        $access_token = $this->authenticate();
        if (empty($access_token)) {
            // Return error response without user notification
            return [
                'response' => ['code' => 401],
                'body' => wp_json_encode([
                    'message' => 'Authentication failed',
                    'error' => true
                ])
            ];
        }

        $argsPayment = array(
            'method'      => 'POST',
            'timeout'     => 15, // Increase timeout for better stability
            'redirection' => 5,
            'blocking'    => true,
            'headers'     => array(
                'Content-Type'   => 'application/json',
                'X-TERMINAL-KEY' => $this->xterminal_key,
                'Authorization'  => 'Bearer ' . $access_token
            ),
            'body' => qp_arr_to_json($post_fields)
        );

        $payment_endpoint = $this->base_url . $end_point;

        if ($this->debug) {
            qp_plugin_log('POST request to: ' . $payment_endpoint);
            $redacted_fields = $this->redact_sensitive_data($post_fields);
            qp_plugin_log('POST payload: ' . qp_arr_to_json($redacted_fields));
        }

        $response = wp_remote_post($payment_endpoint, $argsPayment);

        // Handle WordPress HTTP API errors.
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            if ($this->debug) {
                qp_plugin_log('POST request failed: ' . $error_message);
            }
            return [
                'response' => ['code' => 500],
                'body' => wp_json_encode([
                    'message' => $error_message,
                    'error' => true
                ])
            ];
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($this->debug) {
            qp_plugin_log('POST response status: ' . $status_code);
            qp_plugin_log('POST response body: ' . substr($body, 0, 500) . '...');
        }

        // Validate JSON response
        if (json_decode($body, true) === null) {
            if ($this->debug) {
                qp_plugin_log('Invalid JSON response from API');
            }
            return [
                'response' => ['code' => $status_code ?: 500],
                'body' => wp_json_encode([
                    'message' => 'Invalid API response format',
                    'error' => true
                ])
            ];
        }

        return $response;
    }

    /**
     * Redact sensitive data for logging purposes
     */
    private function redact_sensitive_data($data)
    {
        if (!is_array($data)) {
            return $data;
        }

        $sensitive_fields = ['qp_cvv', 'qp_ccNo', 'card_number', 'cvc', 'cvv', 'security_code'];
        $redacted_data = $data;

        foreach ($sensitive_fields as $field) {
            if (isset($redacted_data[$field])) {
                $redacted_data[$field] = '[REDACTED]';
            }
        }

        // Recursively redact nested arrays
        foreach ($redacted_data as $key => $value) {
            if (is_array($value)) {
                $redacted_data[$key] = $this->redact_sensitive_data($value);
            }
        }

        return $redacted_data;
    }

    /**
     * Get the last error message (useful for caller to handle errors)
     */
    public function get_last_error()
    {
        // You could implement error tracking here if needed
        return null;
    }
}
