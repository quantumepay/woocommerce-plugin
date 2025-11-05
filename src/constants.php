<?php

// define any constants here which will be unique for plugin

define("QP_BACKEND_VIEWS", WC_QUANTUMEPAY_PLUGIN_DIR . '/src/views/backend/pages/');
define("QP_FRONTEND_VIEWS", WC_QUANTUMEPAY_PLUGIN_DIR . '/src/views/frontend/');

define("QP_BACKEND_ASSETS", WC_QUANTUMEPAY_PLUGIN_URL . '/assets/backend/');

define("QP_NOTICES", 'st_notices');
define("QP_FORM_SETTINGS", "st_settings");

define("QP_GATEWAY_ID", "quantumepay");

//testing secret
// define("TEST_API_URL", "https://paymentsuat.quantumepay.com/");
// define("TEST_API_URL_IDENTITY", "https://identityuat.quantumepay.com/connect/token");
// define("TESTING_CLIENT_ID", "client_6u03mdavh1gkavj1f58w009xmq5rdvru");
// define("TESTING_CLIENT_SECRET", "qz0w49mav8afb06aaok5s90pdixxi26y");
// define("TESTING_XTERMINAL_KEY", "26d3b75c-9ce4-4bcc-acce-c744da25f0ea");
// // define("TESTING_XTERMINAL_KEY", "");
// //live secret are here
// define("LIVE_API_URL", "https://payments.quantumepay.com/");
// define("LIVE_API_URL_IDENTITY", "https://identity.quantumepay.com/connect/token");
// define("LIVE_CLIENT_ID", "client_2be5c58f03484229ac16e6e26ec0ca37"); //this is for live client id
// define("LIVE_CLIENT_SECRET", "b455bb58beea40a29c3e3034d7ce8666"); // this is for live client secret
