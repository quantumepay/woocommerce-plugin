<?php

namespace WooQuantum;

use WooQuantum\Application\APIs\CreditCard;

class App
{
    private static $refundContexts = array();
    private $blocksInitialized = false;

	public function __construct()
	{

		new PluginUpdater(
	        WC_QUANTUMEPAY_MAIN_FILE,
	        WC_QUANTUMEPAY_UPDATE_REPO,
	        WC_QUANTUMEPAY_UPDATE_BRANCH,
	        WC_QUANTUMEPAY_UPDATE_ASSET_NAME
	    );

		$this->cleanupLegacyLogs();
		register_activation_hook(WC_QUANTUMEPAY_MAIN_FILE, array($this, 'pluginActivationHook'));
		register_deactivation_hook(WC_QUANTUMEPAY_MAIN_FILE, array($this, 'pluginDeactivationHook'));

		add_action('init', array($this, 'initCallback'));

		add_action('plugins_loaded', array($this, 'QpInitGatewayClass'));
		add_action('admin_enqueue_scripts', array($this, 'enqueueQuantumSettingsAssets'));
        add_action('admin_enqueue_scripts', array($this, 'enqueueRefundDialogs'), 30);
        add_action('admin_notices', array($this, 'showLiveCredentialsNotice'));
		add_action('wp_ajax_qep_save_gateway_settings', array($this, 'saveQuantumGatewaySettings'));
        add_action('woocommerce_cart_calculate_fees', array($this, 'applyCheckoutServiceFee'), 20);
        add_action('woocommerce_create_refund', array($this, 'captureRefundBalance'), 999, 2);
        add_action('woocommerce_blocks_loaded', array($this, 'initializeBlocks'));
        if (did_action('woocommerce_blocks_loaded')) $this->initializeBlocks();
		add_filter(
			'plugin_action_links_' . plugin_basename(WC_QUANTUMEPAY_MAIN_FILE),
			array($this, 'addPluginSettingsLink')
		);
	}

    public function showLiveCredentialsNotice()
    {
        if (!class_exists('\\WooCommerce') || !current_user_can('manage_woocommerce')) return;
        $settings = get_option('woocommerce_' . QP_GATEWAY_ID . '_settings', array());
        $settings = is_array($settings) ? $settings : array();
        foreach (array('client_id', 'client_secret') as $key) {
            if (!isset($settings[$key]) || !is_string($settings[$key]) || trim($settings[$key]) === '') {
                $url = admin_url('admin.php?page=wc-settings&tab=checkout&section=' . QP_GATEWAY_ID);
                echo '<style>
                    .notice.qep-live-credentials-notice { padding: 18px 20px; background: #fff !important; border-left-color: #dba617; }
                    .qep-live-credentials-notice .qep-notice-title { margin: 0 0 8px; padding: 0; color: #1d2327; font-size: 14px; font-weight: 600; line-height: 1.5; }
                    .qep-live-credentials-notice .qep-notice-message { margin: 0 0 14px; padding: 0; font-size: 13px; line-height: 1.6; }
                    .qep-live-credentials-notice .qep-notice-button { padding: 3px 14px; background: #192f59; border-color: #192f59; color: #fff; box-shadow: none; transition: background .2s, border-color .2s; }
                    .qep-live-credentials-notice .qep-notice-button:hover { background: #244474; border-color: #244474; color: #fff; }
                    .qep-live-credentials-notice .qep-notice-button:focus { background: #192f59; border-color: #192f59; color: #fff; box-shadow: 0 0 0 2px #fff, 0 0 0 4px #192f59; }
                </style>';
                echo '<div class="notice notice-warning qep-live-credentials-notice"><p class="qep-notice-title">Quantum ePay: live credentials required</p>';
                echo '<p class="qep-notice-message">Version 2.1.0 and later require a client ID and client secret issued specifically for your merchant account. Live payments cannot be processed until both are saved. Contact Quantum ePay support to obtain your credentials.</p>';
                echo '<a class="button button-primary qep-notice-button" href="' . esc_url($url) . '">Open Quantum ePay settings</a></div>';
                return;
            }
        }
    }

    public function initializeBlocks()
    {
        if ($this->blocksInitialized || !class_exists('Automattic\\WooCommerce\\Blocks\\Payments\\Integrations\\AbstractPaymentMethodType')
            || !function_exists('woocommerce_store_api_register_update_callback')
            || !is_file(WC_QUANTUMEPAY_PLUGIN_DIR . 'src/Application/Blocks/CreditCard.php')
            || !is_file(WC_QUANTUMEPAY_PLUGIN_DIR . 'assets/js/quantumepay-blocks.js')) return;
        $this->blocksInitialized = true;
        add_action('woocommerce_blocks_payment_method_type_registration', array($this, 'registerBlocksPaymentMethod'));
        woocommerce_store_api_register_update_callback(array(
            'namespace' => 'quantumepay-payment-method',
            'callback' => array('WooQuantum\\Application\\Blocks\\CreditCard', 'update_payment_method'),
        ));
        add_action('woocommerce_store_api_checkout_update_order_from_request', array($this, 'validateBlocksFeeSelection'), 10, 2);
    }

    public function registerBlocksPaymentMethod($registry)
    {
        $registry->register(new \WooQuantum\Application\Blocks\CreditCard());
    }

    public function validateBlocksFeeSelection($order, $request)
    {
        if (!function_exists('WC') || !WC()->session || !WC()->cart || !WC()->cart->needs_payment()) return;
        $settings = get_option('woocommerce_' . QP_GATEWAY_ID . '_settings', array());
        if (!is_array($settings) || ($settings['enabled'] ?? 'no') !== 'yes'
            || ($settings['service_fee_mode'] ?? 'none') !== 'custom') return;
        $method = $request->get_param('payment_method');
        if (!is_string($method) || $method === '') return;
        $chosen = WC()->session->get('chosen_payment_method');
        if ($method !== QP_GATEWAY_ID && $chosen !== QP_GATEWAY_ID) return;
        if ($method !== $chosen) {
            throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException('qep_checkout_total_changed',
                'Your payment method changed. Refresh the checkout total before placing your order.', 400);
        }
    }

    public function enqueueRefundDialogs($hook)
    {
        $screen = get_current_screen();
        if (!$screen || !in_array($screen->id, array('shop_order', 'woocommerce_page_wc-orders'), true)) return;
        $order_id = isset($_GET['post']) ? absint($_GET['post']) : (isset($_GET['id']) ? absint($_GET['id']) : 0);
        $order = $order_id ? wc_get_order($order_id) : false;
        if (!$order || $order->get_payment_method() !== QP_GATEWAY_ID || !current_user_can('edit_shop_order', $order_id)) return;
        $dependencies = array('jquery');
        if (wp_script_is('wc-admin-order-meta-boxes', 'registered')) $dependencies[] = 'wc-admin-order-meta-boxes';
        wp_enqueue_script('qep-refund-swal', WC_QUANTUMEPAY_PLUGIN_URL . '/assets/js/sweetalert2.all.min.js', $dependencies, '11.26.25', true);
        $script = 'const QEP_REFUND_ORDER_ID = ' . (int) $order_id . ';' . "\n" . <<<'JS'
jQuery(function ($) {
    if (window.qepRefundDialogsReady) return;
    window.qepRefundDialogsReady = true;
    let confirmedButton = null;
    let confirmationOpen = false;

    function refundIcon(type) {
        const paths = {
            success: '<path d="m6 12 4 4 8-8"/>',
            error: '<path d="m8 8 8 8m0-8-8 8"/>',
            warning: '<path d="M12 7v6"/><circle cx="12" cy="17" r=".7" fill="currentColor" stroke="none"/>'
        };
        return '<svg aria-hidden="true" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' + paths[type] + '</svg>';
    }

    function showRefundError(message) {
        const text = typeof message === 'string' && message.trim() ? message : 'The refund could not be confirmed. Check Qoin or contact support before trying again.';
        const uncertain = /could not (?:be )?confirm|could not load|before trying again|still processing|after it settles/i.test(text);
        if (window.Swal && typeof window.Swal.fire === 'function') {
            return window.Swal.fire({ icon: 'info', iconHtml: refundIcon(uncertain ? 'warning' : 'error'), title: uncertain ? 'Refund needs attention' : 'Refund not completed', text: text, confirmButtonText: 'Got it', confirmButtonColor: '#192f59', width: 480, heightAuto: false, buttonsStyling: false, customClass: { container: 'qep-refund-container', popup: 'qep-refund-popup', confirmButton: 'qep-refund-confirm', cancelButton: 'qep-refund-cancel' } });
        }
        let $notice = $('#qep-refund-notice');
        if (!$notice.length) $notice = $('<div id="qep-refund-notice" class="notice notice-error" role="alert" tabindex="-1"><p></p></div>').insertBefore('#woocommerce-order-items');
        $notice.find('p').text(text);
        $notice[0].focus();
    }

    document.addEventListener('click', function (event) {
        const button = event.target.closest && event.target.closest('#woocommerce-order-items .do-api-refund');
        if (!button || button.disabled) return;
        if (button === confirmedButton) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        if (confirmationOpen) return;
        if (!window.Swal || typeof window.Swal.fire !== 'function') {
            showRefundError('The refund dialog could not load. Refresh this page before trying again.');
            return;
        }
        confirmationOpen = true;
        window.Swal.fire({ title: 'Issue this refund?', text: 'The entered amount will be refunded through Quantum ePay. Please confirm the amount before continuing.', showCancelButton: true, confirmButtonText: 'Issue refund', cancelButtonText: 'Cancel', confirmButtonColor: '#192f59', reverseButtons: true, focusCancel: true, width: 480, heightAuto: false, buttonsStyling: false, customClass: { container: 'qep-refund-container', popup: 'qep-refund-popup', confirmButton: 'qep-refund-confirm', cancelButton: 'qep-refund-cancel' } }).then(function (result) {
            if (!result.isConfirmed || !button.isConnected || button.disabled) return;
            const nativeConfirm = window.confirm;
            confirmedButton = button;
            window.confirm = function () { return true; };
            try { button.click(); } finally { window.confirm = nativeConfirm; confirmedButton = null; }
        }).finally(function () { confirmationOpen = false; });
    }, true);

    $.ajaxPrefilter(function (options, originalOptions) {
        const data = originalOptions.data;
        const params = typeof data === 'string' ? new URLSearchParams(data) : null;
        const action = params ? params.get('action') : data && data.action;
        const orderId = params ? params.get('order_id') : data && data.order_id;
        const apiRefund = params ? params.get('api_refund') : data && data.api_refund;
        if (action !== 'woocommerce_refund_line_items' || String(orderId) !== String(QEP_REFUND_ORDER_ID) || ![true, 'true', 1, '1'].includes(apiRefund)) return;
        const success = options.success;
        const error = options.error;
        options.success = function (response) {
            if (response && response.success === true) {
                const callbackContext = this;
                const callbackArgs = arguments;
                if (window.Swal && typeof window.Swal.fire === 'function') {
                    return window.Swal.fire({ icon: 'info', iconHtml: refundIcon('success'), title: 'Refund completed', text: 'The refund was confirmed and recorded for this order.', confirmButtonText: 'Done', confirmButtonColor: '#192f59', width: 480, heightAuto: false, allowOutsideClick: false, buttonsStyling: false, customClass: { container: 'qep-refund-container', popup: 'qep-refund-popup', confirmButton: 'qep-refund-confirm', cancelButton: 'qep-refund-cancel' } }).then(function () {
                        if (typeof success === 'function') success.apply(callbackContext, callbackArgs);
                    });
                }
                if (typeof success === 'function') return success.apply(this, arguments);
                return;
            }
            const message = response && response.data && response.data.error;
            const nativeAlert = window.alert;
            window.alert = function () { showRefundError(message); };
            try {
                if (typeof success === 'function' && response && response.data && typeof message === 'string') return success.apply(this, arguments);
                $('#woocommerce-order-items').unblock();
                showRefundError(message);
            } finally { window.alert = nativeAlert; }
        };
        options.error = function () {
            const nativeAlert = window.alert;
            window.alert = function () {};
            try { if (typeof error === 'function') error.apply(this, arguments); } finally { window.alert = nativeAlert; }
            $('#woocommerce-order-items').unblock();
            showRefundError('We could not confirm the refund because the connection was interrupted. Check Qoin before trying again to avoid issuing it twice.');
        };
    });
});
JS;
        wp_add_inline_script('qep-refund-swal', $script);
        wp_register_style('qep-refund-dialogs', false, array(), WC_QUANTUMEPAY_VERSION);
        wp_enqueue_style('qep-refund-dialogs');
        wp_add_inline_style('qep-refund-dialogs', <<<'CSS'

.qep-refund-container.swal2-container { background: rgba(17, 29, 48, .42); }
.qep-refund-popup.swal2-popup { padding: 28px; border: 1px solid #dce2ea; border-radius: 12px; background: #fff; color: #253247; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; box-shadow: 0 20px 65px rgba(17, 29, 48, .2); }
.qep-refund-popup .swal2-icon { width: 48px; height: 48px; margin: 0 auto 16px; border: 0; border-radius: 50%; background: #fff4df; color: #946818; }
.qep-refund-popup .swal2-icon:has(svg path[d="m6 12 4 4 8-8"]) { background: #eaf4ee; color: #347653; }
.qep-refund-popup .swal2-icon:has(svg path[d="m8 8 8 8m0-8-8 8"]) { background: #faeded; color: #a54545; }
.qep-refund-popup .swal2-icon .swal2-icon-content { display: flex; align-items: center; justify-content: center; }
.qep-refund-popup .swal2-title { margin: 0; padding: 0; color: #192f59; font-size: 21px; line-height: 1.3; font-weight: 650; }
.qep-refund-popup .swal2-html-container { margin: 12px 0 0; padding: 0; color: #596577; font-size: 14px; line-height: 1.65; overflow-wrap: anywhere; }
.qep-refund-popup .swal2-actions { width: 100%; margin: 22px 0 0; gap: 10px; justify-content: center; }
.qep-refund-popup .qep-refund-confirm, .qep-refund-popup .qep-refund-cancel { min-height: 40px; margin: 0; padding: 9px 18px; border: 1px solid transparent; border-radius: 7px; font: inherit; font-size: 13px; font-weight: 600; line-height: 20px; cursor: pointer; transition: background .18s ease, border-color .18s ease, box-shadow .18s ease; }
.qep-refund-popup .qep-refund-confirm { border-color: #192f59; background: #192f59; color: #fff; }
.qep-refund-popup .qep-refund-confirm:hover { border-color: #112443; background: #112443; }
.qep-refund-popup .qep-refund-cancel { border-color: #dce2ea; background: #f7f8fa; color: #465366; }
.qep-refund-popup .qep-refund-cancel:hover { border-color: #bcc7d5; background: #edf0f4; }
.qep-refund-popup .qep-refund-confirm:focus-visible, .qep-refund-popup .qep-refund-cancel:focus-visible { outline: 2px solid #192f59; outline-offset: 3px; box-shadow: none; }
.qep-refund-popup .qep-refund-confirm:disabled, .qep-refund-popup .qep-refund-cancel:disabled { opacity: .55; cursor: default; }
@media (max-width: 480px) { .qep-refund-popup.swal2-popup { padding: 24px 20px; } }
@media (prefers-reduced-motion: reduce) { .qep-refund-popup *, .qep-refund-popup.swal2-popup { animation: none !important; transition: none !important; } }
CSS
        );
    }

    public function captureRefundBalance($refund, $args)
    {
        $order_id = isset($args['order_id']) ? (int) $args['order_id'] : 0;
        unset(self::$refundContexts[$order_id]);
        if (!$order_id || empty($args['refund_payment']) || !empty($args['refund_id'])) return;
        $order = wc_get_order($order_id);
        if (!$order || $order->get_payment_method() !== QP_GATEWAY_ID) return;
        $amount = $refund->get_amount();
        $remaining = (float) $order->get_remaining_refund_amount();
        $current_record_amount = 0;
        // WooCommerce can persist this new refund while calculating its taxes.
        if ($refund->get_id()) {
            $stored_refund = wc_get_order($refund->get_id());
            if (!$stored_refund || (int) $stored_refund->get_parent_id() !== $order_id || $stored_refund->get_refunded_payment()) return;
            $current_record_amount = (float) $stored_refund->get_amount();
            if (!is_finite($current_record_amount) || $current_record_amount < 0) return;
            $remaining += $current_record_amount;
        }
        if (!is_numeric($amount) || !is_finite((float) $amount) || (float) $amount <= 0
            || round((float) $amount, wc_get_price_decimals()) > round($remaining, wc_get_price_decimals())) return;
        self::$refundContexts[$order_id] = array('refund' => $refund, 'amount' => (float) $amount, 'remaining' => $remaining);
    }

    public static function consumeRefundBalance($order_id, $amount)
    {
        $context = self::$refundContexts[$order_id] ?? null;
        unset(self::$refundContexts[$order_id]);
        if (!$context || !is_numeric($amount) || !is_finite((float) $amount)) return null;
        $refund = $context['refund'];
        if (!$refund->get_id() || (int) $refund->get_parent_id() !== (int) $order_id || $refund->get_refunded_payment()
            || round((float) $amount, wc_get_price_decimals()) !== round($context['amount'], wc_get_price_decimals())
            || round((float) $refund->get_amount(), wc_get_price_decimals()) !== round($context['amount'], wc_get_price_decimals())) return null;
        return $context['remaining'];
    }

    public function applyCheckoutServiceFee($cart)
    {
        if (!function_exists('WC') || !WC()->session || (is_admin() && !wp_doing_ajax())) return;
        $settings = get_option('woocommerce_' . QP_GATEWAY_ID . '_settings', array());
        if (!is_array($settings) || ($settings['enabled'] ?? 'no') !== 'yes'
            || ($settings['service_fee_mode'] ?? 'none') !== 'custom') return;

        $method = WC()->session->get('chosen_payment_method');
        if (isset($_POST['payment_method']) && is_string($_POST['payment_method']) && trim($_POST['payment_method']) !== '') {
            $method = sanitize_text_field(wp_unslash($_POST['payment_method']));
        } elseif (isset($_POST['post_data']) && is_string($_POST['post_data'])) {
            $posted = array();
            parse_str(wp_unslash($_POST['post_data']), $posted);
            if (isset($posted['payment_method']) && is_string($posted['payment_method']) && trim($posted['payment_method']) !== '') {
                $method = sanitize_text_field($posted['payment_method']);
            }
        }
        if ($method !== QP_GATEWAY_ID) return;
        WC()->session->set('chosen_payment_method', $method);

        $subtotal = max(0, (float) $cart->get_cart_contents_total());
        $value = wc_format_decimal($settings['service_fee_amount'] ?? '0');
        $type = $settings['service_fee_type'] ?? 'percentage';
        if ($subtotal <= 0 || !is_numeric($value) || !is_finite((float) $value) || (float) $value <= 0
            || !in_array($type, array('percentage', 'fixed'), true)) return;
        $amount = round($type === 'fixed' ? (float) $value : $subtotal * (float) $value / 100, wc_get_price_decimals());
        if (!is_finite($amount) || $amount <= 0) return;
        $label = isset($settings['service_fee_label']) && is_string($settings['service_fee_label'])
            ? sanitize_text_field($settings['service_fee_label']) : 'Service fee';
        $cart->fees_api()->add_fee(array('id' => 'qep_service_fee', 'name' => $label ?: 'Service fee',
            'amount' => $amount, 'taxable' => ($settings['service_fee_taxable'] ?? 'no') === 'yes', 'tax_class' => ''));
    }

	public function addPluginSettingsLink($links)
	{
		$settings_url = admin_url(
			'admin.php?page=wc-settings&tab=checkout&section=' . QP_GATEWAY_ID
		);

		$settings_link = '<a href="' . esc_url($settings_url) . '">Settings</a>';

		array_unshift($links, $settings_link);

		return $links;
	}


	public function enqueueQuantumSettingsAssets($hook)
	{
	    if (
	        !is_admin()
	        || !isset($_GET['page'], $_GET['tab'], $_GET['section'])
	        || 'wc-settings' !== $_GET['page']
	        || 'checkout' !== $_GET['tab']
	        || QP_GATEWAY_ID !== $_GET['section']
	    ) {
	        return;
	    }

	    wp_enqueue_style(
	        'quantumepay-admin-settings',
	        WC_QUANTUMEPAY_PLUGIN_URL . '/assets/css/quantumepay-admin.css',
	        array(),
	        WC_QUANTUMEPAY_VERSION
	    );

	    wp_enqueue_script(
	        'quantumepay-admin-settings',
	        WC_QUANTUMEPAY_PLUGIN_URL . '/assets/js/quantumepay-admin.js',
	        array('jquery'),
	        WC_QUANTUMEPAY_VERSION,
	        true
	    );

	    wp_localize_script(
	        'quantumepay-admin-settings',
	        'qepAdminSettings',
	        array(
	            'ajaxUrl' => admin_url('admin-ajax.php'),
	            'nonce'   => wp_create_nonce('qep_save_gateway_settings'),
	            'version' => WC_QUANTUMEPAY_VERSION,
	        )
	    );
	}


	public function saveQuantumGatewaySettings()
	{
		if (!current_user_can('manage_woocommerce')) {
			wp_send_json_error(
				array(
					'message' => 'You do not have permission to update Quantum ePay settings.',
				),
				403
			);
		}

		check_ajax_referer('qep_save_gateway_settings', 'nonce');

		$submitted_settings = isset($_POST['settings']) && is_array($_POST['settings'])
			? wp_unslash($_POST['settings'])
			: array();

		$allowed_fields = array(
			'enabled',
			'title',
			'description',
			'testmode',
			'terminal_key',
			'client_id',
			'client_secret',
			'test_terminal_key',
			'test_client_id',
			'test_client_secret',
			'timeout_notification_recipients',
			'service_fee_mode',
			'service_fee_label',
			'service_fee_type',
			'service_fee_amount',
			'service_fee_taxable',
		);

		$option_key = 'woocommerce_' . QP_GATEWAY_ID . '_settings';
		$current_settings = get_option($option_key, array());

		if (!is_array($current_settings)) {
			$current_settings = array();
		}

		foreach ($allowed_fields as $field) {
			if (!array_key_exists($field, $submitted_settings)) {
				continue;
			}

			$value = $submitted_settings[$field];
            if (!is_scalar($value)) {
                wp_send_json_error(array('message' => 'Invalid settings value.'), 422);
                return;
            }
            $choices = array('service_fee_mode' => array('gateway', 'custom', 'none'),
                'service_fee_type' => array('percentage', 'fixed'));
            if (isset($choices[$field]) && !in_array($value, $choices[$field], true)) {
                wp_send_json_error(array('message' => 'Invalid service fee selection.'), 422);
                return;
            }
            if ($field === 'service_fee_amount') {
                $value = wc_format_decimal($value);
                if (!is_numeric($value) || !is_finite((float) $value) || (float) $value < 0) {
                    wp_send_json_error(array('message' => 'Service fee amount must be a number greater than or equal to zero.'), 422);
                    return;
                }
            }

			$protected_credentials = array(
				'client_id',
				'client_secret',
				'test_client_id',
				'test_client_secret',
			);

			if (
				in_array($field, $protected_credentials, true)
				&& '' === trim((string) $value)
			) {
				continue;
			}

			switch ($field) {
				case 'enabled':
				case 'testmode':
				case 'service_fee_taxable':
					$current_settings[$field] = ('yes' === $value || '1' === (string) $value || true === $value)
						? 'yes'
						: 'no';
					break;

				case 'description':
				case 'timeout_notification_recipients':
					$current_settings[$field] = sanitize_textarea_field((string) $value);
					break;

				default:
					$current_settings[$field] = sanitize_text_field((string) $value);
					break;
			}
		}

		if (
			isset($current_settings['testmode'])
			&& 'no' === $current_settings['testmode']
		) {
			$missing_live_credentials = array();

			if (empty($current_settings['terminal_key'])) {
				$missing_live_credentials[] = 'X-TERMINAL-KEY';
			}

			if (empty($current_settings['client_id'])) {
				$missing_live_credentials[] = 'Client ID';
			}

			if (empty($current_settings['client_secret'])) {
				$missing_live_credentials[] = 'Client Secret';
			}

			if (!empty($missing_live_credentials)) {
				wp_send_json_error(
					array(
						'message' => 'Live Mode requires: ' . implode(', ', $missing_live_credentials) . '.',
					),
					422
				);
			}
		}

		$updated = update_option($option_key, $current_settings);

		if (!$updated && get_option($option_key, array()) !== $current_settings) {
			wp_send_json_error(
				array(
					'message' => 'Quantum ePay settings could not be saved. Please try again.',
				),
				500
			);
		}

		wp_send_json_success(
			array(
				'message' => 'Quantum ePay settings saved successfully.',
			)
		);
	}

	private function cleanupLegacyLogs()
	{
	    $installed_version = get_option('wc_quantumepay_version');

	    if (
	        !empty($installed_version) &&
	        version_compare($installed_version, WC_QUANTUMEPAY_VERSION, '>=')
	    ) {
	        return;
	    }

	    $upload_dir = wp_upload_dir();

	    $files = array(
	        $upload_dir['basedir'] . '/quantumepay.log',
	        $upload_dir['basedir'] . '/quantum.log',
	    );

	    foreach ($files as $file) {
	        if (file_exists($file)) {
	            @unlink($file);
	        }
	    }

	    update_option('wc_quantumepay_version', WC_QUANTUMEPAY_VERSION);
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