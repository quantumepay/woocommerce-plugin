<?php

namespace WooQuantum\Application\Gateways;

use WooQuantum\Application\APIs\CreditCard as APIsCreditCard;
use WP_Error;

class CreditCard extends \WC_Payment_Gateway_CC
{
    public $testmode,
        $terminal_key,
        $timeout_notification_recipients,
        $notices;

    public function __construct()
    {

        $this->id = QP_GATEWAY_ID;
        $this->icon = WC_QUANTUMEPAY_PLUGIN_URL . '/assets/img/logo_quantumepay.png';
        $this->has_fields = true;
        $this->method_title = 'Quantum ePay';
        $this->method_description = 'Accept credit card payments with Qoin, the next generation WooCommerce payment gateway. Only from Quantum ePay.'; // will be displayed on the options page

        $this->supports = array(
            'products',
            'refunds',
            'subscriptions',
            'subscription_cancellation',
            'subscription_suspension',
            'subscription_reactivation',
            'subscription_amount_changes',
            'subscription_date_changes',
            'subscription_payment_method_change',
            'subscription_payment_method_change_customer',
            'subscription_payment_method_change_admin',
            'multiple_subscriptions',
        );

        $this->init_form_fields();

        $this->init_settings();
        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->enabled = $this->get_option('enabled');
        $this->testmode = 'yes' === $this->get_option('testmode');

        $this->terminal_key = $this->get_option('terminal_key');
        $this->timeout_notification_recipients = $this->get_option('timeout_notification_recipients');

        $this->check_environment();

        add_action('admin_notices', array($this, 'admin_notices'));

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));

        add_action('wp_enqueue_scripts', array($this, 'payment_scripts'));

        add_action('woocommerce_scheduled_subscription_payment_' . QP_GATEWAY_ID, array($this, 'scheduled_subscription_payment'), 10, 2);
        add_action('woocommerce_before_thankyou', array($this, 'maybe_show_pending_payment_message'), 1);
        // add_action('woocommerce_api_quantumepay_hook', array($this, 'quantumepay_hook'));
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
            $this->add_admin_notice('qp_prompt_connect', 'notice notice-warning', 'Qoin Payment Gateway will not work until you <a href="%s">configure your api data (terminal key) </a>.');
        }

        if (!is_ssl() && $this->testmode != 'yes') {
            $msg = 'Qoin Payment Gateway is enabled and the force SSL option is disabled; your checkout is not secure! Please enable SSL and ensure your server has a valid SSL certificate.';
            $this->add_admin_notice('qp_ssl', 'notice notice-warning', $msg);
        }
    }

    public function get_environment_warning()
    {
        if (version_compare(phpversion(), WC_QUANTUMEPAY_MIN_PHP_VER, '<')) {
            $message = 'Qoin Payment Gateway - The minimum PHP version required for this plugin is %1$s. You are running %2$s.';

            return sprintf($message, WC_QUANTUMEPAY_MIN_PHP_VER, phpversion());
        }
        if (!defined('WC_VERSION')) {
            return 'Qoin Payment Gateway requires WooCommerce to be activated to work.';
        }
        if (version_compare(WC_VERSION, WC_QUANTUMEPAY_MIN_WC_VER, '<')) {
            $message = 'Qoin Payment Gateway - The minimum WooCommerce version required for this plugin is %1$s. You are running %2$s.';

            return sprintf($message, WC_QUANTUMEPAY_MIN_WC_VER, WC_VERSION);
        }
        if (!function_exists('curl_init')) {
            return 'Qoin Payment Gateway - cURL is not installed.';
        }

        return false;
    }

    public function init_form_fields()
    {


        $this->form_fields = array(
            'enabled' => array(
                'title'       => 'Enable/Disable',
                'label'       => 'Enable Qoin Payment Gateway',
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
            'timeout_notification_recipients' => array(
                'title'       => 'Timeout Notification Recipients',
                'type'        => 'textarea',
                'description' => 'Optional comma-separated email addresses to notify when a payment request times out or returns an unknown result. The site admin email is always included.',
                'default'     => '',
                'desc_tip'    => true,
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
        $this->form();
    }

    public function payment_scripts()
    {

        if (!is_cart() && !is_checkout() && !isset($_GET['pay_for_order'])) {
            return;
        }

        if ('no' === $this->enabled) {
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
        $gateway_id = $this->id;
        if (empty($_POST[$gateway_id . '-card-number'])) {
            wc_add_notice('Empty card number', 'error');
            return false;
        }
        if (empty($_POST[$gateway_id . '-card-expiry'])) {
            wc_add_notice('Empty expire date', 'error');
            return false;
        }
        if (empty($_POST[$gateway_id . '-card-cvc'])) {
            wc_add_notice('Empty CVV', 'error');
            return false;
        }
        if (empty($this->terminal_key) && !$this->testmode) {
            wc_add_notice('Please Provide a Xterminal Key', 'error');
            return false;
        }

        return true;
    }

    public function maybe_show_pending_payment_message($order_id)
    {
        if (!$order_id) {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order || $order->get_payment_method() !== $this->id || !$order->get_meta('_quantumepay_pending_timeout')) {
            return;
        }

        echo '<style>.woocommerce-order-overview,.woocommerce-order-details,.woocommerce-customer-details,.woocommerce-thankyou-order-received{display:none!important}.quantumepay-pending-payment-message{text-align:center;max-width:720px;margin:48px auto;padding:48px 24px}.quantumepay-pending-payment-message img{max-width:220px;height:auto;margin:0 auto 28px;display:block}.quantumepay-pending-payment-message h2{font-size:32px;line-height:1.2;margin:0 0 16px}.quantumepay-pending-payment-message p{font-size:18px;line-height:1.5;margin:0}</style>';
        echo '<div class="quantumepay-pending-payment-message">';
        echo '<img src="' . esc_url(WC_QUANTUMEPAY_PLUGIN_URL . '/assets/img/logo_quantumepay.png') . '" alt="Quantum ePay">';
        echo '<h2>' . esc_html__('Thank you for your order!', 'woocommerce-gateway-quantum') . '</h2>';
        echo '<p>' . esc_html__('Your payment is being processed, we will be in contact with you soon.', 'woocommerce-gateway-quantum') . '</p>';
        echo '</div>';
    }

    private function is_timeout_payment_response($responsePayment, $responseBody)
    {
        if (!empty($responsePayment['qp_wp_error_code']) || !empty($responsePayment['qp_wp_error_message'])) {
            $error_text = strtolower($responsePayment['qp_wp_error_code'] . ' ' . $responsePayment['qp_wp_error_message']);

            return strpos($error_text, 'timed out') !== false
                || strpos($error_text, 'timeout') !== false
                || strpos($error_text, 'operation timed out') !== false;
        }

        return empty($responseBody) || !is_array($responseBody);
    }

    private function get_timeout_notification_recipients()
    {
        $recipients = array(get_option('admin_email'));
        $extra_recipients = preg_split('/[,\r\n]+/', (string) $this->timeout_notification_recipients);

        foreach ($extra_recipients as $recipient) {
            $recipient = sanitize_email(trim($recipient));
            if (is_email($recipient)) {
                $recipients[] = $recipient;
            }
        }

        $recipients[] = 'justybryle.ramos@quantumepay.com';
        $recipients[] = 'support@quantumepay.com';

        return array_values(array_unique(array_filter($recipients)));
    }

    private function send_timeout_payment_notification($order, $responsePayment)
    {
        $recipients = $this->get_timeout_notification_recipients();

        if (empty($recipients)) {
            return;
        }

        $subject = sprintf('Quantum ePay payment needs review - Order #%s', $order->get_order_number());

        $message = "A payment was initiated but the gateway request timed out or returned an unknown result.\n\n";
        $message .= "Please check the Qoin dashboard for the actual payment result before changing this order status or asking the customer to pay again.\n\n";
        $message .= "Order: #" . $order->get_order_number() . "\n";
        $message .= "Order ID: " . $order->get_id() . "\n";
        $message .= "Customer: " . trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()) . "\n";
        $message .= "Email: " . $order->get_billing_email() . "\n";
        $message .= "Total: " . strip_tags(html_entity_decode($order->get_formatted_order_total())) . "\n";
        $message .= "Admin URL: " . admin_url('post.php?post=' . $order->get_id() . '&action=edit') . "\n\n";

        if (!empty($responsePayment['qp_wp_error_message'])) {
            $message .= "Gateway error: " . $responsePayment['qp_wp_error_message'] . "\n";
        }

        wp_mail($recipients, $subject, $message);
    }

    public function process_payment($order_id)
    {
        $gateway_id = $this->id;
        global $woocommerce;

        $order = wc_get_order($order_id);

        $lock_key = '_quantumepay_processing_lock';

        if ($order->is_paid()) {
            return array(
                'result' => 'success',
                'redirect' => $this->get_return_url($order)
            );
        }

        if ($order->get_meta($lock_key)) {
            wc_add_notice('Payment is already processing. Please wait.', 'error');

            return array(
                'result'   => 'failure',
                'redirect' => ''
            );
        }

        $order->update_meta_data($lock_key, time());
        $order->save();

        $order_data = $order->get_data();
        qp_plugin_log($order_data);

        $billingData = $order_data['billing'];
        if (empty($billingData) and !empty($order_data['shipping'])) $billingData = $order_data['shipping'];
        if (empty($billingData)) {
            $order->delete_meta_data($lock_key);
            $order->save();

            wc_add_notice('Empty billing address', 'error');
            return;
        }

        if (empty($billingData['first_name']) or empty($billingData['last_name']) or empty($billingData['address_1']) or empty($billingData['postcode'])) {
            $order->delete_meta_data($lock_key);
            $order->save();

            return;
        }

        $qp_ccNo            = trim($_POST[$gateway_id . '-card-number']);
        $qp_expdate_post     = $_POST[$gateway_id . '-card-expiry'];
        $qp_expdate         = str_replace(' ', '', $qp_expdate_post);
        $exp                     = explode("/", $qp_expdate);
        $expiry_month        = $exp[0];
        $expiry_year         = $exp[1];
        $qp_cvv              = trim($_POST[$gateway_id . '-card-cvc']);

        $billing_address = array(
            'address_1' => $billingData['address_1'],
            'address_2' => $billingData['address_2'],
            'city' => $billingData['city'],
            'state' => $billingData['state'],
            'postal_code' => $billingData['postcode'],
            'country_code' => $billingData['country']
        );

        $post_data = array(
            'first_name' => $billingData['first_name'],
            'last_name' => $billingData['last_name'],
            'qp_cvv' => $qp_cvv,
            'expiry_month' => $expiry_month,
            'expiry_year' => $expiry_year,
            'qp_ccNo' => $qp_ccNo,
            'billing_address' => $billing_address,
            'total_amount' => $order_data['total'],
            'currency' => $order_data['currency'],
            'email' => $billingData['email'],
            'phone' => $billingData['phone'],
            'order_id' => strval($order_id),
        );

        $cardPayment = new APIsCreditCard($this->terminal_key, $this->testmode);
        // $responsePayment = $cardPayment->processPayment($post_data);

        if (strtolower(trim($billingData['email'])) === 'justybryle.ramos@quantumepay.com') {
            $responsePayment = array(
                'body' => '',
                'qp_wp_error_code' => 'simulated_timeout',
                'qp_wp_error_message' => 'Simulated timeout for testing',
            );
        } else {
            $responsePayment = $cardPayment->processPayment($post_data);
        }

        qp_plugin_log($responsePayment);
        $responseBody = $responsePayment['body'];
        qp_plugin_log('responseBody');
        qp_plugin_log($responseBody);
        qp_plugin_log('###############################');
        $responseBody = qp_json_to_arr($responseBody, true);

        if ($this->is_timeout_payment_response($responsePayment, $responseBody)) {
            $order->update_meta_data('_quantumepay_pending_timeout', time());
            $order->update_status('on-hold', 'Error with payment due to timeout. Payment was initiated but the gateway response was not confirmed. Please check Qoin dashboard before asking the customer to pay again.');
            $order->save();

            $this->send_timeout_payment_notification($order, $responsePayment);

            $woocommerce->cart->empty_cart();

            return array(
                'result'   => 'success',
                'redirect' => add_query_arg('quantumepay_pending_payment', '1', $this->get_return_url($order)),
            );
        }

        qp_plugin_log($this->id);
        if (!empty($responseBody['message']) && $responseBody['message'] == 'approved or completed') {
            $payment_id    = (!empty($responseBody['payment_id'])) ? $responseBody['payment_id'] : '';
            $transaction_id = (!empty($responseBody['transaction_id'])) ? $responseBody['transaction_id'] : '';

            update_post_meta($order_id, $this->id . '_payment', qp_arr_to_json($responseBody));
            update_post_meta($order_id, $this->id . '_payment_id', $payment_id);
            update_post_meta($order_id, $this->id . '_transaction_id', $transaction_id);

            $message        = $responseBody['message'];

            $orderNote = "Payment result: $message. \r\n payment id: $payment_id <br>\r\n Transaction_id: $transaction_id ";

            qp_plugin_log($orderNote);

            $order->add_order_note($orderNote);

            $order->delete_meta_data($lock_key);
            $order->save();

            $order->payment_complete();
            wc_reduce_stock_levels($order_id);
            $woocommerce->cart->empty_cart();

            return array(
                'result' => 'success',
                'redirect' => $this->get_return_url($order)
            );
        } else {
            $errMsg = '';
            if (!empty($responseBody['status'])) $errMsg .= $responseBody['status'] . ' / ';
            if (!empty($responseBody['title'])) $errMsg .= $responseBody['title'] . ' / ';
            if (!empty($responseBody['message'])) $errMsg .= $responseBody['message'] . ' / ';

            if (!empty($responseBody['processor']['message'])) $errMsg .= " ** " . $responseBody['processor']['message'] . '** / ';

            if (!empty($responseBody['errors'])) {
                foreach ($responseBody['errors'] as $err) {
                    $errMsg .= $err['field'] . ' - ' . $err['message'] . "<br>\r\n";
                }
            }

            qp_plugin_log('******************');
            qp_plugin_log($responseBody['errors']);

            $error_message_to_show = '';

            if ($responseBody['code'] == 'declined_by_processor') {
                $error_message_to_show = 'Payment declined by processor. Please double check the CVC & Card Expiration Date provided and try again.';
            }
            if ($responseBody['code'] == 'avs_code_not_permitted') {
                $error_message_to_show = 'Payment declined by processor. Please double check the Zip Code provided and try again.';
            }

            foreach ($responseBody['errors'] as $error) {
                if ($error['code'] == 'invalid_card_number' && $error['field'] == 'account.card_number') {
                    $error_message_to_show = 'Invalid card number provided. Please try again.';
                    break;
                }
                if ($error['code'] == 'invalid_value' && $error['field'] == 'account.expiry_month') {
                    $error_message_to_show = 'Payment declined by processor. Please double check the CVC & Card Expiration Date provided and try again.';
                    break;
                }
                if ($error['code'] == 'invalid_length' && $error['field'] == 'phone_number') {
                    $error_message_to_show = 'Invalid phone number provided. Please try again.';
                    break;
                }
            }

            $order->delete_meta_data($lock_key);
            $order->save();

            wc_add_notice($error_message_to_show, 'error');
            $order->add_order_note("Error with payment. $error_message_to_show");

            return array(
                'result'   => 'failure',
                'redirect' => '',
                'message' => $error_message_to_show
            );
        }
    }

    public function scheduled_subscription_payment($amount_to_charge, $renewal_order)
    {
        $result = $this->process_subscription_payment($renewal_order, $amount_to_charge);
        if (is_wp_error($result)) {
            \WC_Subscriptions_Manager::process_subscription_payment_failure_on_order($renewal_order);
        } else {
            \WC_Subscriptions_Manager::process_subscription_payments_on_order($renewal_order);
        }
    }

    public function process_subscription_payment($renewal_order, $amount_to_charge)
    {
        qp_plugin_log('Process Subscription Payment');
        $order_id = $renewal_order->get_id();
        $subscriptions = wcs_get_subscriptions_for_order($order_id, ['order_type' => 'any']);
        foreach ($subscriptions as $subscriptionID => $subscriptionObj) {

            $parent_order = $subscriptionObj->get_parent();
            break;
        }
        // qp_dd($renewal_order, false);
        // qp_dd($parent_order, false);


        $payment_id = get_post_meta($parent_order->get_id(), $this->id . '_payment_id', true);


        $currency = $renewal_order->get_currency();
        // qp_plugin_log("user_id");
        $user_id = $parent_order->get_billing_email();
        // qp_dd($user_id);
        $post_data = array(
            'amount' => $amount_to_charge,
            'currency' => $currency,
            'credential_on_file' => array(
                'initiated_by' => 'merchant'
            ),
            'source_ip_address' => qp_get_user_ip(),
            'user_id' => $user_id
        );
        $cardPayment = new APIsCreditCard($this->terminal_key, $this->testmode);
        $responseBody = $cardPayment->processRebill($payment_id, $post_data);
        qp_plugin_log('responseBody');
        qp_plugin_log($responseBody);
        qp_plugin_log('###############################');
        if ($responseBody)
            if ($responseBody['message'] == 'approved or completed') {
                $payment_id    = (!empty($responseBody['payment_id'])) ? $responseBody['payment_id'] : '';
                $transaction_id = (!empty($responseBody['transaction_id'])) ? $responseBody['transaction_id'] : '';

                update_post_meta($order_id, $this->id . '_payment', qp_arr_to_json($responseBody));
                update_post_meta($order_id, $this->id . '_payment_id', $payment_id);
                update_post_meta($order_id, $this->id . '_transaction_id', $transaction_id);

                $message        = $responseBody['message']; // approved or completed

                $orderNote = "Payment result: $message. \r\n payment id: $payment_id <br>\r\n Transaction_id: $transaction_id ";

                // wc_add_notice($orderNote, 'success');
                qp_plugin_log($orderNote);

                $renewal_order->add_order_note($orderNote);

                // if (!$this->testmode) {
                $renewal_order->payment_complete();
                wc_reduce_stock_levels($order_id);

                return array(
                    'result' => 'success',
                );
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
                $error_message_to_show = '';
                // qp_dd($responseBody['errors'][0]['code']);
                if ($responseBody['code'] == 'insufficient_funds') {
                    $error_message_to_show = 'The account does not have sufficient funds to process the payment.';
                }
                $renewal_order->add_order_note("Error with payment. $error_message_to_show");
                $subscriptionObj->add_order_note("Error with payment. $error_message_to_show");

                return new \WP_Error('', $error_message_to_show);
            }
    }

    public function process_refund($order_id, $amount = null, $reason = '')
    {
        // Get the order object
        $order = wc_get_order($order_id);

        // Check if the order was paid with this payment gateway
        // if ($order->get_payment_method() !== 'custom_payment') {
        //     return new WP_Error('invalid_order', __('Invalid order for refund.', 'text-domain'));
        // }
        $cardPayment = new APIsCreditCard($this->terminal_key, $this->testmode);
        $payment_id = get_post_meta($order_id, QP_GATEWAY_ID . '_payment_id', true);
        $user_id = get_post_meta($order_id, '_billing_email', true);
        $pendingSettlement = $cardPayment->isPaymentSettled($payment_id);
        $refund_result = true;
        if (!$pendingSettlement) {
            $post_data = array(
                'user_id' => $user_id,
                'order_id' => $order_id
            );
            $cardPayment->processReversal($payment_id, $post_data);
            $refund_result = false;
        }
        if ($refund_result === true) {
            $post_data = array(
                'amount' => $amount,
                'order_id' => $order_id,
                'user_id' => $user_id
            );
            $cardPayment->processRefund($payment_id, $post_data);

            $order->add_order_note(
                sprintf(
                    __('Refunded %s via ' . $this->method_title . ' Payment Gateway.', ''),
                    wc_price($amount)
                )
            );
            return true;
        } else {
            return new \WP_Error('refund_failed', __('Refund processing via ' . $this->method_title . ' failed because transaction is in settlement state, order marked as cancelled. Click okay to continue, then refresh the page.', ''));
        }
    }
    // public function quantumepay_hook()
    // {

    //     $order = wc_get_order($_GET['id']);
    //     $order->payment_complete();
    //     wc_reduce_stock_levels($order->get_id());

    //     update_option('webhook_debug', $_GET);
    // }
}
