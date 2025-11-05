<?php

namespace WooQuantum;

use WooQuantum\Application\Blocks\Quantum_Block_Handler;
use WooQuantum\Application\Handler\QPKeyManager;

/**
 * Main application class for WooQuantum plugin.
 */
final class App
{
	/**
	 * App constructor.
	 */
	public function __construct()
	{
		$this->registerHooks();
	}

	/**
	 * Register all plugin hooks.
	 */
	private function registerHooks(): void
	{
		// Register activation and deactivation hooks
		register_activation_hook(WC_QUANTUMEPAY_MAIN_FILE, [$this, 'pluginActivationHook']);
		register_deactivation_hook(WC_QUANTUMEPAY_MAIN_FILE, [$this, 'pluginDeactivationHook']);

		// Add actions
		add_action('init', [$this, 'initCallback']);
		add_action('plugins_loaded', [$this, 'QpInitGatewayClass']);
		add_action('woocommerce_blocks_loaded', [$this, 'QpInitGatewayClass']);
	}

	/**
	 * Handle plugin activation.
	 */
	public function pluginActivationHook(): void
	{
		$key_manager = new QPKeyManager();
		$key_manager->handle_activation();
	}

	/**
	 * Handle plugin deactivation.
	 */
	public function pluginDeactivationHook(): void
	{
		delete_option('qp_generated_key');
		delete_option('qp_key_installation_pending');
		delete_transient('qp_show_key_instructions');
	}

	/**
	 * Initialize plugin on 'init' action.
	 */
	public function initCallback(): void
	{
		register_post_status('wc-awaiting-auth', [
			'label' => 'Awaiting Authorization',
			'public' => true,
			'exclude_from_search' => false,
			'show_in_admin_all_list' => true,
			'show_in_admin_status_list' => true,
			'label_count' => _n_noop('Awaiting Authorization (%s)', 'Awaiting Authorization (%s)'),
		]);
		register_post_status('wc-order-submitted', [
			'label' => 'Order Submitted',
			'public' => true,
			'exclude_from_search' => false,
			'show_in_admin_all_list' => true,
			'show_in_admin_status_list' => true,
			'label_count' => _n_noop('Order Submitted (%s)', 'Order Submitted (%s)'),
		]);
		register_post_status('wc-awaiting-payment', [
			'label' => 'Awaiting Payment',
			'public' => true,
			'exclude_from_search' => false,
			'show_in_admin_all_list' => true,
			'show_in_admin_status_list' => true,
			'label_count' => _n_noop('Awaiting Payment (%s)', 'Awaiting Payment (%s)', 'qoin-payment-gateway'),
		]);
	}

	/**
	 * Initializes the payment gateway class.
	 */
	public function QpInitGatewayClass(): void
	{
		if (!$this->isWooCommerceActive()) {
			add_action('admin_notices', [$this, 'wooCommerceMissingNotice']);
			return;
		}
		if (class_exists('\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
			add_action('woocommerce_blocks_payment_method_type_registration', function ($registry) {
				if (! $registry->is_registered('quantumepay')) {
					$registry->register(new Quantum_Block_Handler());
				}
			});
		}
		add_action('wcsat_messages', [$this, 'printMessage'], 10);
		add_filter('woocommerce_payment_gateways', [$this, 'add_gateways']);
	}

	/**
	 * Check if WooCommerce is active.
	 *
	 * @return bool
	 */
	private function isWooCommerceActive(): bool
	{
		return class_exists('WooCommerce');
	}

	/**
	 * Displays an admin notice if WooCommerce is missing.
	 */
	public function wooCommerceMissingNotice(): void
	{
		printf(
			'<div class="error"><p><strong>%s</strong></p></div>',
			sprintf(
				esc_html__(
					'Quantum ePay Gateway requires WooCommerce. You can download %s here.',
					'woocommerce'
				),
				'<a href="https://woocommerce.com/" target="_blank">WooCommerce</a>'
			)
		);
	}

	/**
	 * Displays custom admin notices.
	 */
	public function printMessage(): void
	{
		$notice_arr = qp_show_notices();
		if (!empty($notice_arr) && is_array($notice_arr) && isset($notice_arr['type'], $notice_arr['message'])) {
			printf(
				'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
				esc_attr($notice_arr['type']),
				esc_html($notice_arr['message'])
			);
		}
	}

	/**
	 * Adds the payment gateway to WooCommerce.
	 *
	 * @param array $gateways The array of payment gateways.
	 * @return array The filtered array of payment gateways.
	 */
	public function add_gateways(array $gateways): array
	{
		$gateways[] = 'WooQuantum\\Application\\Gateways\\CreditCard';
		// $gateways[] = 'WooQuantum\\Application\\Gateways\\GatewayBypass';
		return $gateways;
	}

	/**
	 * Adds a custom order note to a WooCommerce order.
	 *
	 * @param int|string $order_id The ID of the order.
	 */
	public function add_custom_order_note($order_id): void
	{
		qp_plugin_log('Order ' . $order_id);

		$order_note = 'Your order note here Asad';
		$order = wc_get_order($order_id);

		if ($order instanceof \WC_Order) {
			$order->add_order_note($order_note);
			$order->add_order_note($order_note, 1);
		}
	}
}
