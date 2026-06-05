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
            'phone_number' => $post_data['phone'],
            'order' => array(
                'order_id' => strval($post_data['order_id']),
                'description' => 'payment for #' . $post_data['order_id']
            ),
            'source_ip_address' => qp_get_user_ip(),
            'user_id' => $post_data['email']
        );
        return $this->postData($post_fields, $this->endpoint . '/sale');
    }

    public function isPaymentSettled($payment_id)
    {
        $payment_endpoint = $this->endpoint . '/' . $payment_id;
        $response_body = $this->getData($payment_endpoint);
        if ($response_body['status'] == 'pending_settlement') {
            return false;
        }
        return true;
    }

    public function processRefund($payment_id, $post_data)
    {


        $post_fields = array(
            'amount' => $post_data['amount'],
            'source_ip_address' => qp_get_user_ip(),
            'user_id' => $post_data['user_id'],
        );
        $payment_endpoint = $this->endpoint . '/' . $payment_id . '/refund';
        $responsePayment = $this->postData($post_fields, $payment_endpoint);


        $responseBody = qp_json_to_arr($responsePayment['body'], 1);
        //    dd($responseBody);

        $payment_id    = (!empty($responseBody['payment_id'])) ? $responseBody['payment_id'] : '';
        $transaction_id = (!empty($responseBody['transaction_id'])) ? $responseBody['transaction_id'] : '';

        $message        = $responseBody['message']; // approved or completed

        $orderNote = "Payment result: $message. \r\n payment id: $payment_id <br>\r\n Transaction_id: $transaction_id ";
        update_post_meta($post_data['order_id'],  '_refund_api_payment', json_encode($responseBody, JSON_PRETTY_PRINT));

        $order_detail_object = wc_get_order($post_data['order_id']);
        $order_detail_object->add_order_note($orderNote);
        qp_change_order_status($post_data['order_id'], 'wc-refunded');
    }

    public function processReversal($payment_id, $post_data)
    {

        $data_fields = array(
            'user_id' => $post_data['user_id'],
            'source_ip_address' => qp_get_user_ip()
        );



        $payment_endpoint = $this->endpoint . '/' . $payment_id . '/reversal';

        $responsePayment = $this->postData($data_fields, $payment_endpoint);
        $responseBody = qp_json_to_arr($responsePayment['body'], true);



        if ($responseBody['status'] == 'reversed') {

            qp_change_order_status($post_data['order_id'], 'wc-cancelled');
        }
    }

    public function processRebill($payment_id, $post_data)
    {
        $payment_endpoint = $this->endpoint . '/' . $payment_id . '/rebill';
        $responsePayment = $this->postData($post_data, $payment_endpoint);


        $responseBody = qp_json_to_arr($responsePayment['body'], true);
        //    dd($responseBody);
        return $responseBody;
    }
}
