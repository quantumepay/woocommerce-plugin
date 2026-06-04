<?php

namespace WooQuantum\CustomClasses;

if (!class_exists('WC_Payment_Gateway')) return;

class Payment extends \WC_Payment_Gateway
{
    public $testmode,
        $client_id,
        $client_secret,
        $api_url,
        $api_url_identity,
        $terminal_key,
        $notices;


    public function __construct()
    {

        $this->id = 'quantumepay';
        $this->icon = WC_QUANTUMEPAY_PLUGIN_URL . '/assets/img/logo_quantumepay.png';
        $this->has_fields = true;
        $this->method_title = 'Quantom E Payment WooCommerce';
        $this->method_description = 'Take credit card payments on your store. If you dont already have an account with Quantumepay, you can create it <a href="#" target="_blank">here</a>. Need help with the setup? Read our documentation <a href="#" target="_blank">here</a>.'; // will be displayed on the options page

        $this->supports = array(
            'products',
            'subscriptions'
        );

        $this->init_form_fields();

        $this->init_settings();
        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->enabled = $this->get_option('enabled');
        $this->testmode = 'yes' === $this->get_option('testmode');
        $this->client_id = $this->testmode ? TESTING_CLIENT_ID : LIVE_CLIENT_ID;
        $this->client_secret = $this->testmode ? TESTING_CLIENT_SECRET : LIVE_CLIENT_SECRET;
        $this->api_url = $this->testmode ? TEST_API_URL : LIVE_API_URL;
        $this->api_url_identity = $this->testmode ? TEST_API_URL_IDENTITY : LIVE_API_URL_IDENTITY;

        $this->terminal_key = $this->get_option('terminal_key');


        $this->check_environment();
        add_action('admin_notices', array($this, 'admin_notices'));

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('wp_enqueue_scripts', array($this, 'payment_scripts'));
        add_action('woocommerce_api_quantumepay_hook', array($this, 'quantumepay_hook'));
    }


    public function admin_notices()
    {
        if (!empty($this->notices)) {
            foreach ((array) $this->notices as $notice_key => $notice) {
                echo "<div class='" . esc_attr($notice['class']) . "'><p>";
                echo wp_kses($notice['message'], array(
                    'a' => array(
                        'href' => array(),
                    ),
                ));
                echo '</p></div>';
            }
        }
    }


    public function add_admin_notice($slug, $class, $message)
    {
        $this->notices[$slug] = array(
            'class'   => $class,
            'message' => $message,
        );
    }


    public function check_environment()
    {
        $environment_warning = $this->get_environment_warning();
        if ($environment_warning && is_plugin_active(plugin_basename(__FILE__))) {
            $this->add_admin_notice('qp_bad_environment', 'error', $environment_warning);
        }
        $terminal_key = $this->terminal_key;

        if (empty($terminal_key) && !(isset($_GET['page'], $_GET['section']) && 'wc-settings' === $_GET['page'] && $this->id === $_GET['section'])) {
            $setting_link = admin_url('admin.php?page=wc-settings&tab=checkout&section=' . $this->id);
            $this->add_admin_notice('qp_prompt_connect', 'notice notice-warning', 'Quantumepay will not work until you <a href="%s">configure your api data (terminal key) </a>.');
        }

        if (get_option('woocommerce_force_ssl_checkout') == 'no' && $this->enabled == 'yes') {
            $msg = 'Quantumepay payment gateway is enabled and the force SSL option is disabled; your checkout is not secure! Please enable SSL and ensure your server has a valid SSL certificate.';
            $this->add_admin_notice('qp_ssl', 'notice notice-warning', $msg);
        }
    }

    public function get_environment_warning()
    {
        if (version_compare(phpversion(), WC_QUANTUMEPAY_MIN_PHP_VER, '<')) {
            $message = 'Quantumepay - The minimum PHP version required for this plugin is %1$s. You are running %2$s.';

            return sprintf($message, WC_QUANTUMEPAY_MIN_PHP_VER, phpversion());
        }
        if (!defined('WC_VERSION')) {
            return 'Quantumepay requires WooCommerce to be activated to work.';
        }
        if (version_compare(WC_VERSION, WC_QUANTUMEPAY_MIN_WC_VER, '<')) {
            $message = 'Quantumepay - The minimum WooCommerce version required for this plugin is %1$s. You are running %2$s.';

            return sprintf($message, WC_QUANTUMEPAY_MIN_WC_VER, WC_VERSION);
        }
        if (!function_exists('curl_init')) {
            return 'Quantumepay - cURL is not installed.';
        }

        return false;
    }


    public function init_form_fields()
    {


        $this->form_fields = array(
            'enabled' => array(
                'title'       => 'Enable/Disable',
                'label'       => 'Enable Quantumepay Gateway',
                'type'        => 'checkbox',
                'description' => '',
                'default'     => 'no'
            ),
            'title' => array(
                'title'       => 'Title',
                'type'        => 'text',
                'description' => 'This controls the title which the user sees during checkout.',
                'default'     => 'Credit Card',
                'desc_tip'    => true,
            ),
            'description' => array(
                'title'       => 'Description',
                'type'        => 'textarea',
                'description' => 'This controls the description which the user sees during checkout.',
                'default'     => 'Pay with your credit card via our payment gateway.',
            ),
            'testmode' => array(
                'title'       => 'Test mode',
                'label'       => 'Enable Test Mode',
                'type'        => 'checkbox',
                'default'     => 'yes',
                'desc_tip'    => false,
                'default'     => 'yes'
            ),

            'terminal_key' => array(
                'title'       => 'X-TERMINAL-KEY',
                'type'        => 'text'
            ),


        );
    }


    public function payment_fields()
    {

        if ($this->description) {
            if ($this->testmode) {
                $this->description .= ' TEST MODE ENABLED. In test mode, you can use the card numbers listed in <a href="#">documentation</a>.';
                $this->description  = trim($this->description);
            }
            echo wpautop(wp_kses_post($this->description));
        }
        require_once QP_FRONTEND_VIEWS . 'form.php';
    }

    public function payment_scripts()
    {

        if (!is_cart() && !is_checkout() && !isset($_GET['pay_for_order'])) {
            return;
        }

        if ('no' === $this->enabled) {
            return;
        }

        if (empty($this->client_id) || empty($this->client_secret)) {
            return;
        }

        if (!$this->testmode && !is_ssl()) {
            return;
        }

        wp_enqueue_style('quantumepay-style', WC_QUANTUMEPAY_PLUGIN_URL .  '/assets/css/quantumepay.css', '', WC_QUANTUMEPAY_VERSION . time());
        wp_enqueue_script('quantumepay-js', WC_QUANTUMEPAY_PLUGIN_URL . '/assets/js/quantumepay.js', array('jquery'), WC_QUANTUMEPAY_VERSION . time());
    }

    public function validate_fields()
    {

        if (empty($_POST['qp_ccNo'])) {
            wc_add_notice('Empty card number', $notice_type = 'error');
            return false;
        }
        if (empty($_POST['qp_expdate'])) {
            wc_add_notice('Empty expire date', $notice_type = 'error');
            return false;
        }
        if (empty($_POST['qp_cvv'])) {
            wc_add_notice('Empty CVV', $notice_type = 'error');
            return false;
        }

        return true;
    }

    public function getRealUserIp()
    {
        switch (true) {
            case (!empty($_SERVER['HTTP_X_REAL_IP'])):
                return $_SERVER['HTTP_X_REAL_IP'];
            case (!empty($_SERVER['HTTP_CLIENT_IP'])):
                return $_SERVER['HTTP_CLIENT_IP'];
            case (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])):
                return $_SERVER['HTTP_X_FORWARDED_FOR'];
            default:
                return $_SERVER['REMOTE_ADDR'];
        }
    }


    public function process_payment($order_id)
    {

        global $woocommerce;

        $order = wc_get_order($order_id);
        qp_plugin_log("-----------------------------------------------------------------------------------------");
        qp_plugin_log("*** process order: $order_id ***");
        $order_data = $order->get_data(); // The Order data  
        qp_plugin_log("*** Order Data: ***");
        qp_plugin_log($order_data);
        //    var_dump($order_data);exit;

        $billingData = $order_data['billing'];
        if (empty($billingData) and !empty($order_data['shipping'])) $billingData = $order_data['shipping'];
        if (empty($billingData)) {
            wc_add_notice('Empty billing address', 'error');
            return;
        }

        qp_plugin_log("*** Billing Data: ***");
        qp_plugin_log($billingData);

        if (empty($billingData['first_name']) or empty($billingData['last_name']) or empty($billingData['address_1']) or empty($billingData['postcode'])) {
            wc_add_notice('Invalid billing address', 'error');

            qp_plugin_log("*** EMPTY Data: ***");

            return;
        }


        $qp_ccNo            = trim($_POST['qp_ccNo']);
        $qp_expdate_post     = $_POST['qp_expdate'];
        $qp_expdate         = str_replace(' ', '', $qp_expdate_post);
        $exp                     = explode("/", $qp_expdate);
        $expiry_month        = $exp[0];
        $expiry_year         = $exp[1];
        $qp_cvv              = trim($_POST['qp_cvv']);


        if (false === ($qp_token = get_transient('qp_token'))) {
            qp_plugin_log("get token ***");

            $argsToken = array(
                'method'      => 'POST',
                'timeout'     => 5,
                'redirection' => 5,
                'blocking'    => true,
                'body' => http_build_query(array(
                    'client_id' => $this->client_id,
                    'client_secret' => $this->client_secret,
                    'grant_type' => 'client_credentials',
                ))
            );

            $token_endpoint = $this->api_url_identity;
            qp_plugin_log($token_endpoint);
            qp_plugin_log($argsToken);

            $responseToken = wp_remote_post($token_endpoint, $argsToken);
            qp_plugin_log("***   Response token ***");

            qp_plugin_log($responseToken);

            if (!is_wp_error($responseToken)) {

                $bodyToken = json_decode($responseToken['body']);
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
                $error_response = json_decode($responseToken->get_error_message());
                qp_plugin_log("wp_error: ");
                qp_plugin_log($error_response);
                wc_add_notice('Payment gateway connection error. ' . serialize($error_response), 'error');
                return;
            }
        } //


        if (!empty($qp_token)) {

            //qp_plugin_log("qp_token: $qp_token");

            $billing_address = array('address_1' => $billingData['address_1'], 'address_2' => $billingData['address_2'], 'city' => $billingData['city'], 'state' => $billingData['state'], 'postal_code' => $billingData['postcode'], 'country_code' => $billingData['country']);
            qp_plugin_log("**** Billing Address  ");
            qp_plugin_log($billing_address);


            $argsPayment = array(
                'method'      => 'POST',
                'timeout'     => 5,
                'redirection' => 5,
                'blocking'    => true,
                'headers'     => array(
                    'Content-Type' => 'application/json',
                    'X-TERMINAL-KEY' => $this->terminal_key,
                    'Authorization' => 'Bearer ' . $qp_token
                ),

                'body' => wp_json_encode(array(
                    'account' => array('first_name' => $billingData['first_name'], 'last_name' => $billingData['last_name'], 'card_security_code' => $qp_cvv, 'expiry_month' => $expiry_month, 'expiry_year' => $expiry_year, 'card_number' => $qp_ccNo, 'billing_address' => $billing_address),
                    'amount' => $order_data['total'],
                    'currency' => $order_data['currency'],
                    'email' => $billingData['email'],
                    'phone_number' => $billingData['phone'],
                    'order' => array('order_id' => strval($order_id), 'description' => 'payment for #' . $order_id),
                    'source_ip_address' => $this->getRealUserIp(),
                    'user_id' => $billingData['email']
                ))
            );

            $payment_endpoint = $this->api_url . 'creditcard/sale';
            qp_plugin_log($payment_endpoint);
            qp_plugin_log($argsPayment);

            $responsePayment = wp_remote_post($payment_endpoint, $argsPayment);
            qp_plugin_log($responsePayment);


            $responseBody = json_decode($responsePayment['body'], 1);
            //    dd($responseBody);
            qp_plugin_log('responseBody');
            qp_plugin_log($responseBody);
            qp_plugin_log('###############################');

            // if ($this->testmode) wc_add_notice("<pre>" . print_r($responseBody, 1) . "</pre>", 'notice');

            $payment_id    = (!empty($responseBody['payment_id'])) ? $responseBody['payment_id'] : '';
            $transaction_id = (!empty($responseBody['transaction_id'])) ? $responseBody['transaction_id'] : '';

            update_post_meta($order_id, $this->id . '_payment', json_encode($responseBody, JSON_PRETTY_PRINT));
            update_post_meta($order_id, $this->id . '_payment_id', $payment_id);
            update_post_meta($order_id, $this->id . '_transaction_id', $transaction_id);
            qp_plugin_log($this->id);
            if ($responseBody['processor']['message'] == 'APPROVED') {
                $processor = $responseBody['processor'];

                $message        = $responseBody['message']; // approved or completed
                $status         = $responseBody['status'];   // pending_settlement

                $orderNote = "Payment result: $message. \r\n payment id: $payment_id <br>\r\n Transaction_id: $transaction_id ";

                // wc_add_notice($orderNote, 'success');
                qp_plugin_log($orderNote);

                $order->add_order_note($orderNote);

                // if (!$this->testmode) {
                $order->payment_complete();
                $order->reduce_order_stock();
                $woocommerce->cart->empty_cart();

                return array(
                    'result' => 'success',
                    'redirect' => $this->get_return_url($order)
                );





                // }

            } else {
                $errMsg = '';
                if (!empty($responseBody['status'])) $errMsg .= $responseBody['status'] . ' / ';
                if (!empty($responseBody['title'])) $errMsg .= $responseBody['title'] . ' / ';
                if (!empty($responseBody['message'])) $errMsg .= $responseBody['message'] . ' / ';

                if (!empty($responseBody['processor']['message'])) $errMsg .= " ** " . $responseBody['processor']['message'] . '** / ';

                if (!empty($responseBody['errors'])) {
                    //$errMsg .= print_r($responseBody['errors'],1);
                    foreach ($responseBody['errors'] as $err) {
                        $errMsg .= $err['field'] . ' - ' . $err['message'] . "<br>\r\n";
                    }
                }
                qp_plugin_log('******************');
                qp_plugin_log($responseBody['errors']);

                wc_add_notice($errMsg, 'error');
                $order->add_order_note("Error with payment. $errMsg");

                /*
              return array(
                   'result' => 'success',
                   'redirect' => $this->get_return_url($order)
               );
              */
                $error_show = array(
                    'result'   => 'failure',
                    'messages' => $errMsg
                );

                // return array(
                //     'result'   => 'failure',
                //     'messages' => $errMsg
                // );


                $msghtml = '<h5>failure</h5>';
                $msghtml .= '<ul>';
                foreach ($errMsg as $msg) {
                    $msghtml .= "<li>'" . $msg . "'</li>";
                }
                $msghtml .= '</ul>';
                qp_plugin_log("#############################################");
                qp_plugin_log($msghtml);

                return $msghtml;
            }


            return;
        } // !empty($qp_token)



    }


    public function quantumepay_hook()
    {

        $order = wc_get_order($_GET['id']);
        $order->payment_complete();
        $order->reduce_order_stock();

        update_option('webhook_debug', $_GET);
    }
}
