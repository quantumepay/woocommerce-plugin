<?php
/*
Plugin Name: Qoin - Payment Gateway
Description: Accept credit card payments with Qoin, the next generation WooCommerce payment gateway. Only from Quantum ePay.
Author: Quantum ePay
Version: 1.4.3
*/

defined('ABSPATH') or die('No script kiddies please!');

define('WC_QUANTUMEPAY_VERSION', '1.4.3');
define('WC_QUANTUMEPAY_MIN_PHP_VER', '5.3.0');
define('WC_QUANTUMEPAY_MIN_WC_VER', '2.5.0');
define('WC_QUANTUMEPAY_MAIN_FILE', __FILE__);
define('WC_QUANTUMEPAY_PLUGIN_URL', untrailingslashit(plugins_url(basename(plugin_dir_path(__FILE__)), basename(__FILE__))));
define('WC_QUANTUMEPAY_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WC_QUANTUMEPAY_UPDATE_REPO', 'quantumepay/woocommerce-plugin');
define('WC_QUANTUMEPAY_UPDATE_BRANCH', 'main');
define('WC_QUANTUMEPAY_UPDATE_ASSET_NAME', 'woocommerce-gateway-quantum.zip');
require_once __DIR__ . "/vendor/autoload.php";

use WooQuantum\App;

$app = new App();
