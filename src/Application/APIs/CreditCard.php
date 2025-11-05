<?php

namespace WooQuantum\Application\APIs;

class CreditCard extends BaseApi
{
    public $endpoint = 'creditcard';

    public function processPayment($post_data)
    {
        $post_fields = [
            'account' => [
                'first_name' => $post_data['first_name'],
                'last_name' => $post_data['last_name'],
                'card_security_code' => $post_data['qp_cvv'],
                'expiry_month' => $post_data['expiry_month'],
                'expiry_year' => $post_data['expiry_year'],
                'card_number' => str_replace(" ", "", $post_data['qp_ccNo']),
                'billing_address' => $post_data['billing_address']
            ],
            'amount' => $post_data['total_amount'],
            'currency' => $post_data['currency'],
            'email' => $post_data['email'],
            'phone_number' => $post_data['phone'],
            'order' => [
                'order_id' => strval($post_data['order_id']),
                'description' => 'payment for #' . $post_data['order_id']
            ],
            'source_ip_address' => qp_get_user_ip(),
            'user_id' => $post_data['email']
        ];

        return $this->postData($post_fields, $this->endpoint . '/sale');
    }

    public function authorizePayment($post_data)
    {
        $required_fields = ['first_name', 'last_name', 'qp_cvv', 'expiry_month', 'expiry_year', 'qp_ccNo', 'total_amount', 'currency', 'email', 'phone', 'order_id'];
        foreach ($required_fields as $field) {
            if (empty($post_data[$field])) {
                qp_plugin_log("Missing required field: $field");
                return ['body' => wp_json_encode(['message' => "Missing required field: $field"])];
            }
        }
        $post_fields = [
            'account' => [
                'first_name' => $post_data['first_name'],
                'last_name' => $post_data['last_name'],
                'card_security_code' => $post_data['qp_cvv'],
                'expiry_month' => $post_data['expiry_month'],
                'expiry_year' => $post_data['expiry_year'],
                'card_number' => str_replace(" ", "", $post_data['qp_ccNo']),
                'billing_address' => $post_data['billing_address']
            ],
            'amount' => $post_data['total_amount'],
            'currency' => $post_data['currency'],
            'email' => $post_data['email'],
            'phone_number' => $post_data['phone'],
            'order' => [
                'order_id' => strval($post_data['order_id']),
                'description' => 'authorization for #' . $post_data['order_id']
            ],
            'source_ip_address' => qp_get_user_ip(),
            'user_id' => $post_data['email']
        ];

        return $this->postData($post_fields, $this->endpoint . '/authorization');
    }

    public function capturePayment($payment_id, $post_data)
    {
        $post_fields = [
            'amount' => $post_data['total_amount'],
            'order' => [
                'order_id' => strval($post_data['order_id']),
                'description' => 'capture for #' . $post_data['order_id']
            ],
            'source_ip_address' => $post_data['source_ip_address'],
            'user_id' => $post_data['user_id']
        ];

        return $this->postData($post_fields, $this->endpoint . '/' . $payment_id . '/capture');
    }

    public function isPaymentSettled($payment_id)
    {
        qp_plugin_log('****** Payment Detail API call *******');
        $payment_endpoint = $this->endpoint . '/' . $payment_id;

        $response_body = $this->getData($payment_endpoint);

        if (isset($response_body['status']) && $response_body['status'] === 'pending_settlement') {
            return false;
        }

        return true;
    }

    public function processRefund($payment_id, $post_data)
    {
        qp_plugin_log('****** Refund API Call *******');
        $post_fields = [
            'amount' => $post_data['amount'],
            'source_ip_address' => $post_data['source_ip_address'],
            'user_id' => $post_data['user_id'],
        ];

        $payment_endpoint = $this->endpoint . '/' . $payment_id . '/refund';
        $response = $this->postData($post_fields, $payment_endpoint);
        return $response;
    }

    public function processReversal($payment_id, $post_data)
    {
        qp_plugin_log('****** Reversal API Call *******');
        $post_fields = [
            'user_id' => $post_data['user_id'],
            'source_ip_address' => $post_data['source_ip_address']
        ];

        $payment_endpoint = $this->endpoint . '/' . $payment_id . '/reversal';
        $response = $this->postData($post_fields, $payment_endpoint);
        return $response;
    }

    public function processRebill($payment_id, $post_data)
    {
        $payment_endpoint = $this->endpoint . '/' . $payment_id . '/rebill';
        $response = $this->postData($post_data, $payment_endpoint);
        if (empty($response['body'])) {
            qp_plugin_log("Empty response from rebill endpoint");
            return ['body' => wp_json_encode(['message' => 'API communication failed'])];
        }
        return $response;
    }
}
