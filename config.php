<?php
session_start();

define('DB_HOST', 'sql.infinityfree.com');
define('DB_USER', 'your_db_user');
define('DB_PASS', 'your_db_password');
define('DB_NAME', 'your_db_name');

define('FAUCETPAY_API_KEY', 'your_faucetpay_api_key');
define('TURNSTILE_SITE_KEY', 'your_turnstile_site_key');
define('TURNSTILE_SECRET_KEY', 'your_turnstile_secret_key');
define('ADMIN_PASSWORD', 'Strong_Admin_Password_Placeholder_ChangeMe123!');

define('DEFAULT_CLAIM_INTERVAL_MIN', 30);
define('MIN_SOL_AMOUNT', 0.00005);
define('MAX_SOL_AMOUNT', 0.0003);
define('REFERRAL_PERCENT', 25);
define('CURRENCY', 'SOL');

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    die('Database connection failed: ' . $conn->connect_error);
}

// Toto je jediný soubor, který upravuješ ručně po nahrání na hosting
