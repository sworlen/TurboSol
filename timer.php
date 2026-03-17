<?php
require 'config.php';

header('Content-Type: application/json');

$emailRaw = trim((string)($_GET['email'] ?? ''));
if (!filter_var($emailRaw, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['remaining_seconds' => 0]);
    exit;
}

$email = mysqli_real_escape_string($conn, $emailRaw);

$settingsResult = $conn->query('SELECT claim_interval_minutes FROM settings WHERE id = 1 LIMIT 1');
$intervalMinutes = DEFAULT_CLAIM_INTERVAL_MIN;
if ($settingsResult && $settingsResult->num_rows > 0) {
    $settings = $settingsResult->fetch_assoc();
    $intervalMinutes = (int)($settings['claim_interval_minutes'] ?? DEFAULT_CLAIM_INTERVAL_MIN);
}
if ($intervalMinutes <= 0) {
    $intervalMinutes = DEFAULT_CLAIM_INTERVAL_MIN;
}

$userResult = $conn->query("SELECT last_claim FROM users WHERE email = '$email' LIMIT 1");
if (!$userResult || $userResult->num_rows === 0) {
    echo json_encode(['remaining_seconds' => 0]);
    exit;
}

$user = $userResult->fetch_assoc();
$lastClaim = $user['last_claim'] ?? null;
if (empty($lastClaim)) {
    echo json_encode(['remaining_seconds' => 0]);
    exit;
}

$lastClaimTs = strtotime((string)$lastClaim);
if ($lastClaimTs === false) {
    echo json_encode(['remaining_seconds' => 0]);
    exit;
}

$remaining = ($lastClaimTs + ($intervalMinutes * 60)) - time();
if ($remaining < 0) {
    $remaining = 0;
}

echo json_encode(['remaining_seconds' => (int)$remaining]);
