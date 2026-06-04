<?php

namespace WooQuantum;

use WooQuantum\Application\APIs\CreditCard;

class App
{

	public function __construct()
	{

		new PluginUpdater(
	        WC_QUANTUMEPAY_MAIN_FILE,
	        WC_QUANTUMEPAY_UPDATE_REPO,
	        WC_QUANTUMEPAY_UPDATE_BRANCH,
	        WC_QUANTUMEPAY_UPDATE_ASSET_NAME
	    );

		
		register_activation_hook(WC_QUANTUMEPAY_MAIN_FILE, array($this, 'pluginActivationHook'));
		register_deactivation_hook(WC_QUANTUMEPAY_MAIN_FILE, array($this, 'pluginDeactivationHook'));

		add_action('init', array($this, 'initCallback'));

		add_action('plugins_loaded', array($this, 'QpInitGatewayClass'));
	}

	public function pluginActivationHook()
	{
	}

	public function pluginDeactivationHook()
	{
	}

	public function initCallback()
	{
	}

	public function QpInitGatewayClass()
	{

		if (!class_exists('\\WooCommerce')) {
			add_action('admin_notices', array($this, 'wooCommerceMissingNotice'));
		} else {
			add_action('wcsat_messages', array($this, 'printMessage'), 10);
			add_filter('woocommerce_payment_gateways', array($this, 'add_gateways'));
		}
	}

	public function wooCommerceMissingNotice()
	{
		echo '<div class="error"><p><strong>' . sprintf(esc_html__('Quantum ePay Gateway requires WooCommerce. You can download %s here.', ''), '<a href="https://woocommerce.com/" target="_blank">WooCommerce</a>') . '</strong></p></div>';
		return;
	}
	/**
	 * printMessage
	 *
	 * @return void
	 */
	public function printMessage()
	{
		$notice_arr = qp_show_notices();
		if (!empty($notice_arr)) {
			echo '<div class="notice notice-' . $notice_arr['type'] . ' is-dismissible">
                    <p>' . $notice_arr['message'] . '</p>
                </div>';
		}
	}

	public function add_gateways($gateways)
	{

		$gateways[] = 'WooQuantum\\Application\\Gateways\\CreditCard';
		return $gateways;
	}

	// public function QpRefundCreateCheck($refund, $args)
	// {
	// 	$payment_gateways   = \WC_Payment_Gateways::instance();
	// 	$payment_gateway    = $payment_gateways->payment_gateways()[QP_GATEWAY_ID];
	// 	$order_id = $args['order_id'];
	// 	$cardPayment = new CreditCard($payment_gateway->terminal_key, $payment_gateway->testmode);
	// 	$payment_id = get_post_meta($order_id, QP_GATEWAY_ID . '_payment_id', true);
	// 	$user_id = get_post_meta($order_id, '_billing_email', true);
	// 	$pendingSettlement = $cardPayment->isPaymentSettled($payment_id);
	// 	if (!$pendingSettlement) {
	// 		$post_data = array(
	// 			'user_id' => $user_id,
	// 			'order_id' => $order_id
	// 		);
	// 		$cardPayment->processReversal($payment_id, $post_data);
	// 		wp_delete_post($refund->get_id(), true);
	// 		// if (isset($refund) && is_a($refund, 'WC_Order_Refund')) {
	// 		// 	$refund->delete(true);
	// 		// }
	// 		// return new \WP_Error('error', 'order cannot be refunded it is under settelment');
	// 	}
	// }

	// function QpOrderRefundAction($order_id, $refund_id)
	// {

	// 	qp_plugin_log('############## Refund  ###################');
	// 	qp_plugin_log("-----------------------------------------------------------------------------------------");
	// 	qp_plugin_log($order_id);
	// 	qp_plugin_log($refund_id);


	// 	// Get the order object
	// 	// $order = wc_get_order($order_id);
	// 	$order = wc_get_order($order_id);
	// 	qp_plugin_log('order sttaus' . $order->get_status());
	// 	if ($order->get_status() == 'wc-cancelled') {
	// 		qp_plugin_log('order cancelled');
	// 		return;
	// 	}
	// 	$order_data = $order->get_data(); // The Order data  

	// 	qp_plugin_log("****** Order Detail**********");
	// 	qp_plugin_log($order_data);
	// 	// Get the refund object
	// 	$refund = wc_get_order($refund_id);
	// 	qp_plugin_log("******Refund**********");
	// 	qp_plugin_log($refund);

	// 	// Check if the refund is fully or partially refunded
	// 	$is_partial_refund = ($refund->get_amount() < $order->get_total());
	// 	qp_plugin_log("******Total amount**********");
	// 	qp_plugin_log($order->get_total());

	// 	$payment_gateways   = \WC_Payment_Gateways::instance();
	// 	$payment_gateway    = $payment_gateways->payment_gateways()[QP_GATEWAY_ID];
	// 	$refund_amount = $refund->get_amount();
	// 	$cardPayment = new CreditCard($payment_gateway->terminal_key, $payment_gateway->testmode);
	// 	$payment_id = get_post_meta($order_id, QP_GATEWAY_ID . '_payment_id', true);
	// 	$user_id = get_post_meta($order_id, '_billing_email', true);
	// 	$post_data = array(
	// 		'amount' => $refund_amount,
	// 		'order_id' => $order_id,
	// 		'user_id' => $user_id
	// 	);
	// 	$cardPayment->processRefund($payment_id, $post_data);
	// }

	public  function add_custom_order_note($order_id)
	{
		qp_plugin_log('Order $order_id ');
		qp_plugin_log($order_id);
		$order_note = 'Your order note here Asad';

		$order = wc_get_order($order_id);
		$order->add_order_note($order_note); // This will add as a private note.
		$order->add_order_note($order_note, 1); //This will add note for          the customer.
	}
}
