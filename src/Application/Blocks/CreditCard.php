<?php

namespace WooQuantum\Application\Blocks;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

class CreditCard extends AbstractPaymentMethodType
{
    protected $name = 'quantumepay';

    public function initialize()
    {
        $settings = get_option('woocommerce_' . $this->name . '_settings', array());
        $this->settings = is_array($settings) ? $settings : array();
    }

    public function is_active()
    {
        return ($this->settings['enabled'] ?? 'no') === 'yes'
            && is_file(WC_QUANTUMEPAY_PLUGIN_DIR . 'assets/js/quantumepay-blocks.js');
    }

    public function get_payment_method_script_handles()
    {
        if (!is_file(WC_QUANTUMEPAY_PLUGIN_DIR . 'assets/js/quantumepay-blocks.js')) return array();
        wp_register_script('quantumepay-blocks', WC_QUANTUMEPAY_PLUGIN_URL . '/assets/js/quantumepay-blocks.js',
            array('wc-blocks-registry', 'wc-settings', 'wc-blocks-checkout', 'wp-element', 'wp-data', 'wp-html-entities'),
            WC_QUANTUMEPAY_VERSION, true);
        return array('quantumepay-blocks');
    }

    public function get_payment_method_script_handles_for_admin()
    {
        return $this->get_payment_method_script_handles();
    }

    public function get_payment_method_data()
    {
        return array(
            'title' => sanitize_text_field($this->settings['title'] ?? 'Credit card'),
            'description' => sanitize_text_field(wp_strip_all_tags($this->settings['description'] ?? 'Pay securely with your credit card.')),
            'supports' => array('products'),
            'custom_service_fee' => ($this->settings['service_fee_mode'] ?? 'none') === 'custom',
            'service_fee_label' => sanitize_text_field($this->settings['service_fee_label'] ?? 'Service fee'),
        );
    }

    public static function update_payment_method($data)
    {
        if (!is_array($data) || !isset($data['payment_method']) || !is_string($data['payment_method'])) {
            throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException('qep_invalid_payment_method', 'Please select a payment method.', 400);
        }
        $method = $data['payment_method'];
        if (strlen($method) > 100 || sanitize_key($method) !== $method) {
            throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException('qep_invalid_payment_method', 'Please select a valid payment method.', 400);
        }
        if (!function_exists('WC') || !WC()->session) {
            throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException('qep_session_unavailable', 'Your checkout session could not be updated. Refresh the page and try again.', 400);
        }
        $gateways = WC()->payment_gateways()->payment_gateways();
        if ($method !== '' && !isset($gateways[$method])) {
            throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException('qep_invalid_payment_method', 'This payment method is unavailable. Please select another.', 400);
        }
        WC()->session->set('chosen_payment_method', $method);
        // Store API recalculates cart totals after this callback.
    }
}
