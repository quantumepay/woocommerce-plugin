<?php

namespace WooQuantum\Application\Blocks;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

class Quantum_Block_Handler extends AbstractPaymentMethodType
{
    protected $gateway;
    public $name = 'quantumepay';

    public function __construct()
    {
        $this->name = defined('QP_GATEWAY_ID') ? QP_GATEWAY_ID : 'quantumepay';
    }

    public function initialize(): void
    {
        $this->settings = get_option("woocommerce_{$this->name}_settings", []);
        $gateways = WC()->payment_gateways->payment_gateways();
        $this->gateway = isset($gateways[$this->name]) ? $gateways[$this->name] : null;
    }

    public function is_active(): bool
    {
        return $this->gateway && $this->gateway->is_available();
    }

    public function get_payment_method_script_handles(): array
    {
        wp_register_script(
            'custom-gateway-blocks-integration',
            WC_QUANTUMEPAY_PLUGIN_URL . '/assets/js/blocks-integration.js',
            ['wc-blocks-registry', 'wp-element', 'wp-html-entities', 'wp-data'],
            WC_QUANTUMEPAY_DEBUG_MODE == 'development' ? time() : WC_QUANTUMEPAY_VERSION,
            true
        );

        // Localize script data
        $effective_checkout_type = $this->gateway ? qp_effective_checkout_type() : 'standard';
        wp_localize_script('custom-gateway-blocks-integration', 'quantumEPayData', [
            'is_bypass' => $effective_checkout_type === 'bypass',
            'checkout_type' => $effective_checkout_type, // Add checkout type
            'supports' => $this->get_supported_features(),
        ]);

        return ['custom-gateway-blocks-integration'];
    }

    public function get_payment_method_data(): array
    {
        $is_bypass = $this->gateway && qp_effective_checkout_type() === 'bypass';

        return [
            'title' => $this->gateway ? $this->gateway->title : 'Quantumepay',
            'description' => $is_bypass ?
                __('No payment required. Click Place Order to submit.', 'qoin-payment-gateway') : ($this->gateway ? $this->gateway->description : ''),
            'supports' => $this->get_supported_features(),
            'icon' => $this->gateway ? $this->gateway->icon : '',
            'is_bypass' => $is_bypass,
            'bypass_mode' => $is_bypass, // Add this for JS detection
        ];
    }

    public function get_supported_features(): array
    {
        return $this->gateway ? array_filter($this->gateway->supports, [$this->gateway, 'supports']) : ['products'];
    }
}
