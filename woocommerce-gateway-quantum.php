<?php
/*
Plugin Name: Qoin - Payment Gateway
Description: Accept credit card payments with Qoin, the next generation WooCommerce payment gateway. Only from Quantum ePay.
Author: Quantum ePay
Version: 2.0.0
*/

defined('ABSPATH') or die('No script kiddies please!');
define('WC_QUANTUMEPAY_DEBUG_MODE', 'development');
define('WC_QUANTUMEPAY_VERSION', '2.0.0');
define('WC_QUANTUMEPAY_MIN_PHP_VER', '7.1');
define('WC_QUANTUMEPAY_MIN_WC_VER', '2.5.0');
define('WC_QUANTUMEPAY_MAIN_FILE', __FILE__);
define('WC_QUANTUMEPAY_PLUGIN_URL', untrailingslashit(plugins_url(basename(plugin_dir_path(__FILE__)), basename(__FILE__))));
define('WC_QUANTUMEPAY_PLUGIN_DIR', plugin_dir_path(__FILE__));
require_once __DIR__ . "/vendor/autoload.php";

use WooQuantum\App;
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$myUpdateChecker = PucFactory::buildUpdateChecker(
    'https://github.com/quantumepay/woocommerce-plugin',
    __FILE__,
    'woocommerce-plugin'
);

// Optional: specify where to get release metadata (use GitHub releases)
$myUpdateChecker->getVcsApi()->enableReleaseAssets();


$app = new App();
