<?php

namespace WooQuantum\Application\APIs;

class BaseApi
{
    public $xterminal_key,
        $client_id,
        $client_secret,
        $base_url,
        $token_url;

    public function __construct($terminal_key, $environment_test = true)
    {
        $this->xterminal_key = $environment_test ? TESTING_XTERMINAL_KEY : $terminal_key;
        $this->client_id = $environment_test ? TESTING_CLIENT_ID : LIVE_CLIENT_ID;
        $this->client_secret = $environment_test ? TESTING_CLIENT_SECRET : LIVE_CLIENT_SECRET;
        $this->base_url = $environment_test ? TEST_API_URL : LIVE_API_URL;
        $this->token_url = $environment_test ? TEST_API_URL_IDENTITY : LIVE_API_URL_IDENTITY;
    }

    public function authenticate()
    {
        $qp_token = get_transient('qp_token');
        if (empty($qp_token)) {
            qp_plugin_log("get token ***");

            $argsToken = array(
                'method'      => 'POST',
                'timeout'     => 60,
                'redirection' => 5,
                'blocking'    => true,
                'body' => http_build_query(array(
                    'client_id' => $this->client_id,
                    'client_secret' => $this->client_secret,
                    'grant_type' => 'client_credentials',
                ))
            );
            $token_endpoint = $this->token_url;
            qp_plugin_log($token_endpoint);
            qp_plugin_log($argsToken);

            $responseToken = wp_remote_post($token_endpoint, $argsToken);
            qp_plugin_log("*** GET TOKEN  Response token App***");
            if (!is_wp_error($responseToken)) {

                $bodyToken = qp_json_to_arr($responseToken['body']);
                if (!empty($bodyToken->access_token)) {
                    $qp_token = $bodyToken->access_token;
                    set_transient('qp_token', $qp_token, 600);
                    qp_plugin_log("fetched token: " . $qp_token);
                } else {
                    qp_plugin_log("Token error: " . serialize($bodyToken));
                    wc_add_notice('Token error: ' . $bodyToken->error, 'error');
                    return;
                }
            } else {
                $error_response = qp_json_to_arr($responseToken->get_error_message());
                qp_plugin_log("wp_error: ");
                qp_plugin_log($error_response);
                wc_add_notice('Payment gateway connection error. ' . serialize($error_response), 'error');
                return;
            }
        }
        return $qp_token;
    }

    public function getData($end_point)
    {
        $access_token = $this->authenticate();
        if (empty($access_token)) {
            return [];
        }
        $argsPayment = array(
            'method'      => 'GET',
            'timeout'     => 60,
            'redirection' => 5,
            'blocking'    => true,
            'headers'     => array(
                'Content-Type' => 'application/json',
                'X-TERMINAL-KEY' => $this->xterminal_key,
                'Authorization' => 'Bearer ' . $access_token
            ),

        );

        qp_plugin_log('****** Get Data*******');

        qp_plugin_log($argsPayment);

        $responsePayment = wp_remote_get($this->base_url . $end_point, $argsPayment);
        if (!is_wp_error($responsePayment)) {
            $responseBody = qp_json_to_arr($responsePayment['body'], true);
        }

        return [];
    }

    public function postData($post_fields, $end_point)
    {
        $access_token = $this->authenticate();
        if (empty($access_token)) {
            return ['body' => ''];
        }
        $argsPayment = array(
            'method'      => 'POST',
            'timeout'     => 60,
            'redirection' => 5,
            'blocking'    => true,
            'headers'     => array(
                'Content-Type' => 'application/json',
                'X-TERMINAL-KEY' => $this->xterminal_key,
                'Authorization' => 'Bearer ' . $access_token
            ),

            'body' => qp_arr_to_json($post_fields)
        );

        $payment_endpoint = $this->base_url . $end_point;
        qp_plugin_log($payment_endpoint);
        qp_plugin_log($argsPayment);

        $response = wp_remote_post($payment_endpoint, $argsPayment);
        if (!is_wp_error($response)) {
            return $response;
        }

        qp_plugin_log('wp_remote_post error: ' . $response->get_error_message());

        return array(
            'body' => '',
            'qp_wp_error_code' => $response->get_error_code(),
            'qp_wp_error_message' => $response->get_error_message(),
        );
    }
}
