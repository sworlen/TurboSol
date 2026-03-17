<?php
require 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    die('Method not allowed.');
}

function log_event(mysqli $conn, string $action, string $details): void
{
    $actionSafe = mysqli_real_escape_string($conn, $action);
    $detailsSafe = mysqli_real_escape_string($conn, $details);
    $conn->query("INSERT INTO logs (action, details) VALUES ('$actionSafe', '$detailsSafe')");
}

function respond(bool $ok, string $message, ?string $amount = null): void
{
    global $conn;

    log_event(
        $conn,
        $ok ? 'claim_success' : 'claim_error',
        $message . ($amount !== null ? (' | amount=' . $amount) : '')
    );

    $wantsJson = (
        (isset($_GET['format']) && $_GET['format'] === 'json') ||
        (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) ||
        (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest')
    );

    if ($wantsJson) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => $ok,
            'message' => $message,
            'amount' => $amount,
        ]);
        exit;
    }

    if ($ok) {
        header('Location: index.php?success=' . urlencode((string)$amount));
    } else {
        header('Location: index.php?error=' . urlencode($message));
    }
    exit;
}

$walletRaw = $_POST['wallet'] ?? '';
$faucetpayRaw = $_POST['faucetpay'] ?? '';
$refRaw = $_POST['ref'] ?? '';
$turnstileTokenRaw = $_POST['cf-turnstile-response'] ?? '';
$ip = $_SERVER['REMOTE_ADDR'] ?? '';

$wallet = mysqli_real_escape_string($conn, trim((string)$walletRaw));
$faucetpay = mysqli_real_escape_string($conn, trim((string)$faucetpayRaw));
$referralInput = mysqli_real_escape_string($conn, trim((string)$refRaw));
$turnstileToken = trim((string)$turnstileTokenRaw);
$ip = mysqli_real_escape_string($conn, trim((string)$ip));

if ($wallet === '' || $faucetpay === '' || $turnstileToken === '') {
    respond(false, 'Chybí povinné údaje.');
}

$ipLimitStmt = $conn->prepare('SELECT COUNT(*) AS ip_claim_count FROM claims WHERE ip = ? AND created_at > NOW() - INTERVAL 1 DAY');
if (!$ipLimitStmt) {
    respond(false, 'Nepodařilo se ověřit IP limit.');
}
$ipRaw = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
$ipLimitStmt->bind_param('s', $ipRaw);
$ipLimitStmt->execute();
$ipLimitResult = $ipLimitStmt->get_result();
$ipLimitRow = $ipLimitResult ? $ipLimitResult->fetch_assoc() : null;
$ipClaimCount = (int)($ipLimitRow['ip_claim_count'] ?? 0);
$ipLimitStmt->close();

if ($ipClaimCount > 5) {
    respond(false, 'IP limit překročen. Přístup je dočasně zablokován.');
}

$turnstilePost = http_build_query([
    'secret' => TURNSTILE_SECRET_KEY,
    'response' => $turnstileToken,
    'remoteip' => $ip,
]);

$turnstileCh = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
curl_setopt_array($turnstileCh, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $turnstilePost,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 20,
]);
$turnstileResponse = curl_exec($turnstileCh);
$turnstileErr = curl_error($turnstileCh);
curl_close($turnstileCh);

if ($turnstileResponse === false) {
    respond(false, 'Turnstile verification error: ' . $turnstileErr);
}

$turnstileData = json_decode((string)$turnstileResponse, true);
$turnstileSuccess = is_array($turnstileData) && !empty($turnstileData['success']);
if (!$turnstileSuccess) {
    respond(false, 'Turnstile ověření selhalo.');
}

$settingsResult = $conn->query('SELECT * FROM settings LIMIT 1');
if (!$settingsResult || $settingsResult->num_rows === 0) {
    respond(false, 'Nastavení faucet není dostupné.');
}
$settings = $settingsResult->fetch_assoc();

$faucetActive = (int)($settings['faucet_active'] ?? 0);
if ($faucetActive !== 1) {
    respond(false, 'Faucet je momentálně pozastaven.');
}

$intervalMinutes = (int)($settings['claim_interval_minutes'] ?? DEFAULT_CLAIM_INTERVAL_MIN);
$minAmount = (float)($settings['min_amount'] ?? MIN_SOL_AMOUNT);
$maxAmount = (float)($settings['max_amount'] ?? MAX_SOL_AMOUNT);

if ($minAmount <= 0 || $maxAmount <= 0 || $minAmount > $maxAmount) {
    respond(false, 'Neplatná konfigurace částek.');
}

$userSql = "SELECT id, faucetpay_id, last_claim FROM users WHERE wallet_address = '$wallet' LIMIT 1";
$userResult = $conn->query($userSql);
if (!$userResult) {
    respond(false, 'Chyba při načítání uživatele.');
}

if ($userResult->num_rows === 0) {
    $referralCode = substr(md5($wallet . time()), 0, 12);
    $referredBy = 'NULL';
    if ($referralInput !== '') {
        $refCheckSql = "SELECT referral_code FROM users WHERE referral_code = '$referralInput' LIMIT 1";
        $refCheckResult = $conn->query($refCheckSql);
        if ($refCheckResult && $refCheckResult->num_rows > 0) {
            $referredBy = "'$referralInput'";
        }
    }

    $insertUserSql = "INSERT INTO users (wallet_address, faucetpay_id, ip, referral_code, referred_by) VALUES ('$wallet', '$faucetpay', '$ip', '$referralCode', $referredBy)";
    if (!$conn->query($insertUserSql)) {
        respond(false, 'Nepodařilo se vytvořit uživatele.');
    }

    $userId = (int)$conn->insert_id;
    $lastClaim = null;
    $faucetpayId = $faucetpay;
    $userReferralBy = $referredBy === 'NULL' ? '' : $referralInput;
} else {
    $user = $userResult->fetch_assoc();
    $userId = (int)$user['id'];
    $lastClaim = $user['last_claim'];
    $faucetpayId = mysqli_real_escape_string($conn, trim((string)$user['faucetpay_id']));
    $userReferralBy = trim((string)($user['referred_by'] ?? ''));

    if ($userReferralBy === '' && $referralInput !== '') {
        $refCheckSql = "SELECT referral_code FROM users WHERE referral_code = '$referralInput' LIMIT 1";
        $refCheckResult = $conn->query($refCheckSql);
        if ($refCheckResult && $refCheckResult->num_rows > 0) {
            $userReferralBy = $referralInput;
        }
    }

    $referredBySqlPart = $userReferralBy !== '' ? ", referred_by = '$userReferralBy'" : '';
    $updateUserSql = "UPDATE users SET faucetpay_id = '$faucetpay', ip = '$ip' $referredBySqlPart WHERE id = $userId";
    $conn->query($updateUserSql);
}

if (!empty($lastClaim)) {
    $lastClaimTs = strtotime((string)$lastClaim);
    $nextClaimTs = $lastClaimTs + ($intervalMinutes * 60);
    $nowTs = time();

    if ($nextClaimTs > $nowTs) {
        $minutesLeft = (int)ceil(($nextClaimTs - $nowTs) / 60);
        respond(false, 'Počkej ještě ' . $minutesLeft . ' minut');
    }
}

$minNano = (int)round($minAmount * 1000000000);
$maxNano = (int)round($maxAmount * 1000000000);
$amountNano = mt_rand($minNano, $maxNano);
$amountSol = $amountNano / 1000000000;

$faucetPayload = [
    'api_key' => FAUCETPAY_API_KEY,
    'to' => $faucetpayId,
    'amount' => (int)$amountNano,
    'currency' => CURRENCY,
];

$faucetCh = curl_init('https://faucetpay.io/api/v1/send');
curl_setopt_array($faucetCh, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query($faucetPayload),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 20,
]);
$faucetResponse = curl_exec($faucetCh);
$faucetHttpCode = (int)curl_getinfo($faucetCh, CURLINFO_HTTP_CODE);
$faucetErr = curl_error($faucetCh);
curl_close($faucetCh);

if ($faucetResponse === false) {
    $safeErr = mysqli_real_escape_string($conn, $faucetErr);
    $amountSql = number_format($amountSol, 9, '.', '');
    $conn->query("INSERT INTO claims (user_id, ip, amount, status) VALUES ($userId, '$ip', $amountSql, 'failed')");
    respond(false, 'FaucetPay request error: ' . $safeErr);
}

$faucetData = json_decode((string)$faucetResponse, true);
$apiStatus = is_array($faucetData) ? (int)($faucetData['status'] ?? 0) : 0;

if ($faucetHttpCode === 200 && $apiStatus === 200) {
    $amountSql = number_format($amountSol, 9, '.', '');
    $conn->query("INSERT INTO claims (user_id, ip, amount, status) VALUES ($userId, '$ip', $amountSql, 'sent')");
    $conn->query("UPDATE users SET last_claim = NOW() WHERE id = $userId");

    if ($userReferralBy !== '') {
        $refFindSql = "SELECT id FROM users WHERE referral_code = '$userReferralBy' LIMIT 1";
        $refFindResult = $conn->query($refFindSql);
        if ($refFindResult && $refFindResult->num_rows > 0) {
            $refRow = $refFindResult->fetch_assoc();
            $referrerId = (int)$refRow['id'];
            $refBonus = number_format($amountSol * (REFERRAL_PERCENT / 100), 9, '.', '');
            $conn->query("UPDATE users SET balance = balance + $refBonus WHERE id = $referrerId");
            log_event($conn, 'referral_bonus', 'referrer_id=' . $referrerId . ' bonus=' . $refBonus . ' from_user=' . $userId);
        }
    }

    respond(true, 'Claim úspěšný.', $amountSql);
}

$errorMessage = 'Claim selhal.';
if (is_array($faucetData)) {
    if (!empty($faucetData['message'])) {
        $errorMessage = (string)$faucetData['message'];
    } elseif (!empty($faucetData['error'])) {
        $errorMessage = (string)$faucetData['error'];
    }
}

$amountSql = number_format($amountSol, 9, '.', '');
$conn->query("INSERT INTO claims (user_id, ip, amount, status) VALUES ($userId, '$ip', $amountSql, 'failed')");
respond(false, $errorMessage);
