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


// function getToken($payment = null)
// {
//     if (false === ($qp_token = get_transient('qp_token'))) {
//         qp_plugin_log("get token ***");

//         $argsToken = array(
//             'method'      => 'POST',
//             'timeout'     => 5,
//             'redirection' => 5,
//             'blocking'    => true,
//             'body' => http_build_query(array(
//                 'client_id' => $payment->testmode ? TESTING_CLIENT_ID : LIVE_CLIENT_ID,
//                 'client_secret' => $payment->testmode ? TESTING_CLIENT_SECRET : LIVE_CLIENT_SECRET,
//                 'grant_type' => 'client_credentials',
//             ))
//         );

//         $token_endpoint = $payment->testmode ? TEST_API_URL_IDENTITY : LIVE_API_URL_IDENTITY;
//         qp_plugin_log($token_endpoint);
//         qp_plugin_log($argsToken);

//         $responseToken = wp_remote_post($token_endpoint, $argsToken);
//         qp_plugin_log("*** GET TOKEN  Response token App***");

//         qp_plugin_log($responseToken);




//         if (!is_wp_error($responseToken)) {

//             $bodyToken = json_decode($responseToken['body']);
//             if (!empty($bodyToken->access_token)) {
//                 $qp_token = $bodyToken->access_token;
//                 set_transient('qp_token', $qp_token, 600);
//                 qp_plugin_log("fetched token: " . $qp_token);
//             } else {
//                 qp_plugin_log("Token error: " . serialize($bodyToken));
//                 wc_add_notice('Token error: ' . $bodyToken->error, 'error');
//                 return;
//             }
//         } else {
//             $error_response = json_decode($responseToken->get_error_message());
//             qp_plugin_log("wp_error: ");
//             qp_plugin_log($error_response);
//             wc_add_notice('Payment gateway connection error. ' . serialize($error_response), 'error');
//             return;
//         }
//     } //

//     return $qp_token;
// }
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
// function reversel_api_hit($payment_id, $payment, $user_id, $order_id)
// {

//     qp_plugin_log("*************** Reversal Api Calling **************");
//     $argsPayment = array(
//         'method'      => 'POST',
//         'timeout'     => 5,
//         'redirection' => 5,
//         'blocking'    => true,
//         'headers'     => array(
//             'Content-Type' => 'application/json',
//             'X-TERMINAL-KEY' => $payment->get_option('terminal_key'),
//             'Authorization' => 'Bearer ' . get_transient('qp_token')
//         ),
//         'body' => wp_json_encode(array(
//             // 'payment_id' => $payment_id,

//             // 'account' => array('first_name' => $billingData['first_name'], 'last_name' => $billingData['last_name'], 'card_security_code' => $qp_cvv, 'expiry_month' => $expiry_month, 'expiry_year' => $expiry_year, 'card_number' => $qp_ccNo, 'billing_address' => $billing_address),
//             // 'currency' => $order['currency'],

//             'source_ip_address' => getRealUserIp(),
//             'user_id' => $user_id
//         ))


//     );

//     qp_plugin_log('****** Response Reversel ARGS Detail*******');

//     qp_plugin_log($argsPayment);
//     qp_plugin_log('****** Reversal Api call *******');
//     $payment_endpoint = TEST_API_URL . 'creditcard/' . $payment_id . '/reversal';

//     $responsePayment = wp_remote_post($payment_endpoint, $argsPayment);
//     $responseBody = json_decode($responsePayment['body'], 1);

//     qp_plugin_log('****** $$  Reversal Body Response*******');
//     qp_plugin_log($responseBody);

//     qp_plugin_log('****** $$  Reversal Status*******');
//     qp_plugin_log($responseBody['status']);
//     if ($responseBody['status'] == 'reversed') {

//         change_status_to_refund($order_id);
//     }
//     //     add_action( 'woocommerce_order_status_cancelled', 'change_status_to_refund', 
//     // 21, 1 );

// }

// function payment_detail_api_hit($payment_id, $payment, $order_id)
// {
//     qp_plugin_log('****** Payment Detail Api call *******');
//     $payment_endpoint = TEST_API_URL . 'creditcard/' . $payment_id;
//     qp_plugin_log('****** Payment Detail Api End Point*******');
//     qp_plugin_log($payment_endpoint);
//     // getToken($payment);
//     // if (!empty(getToken($payment))) {
//     $argsPayment = array(
//         'method'      => 'GET',
//         'timeout'     => 5,
//         'redirection' => 5,
//         'blocking'    => true,
//         'headers'     => array(
//             'Content-Type' => 'application/json',
//             'X-TERMINAL-KEY' => $payment->get_option('terminal_key'),
//             'Authorization' => 'Bearer ' . get_transient('qp_token')
//         ),

//     );

//     qp_plugin_log('****** Response Payment ARGS Detail*******');

//     qp_plugin_log($argsPayment);

//     $responsePayment = wp_remote_get($payment_endpoint, $argsPayment);
//     $responseBody = json_decode($responsePayment['body'], 1);

//     qp_plugin_log('****** $$  Payment Status*******');
//     qp_plugin_log($responseBody['status']);

//     if ($responseBody['status'] == 'pending_settlement') {
//         $user_id = get_post_meta($order_id, '_billing_email', true);
//         reversel_api_hit($payment_id, $payment, $user_id, $order_id);
//         return true;
//     }


//     // }
//     return false;
// }

// function refundApiHit($refund_amount, $payment, $order_id, $order, $type_refund)
// {

//     qp_plugin_log('****** ####################### *******');
//     qp_plugin_log('****** Function call RefundAPIHIT*******');
//     qp_plugin_log($order);

//     $payment_id = get_post_meta($order_id, 'quantumepay_payment_id', true);

//     qp_plugin_log('****** Payment id *******');
//     qp_plugin_log($payment_id);

//     //payment detail api call
//     $payment_check = payment_detail_api_hit($payment_id, $payment, $order_id);

//     if (!$payment_check) {

//         $billingData = $order['billing'];
//         $billing_address = array('address_1' => $billingData['address_1'], 'address_2' => $billingData['address_2'], 'city' => $billingData['city'], 'state' => $billingData['state'], 'postal_code' => $billingData['postcode'], 'country_code' => $billingData['country']);


//         $qp_ccNo            = trim($_POST['qp_ccNo']);
//         $qp_expdate_post     = $_POST['qp_expdate'];
//         $qp_expdate         = str_replace(' ', '', $qp_expdate_post);
//         $exp                     = explode("/", $qp_expdate);
//         $expiry_month        = $exp[0];
//         $expiry_year         = $exp[1];
//         $qp_cvv              = trim($_POST['qp_cvv']);

//         $argsPayment = array(
//             'method'      => 'POST',
//             'timeout'     => 5,
//             'redirection' => 5,
//             'blocking'    => true,
//             'headers'     => array(
//                 'Content-Type' => 'application/json',
//                 'X-TERMINAL-KEY' => $payment->get_option('terminal_key'),
//                 'Authorization' => 'Bearer ' . get_transient('qp_token')
//             ),

//             'body' => wp_json_encode(array(
//                 // 'payment_id' => $payment_id,
//                 'amount' => $refund_amount,
//                 // 'account' => array('first_name' => $billingData['first_name'], 'last_name' => $billingData['last_name'], 'card_security_code' => $qp_cvv, 'expiry_month' => $expiry_month, 'expiry_year' => $expiry_year, 'card_number' => $qp_ccNo, 'billing_address' => $billing_address),
//                 // 'currency' => $order['currency'],

//                 'source_ip_address' => getRealUserIp(),
//                 'user_id' => get_post_meta($order_id, '_billing_email', true)
//             ))
//         );
//         qp_plugin_log('****** Payment args*******');
//         qp_plugin_log($argsPayment);
//         qp_plugin_log('****** Xtermina Key*******');
//         qp_plugin_log($payment->get_option('terminal_key'));

//         // Your API integration code here for partial refund

//         // https://uatpayments.quantumepay.com/creditcard/{payment_id}/refund
//         $payment_endpoint = TEST_API_URL . 'creditcard/' . get_post_meta($order_id, 'quantumepay_payment_id', true) . '/refund';
//         qp_plugin_log('****** End Point*******');
//         qp_plugin_log($payment_endpoint);

//         $responsePayment = wp_remote_post($payment_endpoint, $argsPayment);
//         qp_plugin_log('****** Response Payment*******');
//         qp_plugin_log($responsePayment);


//         $responseBody = json_decode($responsePayment['body'], 1);
//         //    dd($responseBody);
//         qp_plugin_log(' ===>>>> responseBody >>>>>' . $type_refund);
//         qp_plugin_log($responseBody);
//         qp_plugin_log('##');

//         $payment_id    = (!empty($responseBody['payment_id'])) ? $responseBody['payment_id'] : '';
//         $transaction_id = (!empty($responseBody['transaction_id'])) ? $responseBody['transaction_id'] : '';
//         // $orderNote = "Payment result: $message. \r\n payment id: $payment_id <br>\r\n Transaction_id: $transaction_id ";


//         // update_post_meta($order_id, $this->id . '_payment', json_encode($responseBody, JSON_PRETTY_PRINT));
//         // update_post_meta($order_id, $this->id . '_payment_id', $payment_id);
//         // update_post_meta($order_id, $this->id . '_transaction_id', $transaction_id);

//         // if ($responseBody['processor']['message'] == 'APPROVED') {
//         // $processor = $responseBody['processor'];

//         $message        = $responseBody['message']; // approved or completed
//         $status         = $responseBody['status'];   // pending_settlement

//         $orderNote = "Payment result: $message. \r\n payment id: $payment_id <br>\r\n Transaction_id: $transaction_id ";
//         update_post_meta($order_id,  '_refund_api_payment', json_encode($responseBody, JSON_PRETTY_PRINT));

//         qp_plugin_log($orderNote);
//         $order_detail_object = wc_get_order($order_id);
//         $order_detail_object->add_order_note($orderNote);

//         // qp_plugin_log('$$$$$$$ End Function $$$$$$$$$$$');

//         // }       

//     } //payment_check id detail


// }
