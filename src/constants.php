<?php

// define any constants here which will be unique for plugin

define("QP_BACKEND_VIEWS", WC_QUANTUMEPAY_PLUGIN_DIR . '/src/views/backend/pages/');
define("QP_FRONTEND_VIEWS", WC_QUANTUMEPAY_PLUGIN_DIR . '/src/views/frontend/');

define("QP_BACKEND_ASSETS", WC_QUANTUMEPAY_PLUGIN_URL . '/assets/backend/');

define("QP_NOTICES", 'st_notices');
define("QP_FORM_SETTINGS", "st_settings");

define("QP_GATEWAY_ID", "quantumepay");

//testing secret
define("TEST_API_URL", "https://uatpayments.quantumepay.com/");
define("TEST_API_URL_IDENTITY", "https://uatidentity.quantumepay.com/connect/token");
define("TESTING_CLIENT_ID", "client_6a2b0b8c-690c-4c68-848a-539f7547c63a");
define("TESTING_CLIENT_SECRET", "secret");
define("TESTING_XTERMINAL_KEY", "110d0ad1-8e78-49f7-8784-66fa548af52b");
// define("TESTING_XTERMINAL_KEY", "");
//live secret are here
define("LIVE_API_URL", "https://payments.quantumepay.com/");
define("LIVE_API_URL_IDENTITY", "https://identity.quantumepay.com/connect/token");
define("LIVE_CLIENT_ID", "client_2be5c58f03484229ac16e6e26ec0ca37"); //this is for live client id
define("LIVE_CLIENT_SECRET", "b455bb58beea40a29c3e3034d7ce8666"); // this is for live client secret
