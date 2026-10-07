<?php
/*
Plugin Name: Qoin - Payment Gateway
Description: Accept credit card payments with Qoin, the next generation WooCommerce payment gateway. Only from Quantum ePay.
Author: Quantum ePay
Version: 2.1.0
Requires PHP: 7.1
WC requires at least: 3.3
*/

defined('ABSPATH') or die('No script kiddies please!');

define('WC_QUANTUMEPAY_VERSION', '2.1.0');
define('WC_QUANTUMEPAY_MIN_PHP_VER', '7.1.0');
define('WC_QUANTUMEPAY_MIN_WC_VER', '3.3.0');
define('WC_QUANTUMEPAY_MAIN_FILE', __FILE__);
define('WC_QUANTUMEPAY_PLUGIN_URL', untrailingslashit(plugins_url(basename(plugin_dir_path(__FILE__)), basename(__FILE__))));
define('WC_QUANTUMEPAY_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WC_QUANTUMEPAY_UPDATE_REPO', 'quantumepay/woocommerce-plugin');
define('WC_QUANTUMEPAY_UPDATE_BRANCH', 'main');
define('WC_QUANTUMEPAY_UPDATE_ASSET_NAME', 'woocommerce-gateway-quantum.zip');
if (version_compare(PHP_VERSION, WC_QUANTUMEPAY_MIN_PHP_VER, '<')) {
    add_action('admin_notices', function () {
        echo '<div class="notice notice-error"><p>Qoin - Payment Gateway requires PHP 7.1 or newer. Please update PHP before using this plugin.</p></div>';
    });
    return;
}

require_once __DIR__ . "/vendor/autoload.php";

use WooQuantum\App;

$app = new App();
	