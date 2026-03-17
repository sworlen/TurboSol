<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

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

define('APP_DEBUG', false);

function app_bootstrap_fail(string $message): void
{
    http_response_code(500);

    if (APP_DEBUG) {
        echo 'TurboSol bootstrap error: ' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    } else {
        echo 'TurboSol temporary error. Check config.php DB/API values and try again.';
    }
    exit;
}

if (!class_exists('mysqli')) {
    app_bootstrap_fail('PHP extension mysqli is not available on this hosting account.');
}

mysqli_report(MYSQLI_REPORT_OFF);
$conn = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

if ($conn->connect_error) {
    error_log('TurboSol DB connection failed: ' . $conn->connect_error);
    app_bootstrap_fail('Database connection failed.');
}

if (!$conn->set_charset('utf8mb4')) {
    error_log('TurboSol charset set failed: ' . $conn->error);
}

// Toto je jediný soubor, který upravuješ ručně po nahrání na hosting
