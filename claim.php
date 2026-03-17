<?php
require 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    die('Method not allowed.');
}

const LEVEL_BONUS_PERCENT = 0.03; // +0.03% per level
const CLAIMS_PER_LEVEL = 10;

function log_event(mysqli $conn, string $action, string $details): void
{
    $actionSafe = mysqli_real_escape_string($conn, $action);
    $detailsSafe = mysqli_real_escape_string($conn, $details);
    $conn->query("INSERT INTO logs (action, details) VALUES ('$actionSafe', '$detailsSafe')");
}

function respond(bool $ok, string $message, ?string $amount = null): void
{
    global $conn;

    log_event($conn, $ok ? 'claim_success' : 'claim_error', $message . ($amount !== null ? (' | amount=' . $amount) : ''));

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

$emailRaw = trim((string)($_POST['email'] ?? ''));
$faucetpayRaw = trim((string)($_POST['faucetpay'] ?? ''));
$refRaw = trim((string)($_POST['ref'] ?? ''));
$turnstileToken = trim((string)($_POST['cf-turnstile-response'] ?? ''));
$ipRaw = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));

if (!filter_var($emailRaw, FILTER_VALIDATE_EMAIL)) {
    respond(false, 'Zadej platný email.');
}
if ($faucetpayRaw === '' || $turnstileToken === '') {
    respond(false, 'Chybí povinné údaje.');
}

$email = mysqli_real_escape_string($conn, $emailRaw);
$faucetpay = mysqli_real_escape_string($conn, $faucetpayRaw);
$referralInput = mysqli_real_escape_string($conn, $refRaw);
$ip = mysqli_real_escape_string($conn, $ipRaw);

$ipLimitStmt = $conn->prepare('SELECT COUNT(*) AS ip_claim_count FROM claims WHERE ip = ? AND created_at > NOW() - INTERVAL 1 DAY');
if (!$ipLimitStmt) {
    respond(false, 'Nepodařilo se ověřit IP limit.');
}
$ipLimitStmt->bind_param('s', $ipRaw);
$ipLimitStmt->execute();
$ipLimitResult = $ipLimitStmt->get_result();
$ipLimitRow = $ipLimitResult ? $ipLimitResult->fetch_assoc() : null;
$ipClaimCount = (int)($ipLimitRow['ip_claim_count'] ?? 0);
$ipLimitStmt->close();

if ($ipClaimCount > 5) {
    respond(false, 'IP limit překročen. Přístup je dočasně zablokován.');
}

$turnstileCh = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
curl_setopt_array($turnstileCh, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query([
        'secret' => TURNSTILE_SECRET_KEY,
        'response' => $turnstileToken,
        'remoteip' => $ipRaw,
    ]),
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
if (!(is_array($turnstileData) && !empty($turnstileData['success']))) {
    respond(false, 'Turnstile ověření selhalo.');
}

$settingsResult = $conn->query('SELECT * FROM settings LIMIT 1');
if (!$settingsResult || $settingsResult->num_rows === 0) {
    respond(false, 'Nastavení faucet není dostupné.');
}
$settings = $settingsResult->fetch_assoc();

if ((int)($settings['faucet_active'] ?? 0) !== 1) {
    respond(false, 'Faucet je momentálně pozastaven.');
}

$intervalMinutes = (int)($settings['claim_interval_minutes'] ?? DEFAULT_CLAIM_INTERVAL_MIN);
$minAmount = (float)($settings['min_amount'] ?? MIN_SOL_AMOUNT);
$maxAmount = (float)($settings['max_amount'] ?? MAX_SOL_AMOUNT);
if ($minAmount <= 0 || $maxAmount <= 0 || $minAmount > $maxAmount) {
    respond(false, 'Neplatná konfigurace částek.');
}

$userResult = $conn->query("SELECT id, faucetpay_id, referred_by, last_claim, total_claims, level FROM users WHERE email = '$email' LIMIT 1");
if (!$userResult) {
    respond(false, 'Chyba při načítání uživatele.');
}

if ($userResult->num_rows === 0) {
    $referralCode = substr(md5($email . time()), 0, 12);
    $referredBySql = 'NULL';
    $userReferralBy = '';

    if ($referralInput !== '') {
        $refCheck = $conn->query("SELECT referral_code FROM users WHERE referral_code = '$referralInput' LIMIT 1");
        if ($refCheck && $refCheck->num_rows > 0) {
            $referredBySql = "'$referralInput'";
            $userReferralBy = $refRaw;
        }
    }

    $insertSql = "INSERT INTO users (email, faucetpay_id, ip, referral_code, referred_by, total_claims, level)
                  VALUES ('$email', '$faucetpay', '$ip', '$referralCode', $referredBySql, 0, 1)";
    if (!$conn->query($insertSql)) {
        respond(false, 'Nepodařilo se vytvořit uživatele.');
    }

    $userId = (int)$conn->insert_id;
    $lastClaim = null;
    $totalClaims = 0;
    $level = 1;
    $faucetpayId = $faucetpayRaw;
} else {
    $user = $userResult->fetch_assoc();
    $userId = (int)$user['id'];
    $lastClaim = $user['last_claim'];
    $totalClaims = (int)($user['total_claims'] ?? 0);
    $level = max(1, (int)($user['level'] ?? 1));
    $userReferralBy = trim((string)($user['referred_by'] ?? ''));
    $faucetpayId = $faucetpayRaw;

    if ($userReferralBy === '' && $referralInput !== '') {
        $refCheck = $conn->query("SELECT referral_code FROM users WHERE referral_code = '$referralInput' LIMIT 1");
        if ($refCheck && $refCheck->num_rows > 0) {
            $userReferralBy = $refRaw;
        }
    }

    $referredPart = '';
    if ($userReferralBy !== '') {
        $userReferralByEsc = mysqli_real_escape_string($conn, $userReferralBy);
        $referredPart = ", referred_by = '$userReferralByEsc'";
    }

    $conn->query("UPDATE users SET faucetpay_id = '$faucetpay', ip = '$ip' $referredPart WHERE id = $userId");
}

if (!empty($lastClaim)) {
    $nextClaimTs = strtotime((string)$lastClaim) + ($intervalMinutes * 60);
    if ($nextClaimTs > time()) {
        $minutesLeft = (int)ceil(($nextClaimTs - time()) / 60);
        respond(false, 'Počkej ještě ' . $minutesLeft . ' minut');
    }
}

$minNano = (int)round($minAmount * 1000000000);
$maxNano = (int)round($maxAmount * 1000000000);
$baseNano = mt_rand($minNano, $maxNano);
$bonusPercent = max(0, ($level - 1) * LEVEL_BONUS_PERCENT);
$finalNano = (int)round($baseNano * (1 + ($bonusPercent / 100)));
$amountSol = $finalNano / 1000000000;
$amountSql = number_format($amountSol, 9, '.', '');

$faucetCh = curl_init('https://faucetpay.io/api/v1/send');
curl_setopt_array($faucetCh, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query([
        'api_key' => FAUCETPAY_API_KEY,
        'to' => $faucetpayId,
        'amount' => $finalNano,
        'currency' => CURRENCY,
    ]),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 20,
]);
$faucetResponse = curl_exec($faucetCh);
$faucetHttpCode = (int)curl_getinfo($faucetCh, CURLINFO_HTTP_CODE);
$faucetErr = curl_error($faucetCh);
curl_close($faucetCh);

if ($faucetResponse === false) {
    $conn->query("INSERT INTO claims (user_id, ip, amount, status) VALUES ($userId, '$ip', $amountSql, 'failed')");
    respond(false, 'FaucetPay request error: ' . $faucetErr);
}

$faucetData = json_decode((string)$faucetResponse, true);
$apiStatus = is_array($faucetData) ? (int)($faucetData['status'] ?? 0) : 0;

if ($faucetHttpCode === 200 && $apiStatus === 200) {
    $conn->query("INSERT INTO claims (user_id, ip, amount, status) VALUES ($userId, '$ip', $amountSql, 'sent')");

    $newTotalClaims = $totalClaims + 1;
    $newLevel = max(1, (int)floor($newTotalClaims / CLAIMS_PER_LEVEL) + 1);
    $conn->query("UPDATE users SET last_claim = NOW(), total_claims = $newTotalClaims, level = $newLevel WHERE id = $userId");

    if (!empty($userReferralBy)) {
        $userReferralByEsc = mysqli_real_escape_string($conn, $userReferralBy);
        $refFind = $conn->query("SELECT id FROM users WHERE referral_code = '$userReferralByEsc' LIMIT 1");
        if ($refFind && $refFind->num_rows > 0) {
            $refRow = $refFind->fetch_assoc();
            $referrerId = (int)$refRow['id'];
            $refBonus = number_format($amountSol * (REFERRAL_PERCENT / 100), 9, '.', '');
            $conn->query("UPDATE users SET balance = balance + $refBonus WHERE id = $referrerId");
            log_event($conn, 'referral_bonus', 'referrer_id=' . $referrerId . ' bonus=' . $refBonus . ' from_user=' . $userId);
        }
    }

    respond(true, $amountSql);
}

$errorMessage = 'Claim selhal.';
if (is_array($faucetData)) {
    if (!empty($faucetData['message'])) {
        $errorMessage = (string)$faucetData['message'];
    } elseif (!empty($faucetData['error'])) {
        $errorMessage = (string)$faucetData['error'];
    }
}

$conn->query("INSERT INTO claims (user_id, ip, amount, status) VALUES ($userId, '$ip', $amountSql, 'failed')");
respond(false, $errorMessage);
