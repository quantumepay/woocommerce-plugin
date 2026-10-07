<?php

namespace WooQuantum\Application\APIs;

class CreditCard extends BaseApi
{
    public $endpoint = 'creditcard';
    public function processPayment($post_data)
    {
        $post_fields = array(
            'account' => array(
                'first_name' => $post_data['first_name'],
                'last_name' => $post_data['last_name'],
                'card_security_code' => $post_data['qp_cvv'],
                'expiry_month' => $post_data['expiry_month'],
                'expiry_year' => $post_data['expiry_year'],
                'card_number' => str_replace(" ","",$post_data['qp_ccNo']),
                'billing_address' => $post_data['billing_address']
            ),
            'amount' => $post_data['total_amount'],
            'currency' => $post_data['currency'],
            'email' => $post_data['email'],
            'phone_number' => qep_normalize_us_phone($post_data['phone']),
            'order' => array(
                'order_id' => strval($post_data['order_id']),
                'description' => 'payment for #' . $post_data['order_id']
            ),
            'source_ip_address' => qp_get_user_ip(),
            'user_id' => $post_data['email']
        );
        return $this->postData($post_fields, $this->endpoint . '/sale');
    }

    public function isPaymentSettled($payment_id, $context = array())
    {
        if (empty($payment_id)) return new \WP_Error('qep_missing_payment_id', 'The order has no gateway payment ID.');
        $body = $this->getData($this->endpoint . '/' . rawurlencode($payment_id), $context);
        if (is_wp_error($body)) return $body;
        $status = $body['status'] ?? '';
        if ($status === 'pending_settlement') return false;
        if ($status === 'settled') return true;
        return new \WP_Error('qep_unknown_settlement', 'Cannot refund automatically: gateway payment status is ' . sanitize_text_field($status) . '. Please check Qoin.');
    }

    private function operationResult($response)
    {
        $result = $response['qep_result'];
        if ($result['outcome'] !== 'approved') return new \WP_Error('qep_' . $result['outcome'], $result['message'], $result);
        $body = json_decode($response['body'], true);
        return is_array($body) ? $body : array();
    }

    public function processRefund($payment_id, $post_data)
    {
        $fields = array('amount' => $post_data['amount'], 'source_ip_address' => qp_get_user_ip(), 'user_id' => $post_data['user_id']);
        return $this->operationResult($this->postData($fields, $this->endpoint . '/' . rawurlencode($payment_id) . '/refund',
            array('order_id' => $post_data['order_id'], 'amount' => $post_data['amount'], 'currency' => $post_data['currency'] ?? 'USD')));
    }

    public function processReversal($payment_id, $post_data)
    {
        $fields = array('user_id' => $post_data['user_id'], 'source_ip_address' => qp_get_user_ip());
        return $this->operationResult($this->postData($fields, $this->endpoint . '/' . rawurlencode($payment_id) . '/reversal',
            array('order_id' => $post_data['order_id'], 'currency' => $post_data['currency'] ?? 'USD')));
    }

    public function processRebill($payment_id, $post_data)
    {
        $context = array('order_id' => $post_data['order_id'] ?? '', 'customer_email' => $post_data['user_id'] ?? '');
        unset($post_data['order_id']);
        return $this->operationResult($this->postData($post_data, $this->endpoint . '/' . rawurlencode($payment_id) . '/rebill', $context));
    }
}
