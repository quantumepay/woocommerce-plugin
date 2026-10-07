<?php

namespace WooQuantum\Application\Gateways;

use WooQuantum\Application\APIs\CreditCard as APIsCreditCard;
use WP_Error;

class CreditCard extends \WC_Payment_Gateway_CC
{
    public $testmode,
    $terminal_key,
    $client_id,
    $client_secret,
    $test_terminal_key,
    $test_client_id,
    $test_client_secret,
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
        $this->client_id = $this->get_option('client_id');
        $this->client_secret = $this->get_option('client_secret');
        $this->test_terminal_key = $this->get_option('test_terminal_key');
        $this->test_client_id = $this->get_option('test_client_id');
        $this->test_client_secret = $this->get_option('test_client_secret');
        $this->timeout_notification_recipients = $this->get_option('timeout_notification_recipients');

        $this->check_environment();

        add_action('admin_notices', array($this, 'admin_notices'));

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));

        add_action('wp_enqueue_scripts', array($this, 'payment_scripts'));
        add_action('woocommerce_cart_calculate_fees', array($this, 'add_service_fee'));
        add_action('woocommerce_checkout_update_order_review', array($this, 'update_service_fee_payment_method'));
        add_action('woocommerce_refund_created', array($this, 'complete_refund_record'), 10, 2);

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
        $is_settings_page = isset($_GET['page'], $_GET['section'])
            && 'wc-settings' === $_GET['page']
            && $this->id === $_GET['section'];

        if (!$this->testmode && !$is_settings_page) {
            $missing_credentials = array();

            if (empty($this->terminal_key)) {
                $missing_credentials[] = 'X-TERMINAL-KEY';
            }

            if (empty($this->client_id)) {
                $missing_credentials[] = 'Client ID';
            }

            if (empty($this->client_secret)) {
                $missing_credentials[] = 'Client Secret';
            }

            if (!empty($missing_credentials)) {
                $setting_link = admin_url('admin.php?page=wc-settings&tab=checkout&section=' . $this->id);

                $this->add_admin_notice(
                    'qp_prompt_connect',
                    'notice notice-warning',
                    sprintf(
                        'Quantum ePay is not fully configured for Live Mode. Please <a href="%s">complete your API credentials</a>. Missing: %s.',
                        esc_url($setting_link),
                        esc_html(implode(', ', $missing_credentials))
                    )
                );
            }
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
                'title'       => 'Enable Gateway',
                'label'       => 'Enable Quantum ePay',
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
                'description' => 'Describe what the user sees during checkout.',
                'default'     => 'Pay with your credit card via our payment gateway.',
                'desc_tip'    => true,
            ),
            'service_fee_mode' => array(
                'title' => 'Service fee', 'type' => 'select', 'default' => 'none',
                'options' => array('gateway' => 'Use gateway settings (pending API integration)',
                    'custom' => 'Use a different service fee on this website', 'none' => 'Do not apply a service fee'),
                'description' => 'Only the website-specific fee is available now. Gateway settings do not add a fee until API integration is available.',
            ),
            'service_fee_label' => array(
                'title' => 'Service fee label', 'type' => 'text', 'default' => 'Service fee',
                'description' => 'Displayed in checkout, order totals, and order emails.',
            ),
            'service_fee_type' => array(
                'title' => 'Custom fee type', 'type' => 'select', 'default' => 'percentage',
                'options' => array('percentage' => 'Percentage', 'fixed' => 'Fixed amount'),
            ),
            'service_fee_amount' => array(
                'title' => 'Custom fee amount', 'type' => 'text', 'default' => '0',
                'description' => 'Enter a percentage or an amount in the store currency. Percentage uses the discounted product subtotal, excluding shipping and tax. Free product subtotals are fee-free.',
            ),
            'service_fee_taxable' => array(
                'title' => 'Fee tax', 'type' => 'checkbox', 'default' => 'no',
                'label' => 'Apply the standard tax class to the service fee',
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
                'type'        => 'text',
                'description' => 'Enter the X-TERMINAL-KEY provided for your Quantum ePay live merchant account.',
                'desc_tip'    => true,
            ),
            'client_id' => array(
                'title'       => 'Client ID',
                'type'        => 'qep_secret',
                'description' => 'Enter the Client ID provided for your Quantum ePay live merchant account.',
                'placeholder' => 'Enter Client ID',
                'desc_tip'    => true,
            ),
            'client_secret' => array(
                'title'       => 'Client Secret',
                'type'        => 'qep_secret',
                'description' => 'Enter the Client Secret provided for your Quantum ePay live merchant account.',
                'placeholder' => 'Enter Client Secret',
                'desc_tip'    => true,
            ),
            'test_terminal_key' => array(
                'title'       => 'Test X-TERMINAL-KEY',
                'type'        => 'text',
                'description' => 'Optional. Leave blank to use the built-in Quantum ePay test X-TERMINAL-KEY.',
                'desc_tip'    => true,
            ),
            'test_client_id' => array(
                'title'       => 'Test Client ID',
                'type'        => 'qep_secret',
                'description' => 'Optional. Leave blank to use the built-in Quantum ePay test Client ID.',
                'placeholder' => 'Enter Test Client ID',
                'desc_tip'    => true,
            ),
            'test_client_secret' => array(
                'title'       => 'Test Client Secret',
                'type'        => 'qep_secret',
                'description' => 'Optional. Leave blank to use the built-in Quantum ePay test Client Secret.',
                'placeholder' => 'Enter Test Client Secret',
                'desc_tip'    => true,
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


    public function generate_qep_secret_html($key, $data)
    {
        $field_key = $this->get_field_key($key);

        $defaults = array(
            'title'       => '',
            'class'       => '',
            'css'         => '',
            'placeholder' => '',
            'description' => '',
            'desc_tip'    => false,
        );

        $data = wp_parse_args($data, $defaults);

        $has_saved_value = !empty($this->get_option($key));

        $placeholder = $has_saved_value
            ? 'Saved — enter a new value to replace'
            : $data['placeholder'];

        ob_start();
        ?>
        <tr valign="top">
            <th scope="row" class="titledesc">
                <label for="<?php echo esc_attr($field_key); ?>">
                    <?php echo wp_kses_post($data['title']); ?>
                    <?php echo $this->get_tooltip_html($data); ?>
                </label>
            </th>
            <td class="forminp">
                <fieldset>
                    <legend class="screen-reader-text">
                        <span><?php echo wp_kses_post($data['title']); ?></span>
                    </legend>

                    <input
                        class="input-text regular-input <?php echo esc_attr($data['class']); ?>"
                        type="password"
                        name="<?php echo esc_attr($field_key); ?>"
                        id="<?php echo esc_attr($field_key); ?>"
                        value=""
                        placeholder="<?php echo esc_attr($placeholder); ?>"
                        autocomplete="new-password"
                        data-qep-saved="<?php echo $has_saved_value ? '1' : '0'; ?>"
                        style="<?php echo esc_attr($data['css']); ?>"
                    />

                    <?php echo $this->get_description_html($data); ?>
                </fieldset>
            </td>
        </tr>
        <?php

        return ob_get_clean();
    }

    public function validate_qep_secret_field($key, $value)
    {
        $value = trim((string) $value);

        if ('' === $value) {
            return (string) $this->get_option($key);
        }

        return sanitize_text_field($value);
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
        wp_add_inline_script('quantumepay-js', "jQuery(function($){ $(document.body).off('change.qepServiceFee', 'input[name=payment_method]').on('change.qepServiceFee', 'input[name=payment_method]', function(){ $(document.body).trigger('update_checkout'); }); });");
    }

    public function validate_service_fee_amount_field($key, $value)
    {
        $amount = wc_format_decimal($value);
        if (!is_numeric($amount) || !is_finite((float) $amount) || (float) $amount < 0) {
            throw new \Exception('Service fee amount must be a number greater than or equal to zero.');
        }
        return $amount;
    }

    public function update_service_fee_payment_method($posted_data)
    {
        if (!WC()->session || !is_string($posted_data)) return;
        parse_str($posted_data, $data);
        if (isset($data['payment_method']) && is_string($data['payment_method'])) {
            WC()->session->set('chosen_payment_method', sanitize_text_field($data['payment_method']));
        }
    }

    public function add_service_fee($cart)
    {
        if ((is_admin() && !wp_doing_ajax()) || $this->enabled !== 'yes' || !WC()->session) return;
        if ($this->get_option('service_fee_mode', 'none') !== 'custom') return;
        if (WC()->session->get('chosen_payment_method') !== $this->id) return;
        $subtotal = max(0, (float) $cart->get_cart_contents_total());
        if ($subtotal <= 0) return;
        $value = wc_format_decimal($this->get_option('service_fee_amount', '0'));
        if (!is_numeric($value) || !is_finite((float) $value) || (float) $value <= 0) return;
        $type = $this->get_option('service_fee_type', 'percentage');
        if (!in_array($type, array('percentage', 'fixed'), true)) return;
        $amount = round($type === 'fixed' ? (float) $value : $subtotal * (float) $value / 100, wc_get_price_decimals());
        if (!is_finite($amount) || $amount <= 0) return;
        $label = sanitize_text_field($this->get_option('service_fee_label', 'Service fee'));
        $cart->fees_api()->add_fee(array('id' => 'qep_service_fee', 'name' => $label ?: 'Service fee',
            'amount' => $amount, 'taxable' => $this->get_option('service_fee_taxable', 'no') === 'yes', 'tax_class' => ''));
    }

    public function validate_fields()
    {
        $fields = array('-card-number' => 'Please enter your card number.',
            '-card-expiry' => 'Please enter your card expiration date.', '-card-cvc' => 'Please enter your card security code.');
        $messages = array();
        foreach ($fields as $suffix => $message) {
            $key = $this->id . $suffix;
            if (!isset($_POST[$key]) || !is_string($_POST[$key]) || trim(wp_unslash($_POST[$key])) === '') $messages[] = $message;
        }
        if (!$this->testmode && (empty($this->terminal_key) || empty($this->client_id) || empty($this->client_secret))) {
            $messages[] = 'The payment gateway is unavailable. Please contact the store.';
        }
        if (!$messages) return true;
        foreach ($messages as $message) wc_add_notice($message, 'error');
        qp_send_plugin_event('gateway_error', array('message' => implode(' ', $messages), 'error_code' => 'checkout_validation'));
        return false;
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
        echo '<h2>' . esc_html__('Payment confirmation pending', 'woocommerce-gateway-quantum') . '</h2>';
        echo '<p>' . esc_html__('We could not confirm your payment result. Please contact the store and do not pay again until your payment has been checked.', 'woocommerce-gateway-quantum') . '</p>';
        echo '</div>';
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
            $message .= "Gateway error: " . qep_safe_error_text($responsePayment['qp_wp_error_message']) . "\n";
        }

        wp_mail($recipients, $subject, $message);
    }

    private function create_api_client()
    {
        return new APIsCreditCard($this->terminal_key, $this->testmode, $this->client_id, $this->client_secret,
            $this->test_terminal_key, $this->test_client_id, $this->test_client_secret);
    }

    private function payment_failure($order, $message, $code = 'checkout_validation', $log = true)
    {
        if ($log) qp_send_plugin_event('gateway_error', array('order_id' => $order->get_id(),
            'amount' => $order->get_total(), 'currency' => $order->get_currency(), 'message' => $message, 'error_code' => $code));
        $order->add_order_note('Payment failed: ' . $message);
        wc_add_notice($message, 'error');
        return array('result' => 'failure', 'redirect' => '', 'message' => $message);
    }

    public function process_payment($order_id)
    {
        $order = wc_get_order($order_id);
        if (!$order) {
            wc_add_notice('Your order could not be found. Please refresh checkout.', 'error');
            return array('result' => 'failure');
        }
        if ($order->is_paid()) return array('result' => 'success', 'redirect' => $this->get_return_url($order));
        if ($order->get_meta('_quantumepay_pending_timeout')) {
            return $this->payment_failure($order, 'Your previous payment needs review. Please contact the store before trying again.', 'payment_needs_review');
        }
        if (!$order->has_status(array('pending', 'failed', 'on-hold'))) return $this->payment_failure($order, 'This order cannot accept another payment. Please contact the store.', 'invalid_order_status');
        $lock_key = '_quantumepay_processing_lock';
        $option_lock = 'qep_payment_lock_' . $order_id;
        if ($order->get_meta($lock_key) || !add_option($option_lock, time(), '', false)) {
            return $this->payment_failure($order, 'Payment is already processing or needs review. Please contact the store if this continues.', 'payment_locked');
        }
        $order->update_meta_data($lock_key, time());
        $order->save();
        $unknown = false;
        $sent = false;
        try {
            $total = (float) $order->get_total();
            if ($total === 0.0) {
                $order->add_order_note('No payment required: the final order total is zero.');
                $order->payment_complete();
                if (WC()->cart) WC()->cart->empty_cart();
                return array('result' => 'success', 'redirect' => $this->get_return_url($order));
            }
            if (!is_finite($total) || $total < 0.01) return $this->payment_failure($order, 'The minimum card payment is $0.01. Please contact the store.', 'amount_below_minimum');
            $billing = $order->get_data()['billing'];
            foreach (array('first_name', 'last_name', 'address_1', 'postcode') as $field) {
                if (empty($billing[$field])) return $this->payment_failure($order, 'Please complete your billing name, address, and ZIP code.');
            }
            $read = function ($key) {
                return isset($_POST[$key]) && is_string($_POST[$key]) ? trim(wp_unslash($_POST[$key])) : '';
            };
            $number = preg_replace('/[\s-]+/', '', $read($this->id . '-card-number'));
            $expiry = preg_replace('/\s+/', '', $read($this->id . '-card-expiry'));
            $cvv = $read($this->id . '-card-cvc');
            if (!preg_match('/^\d{12,19}$/', $number)) return $this->payment_failure($order, 'Please check your card number and try again.', 'invalid_card_number');
            if (!preg_match('/^(0?[1-9]|1[0-2])\/(\d{2}|\d{4})$/', $expiry, $matches)) return $this->payment_failure($order, 'Please check your card expiration date and try again.', 'invalid_expiry');
            $year = strlen($matches[2]) === 2 ? '20' . $matches[2] : $matches[2];
            if ((int) ($year . sprintf('%02d', $matches[1])) < (int) gmdate('Ym')) return $this->payment_failure($order, 'Your card has expired. Please use another card.', 'expired_card');
            if (!preg_match('/^\d{3,4}$/', $cvv)) return $this->payment_failure($order, 'Please check your card security code and try again.', 'invalid_security_code');
            $data = array('first_name' => $billing['first_name'], 'last_name' => $billing['last_name'],
                'qp_ccNo' => $number, 'qp_cvv' => $cvv, 'expiry_month' => sprintf('%02d', $matches[1]), 'expiry_year' => $year,
                'billing_address' => array('address_1' => $billing['address_1'], 'address_2' => $billing['address_2'],
                    'city' => $billing['city'], 'state' => $billing['state'], 'postal_code' => $billing['postcode'], 'country_code' => $billing['country']),
                'total_amount' => $order->get_total(), 'currency' => $order->get_currency(), 'email' => $billing['email'],
                'phone' => $billing['phone'], 'order_id' => (string) $order_id);
            $sent = true;
            $response = $this->create_api_client()->processPayment($data);
            $result = $response['qep_result'];
            if ($result['outcome'] === 'failed') {
                $order->add_order_note($result['message'] . "\nCodes: " . $result['error_code']);
                return $this->payment_failure($order, $result['customer_message'], $result['error_code'], false);
            }
            if ($result['outcome'] !== 'approved') {
                $unknown = true;
                $order->update_meta_data('_quantumepay_pending_timeout', time());
                $order->update_status('on-hold', 'Payment result is unconfirmed. Check Qoin before retrying. ' . $result['message']);
                $order->save();
                $this->send_timeout_payment_notification($order, $response);
                if (WC()->cart) WC()->cart->empty_cart();
                return array('result' => 'success', 'redirect' => $this->get_return_url($order));
            }
            $order->update_meta_data($this->id . '_payment_id', $result['payment_id']);
            $order->update_meta_data($this->id . '_transaction_id', $result['transaction_id']);
            $order->delete_meta_data('_quantumepay_pending_timeout');
            $order->add_order_note('Gateway approved payment. Payment ID: ' . $result['payment_id'] . '. Transaction ID: ' . $result['transaction_id']);
            $order->save();
            $order->payment_complete($result['transaction_id']);
            if (WC()->cart) WC()->cart->empty_cart();
            return array('result' => 'success', 'redirect' => $this->get_return_url($order));
        } catch (\Throwable $error) {
            $unknown = $sent;
            $message = $sent ? 'We could not confirm your payment result. Please contact the store before trying again.' : 'Your payment could not be processed. Please contact the store.';
            qp_send_plugin_event('gateway_error', array('order_id' => $order_id, 'message' => 'Payment processing exception: ' . get_class($error), 'error_code' => 'processing_exception'));
            if ($unknown) {
                $order->update_meta_data('_quantumepay_pending_timeout', time());
                $order->update_status('on-hold', $message);
                $order->save();
            }
            return $this->payment_failure($order, $message, 'processing_exception', false);
        } finally {
            delete_option($option_lock);
            if (!$unknown) {
                $order->delete_meta_data($lock_key);
                $order->save();
            }
        }
    }

    public function scheduled_subscription_payment($amount_to_charge, $renewal_order)
    {
        $result = $this->process_subscription_payment($renewal_order, $amount_to_charge);
        if ($renewal_order->get_meta('_quantumepay_pending_timeout') || (is_wp_error($result) && in_array($result->get_error_code(), array('qep_payment_locked', 'qep_unknown'), true))) return;
        if (is_wp_error($result)) \WC_Subscriptions_Manager::process_subscription_payment_failure_on_order($renewal_order);
        else \WC_Subscriptions_Manager::process_subscription_payments_on_order($renewal_order);
    }

    public function process_subscription_payment($renewal_order, $amount_to_charge)
    {
        $id = $renewal_order->get_id();
        $lock = 'qep_payment_lock_' . $id;
        if (!add_option($lock, time(), '', false)) return new \WP_Error('qep_payment_locked', 'Renewal payment is processing.');
        $started = false;
        try {
            $renewal_order = wc_get_order($id);
            if (!$renewal_order) return new \WP_Error('invalid_order', 'Renewal order could not be found.');
            if ($renewal_order->get_meta('_quantumepay_processing_lock')) return new \WP_Error('qep_unknown', 'A previous payment needs review before retrying.');
            $renewal_order->update_meta_data('_quantumepay_processing_lock', time());
            $renewal_order->save();
            $started = true;
            return $this->process_subscription_payment_locked($renewal_order, $amount_to_charge);
        } catch (\Throwable $error) {
            if ($renewal_order && $started) {
                $renewal_order->update_meta_data('_quantumepay_pending_timeout', time());
                $renewal_order->update_status('on-hold', 'Renewal processing was interrupted. Check Qoin before retrying.');
                $renewal_order->save();
            }
            qp_send_plugin_event('gateway_error', array('order_id' => $id, 'error_code' => 'renewal_exception', 'message' => 'Renewal exception: ' . get_class($error)));
            return new \WP_Error('qep_unknown', 'Renewal payment needs manual review.');
        } finally {
            if ($renewal_order && $started && !$renewal_order->get_meta('_quantumepay_pending_timeout')) {
                $renewal_order->delete_meta_data('_quantumepay_processing_lock');
                $renewal_order->save();
            }
            delete_option($lock);
        }
    }

    private function process_subscription_payment_locked($renewal_order, $amount_to_charge)
    {
        if ($renewal_order->is_paid()) return array('result' => 'success');
        if (!$renewal_order->has_status(array('pending', 'failed', 'on-hold'))) return new \WP_Error('invalid_order_status', 'This renewal cannot accept another payment.');
        if ($renewal_order->get_meta('_quantumepay_pending_timeout')) return new \WP_Error('qep_unknown', 'Renewal payment needs manual review.');
        if (is_numeric($amount_to_charge) && (float) $amount_to_charge === 0.0) {
            $renewal_order->add_order_note('No renewal payment required: the amount due is zero.');
            $renewal_order->payment_complete();
            return array('result' => 'success');
        }
        if (!is_numeric($amount_to_charge) || !is_finite((float) $amount_to_charge) || (float) $amount_to_charge < 0.01) {
            $message = 'The minimum card payment is $0.01.';
            qp_send_plugin_event('gateway_error', array('order_id' => $renewal_order->get_id(), 'message' => $message, 'error_code' => 'amount_below_minimum'));
            return new \WP_Error('amount_below_minimum', $message);
        }
        $subscriptions = wcs_get_subscriptions_for_order($renewal_order->get_id(), array('order_type' => 'any'));
        $subscription = reset($subscriptions);
        $parent = $subscription ? $subscription->get_parent() : false;
        $payment_id = $parent ? $parent->get_meta($this->id . '_payment_id') : '';
        if (!$payment_id) {
            $message = 'Subscription payment cannot be processed: the original gateway payment ID is missing.';
            qp_send_plugin_event('gateway_error', array('order_id' => $renewal_order->get_id(), 'message' => $message, 'error_code' => 'missing_payment_id'));
            return new \WP_Error('qep_missing_payment_id', $message);
        }
        $result = $this->create_api_client()->processRebill($payment_id, array('amount' => $amount_to_charge,
            'currency' => $renewal_order->get_currency(), 'credential_on_file' => array('initiated_by' => 'merchant'),
            'source_ip_address' => qp_get_user_ip(), 'user_id' => $parent->get_billing_email(), 'order_id' => $renewal_order->get_id()));
        if (is_wp_error($result)) {
            $renewal_order->add_order_note($result->get_error_message());
            $subscription->add_order_note($result->get_error_message());
            if ($result->get_error_code() === 'qep_unknown') {
                $renewal_order->update_meta_data('_quantumepay_pending_timeout', time());
                $renewal_order->update_status('on-hold', 'Renewal outcome is unknown. Check Qoin before retrying.');
                $renewal_order->save();
            }
            return $result;
        }
        $renewal_order->update_meta_data($this->id . '_payment_id', $result['payment_id'] ?? '');
        $renewal_order->update_meta_data($this->id . '_transaction_id', $result['transaction_id'] ?? '');
        $renewal_order->save();
        $renewal_order->payment_complete($result['transaction_id'] ?? '');
        return array('result' => 'success');
    }

    public function process_refund($order_id, $amount = null, $reason = '')
    {
        $lock = 'qep_refund_lock_' . $order_id;
        if (!add_option($lock, time(), '', false)) return new \WP_Error('qep_refund_locked', 'A refund is already being processed for this order. Check Qoin before trying again.');
        $order = null;
        $started = false;
        $confirmed = false;
        try {
            $order = wc_get_order($order_id);
            if (!$order || $order->get_payment_method() !== $this->id) return new \WP_Error('invalid_order', 'This order cannot be refunded through Quantum ePay.');
            if ($order->get_meta('_qep_refund_in_progress')) return new \WP_Error('qep_refund_review', 'A previous refund could not be confirmed. Check Qoin or contact support before trying again.');
            $order->update_meta_data('_qep_refund_in_progress', time());
            $order->save();
            $started = true;
            $result = $this->process_refund_locked($order_id, $amount, $reason);
            if (is_wp_error($result)) $result = $this->merchant_refund_error($result, $order);
            if ($result === true) {
                $confirmed = true;
                $order->update_meta_data('_qep_refund_pending_record', (string) $amount);
                $order->save();
            }
            return $result;
        } catch (\Throwable $error) {
            if ($order && $started) {
                $order->update_meta_data('_qep_refund_review', time());
                $order->save();
            }
            qp_send_plugin_event('gateway_error', array('order_id' => $order_id, 'error_code' => 'refund_exception', 'message' => 'Refund exception: ' . get_class($error)));
            return new \WP_Error('qep_unknown', 'We could not confirm the refund. Check Qoin or contact support before trying again to avoid issuing it twice.');
        } finally {
            if (!$confirmed) {
                if ($order && $started && !$order->get_meta('_qep_refund_review')) {
                    $order->delete_meta_data('_qep_refund_in_progress');
                    $order->save();
                }
                delete_option($lock);
            }
        }
    }

    private function merchant_refund_error($error, $order)
    {
        $code = $error->get_error_code();
        $detail = qep_safe_error_text($error->get_error_message());
        if (function_exists('wc_get_logger')) {
            wc_get_logger()->error('Refund failed: ' . $code . '; ' . $detail,
                array('source' => 'quantumepay-refunds', 'order_id' => $order->get_id()));
        }
        qp_send_plugin_event('gateway_error', array('order_id' => $order->get_id(), 'currency' => $order->get_currency(),
            'status' => 'refund_failed', 'error_code' => $code, 'message' => $detail));
        $messages = array(
            'qep_missing_payment_id' => 'No Quantum ePay payment is recorded for this order, so an automatic refund is unavailable. If you collected payment elsewhere, refund it through that payment provider.',
            'invalid_amount' => 'Enter a refund amount greater than zero and no higher than the amount available to refund.',
            'qep_partial_reversal' => 'This payment is still processing. A partial refund will be available after it settles.',
            'qep_unknown_settlement' => 'We could not confirm whether this payment is ready for a refund. Check the payment in Qoin or contact support before trying again.',
            'qep_refund_review' => 'A previous refund could not be confirmed. Check Qoin or contact support before trying again.',
            'qep_unknown' => 'We could not confirm the refund. Check Qoin or contact support before trying again to avoid issuing it twice.',
        );
        $message = $messages[$code] ?? 'The refund could not be completed. Check the payment in Qoin or contact support for help.';
        $order->add_order_note($message);
        return new \WP_Error($code, $message, $error->get_error_data());
    }

    public function complete_refund_record($refund_id, $args)
    {
        if (empty($args['refund_payment']) || empty($args['order_id'])) return;
        $order = wc_get_order($args['order_id']);
        $refund = wc_get_order($refund_id);
        if (!$order || !$refund || !$refund->get_refunded_payment() || $order->get_payment_method() !== $this->id) return;
        $amount = $order->get_meta('_qep_refund_pending_record');
        if ($amount === '' || round((float) $amount, wc_get_price_decimals()) !== round((float) $refund->get_amount(), wc_get_price_decimals())) return;
        $order->delete_meta_data('_qep_refund_pending_record');
        $order->delete_meta_data('_qep_refund_in_progress');
        $order->save();
        delete_option('qep_refund_lock_' . $order->get_id());
    }

    private function process_refund_locked($order_id, $amount = null, $reason = '')
    {
        $order = wc_get_order($order_id);
        if (!$order || $order->get_payment_method() !== $this->id) return new \WP_Error('invalid_order', 'This order cannot be refunded through Quantum ePay.');
        if ($order->get_meta('_qep_refund_review')) return new \WP_Error('qep_refund_review', 'A previous refund has an unknown result. Check Qoin before retrying.');
        $reported_remaining = (float) $order->get_remaining_refund_amount();
        $captured_remaining = method_exists('\\WooQuantum\\App', 'consumeRefundBalance')
            ? \WooQuantum\App::consumeRefundBalance($order_id, $amount) : null;
        $remaining = $captured_remaining === null ? $reported_remaining : $captured_remaining;
        $invalid_amount = $amount === null || !is_numeric($amount) || !is_finite((float) $amount)
            || (float) $amount <= 0 || round((float) $amount, wc_get_price_decimals()) > round($remaining, wc_get_price_decimals());
        if ($invalid_amount) {
            return new \WP_Error('invalid_amount', 'Enter an explicit refund amount greater than zero and within the remaining refundable total.');
        }
        $api = $this->create_api_client();
        $payment_id = $order->get_meta($this->id . '_payment_id');
        $settled = $api->isPaymentSettled($payment_id, array('order_id' => $order_id, 'currency' => $order->get_currency()));
        if (is_wp_error($settled)) return $settled;
        if (!$settled && round((float) $amount, wc_get_price_decimals()) !== round($remaining, wc_get_price_decimals())) {
            return new \WP_Error('qep_partial_reversal', 'A partial refund cannot use a full reversal. Wait for settlement, then retry.');
        }
        $user = wp_get_current_user();
        $data = array('amount' => $amount, 'order_id' => $order_id, 'currency' => $order->get_currency(),
            'user_id' => $user->exists() ? (string) $user->ID : 'woocommerce');
        $result = $settled ? $api->processRefund($payment_id, $data) : $api->processReversal($payment_id, $data);
        if (is_wp_error($result)) {
            if ($result->get_error_code() === 'qep_unknown') { $order->update_meta_data('_qep_refund_review', time()); $order->save(); }
            return $result;
        }
        $order->add_order_note(($settled ? 'Refund' : 'Reversal') . ' confirmed by gateway. Transaction ID: ' . sanitize_text_field($result['transaction_id'] ?? ''));
        return true;
    }

    // public function quantumepay_hook()
    // {

    //     $order = wc_get_order($_GET['id']);
    //     $order->payment_complete();
    //     wc_reduce_stock_levels($order->get_id());

    //     update_option('webhook_debug', $_GET);
    // }
}