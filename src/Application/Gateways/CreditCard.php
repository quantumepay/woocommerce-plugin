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
        $api_url,
        $api_url_identity,
        $recurring_enabled,
        $debug_mode,
        $notices,
        $staging_api_url,
        $staging_api_url_identity,
        $live_api_url,
        $live_api_url_identity;

    private $sensitive_fields = [
        // --- Staging Environment ---
        'staging_client_id',
        'staging_client_secret',
        'staging_terminal_key',
        'staging_api_url',
        'staging_api_url_identity',

        'live_client_id',
        'live_client_secret',
        'live_terminal_key',
        'live_api_url',
        'live_api_url_identity',
    ];

    public function __construct()
    {
        $this->id = defined('QP_GATEWAY_ID') ? QP_GATEWAY_ID : 'quantumepay';
        $this->has_fields = true;
        $this->method_title = 'Quantum ePay';
        $this->method_description = 'Accept credit card payments with Qoin, the next generation WooCommerce payment gateway. Only from Quantum ePay.';
        $this->supports = [
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
            'woocommerce-blocks',
        ];
        $this->init_form_fields();
        $this->init_settings();
        $this->title = $this->get_option('title', 'Quantum ePay');
        $this->description = $this->get_option('description', 'Pay with Quantum ePay.');
        $this->enabled = $this->get_option('enabled', 'yes');
        $this->testmode = 'yes' === $this->get_option('testmode', 'no');
        $this->debug_mode = 'yes' === $this->get_option('debug_mode', 'no');

        // Hardcoded API URLs as requested
        $this->staging_api_url = 'https://paymentsuat.quantumepay.com/';
        $this->staging_api_url_identity = 'https://identityuat.quantumepay.com/connect/token';
        $this->live_api_url = 'https://payments.quantumepay.com/';
        $this->live_api_url_identity = 'https://identity.quantumepay.com/connect/token';

        // Determine which environment to load (staging or live)
        if ($this->testmode) {
            // --- STAGING CREDENTIALS ---
            $this->terminal_key = $this->decrypt_setting($this->get_option('staging_terminal_key', ''));
            $this->client_id = $this->decrypt_setting($this->get_option('staging_client_id', ''));
            $this->client_secret = $this->decrypt_setting($this->get_option('staging_client_secret', ''));
            $this->api_url = $this->staging_api_url;
            $this->api_url_identity = $this->staging_api_url_identity;
        } else {
            // --- LIVE CREDENTIALS ---
            $this->terminal_key = $this->decrypt_setting($this->get_option('live_terminal_key', ''));
            $this->client_id = $this->decrypt_setting($this->get_option('live_client_id', ''));
            $this->client_secret = $this->decrypt_setting($this->get_option('live_client_secret', ''));
            $this->api_url = $this->live_api_url;
            $this->api_url_identity = $this->live_api_url_identity;
        }

        $this->ensure_encryption_key();
        $this->check_environment();
        add_action('admin_notices', [$this, 'admin_notices']);
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
        add_action('wp_enqueue_scripts', [$this, 'payment_scripts']);
        add_action('woocommerce_scheduled_subscription_payment_' . $this->id, [$this, 'scheduled_subscription_payment'], 10, 2);
        add_action('woocommerce_order_status_changed', [$this, 'handle_order_status_change'], 10, 3);
        add_filter('woocommerce_cart_needs_payment', [$this, 'cart_needs_payment'], 10, 2);
        add_action('woocommerce_review_order_before_submit', [$this, 'add_bypass_notice']);
        add_filter('woocommerce_available_payment_gateways', [$this, 'filter_available_gateways'], 10, 1);
        add_action('woocommerce_product_options_general_product_data', [$this, 'add_product_checkout_type_field']);
        add_action('woocommerce_process_product_meta', [$this, 'save_product_checkout_type_field']);
        add_action('product_cat_add_form_fields', [$this, 'add_category_checkout_type_field']);
        add_action('product_cat_edit_form_fields', [$this, 'edit_category_checkout_type_field']);
        add_action('created_product_cat', [$this, 'save_category_checkout_type_field']);
        add_action('edited_product_cat', [$this, 'save_category_checkout_type_field']);
        add_filter('wc_order_statuses', [$this, 'add_custom_order_statuses']);
        add_filter('woocommerce_checkout_posted_data', [$this, 'force_payment_method_for_bypass']);
        add_filter('woocommerce_checkout_fields', [$this, 'maybe_remove_payment_fields']);
        add_filter('woocommerce_order_needs_payment', [$this, 'bypass_order_payment'], 10, 3);
        add_filter('woocommerce_rest_prepare_shop_order', [$this, 'handle_rest_order_response'], 10, 3);
        add_action('woocommerce_rest_insert_shop_order', [$this, 'handle_rest_order_creation'], 10, 3);
        add_action('woocommerce_checkout_order_review', [$this, 'qp_remove_payment_method_selection'], 5);
        add_action('rest_api_init', [$this, 'register_rest_api_fields']);
        add_filter('woocommerce_rest_pre_insert_shop_order_object', [$this, 'pre_order_handle_rest_order_creation'], 10, 3);
        add_action('init', [$this, 'register_payment_link_endpoint']);
        add_action('template_redirect', [$this, 'handle_payment_link_page']);
        add_action('admin_post_qp_process_payment', [$this, 'process_payment_link']);
        add_filter('woocommerce_my_account_my_orders_actions', [$this, 'remove_pay_button_for_bypass_orders'], 10, 2);
        add_action('wp_head', [$this, 'hide_pay_button_css']);
        add_action('admin_init', [$this, 'handle_key_rotation']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
    }

    /**
     * Ensure an encryption key exists, generate and store if not present
     */
    private function ensure_encryption_key()
    {
        $option_key = 'qp_encryption_key';
        $stored_key = get_option($option_key);
        if (!$stored_key) {
            if (function_exists('sodium_crypto_secretbox_keygen')) {
                $key = sodium_crypto_secretbox_keygen();
            } else {
                $key = openssl_random_pseudo_bytes(32); // AES-256 key length
            }
            // Store encrypted to prevent direct DB access exposure
            $encrypted_key = base64_encode($key);
            update_option($option_key, $encrypted_key);
        }
    }


    private function sanitize_card_data($card_number, $expiry_month, $expiry_year, $cvv)
    {
        // Remove spaces/dashes from card number
        $card_number = preg_replace('/[^0-9]/', '', $card_number);

        // Validate card number length (13-19 digits)
        if (strlen($card_number) < 13 || strlen($card_number) > 19) {
            throw new \Exception('Invalid card number length');
        }

        // Validate expiry
        $current_year = date('Y');
        if ($expiry_month < 1 || $expiry_month > 12) {
            throw new \Exception('Invalid expiry date');
        }

        // Validate CVV length
        if (strlen($cvv) < 3 || strlen($cvv) > 4) {
            throw new \Exception('Invalid CVV length');
        }

        return [$card_number, $expiry_month, $expiry_year, $cvv];
    }

    /**
     * Retrieve the encryption key
     */
    private function get_encryption_key()
    {
        // Priority 1: wp-config.php constant
        if (defined('QP_KMS_KEY')) {
            $key = base64_decode(QP_KMS_KEY);
            return $this->validate_encryption_key($key) ? $key : $this->get_fallback_encryption_key();
        }

        // Priority 2: Check if we have a generated key waiting for installation
        $pending_key = get_option('qp_generated_key');
        if ($pending_key) {
            $this->add_admin_notice(
                'qp_key_pending',
                'warning',
                __('Quantum ePay encryption key is ready but needs to be installed in wp-config.php for maximum security.', 'qoin-payment-gateway')
            );
        }

        // Priority 3: Fallback to database storage
        return $this->get_fallback_encryption_key();
    }

    /**
     * Validate encryption key length and format
     */
    private function validate_encryption_key($key)
    {
        if (function_exists('sodium_crypto_secretbox')) {
            $expected_length = SODIUM_CRYPTO_SECRETBOX_KEYBYTES;
            if (strlen($key) !== $expected_length) {
                $this->add_admin_notice(
                    'qp_invalid_key_length',
                    'error',
                    sprintf(__('QP_KMS_KEY must be %d bytes for Sodium encryption. Using database fallback.', 'qoin-payment-gateway'), $expected_length)
                );
                return false;
            }
        } else {
            $valid_lengths = [16, 24, 32];
            if (!in_array(strlen($key), $valid_lengths)) {
                $this->add_admin_notice(
                    'qp_invalid_key_length',
                    'error',
                    __('QP_KMS_KEY must be 16, 24, or 32 bytes for AES encryption. Using database fallback.', 'qoin-payment-gateway')
                );
                return false;
            }
        }

        return true;
    }

    /**
     * Fallback to database storage
     */
    private function get_fallback_encryption_key()
    {
        $option_key = 'qp_encryption_key';
        $stored_key = get_option($option_key);

        if (!$stored_key) {
            // Generate and store a new key
            $key = $this->generate_secure_key();
            $encrypted_key = base64_encode($key);
            update_option($option_key, $encrypted_key);

            $this->add_admin_notice(
                'qp_db_key_warning',
                'warning',
                __('Quantum ePay is using database-stored encryption keys. For maximum security, ensure QP_KMS_KEY is defined in wp-config.php.', 'qoin-payment-gateway')
            );

            return $key;
        }

        return base64_decode($stored_key);
    }

    private function generate_secure_key()
    {
        if (function_exists('sodium_crypto_secretbox_keygen')) {
            return sodium_crypto_secretbox_keygen();
        } else {
            return openssl_random_pseudo_bytes(32);
        }
    }

    /**
     * Encrypt a setting value using Sodium secretbox or AES-GCM fallback
     */
    private function encrypt_setting($value)
    {
        if (empty($value)) {
            return $value;
        }

        $key = $this->get_encryption_key();
        if (strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES && !in_array(strlen($key), [16, 24, 32])) {
            return $value; // Invalid key length
        }

        if (function_exists('sodium_crypto_secretbox')) {
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $ciphertext = sodium_crypto_secretbox($value, $nonce, $key);
            return base64_encode($nonce . $ciphertext);
        } else {
            // Fallback to AES-GCM
            $nonce = openssl_random_pseudo_bytes(12);
            $ciphertext = openssl_encrypt(
                $value,
                'aes-256-gcm',
                $key,
                OPENSSL_RAW_DATA,
                $nonce,
                $tag
            );
            return base64_encode($nonce . $tag . $ciphertext);
        }
    }

    /**
     * Decrypt a setting value using Sodium secretbox or AES-GCM fallback
     */
    private function decrypt_setting($value)
    {
        if (empty($value)) {
            return $value;
        }

        $key = $this->get_encryption_key();
        $decoded = base64_decode($value);
        if ($decoded === false) {
            return $value;
        }

        if (function_exists('sodium_crypto_secretbox_open')) {
            if (strlen($decoded) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
                return $value;
            }
            $nonce = substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $ciphertext = substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            try {
                $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $key);
                return $plaintext !== false ? $plaintext : $value;
            } catch (\Exception $e) {
                return $value;
            }
        } else {
            // Fallback to AES-GCM
            if (strlen($decoded) < 28) { // 12 (nonce) + 16 (tag)
                return $value;
            }
            $nonce = substr($decoded, 0, 12);
            $tag = substr($decoded, 12, 16);
            $ciphertext = substr($decoded, 28);
            $plaintext = openssl_decrypt(
                $ciphertext,
                'aes-256-gcm',
                $key,
                OPENSSL_RAW_DATA,
                $nonce,
                $tag
            );
            return $plaintext !== false ? $plaintext : $value;
        }
    }

    /**
     * Handle key rotation for encrypted settings and key
     */
    public function handle_key_rotation()
    {
        if (!isset($_POST['qp_rotate_key']) || !wp_verify_nonce($_POST['qp_key_rotation_nonce'], 'qp_key_rotation')) {
            return;
        }
        // Generate new key
        if (function_exists('sodium_crypto_secretbox_keygen')) {
            $new_key = sodium_crypto_secretbox_keygen();
        } else {
            $new_key = openssl_random_pseudo_bytes(32);
        }
        // Re-encrypt settings with new key
        foreach ($this->sensitive_fields as $setting) {
            $encrypted_value = $this->get_option($setting);
            if ($encrypted_value) {
                $decrypted_value = $this->decrypt_setting($encrypted_value);
                update_option('qp_encryption_key', base64_encode($new_key));
                $new_encrypted_value = $this->encrypt_setting($decrypted_value);
                $this->update_option($setting, $new_encrypted_value);
            }
        }
        // Update stored key
        update_option('qp_encryption_key', base64_encode($new_key));
        add_settings_error(
            'quantumepay_settings',
            'key_rotation_success',
            __('Encryption key rotated successfully.', 'qoin-payment-gateway'),
            'updated'
        );
    }

    /**
     * Enqueue admin scripts for revealing secrets
     */
    public function enqueue_admin_scripts($hook)
    {
        // Load only on your gateway settings page
        if (
            'woocommerce_page_wc-settings' !== $hook ||
            empty($_GET['section']) ||
            $_GET['section'] !== $this->id
        ) {
            return;
        }

        wp_enqueue_style('dashicons');

        // Enqueue external CSS file with same versioning pattern
        wp_enqueue_style(
            'quantumepay-admin-style',
            WC_QUANTUMEPAY_PLUGIN_URL . '/assets/css/admin-settings.css',
            ['dashicons'],
            $this->debug_mode ? time() : WC_QUANTUMEPAY_VERSION
        );

        // Enqueue external JS file with same versioning pattern
        wp_enqueue_script(
            'quantumepay-admin-js',
            WC_QUANTUMEPAY_PLUGIN_URL . '/assets/js/admin-settings.js',
            ['jquery'],
            $this->debug_mode ? time() : WC_QUANTUMEPAY_VERSION,
            true
        );
    }




    public function admin_notices(): void
    {
        if (empty($this->notices)) {
            return;
        }
        foreach ($this->notices as $notice_key => $notice) {
            $class = isset($notice['class']) && is_string($notice['class']) ? sanitize_html_class($notice['class']) : 'error';
            $message = isset($notice['message']) && is_string($notice['message']) ? $notice['message'] : '';
            if (empty($message)) {
                continue;
            }
            $allowed_html = [
                'a' => ['href' => [], 'title' => []],
                'strong' => [],
                'em' => [],
                'p' => [],
            ];
            printf(
                '<div class="%s"><p>%s</p></div>',
                esc_attr($class),
                wp_kses($message, $allowed_html)
            );
        }
    }

    public function add_admin_notice(string $slug, string $class, string $message): void
    {
        $this->notices[$slug] = [
            'class' => $class,
            'message' => $message,
        ];
    }

    public function check_environment(): void
    {
        $environment_warning = $this->get_environment_warning();
        if (!empty($environment_warning) && is_plugin_active(plugin_basename(WC_QUANTUMEPAY_MAIN_FILE))) {
            $this->add_admin_notice('qp_bad_environment', 'error', $environment_warning);
        }
        $is_on_settings_page = (
            isset($_GET['page'], $_GET['section']) &&
            'wc-settings' === $_GET['page'] &&
            $this->id === $_GET['section']
        );
        if (empty($this->terminal_key) && !$is_on_settings_page) {
            $setting_link = admin_url('admin.php?page=wc-settings&tab=checkout&section=' . $this->id);
            $message = sprintf(
                'Qoin Payment Gateway will not work until you <a href="%s">configure your API data (terminal key)</a>.',
                esc_url($setting_link)
            );
            $this->add_admin_notice('qp_prompt_connect', 'notice notice-warning', $message);
        }
        if (!is_ssl() && !$this->testmode) {
            $msg = 'Qoin Payment Gateway is enabled and the force SSL option is disabled; your checkout is not secure! Please enable SSL and ensure your server has a valid SSL certificate.';
            $this->add_admin_notice('qp_ssl', 'notice notice-warning', $msg);
        }
    }

    public function get_environment_warning(): string|false
    {
        if (version_compare(phpversion(), WC_QUANTUMEPAY_MIN_PHP_VER, '<')) {
            $message = esc_html__('Qoin Payment Gateway - The minimum PHP version required for this plugin is %1$s. You are running %2$s.', 'qoin-payment-gateway');
            return sprintf($message, WC_QUANTUMEPAY_MIN_PHP_VER, phpversion());
        }
        if (!defined('WC_VERSION')) {
            return esc_html__('Qoin Payment Gateway requires WooCommerce to be activated to work.', 'qoin-payment-gateway');
        }
        if (version_compare(WC_VERSION, WC_QUANTUMEPAY_MIN_WC_VER, '<')) {
            $message = esc_html__('Qoin Payment Gateway - The minimum WooCommerce version required for this plugin is %1$s. You are running %2$s.', 'qoin-payment-gateway');
            return sprintf($message, WC_QUANTUMEPAY_MIN_WC_VER, WC_VERSION);
        }
        if (!function_exists('curl_init')) {
            return esc_html__('Qoin Payment Gateway - cURL is not installed.', 'qoin-payment-gateway');
        }
        return false;
    }

    public function init_form_fields()
    {
        $key_status = defined('QP_KMS_KEY')
            ? '<span style="color: green;">✓ ' . __('Secure (wp-config.php)', 'qoin-payment-gateway') . '</span>'
            : '<span style="color: orange;">⚠ ' . __('Less Secure (database)', 'qoin-payment-gateway') . '</span>';

        $this->form_fields = [
            'encryption_status' => [
                'title' => __('Encryption Status', 'qoin-payment-gateway'),
                'type' => 'title',
                'description' => sprintf(__('Current encryption: %s', 'qoin-payment-gateway'), $key_status),
            ],

            'enabled' => [
                'title' => __('Enable/Disable', 'qoin-payment-gateway'),
                'type' => 'checkbox',
                'label' => __('Enable Quantum ePay Gateway', 'qoin-payment-gateway'),
                'default' => 'yes',
            ],

            'title' => [
                'title' => __('Title', 'qoin-payment-gateway'),
                'type' => 'text',
                'description' => __('This controls the title which the user sees during checkout.', 'qoin-payment-gateway'),
                'default' => 'Quantum ePay',
                'desc_tip' => true,
            ],

            'description' => [
                'title' => __('Description', 'qoin-payment-gateway'),
                'type' => 'textarea',
                'description' => __('This controls the description which the user sees during checkout.', 'qoin-payment-gateway'),
                'default' => __('Pay with Quantum ePay.', 'qoin-payment-gateway'),
            ],

            'testmode' => [
                'title' => __('Test Mode', 'qoin-payment-gateway'),
                'type' => 'checkbox',
                'label' => __('Enable Test (Staging) Mode', 'qoin-payment-gateway'),
                'default' => 'no',
                'description' => __('Enable to use staging API credentials instead of live ones.', 'qoin-payment-gateway'),
            ],

            // Credentials Tabs Container
            'credentials_tabs' => [
                'title' => __('API Credentials', 'qoin-payment-gateway'),
                'type' => 'title',
                'description' => $this->get_api_credentials_notice(),
                'class' => 'qp-credentials-tabs-container',
            ],

            // --- LIVE CREDENTIALS (shown by default) ---
            'live_section' => [
                'title' => __('Live Credentials', 'qoin-payment-gateway'),
                'type' => 'title',
                'description' => __('Used when Test Mode is disabled.', 'qoin-payment-gateway'),
                'class' => 'qp-credentials-section qp-live-credentials qp-section-title',
            ],

            'live_client_id' => [
                'title' => __('Client ID', 'qoin-payment-gateway'),
                'type' => 'password',
                'default' => '',
                'class' => 'qp-credentials-field qp-live-credentials qp-sensitive-field',
                'description' => '',
            ],

            'live_client_secret' => [
                'title' => __('Client Secret', 'qoin-payment-gateway'),
                'type' => 'password',
                'default' => '',
                'class' => 'qp-credentials-field qp-live-credentials qp-sensitive-field',
                'description' => '',
            ],

            'live_terminal_key' => [
                'title' => __('Terminal/Merchant UID', 'qoin-payment-gateway'),
                'type' => 'password',
                'default' => '',
                'class' => 'qp-credentials-field qp-live-credentials qp-sensitive-field',
                'description' => '',
            ],

            // --- STAGING CREDENTIALS (hidden by default) ---
            'staging_section' => [
                'title' => __('Staging Credentials', 'qoin-payment-gateway'),
                'type' => 'title',
                'description' => __('Used when Test Mode is enabled.', 'qoin-payment-gateway'),
                'class' => 'qp-credentials-section qp-staging-credentials qp-section-title',
            ],

            'staging_client_id' => [
                'title' => __('Client ID', 'qoin-payment-gateway'),
                'type' => 'password',
                'default' => '',
                'class' => 'qp-credentials-field qp-staging-credentials qp-sensitive-field',
                'description' => '',
            ],

            'staging_client_secret' => [
                'title' => __('Client Secret', 'qoin-payment-gateway'),
                'type' => 'password',
                'default' => '',
                'class' => 'qp-credentials-field qp-staging-credentials qp-sensitive-field',
                'description' => '',
            ],

            'staging_terminal_key' => [
                'title' => __('Terminal/Merchant UID', 'qoin-payment-gateway'),
                'type' => 'password',
                'default' => '',
                'class' => 'qp-credentials-field qp-staging-credentials qp-sensitive-field',
                'description' => '',
            ],

            'debug_mode' => [
                'title' => 'Debug Mode',
                'type' => 'checkbox',
                'label' => 'Enable Debug Mode',
                'default' => 'no',
                'description' => 'Enable debug mode for troubleshooting. Logs are stored securely but should be disabled in production.',
            ],

            'global_checkout_type' => [
                'title' => 'Default Checkout Type',
                'type' => 'select',
                'options' => qp_checkout_types(),
                'default' => 'standard',
                'description' => 'Select the default checkout type for orders. Can be overridden by product or category settings.',
                'desc_tip' => true,
            ],
        ];
    }

    private function get_api_credentials_notice()
    {
        return '<div class="qp-notice-section qp-credentials-notice">
        <div class="qp-notice-content">
            <p><strong>Quantum ePay has taken care of your credentials for you.</strong></p>
            <p>We\'ve pre-configured your information for a seamless setup. For further assistance, please contact support at <strong>888-858-1678</strong>.</p>
        </div>
    </div>';
    }

    /**
     * Override process_admin_options to encrypt sensitive fields before saving
     */
    public function process_admin_options()
    {
        $posted_data = $this->get_post_data();

        // Get old credentials before processing
        $old_live_client_id = $this->get_option('live_client_id');
        $old_live_client_secret = $this->get_option('live_client_secret');
        $old_staging_client_id = $this->get_option('staging_client_id');
        $old_staging_client_secret = $this->get_option('staging_client_secret');

        foreach ($this->sensitive_fields as $field) {
            $field_key = 'woocommerce_' . $this->id . '_' . $field;

            if (isset($posted_data[$field_key])) {
                $value = $posted_data[$field_key];

                // Detect if value is masked (all dots)
                if (preg_match('/^•+$/u', $value)) {
                    // Keep the existing encrypted value (no change)
                    $existing_value = parent::get_option($field);
                    $posted_data[$field_key] = $existing_value;
                } else {
                    // Encrypt new input value before saving
                    $posted_data[$field_key] = $this->encrypt_setting($value);
                }
            }
        }

        $_POST = array_merge($_POST, $posted_data);

        // Save the options
        parent::process_admin_options();

        // Get new credentials after saving
        $new_live_client_id = $this->get_option('live_client_id');
        $new_live_client_secret = $this->get_option('live_client_secret');
        $new_staging_client_id = $this->get_option('staging_client_id');
        $new_staging_client_secret = $this->get_option('staging_client_secret');

        // Check if any credentials changed
        $credentials_changed =
            $old_live_client_id !== $new_live_client_id ||
            $old_live_client_secret !== $new_live_client_secret ||
            $old_staging_client_id !== $new_staging_client_id ||
            $old_staging_client_secret !== $new_staging_client_secret;

        // Clear transient if credentials changed
        if ($credentials_changed) {
            delete_transient('qp_token');

            // Optional: Log the credential change
            if ($this->debug_mode) {
                qp_plugin_log('API credentials changed - cleared cached token');
            }
        }

        return true;
    }


    /**
     * Override get_option to mask sensitive fields in admin
     */
    public function get_option($key, $empty_value = null)
    {
        $value = parent::get_option($key, $empty_value);

        // Only mask sensitive fields when rendering the WooCommerce settings page
        $is_settings_page = (
            isset($_GET['page'], $_GET['section']) &&
            $_GET['page'] === 'wc-settings' &&
            $_GET['section'] === $this->id
        );

        if (in_array($key, $this->sensitive_fields, true) && $is_settings_page && !wp_doing_ajax() && !empty($value)) {
            // Attempt to decrypt the value to determine its original length
            $decrypted = $this->decrypt_setting($value);

            if (!empty($decrypted)) {
                // Replace with same number of dots as decrypted length
                return str_repeat('•', strlen($decrypted));
            }

            // Fallback if decryption fails or empty
            return '••••';
        }

        return $value;
    }


    public function payment_fields()
    {
        $effective_checkout_type = qp_effective_checkout_type();
        $bypass_class = $effective_checkout_type === 'bypass' ? ' quantumepay-bypass-mode' : '';
        echo '<div class="payment_method_' . esc_attr($this->id) . $bypass_class . '">';
        if ($effective_checkout_type === 'bypass') {
            echo '<div class="woocommerce-info quantumepay-bypass-notice">';
            echo esc_html__('No payment is required for this order. Click Place Order to complete.', 'qoin-payment-gateway');
            echo '</div>';
            echo '<input type="hidden" name="payment_method" value="' . esc_attr($this->id) . '">';
        } else {
            if ($effective_checkout_type === 'pre_authorize') {
                echo '<div class="woocommerce-info quantumepay-preauth-notice">';
                echo '<strong>' . esc_html__('Pre-Authorization Notice', 'qoin-payment-gateway') . '</strong><br>';
                echo esc_html__('This transaction is a pre-authorization hold on your card. The actual charge will be processed upon order fulfillment or admin approval.', 'qoin-payment-gateway');
                echo '</div>';
            }
            echo '<div class="quantumepay-payment-fields">';
            echo '<div class="quantumepay-form-row quantumepay-form-row-wide quantumepay-form-group">
                    <label for="' . esc_attr($this->id) . '_card_number">Card Number <span class="required">*</span></label>
                    <div class="quantumepay-form-input-container">
                        <input type="text" class="quantumepay-form-control input-text" id="' . esc_attr($this->id) . '_card_number" name="' . esc_attr($this->id) . '-card-number" placeholder=" " />
                        <label class="quantumepay-form-label" for="' . esc_attr($this->id) . '_card_number">Card Number</label>
                    </div>
                  </div>
                  <div class="quantumepay-form-row">
                      <div class="quantumepay-form-group quantumepay-form-row-first quantumepay-half-width">
                          <label for="' . esc_attr($this->id) . '_card_expiry">Expiry (MM/YY) <span class="required">*</span></label>
                          <div class="quantumepay-form-input-container">
                              <input type="text" class="quantumepay-form-control input-text" id="' . esc_attr($this->id) . '_card_expiry" name="' . esc_attr($this->id) . '-card-expiry" placeholder=" " />
                              <label class="quantumepay-form-label" for="' . esc_attr($this->id) . '_card_expiry">Expiry (MM/YY)</label>
                          </div>
                      </div>
                      <div class="quantumepay-form-group quantumepay-form-row-last quantumepay-half-width">
                          <label for="' . esc_attr($this->id) . '_card_cvc">CVC <span class="required">*</span></label>
                          <div class="quantumepay-form-input-container">
                              <input type="text" class="quantumepay-form-control input-text" id="' . esc_attr($this->id) . '_card_cvc" name="' . esc_attr($this->id) . '-card-cvc" placeholder=" " />
                              <label class="quantumepay-form-label" for="' . esc_attr($this->id) . '_card_cvc">CVC</label>
                          </div>
                      </div>
                  </div>
                  <div class="quantumepay-form-group quantumepay-security-message">
                      <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
                          <path d="M18 8h-1V6c0-2.76-2.24-5-5-5S7 3.24 7 6v2H6c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V10c0-1.1-.9-2-2-2zm-6 9c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2zm3.1-9H8.9V6c0-1.71 1.39-3.1 3.1-3.1 1.71 0 3.1 1.39 3.1 3.1v2z"></path>
                      </svg>
                      Your payment information is secured with 256-bit SSL encryption.
                  </div>';
            echo '</div>';
        }
        echo '</div>';
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
        wp_enqueue_style('quantumepay-style', WC_QUANTUMEPAY_PLUGIN_URL . '/assets/css/quantumepay.css', [], $this->debug_mode ? time() : WC_QUANTUMEPAY_VERSION);
        wp_enqueue_script('quantumepay-js', WC_QUANTUMEPAY_PLUGIN_URL . '/assets/js/quantumepay.js', ['jquery'], $this->debug_mode ? time() : WC_QUANTUMEPAY_VERSION, true);
        $is_bypass = qp_effective_checkout_type() === 'bypass';
        wp_localize_script('quantumepay-js', 'quantumEPayData', [
            'is_bypass' => $is_bypass,
        ]);
        if ($is_bypass) {
            wp_add_inline_script('quantumepay-js', '
            jQuery(document).ready(function($) {
                $(".wc_payment_methods, .payment_methods").hide();
                $("#place_order").show();
            });
        ');
            wp_add_inline_script('quantumepay-js', '
            jQuery(document).ready(function($) {
                $(".wc_payment_methods, .payment_methods").hide();
                $(".payment_box.payment_method_' . esc_js($this->id) . ' .quantumepay-payment-fields").hide();
                $("#place_order").show();
                $(".quantumepay-bypass-notice").show();
            });
        ');
        }
    }

    public function validate_fields(): bool
    {
        $effective_checkout_type = qp_effective_checkout_type();
        if ($effective_checkout_type === 'bypass') {
            return true;
        }
        $gateway_id = $this->id;
        $card_number = isset($_POST[$gateway_id . '-card-number']) ? sanitize_text_field($_POST[$gateway_id . '-card-number']) : '';
        $card_expiry = isset($_POST[$gateway_id . '-card-expiry']) ? sanitize_text_field($_POST[$gateway_id . '-card-expiry']) : '';
        $card_cvc = isset($_POST[$gateway_id . '-card-cvc']) ? sanitize_text_field($_POST[$gateway_id . '-card-cvc']) : '';
        if (empty($card_number)) {
            wc_add_notice(esc_html__('Please provide a card number.', 'qoin-payment-gateway'), 'error');
            return false;
        }
        if (empty($card_expiry)) {
            wc_add_notice(esc_html__('Please provide a valid expiry date.', 'qoin-payment-gateway'), 'error');
            return false;
        }
        if (empty($card_cvc)) {
            wc_add_notice(esc_html__('Please provide the card CVC.', 'qoin-payment-gateway'), 'error');
            return false;
        }
        if (empty($this->terminal_key) && !$this->testmode) {
            wc_add_notice(esc_html__('Please provide a Terminal/Merchant UID in the gateway settings.', 'qoin-payment-gateway'), 'error');
            return false;
        }
        return true;
    }

    public function process_payment($order_id)
    {
        qp_plugin_log('Processing payment for order ' . $order_id . qp_effective_checkout_type());
        global $woocommerce;
        $order = wc_get_order($order_id);
        if (!$order) {
            $error_message = __('Invalid order.', 'qoin-payment-gateway');
            wc_add_notice($error_message, 'error');
            return [
                'result' => 'failure',
                'redirect' => '',
                'message' => $error_message,
                'messages' => '<div class="woocommerce-error">' . $error_message . '</div>'
            ];
        }
        $order_data = $order->get_data();
        $billing_data = $order_data['billing'];
        if (empty($billing_data) || empty($billing_data['first_name'])) {
            $billing_data = $order_data['shipping'];
        }
        if (empty($billing_data) || empty($billing_data['first_name'])) {
            $billing_data = [
                'first_name' => sanitize_text_field($_POST['billing_first_name'] ?? ''),
                'last_name' => sanitize_text_field($_POST['billing_last_name'] ?? ''),
                'address_1' => sanitize_text_field($_POST['billing_address_1'] ?? ''),
                'address_2' => sanitize_text_field($_POST['billing_address_2'] ?? ''),
                'city' => sanitize_text_field($_POST['billing_city'] ?? ''),
                'state' => sanitize_text_field($_POST['billing_state'] ?? ''),
                'postcode' => sanitize_text_field($_POST['billing_postcode'] ?? ''),
                'country' => sanitize_text_field($_POST['billing_country'] ?? ''),
                'email' => sanitize_email($_POST['billing_email'] ?? ''),
                'phone' => sanitize_text_field($_POST['billing_phone'] ?? '')
            ];
        }
        if (empty($billing_data)) {
            $error_message = __('Empty billing address.', 'qoin-payment-gateway');
            wc_add_notice($error_message, 'error');
            $order->update_status('wc-failed', $error_message);
            return [
                'result' => 'failure',
                'redirect' => '',
                'message' => $error_message,
                'messages' => '<div class="woocommerce-error">' . $error_message . '</div>'
            ];
        }
        $effective_checkout_type = qp_effective_checkout_type();
        if ($effective_checkout_type === 'bypass') {
            $this->has_fields = false;
            update_post_meta($order_id, '_qp_bypass', 'yes');
            $order->add_order_note(__('Order processed in Bypass mode.', 'qoin-payment-gateway'));
            $order->update_status('wc-order-submitted', __('Order submitted in Bypass mode.', 'qoin-payment-gateway'));
            $woocommerce->cart->empty_cart();
            return [
                'result' => 'success',
                'redirect' => $this->get_return_url($order),
                'messages' => '',
                'order_id' => $order_id
            ];
        }
        $gateway_id = $this->id;
        // $card_number = sanitize_text_field(trim($_POST[$gateway_id . '-card-number'] ?? ''));
        // $exp_date = sanitize_text_field(str_replace(' ', '', $_POST[$gateway_id . '-card-expiry'] ?? ''));
        // $cvv = sanitize_text_field(trim($_POST[$gateway_id . '-card-cvc'] ?? ''));
        // $exp = explode('/', $exp_date);
        // $expiry_month = $exp[0] ?? '';
        // $expiry_year = $exp[1] ?? '';
        // if (empty($card_number) || empty($exp_date) || empty($cvv) || empty($expiry_month) || empty($expiry_year)) {
        //     $error_message = __('Please provide valid card details.', 'qoin-payment-gateway');
        //     wc_add_notice($error_message, 'error');
        //     $order->update_status('wc-failed', $error_message);
        //     return [
        //         'result' => 'failure',
        //         'redirect' => '',
        //         'message' => $error_message,
        //         'messages' => '<div class="woocommerce-error">' . $error_message . '</div>'
        //     ];
        // }
        $card_number = sanitize_text_field(trim($_POST[$gateway_id . '-card-number'] ?? ''));
        $exp_date = sanitize_text_field(str_replace(' ', '', $_POST[$gateway_id . '-card-expiry'] ?? ''));
        $cvv = sanitize_text_field(trim($_POST[$gateway_id . '-card-cvc'] ?? ''));
        $exp = explode('/', $exp_date);
        $expiry_month = $exp[0] ?? '';
        $expiry_year = $exp[1] ?? '';

        // Add card data sanitization and validation
        try {
            list($card_number, $expiry_month, $expiry_year, $cvv) = $this->sanitize_card_data(
                $card_number,
                $expiry_month,
                $expiry_year,
                $cvv
            );
        } catch (\Exception $e) {
            $error_message = $e->getMessage();
            wc_add_notice($error_message, 'error');
            $order->update_status('wc-failed', $error_message);
            return [
                'result' => 'failure',
                'redirect' => '',
                'message' => $error_message,
                'messages' => '<div class="woocommerce-error">' . $error_message . '</div>'
            ];
        }
        // Use actual values for API payload
        $post_data = [
            'first_name' => $billing_data['first_name'],
            'last_name' => $billing_data['last_name'],
            'qp_cvv' => $cvv,
            'expiry_month' => $expiry_month,
            'expiry_year' => $expiry_year,
            'qp_ccNo' => $card_number,
            'billing_address' => [
                'address_1' => $billing_data['address_1'] ?? '',
                'address_2' => $billing_data['address_2'] ?? '',
                'city' => $billing_data['city'] ?? '',
                'state' => $billing_data['state'] ?? '',
                'postal_code' => $billing_data['postcode'] ?? '',
                'country_code' => $billing_data['country'] ?? ''
            ],
            'total_amount' => floatval($order_data['total']),
            'currency' => $order_data['currency'],
            'email' => $billing_data['email'],
            'phone' => $billing_data['phone'],
            'order_id' => strval($order_id)
        ];
        $post_data['idempotency_key'] = $this->generate_idempotency_key();
        // Log redacted data for debugging (optional)
        if ($this->debug_mode) {
            $log_data = $post_data;
            $log_data['qp_cvv'] = '[REDACTED]';
            $log_data['qp_ccNo'] = '[REDACTED]';
            $log_data['email'] = '[REDACTED]';
            $log_data['phone'] = '[REDACTED]';
            $log_data['billing_address'] = array_fill_keys(array_keys($log_data['billing_address']), '[REDACTED]');
            qp_plugin_log('Payment API payload: ' . qp_arr_to_json($log_data));
            qp_plugin_log('Decrypted API credentials: ' . json_encode([
                'terminal_key' => substr($this->terminal_key, 0, 4) . '...', // Partial for security
                'client_id' => substr($this->client_id, 0, 4) . '...',
                'client_secret' => substr($this->client_secret, 0, 4) . '...',
                'api_url' => $this->api_url,
                'api_url_identity' => $this->api_url_identity,
            ]));
        }
        try {

            $card_payment = new APIsCreditCard($this->terminal_key, $this->testmode, $this->client_id, $this->client_secret, $this->api_url, $this->api_url_identity);
            $response = ($effective_checkout_type === 'pre_authorize')
                ? $card_payment->authorizePayment($post_data)
                : $card_payment->processPayment($post_data);
            $response_body = qp_json_to_arr($response['body'], true);
            if ($response_body === null) {
                $error_message = __('Invalid API response format.', 'qoin-payment-gateway');
                $order->add_order_note($error_message);
                $order->update_status('wc-failed', $error_message);
                wc_add_notice($error_message, 'error');
                return [
                    'result' => 'failure',
                    'redirect' => '',
                    'message' => $error_message,
                    'messages' => '<div class="woocommerce-error">' . $error_message . '</div>'
                ];
            }
            $response_message = isset($response_body['message']) ? strtolower($response_body['message']) : '';
            $processor_message = isset($response_body['processor']['message']) ? strtolower($response_body['processor']['message']) : '';
            $response_status = isset($response_body['status']) ? strtolower($response_body['status']) : '';
            $valid_response_messages = ['approved', 'completed', 'approved or completed', 'success'];
            $valid_processor_messages = ['approved'];
            $valid_statuses = ['authorized', 'completed'];
            if (
                ($response_message && in_array($response_message, $valid_response_messages, true)) ||
                ($processor_message && in_array($processor_message, $valid_processor_messages, true)) ||
                ($response_status && in_array($response_status, $valid_statuses, true))
            ) {
                $payment_id = $response_body['payment_id'] ?? '';
                $transaction_id = $response_body['transaction_id'] ?? 'N/A';
                update_post_meta($order_id, "{$gateway_id}_payment", qp_arr_to_json($response_body));
                update_post_meta($order_id, "{$gateway_id}_payment_id", $payment_id);
                update_post_meta($order_id, "{$gateway_id}_transaction_id", $transaction_id);
                $order_note = sprintf(
                    __('Payment result: %s. Payment ID: %s. Transaction ID: %s.', 'qoin-payment-gateway'),
                    $response_body['message'],
                    $payment_id,
                    $transaction_id
                );
                $order->add_order_note($order_note);
                if ($effective_checkout_type === 'pre_authorize') {
                    $order->update_status('wc-awaiting-auth', __('Payment authorized, awaiting admin approval.', 'qoin-payment-gateway'));
                    return [
                        'result' => 'success',
                        'redirect' => $this->get_return_url($order),
                        'messages' => '',
                        'order_id' => $order_id
                    ];
                } else {
                    $order->update_status('processing', __('Payment processed via Quantum ePay.', 'qoin-payment-gateway'));
                    $order->payment_complete();
                    wc_reduce_stock_levels($order_id);
                    $woocommerce->cart->empty_cart();
                    return [
                        'result' => 'success',
                        'redirect' => $this->get_return_url($order),
                        'messages' => '',
                        'order_id' => $order_id
                    ];
                }
            } else {
                $error_message = $response_body['message'] ?? 'Payment failed.';
                if (!empty($response_body['errors'])) {
                    foreach ($response_body['errors'] as $error) {
                        $error_message .= "<br>" . esc_html("{$error['field']} - {$error['message']}");
                    }
                }
                $order->add_order_note("Payment failed: $error_message");
                $order->update_status('wc-failed', $error_message);
                wc_add_notice(__('Payment failed: ', 'qoin-payment-gateway') . $error_message, 'error');
                return [
                    'result' => 'failure',
                    'redirect' => '',
                    'message' => $error_message,
                    'messages' => '<div class="woocommerce-error">' . __('Payment failed: ', 'qoin-payment-gateway') . $error_message . '</div>'
                ];
            }
        } catch (\Exception $e) {
            $error_message = __('Payment processing failed: ', 'qoin-payment-gateway') . $e->getMessage();
            $order->add_order_note($error_message);
            $order->update_status('wc-failed', $error_message);
            wc_add_notice($error_message, 'error');
            return [
                'result' => 'failure',
                'redirect' => '',
                'message' => $error_message,
                'messages' => '<div class="woocommerce-error">' . $error_message . '</div>'
            ];
        }
    }

    public function handle_order_status_change($order_id, $old_status, $new_status)
    {
        $order = wc_get_order($order_id);
        if (!$order || $order->get_payment_method() !== $this->id) {
            return;
        }
        if (get_post_meta($order_id, '_qp_bypass', true) === 'yes' && $new_status === 'awaiting-payment' && $old_status !== 'awaiting-payment') {
            $payment_url = $this->generate_payment_link($order);
            $this->send_payment_email($order, $payment_url);
            return;
        }
        if ($old_status === 'awaiting-auth' && $new_status === 'processing') {
            $gateway_id = $this->id;
            $payment_id = get_post_meta($order_id, "{$gateway_id}_payment_id", true);
            if (empty($payment_id)) {
                $error_message = __('Capture failed: No payment ID found.', 'qoin-payment-gateway');
                $order->add_order_note($error_message);
                wc_add_notice($error_message, 'error');
                return;
            }
            try {
                $card_payment = new APIsCreditCard($this->terminal_key, $this->testmode, $this->client_id, $this->client_secret, $this->api_url, $this->api_url_identity);
                $post_data = [
                    'total_amount' => floatval($order->get_total()),
                    'order_id' => strval($order_id),
                    'source_ip_address' => qp_get_user_ip(),
                    'user_id' => $order->get_billing_email() // Use billing email
                ];
                $post_data['idempotency_key'] = $this->generate_idempotency_key();
                $response = $card_payment->capturePayment($payment_id, $post_data);
                $response_body = qp_json_to_arr($response['body'], true);
                if ($response_body === null) {
                    $error_message = __('Capture failed: Invalid API response format.', 'qoin-payment-gateway');
                    $order->add_order_note($error_message);
                    $order->update_status('wc-failed', $error_message);
                    wc_add_notice($error_message, 'error');
                    return;
                }
                $response_message = isset($response_body['message']) ? strtolower($response_body['message']) : '';
                $valid_response_messages = ['approved', 'completed', 'approved or completed'];
                if (in_array($response_message, $valid_response_messages, true)) {
                    $transaction_id = $response_body['transaction_id'] ?? 'N/A';
                    update_post_meta($order_id, "{$gateway_id}_capture_payment", qp_arr_to_json($response_body));
                    update_post_meta($order_id, "{$gateway_id}_capture_transaction_id", $transaction_id);
                    $order->add_order_note(sprintf(__('Payment captured. Transaction ID: %s', 'qoin-payment-gateway'), $transaction_id));
                    $order->update_status('wc-processing', __('Payment captured via Quantum ePay.', 'qoin-payment-gateway'));
                    wc_reduce_stock_levels($order_id);
                } else {
                    $error_message = $response_body['message'] ?? 'Capture failed.';
                    if (!empty($response_body['errors'])) {
                        foreach ($response_body['errors'] as $error) {
                            $error_message .= "<br>" . esc_html("{$error['field']} - {$error['message']}");
                        }
                    }
                    $order->add_order_note("Capture failed: $error_message");
                    $order->update_status('wc-failed', $error_message);
                    wc_add_notice($error_message, 'error');
                }
            } catch (Exception $e) {
                $error_message = __('Capture processing failed: ', 'qoin-payment-gateway') . $e->getMessage();
                $order->add_order_note($error_message);
                $order->update_status('wc-failed', $error_message);
                wc_add_notice($error_message, 'error');
            }
            if (!wp_doing_ajax()) {
                wp_safe_redirect(admin_url('post.php?post=' . $order_id . '&action=edit'));
                exit;
            }
        }
    }

    public function process_refund($order_id, $amount = null, $reason = '')
    {
        $order = wc_get_order($order_id);
        if (!$order) {
            return new WP_Error('refund_failed', __('Invalid order.', 'qoin-payment-gateway'));
        }
        $gateway_id = $this->id;
        $card_payment = new APIsCreditCard($this->terminal_key, $this->testmode, $this->client_id, $this->client_secret, $this->api_url, $this->api_url_identity);
        $payment_id = get_post_meta($order_id, "{$gateway_id}_payment_id", true);
        $user_id = $order->get_billing_email(); // Use billing email
        if (empty($payment_id)) {
            $error_message = __('Refund failed: No payment ID found.', 'qoin-payment-gateway');
            $order->add_order_note($error_message);
            return new WP_Error('refund_failed', $error_message);
        }
        $pending_settlement = $card_payment->isPaymentSettled($payment_id);
        if (!$pending_settlement) {
            $post_data = [
                'user_id' => $user_id,
                'order_id' => strval($order_id),
                'source_ip_address' => qp_get_user_ip()
            ];
            $post_data['idempotency_key'] = $this->generate_idempotency_key();
            $response = $card_payment->processReversal($payment_id, $post_data);
            $response_body = qp_json_to_arr($response['body'], true);
            if ($response_body === null) {
                $error_message = __('Reversal failed: Invalid API response format.', 'qoin-payment-gateway');
                $order->add_order_note($error_message);
                return new WP_Error('reversal_failed', $error_message);
            }
            if (isset($response_body['status']) && strtolower($response_body['status']) === 'reversed') {
                $order->add_order_note(sprintf(__('Order reversed via %s Payment Gateway.', 'qoin-payment-gateway'), $this->method_title));
                return true;
            } else {
                $error_message = $response_body['message'] ?? 'Reversal failed.';
                if (!empty($response_body['errors'])) {
                    foreach ($response_body['errors'] as $error) {
                        $error_message .= "<br>" . esc_html("{$error['field']} - {$error['message']}");
                    }
                }
                $order->add_order_note("Reversal failed: $error_message");
                return new WP_Error('reversal_failed', __('Reversal processing failed: ', 'qoin-payment-gateway') . $error_message);
            }
        }
        $post_data = [
            'amount' => floatval($amount ?: $order->get_total()),
            'order_id' => strval($order_id),
            'user_id' => $user_id,
            'source_ip_address' => qp_get_user_ip()
        ];
        $post_data['idempotency_key'] = $this->generate_idempotency_key();
        $response = $card_payment->processRefund($payment_id, $post_data);
        $response_body = qp_json_to_arr($response['body'], true);
        if ($response_body === null) {
            $error_message = __('Refund failed: Invalid API response format.', 'qoin-payment-gateway');
            $order->add_order_note($error_message);
            return new WP_Error('refund_failed', $error_message);
        }
        if (isset($response_body['message']) && in_array(strtolower($response_body['message']), ['approved', 'completed'], true)) {
            $transaction_id = $response_body['transaction_id'] ?? 'N/A';
            $order_note = sprintf(
                __('Refund processed successfully. Amount: %s. Payment ID: %s. Transaction ID: %s.', 'qoin-payment-gateway'),
                wc_price($post_data['amount']),
                $payment_id,
                $transaction_id
            );
            $order->add_order_note($order_note);
            update_post_meta($order_id, "{$gateway_id}_refund", qp_arr_to_json($response_body));
            return true;
        } else {
            $error_message = $response_body['message'] ?? 'Refund failed.';
            if (!empty($response_body['errors'])) {
                foreach ($response_body['errors'] as $error) {
                    $error_message .= "<br>" . esc_html("{$error['field']} - {$error['message']}");
                }
            }
            $order->add_order_note("Refund failed: $error_message");
            return new WP_Error('refund_failed', __('Refund processing failed: ', 'qoin-payment-gateway') . $error_message);
        }
    }

    public function scheduled_subscription_payment($amount_to_charge, $renewal_order)
    {
        if (!$this->recurring_enabled) {
            $renewal_order->add_order_note(__('Recurring payments are disabled in gateway settings.', 'qoin-payment-gateway'));
            return new WP_Error('subscription_disabled', __('Recurring payments are disabled.', 'qoin-payment-gateway'));
        }
        $result = $this->process_subscription_payment($renewal_order, $amount_to_charge);
        if (is_wp_error($result)) {
            \WC_Subscriptions_Manager::process_subscription_payment_failure_on_order($renewal_order);
        } else {
            \WC_Subscriptions_Manager::process_subscription_payments_on_order($renewal_order);
        }
    }

    public function process_subscription_payment($renewal_order, $amount_to_charge)
    {
        $order_id = $renewal_order->get_id();
        $subscriptions = wcs_get_subscriptions_for_order($order_id, ['order_type' => 'any']);
        $parent_order = null;
        foreach ($subscriptions as $subscriptionObj) {
            $parent_order = $subscriptionObj->get_parent();
            break;
        }
        if (!$parent_order) {
            $error_message = __('No parent order found for subscription.', 'qoin-payment-gateway');
            $renewal_order->add_order_note($error_message);
            return new WP_Error('subscription_failed', $error_message);
        }
        $payment_id = get_post_meta($parent_order->get_id(), $this->id . '_payment_id', true);
        if (empty($payment_id)) {
            $error_message = __('No payment ID found for subscription.', 'qoin-payment-gateway');
            $renewal_order->add_order_note($error_message);
            return new WP_Error('subscription_failed', $error_message);
        }
        $currency = $renewal_order->get_currency();
        $user_id = $renewal_order->get_billing_email(); // Use billing email
        $post_data = [
            'amount' => floatval($amount_to_charge),
            'currency' => $currency,
            'credential_on_file' => ['initiated_by' => 'merchant'],
            'source_ip_address' => qp_get_user_ip(),
            'user_id' => $user_id
        ];
        $post_data['idempotency_key'] = $this->generate_idempotency_key();
        try {
            $card_payment = new APIsCreditCard($this->terminal_key, $this->testmode, $this->client_id, $this->client_secret, $this->api_url, $this->api_url_identity);
            $response = $card_payment->processRebill($payment_id, $post_data);
            $response_body = qp_json_to_arr($response['body'], true);
            if ($response_body === null) {
                $error_message = __('Invalid API response format for subscription.', 'qoin-payment-gateway');
                $renewal_order->add_order_note($error_message);
                return new WP_Error('subscription_failed', $error_message);
            }
            if (isset($response_body['message']) && in_array(strtolower($response_body['message']), ['approved', 'completed'], true)) {
                $payment_id = $response_body['payment_id'] ?? '';
                $transaction_id = $response_body['transaction_id'] ?? 'N/A';
                update_post_meta($order_id, $this->id . '_payment', qp_arr_to_json($response_body));
                update_post_meta($order_id, $this->id . '_payment_id', $payment_id);
                update_post_meta($order_id, $this->id . '_transaction_id', $transaction_id);
                $order_note = sprintf(
                    __('Payment result: %s. Payment ID: %s. Transaction ID: %s.', 'qoin-payment-gateway'),
                    $response_body['message'],
                    $payment_id,
                    $transaction_id
                );
                $renewal_order->add_order_note($order_note);
                $renewal_order->payment_complete();
                wc_reduce_stock_levels($order_id);
                return ['result' => 'success'];
            } else {
                $error_message = $response_body['message'] ? $response_body['message'] : 'Subscription payment failed.';
                if (!empty($response_body['errors'])) {
                    foreach ($response_body['errors'] as $error) {
                        $error_message .= "<br>" . esc_html("{$error['field']} - {$error['message']}");
                    }
                }
                $renewal_order->add_order_note("Error with payment: $error_message");
                $subscriptions[0]->add_order_note("Error with payment: $error_message");
                return new WP_Error('subscription_failed', $error_message);
            }
        } catch (Exception $e) {
            $error_message = __('Subscription payment failed: ', 'qoin-payment-gateway') . $e->getMessage();
            $renewal_order->add_order_note($error_message);
            $subscriptions[0]->add_order_note($error_message);
            return new WP_Error('subscription_failed', $error_message);
        }
    }

    public function add_product_checkout_type_field()
    {
        $available_types = qp_checkout_types();
        woocommerce_wp_select([
            'id' => '_quantumepay_checkout_type',
            'label' => 'Checkout Type',
            'options' => $available_types,
            'description' => 'Select the checkout type for this product. Overrides category/global settings.',
            'desc_tip' => true,
        ]);
    }

    public function save_product_checkout_type_field($post_id)
    {
        $checkout_type = isset($_POST['_quantumepay_checkout_type']) ? sanitize_text_field($_POST['_quantumepay_checkout_type']) : '';
        update_post_meta($post_id, '_quantumepay_checkout_type', $checkout_type);
    }

    public function add_category_checkout_type_field()
    {
        $available_types = qp_checkout_types();
?>
        <div class="form-field">
            <label for="quantumepay_checkout_type">Checkout Type</label>
            <select name="quantumepay_checkout_type" id="quantumepay_checkout_type">
                <?php foreach ($available_types as $key => $label) : ?>
                    <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
            </select>
            <p class="description">Select the checkout type for this category. Overrides global settings but is overridden by product settings.</p>
        </div>
    <?php
    }

    public function edit_category_checkout_type_field($term)
    {
        $available_types = qp_checkout_types();
        $value = get_term_meta($term->term_id, 'quantumepay_checkout_type', true);
    ?>
        <tr class="form-field">
            <th><label for="quantumepay_checkout_type">Checkout Type</label></th>
            <td>
                <select name="quantumepay_checkout_type" id="quantumepay_checkout_type">
                    <?php foreach ($available_types as $key => $label) : ?>
                        <option value="<?php echo esc_attr($key); ?>" <?php selected($value, $key); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="description">Select the checkout type for this category. Overrides global settings but is overridden by product settings.</p>
            </td>
        </tr>
<?php
    }

    public function save_category_checkout_type_field($term_id)
    {
        $checkout_type = isset($_POST['quantumepay_checkout_type']) ? sanitize_text_field($_POST['quantumepay_checkout_type']) : '';
        update_term_meta($term_id, 'quantumepay_checkout_type', $checkout_type);
    }

    public function add_custom_order_statuses($statuses)
    {
        $statuses['wc-awaiting-auth'] = 'Awaiting Authorization';
        $statuses['wc-order-submitted'] = 'Order Submitted';
        $statuses['wc-awaiting-payment'] = 'Awaiting Payment';
        return $statuses;
    }

    public function add_bypass_notice()
    {
        if (qp_effective_checkout_type() === 'bypass') {
            // echo '<div class="woocommerce-info">' . esc_html__('No payment is required for this order. Click Place Order to complete.', 'qoin-payment-gateway') . '</div>';
        }
    }

    public function filter_available_gateways($gateways)
    {
        $effective_checkout_type = qp_effective_checkout_type();
        if ($effective_checkout_type === 'bypass') {
            foreach ($gateways as $id => $gateway) {
                if ($id !== $this->id) {
                    unset($gateways[$id]);
                }
            }
            if (isset($gateways[$this->id])) {
                $gateways[$this->id]->description = __('No payment required. Click Place Order to submit.', 'qoin-payment-gateway');
                $gateways[$this->id]->has_fields = false;
            }
        }
        return $gateways;
    }

    public function cart_needs_payment($needs_payment, $cart)
    {
        if (qp_effective_checkout_type() === 'bypass' && WC()->session->get('chosen_payment_method') === $this->id) {
            return true;
        }
        return $needs_payment;
    }

    public function force_payment_method_for_bypass($data)
    {
        if (qp_effective_checkout_type() === 'bypass') {
            $data['payment_method'] = $this->id;
        }
        return $data;
    }

    public function maybe_remove_payment_fields($fields)
    {
        if (qp_effective_checkout_type() === 'bypass') {
            unset($fields['billing']['payment_method']);
            if (isset($fields['order'])) {
                unset($fields['order']['payment_method']);
            }
        }
        return $fields;
    }

    public function bypass_order_payment($needs_payment, $order, $valid_order_statuses)
    {
        if (qp_effective_checkout_type() === 'bypass' && $order->get_payment_method() === $this->id) {
            return true;
        }
        return $needs_payment;
    }

    public function handle_rest_order_response($response, $order, $request)
    {
        if (qp_effective_checkout_type() === 'bypass') {
            $data = $response->get_data();
            $data['payment_method'] = 'quantumepay';
            $response->set_data($data);
        }
        return $response;
    }

    public function handle_rest_order_creation($order, $request, $creating)
    {
        if (qp_effective_checkout_type() === 'bypass' && $creating) {
            $order->set_payment_method('quantumepay');
            $order->save();
        }
    }

    public function qp_remove_payment_method_selection()
    {
        if (qp_effective_checkout_type() === 'bypass') {
            echo '<style>
                li.wc_payment_method.payment_method_quantumepay > input,
                li.wc_payment_method.payment_method_quantumepay > label {
                    display: none !important;
                }
                .payment_box.payment_method_quantumepay {
                    display: block !important;
                    visibility: visible !important;
                    opacity: 1 !important;
                    margin: 0 !important;
                    padding: 0 !important;
                    border: none !important;
                    background: none !important;
                }
                .payment_box.payment_method_quantumepay .quantumepay-payment-fields {
                    display: none !important;
                }
                .payment_box.payment_method_quantumepay .quantumepay-bypass-notice {
                    display: block !important;
                    margin: 0 0 20px 0 !important;
                }
                .payment_box.payment_method_quantumepay::before {
                    content: none !important;
                    border: 0 !important;
                }
                    .woocommerce-info.quantumepay-bypass-notice::before {
                        content: none !important;
                    }
            </style>';
        }
    }

    public function register_rest_api_fields()
    {
        register_rest_field(
            'shop_order',
            'quantumepay_bypass',
            array(
                'get_callback' => function ($object) {
                    return get_post_meta($object['id'], 'quantumepay_bypass', true);
                },
                'update_callback' => function ($value, $object) {
                    update_post_meta($object->ID, 'quantumepay_bypass', $value);
                },
                'schema' => array(
                    'type' => 'boolean',
                    'description' => 'Whether this order was placed in bypass mode.',
                ),
            )
        );
    }

    public function pre_order_handle_rest_order_creation($order, $request, $creating)
    {
        if ($creating && qp_effective_checkout_type() === 'bypass') {
            $order->set_payment_method($this->id);
            $order->set_payment_method_title('Quantum ePay (Bypass Mode)');
            $order->update_meta_data('quantumepay_bypass', true);
        }
        return $order;
    }

    private function generate_payment_link($order)
    {
        $order_id = $order->get_id();
        $terminal_key = $this->terminal_key;
        $page_slug = 'pay-order-' . $order_id . '-' . wp_generate_password(8, false);
        $payment_url = home_url("/{$page_slug}/");
        $transient_key = "qp_form_data_{$page_slug}";
        $transient_data = [
            'gwlogin' => $terminal_key,
            'item_cost' => number_format($order->get_total(), 2),
            'item_description' => 'Payment for Order #' . $order_id,
            'invoice_num' => $order_id,
            'item_qty' => 1,
            'post_return_url' => $order->get_checkout_order_received_url(),
            'first_name' => $order->get_billing_first_name(),
            'last_name' => $order->get_billing_last_name(),
            'email' => $order->get_billing_email(), // Use actual email
            'phone' => $order->get_billing_phone(), // Use actual phone
            'company' => $order->get_billing_company(),
        ];
        set_transient($transient_key, $transient_data, 24 * HOUR_IN_SECONDS);
        return $payment_url;
    }

    private function send_payment_email($order, $payment_url)
    {
        $mailer = WC()->mailer();
        $subject = sprintf(
            __('Payment Request for Order #%s', 'qoin-payment-gateway'),
            $order->get_order_number()
        );
        ob_start();
        $template_path = QP_FRONTEND_VIEWS . 'email/payment-request.php';
        if (file_exists($template_path)) {
            include $template_path;
        }
        $message_body = ob_get_clean();
        $message = $mailer->wrap_message($subject, $message_body);
        $mailer->send(
            $order->get_billing_email(), // Use actual billing email
            $subject,
            $message
        );
        $order->add_order_note(
            sprintf(
                __('Payment link sent to customer: %s', 'qoin-payment-gateway'),
                esc_url($payment_url)
            )
        );
    }

    public function register_payment_link_endpoint()
    {
        add_rewrite_rule(
            '^pay-order-([0-9]+)-([a-zA-Z0-9]+)?$',
            'index.php?qp_pay_order=$matches[1]&qp_slug=$matches[2]',
            'top'
        );
        add_filter('query_vars', function ($vars) {
            $vars[] = 'qp_pay_order';
            $vars[] = 'qp_slug';
            return $vars;
        });
        if (get_option('qp_rewrite_rules_flushed') !== 'yes') {
            flush_rewrite_rules();
            update_option('qp_rewrite_rules_flushed', 'yes');
        }
    }

    public function handle_payment_link_page()
    {
        // Only handle if both query vars are present
        if (!get_query_var('qp_pay_order') || !get_query_var('qp_slug')) {
            return; // Exit silently so it doesn’t interfere with other pages
        }

        $order_id = get_query_var('qp_pay_order');
        $slug = get_query_var('qp_slug');

        $transient_key = 'qp_form_data_pay-order-' . $order_id . '-' . $slug;
        $form_data = get_transient($transient_key);
        if (!$form_data) {
            wp_die('Payment link expired or invalid.');
        }

        $order = wc_get_order($order_id);
        if (
            !$order ||
            $order->get_payment_method() !== $this->id ||
            get_post_meta($order_id, '_qp_bypass', true) !== 'yes'
        ) {
            wp_die('Invalid order or payment method.');
        }

        $template_path = plugin_dir_path(__FILE__) . '../../views/frontend/payment-form.php';
        if (!file_exists($template_path)) {
            wp_die('Payment form template not found.');
        }

        include $template_path;
        exit;
    }


    public function process_payment_link()
    {
        if (!isset($_POST['action']) || $_POST['action'] !== 'qp_process_payment' || !isset($_POST['order_id']) || !isset($_POST['qp_slug'])) {
            wp_die('Invalid request.');
        }
        $order_id = intval($_POST['order_id']);
        $slug = sanitize_text_field($_POST['qp_slug']);
        $order = wc_get_order($order_id);
        if (!$order || $order->get_payment_method() !== $this->id || get_post_meta($order_id, '_qp_bypass', true) !== 'yes') {
            wp_die('Invalid order or payment method.');
        }
        $transient_key = 'qp_form_data_pay-order-' . $order_id . '-' . $slug;
        $form_data = get_transient($transient_key);
        if (!$form_data) {
            wp_die('Payment link expired.');
        }
        $total_amount = floatval($order->get_total());
        if ($total_amount == 0) {
            $order->add_order_note(__('Bypass order with zero amount, no payment required.', 'qoin-payment-gateway'));
            $order->update_status('wc-processing', __('Bypass order processed without payment.', 'qoin-payment-gateway'));
            $order->payment_complete();
            wc_reduce_stock_levels($order_id);
            delete_transient($transient_key);
            wp_safe_redirect($order->get_checkout_order_received_url());
            exit;
        }
        // $card_number = sanitize_text_field(trim($_POST['card_number'] ?? ''));
        // $exp_date = sanitize_text_field(str_replace(' ', '', $_POST['card_expiry'] ?? ''));
        // $cvv = sanitize_text_field(trim($_POST['card_cvc'] ?? ''));
        // $exp = explode('/', $exp_date);
        // $expiry_month = $exp[0] ?? '';
        // $expiry_year = isset($exp[1]) ? '20' . $exp[1] : '';
        // if (empty($card_number) || empty($exp_date) || empty($cvv) || empty($expiry_month) || empty($expiry_year)) {
        //     $order->add_order_note('Payment failed: Invalid card details.');
        //     wp_die('Please provide valid card details.');
        // }
        $card_number = sanitize_text_field(trim($_POST['card_number'] ?? ''));
        $exp_date = sanitize_text_field(str_replace(' ', '', $_POST['card_expiry'] ?? ''));
        $cvv = sanitize_text_field(trim($_POST['card_cvc'] ?? ''));
        $exp = explode('/', $exp_date);
        $expiry_month = $exp[0] ?? '';
        $expiry_year = isset($exp[1]) ? $exp[1] : '';

        // Add card data sanitization and validation
        try {
            list($card_number, $expiry_month, $expiry_year, $cvv) = $this->sanitize_card_data(
                $card_number,
                $expiry_month,
                $expiry_year,
                $cvv
            );
        } catch (\Exception $e) {
            $error_message = $e->getMessage();
            $order->add_order_note('Payment failed: ' . $error_message);
            wp_die('Payment failed: ' . $error_message);
        }
        // Use actual values for API payload
        $post_data = [
            'first_name' => $form_data['first_name'],
            'last_name' => $form_data['last_name'],
            'qp_cvv' => $cvv,
            'expiry_month' => $expiry_month,
            'expiry_year' => $expiry_year,
            'qp_ccNo' => $card_number,
            'billing_address' => [
                'address_1' => $order->get_billing_address_1() ?? '',
                'address_2' => $order->get_billing_address_2() ?? '',
                'city' => $order->get_billing_city() ?? '',
                'state' => $order->get_billing_state() ?? '',
                'postal_code' => $order->get_billing_postcode() ?? '',
                'country_code' => $order->get_billing_country() ?? ''
            ],
            'total_amount' => $total_amount,
            'currency' => $order->get_currency(),
            'email' => $order->get_billing_email(),
            'phone' => $order->get_billing_phone(),
            'order_id' => strval($order_id)
        ];
        $post_data['idempotency_key'] = $this->generate_idempotency_key();
        // Log redacted data for debugging (optional)
        if ($this->debug_mode) {
            $log_data = $post_data;
            $log_data['qp_cvv'] = '[REDACTED]';
            $log_data['qp_ccNo'] = '[REDACTED]';
            $log_data['email'] = '[REDACTED]';
            $log_data['phone'] = '[REDACTED]';
            $log_data['billing_address'] = array_fill_keys(array_keys($log_data['billing_address']), '[REDACTED]');
            qp_plugin_log('Payment link API payload: ' . qp_arr_to_json($log_data));
            qp_plugin_log('urls ' . $this->api_url . ' ' . $this->api_url_identity);
        }
        try {
            $card_payment = new APIsCreditCard($this->terminal_key, $this->testmode, $this->client_id, $this->client_secret, $this->api_url, $this->api_url_identity);
            $response = $card_payment->processPayment($post_data);
            $response_body = qp_json_to_arr($response['body'], true);
            if ($response_body === null) {
                $error_message = __('Payment failed: Invalid API response format.', 'qoin-payment-gateway');
                $order->add_order_note($error_message);
                $order->update_status('wc-failed', $error_message);
                wp_die($error_message);
            }
            $response_message = isset($response_body['message']) ? strtolower($response_body['message']) : '';
            $valid_response_messages = ['approved', 'completed', 'approved or completed'];
            if (in_array($response_message, $valid_response_messages, true)) {
                $payment_id = $response_body['payment_id'] ?? '';
                $transaction_id = $response_body['transaction_id'] ?? 'N/A';
                update_post_meta($order_id, "{$this->id}_payment", qp_arr_to_json($response_body));
                update_post_meta($order_id, "{$this->id}_payment_id", $payment_id);
                update_post_meta($order_id, "{$this->id}_transaction_id", $transaction_id);
                $order->add_order_note(sprintf(__('Payment processed. Transaction ID: %s', 'qoin-payment-gateway'), $transaction_id));
                $order->update_status('wc-processing', __('Payment processed via Quantum ePay.', 'qoin-payment-gateway'));
                $order->payment_complete();
                wc_reduce_stock_levels($order_id);
                delete_transient($transient_key);
                wp_safe_redirect($order->get_checkout_order_received_url());
                exit;
            } else {
                $error_message = $response_body['message'] ?? 'Payment failed.';
                if (!empty($response_body['errors'])) {
                    foreach ($response_body['errors'] as $error) {
                        $error_message .= "<br>" . esc_html("{$error['field']} - {$error['message']}");
                    }
                }
                $order->add_order_note("Payment failed: $error_message");
                $order->update_status('wc-failed', $error_message);
                wp_die('Payment failed: ' . $error_message);
            }
        } catch (\Exception $e) {
            $error_message = __('Payment processing failed: ', 'qoin-payment-gateway') . $e->getMessage();
            $order->add_order_note($error_message);
            $order->update_status('wc-failed', $error_message);
            wp_die($error_message);
        }
    }

    public function remove_pay_button_for_bypass_orders($actions, $order)
    {
        if (
            $order->get_payment_method() === 'quantumepay' &&
            $order->get_meta('_qp_bypass') === 'yes'
        ) {
            unset($actions['pay']);
        }
        return $actions;
    }

    public function hide_pay_button_css()
    {
        if (is_wc_endpoint_url('order-received')) {
            global $wp;
            $order_id = absint($wp->query_vars['order-received']);
            $order = wc_get_order($order_id);
            if (
                $order && $order->get_payment_method() === $this->id &&
                $order->get_meta('_qp_bypass') === 'yes'
            ) {
                echo '<style>.woocommerce-order-details__actions .button.pay { display: none !important; }</style>';
            }
        }
    }
    private function generate_idempotency_key()
    {
        return $this->id . '_' . uniqid() . '_' . time();
    }
}
