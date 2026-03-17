<?php
require 'config.php';

$error = isset($_GET['error']) ? trim((string)$_GET['error']) : '';
$success = isset($_GET['success']) ? trim((string)$_GET['success']) : '';

$prefillEmail = trim((string)($_GET['email'] ?? ''));
$prefillFaucetpay = trim((string)($_GET['faucetpay'] ?? ''));
$prefillRef = trim((string)($_GET['ref'] ?? ''));

$settingsResult = $conn->query('SELECT * FROM settings WHERE id = 1 LIMIT 1');
$settings = $settingsResult && $settingsResult->num_rows > 0 ? $settingsResult->fetch_assoc() : [];
$adHorizontalUrl = trim((string)($settings['ad_horizontal_url'] ?? ''));
$adVerticalLeftUrl = trim((string)($settings['ad_vertical_left_url'] ?? ''));
$adVerticalRightUrl = trim((string)($settings['ad_vertical_right_url'] ?? ''));

$userProfile = null;
if (filter_var($prefillEmail, FILTER_VALIDATE_EMAIL)) {
    $emailEsc = mysqli_real_escape_string($conn, $prefillEmail);
    $profileResult = $conn->query("SELECT referral_code, balance, total_claims, level FROM users WHERE email = '$emailEsc' LIMIT 1");
    if ($profileResult && $profileResult->num_rows > 0) {
        $userProfile = $profileResult->fetch_assoc();
    }
}

function normalizeSolBalance($value): ?float
{
    if ($value === null || !is_numeric((string)$value)) {
        return null;
    }

    $raw = (float)$value;

    // If API returns integer-like "satoshi/nano" units, convert to SOL.
    if ($raw > 1 && floor($raw) === $raw) {
        $converted = $raw / 1000000000;
        return $converted;
    }

    return $raw;
}

function fetchFaucetSolBalance(): array
{
    $fallback = ['ok' => false, 'balance' => null, 'raw' => null];
    if (!function_exists('curl_init') || trim((string)FAUCETPAY_API_KEY) === '' || FAUCETPAY_API_KEY === 'your_faucetpay_api_key') {
        return $fallback;
    }

    $endpoints = [
        'https://faucetpay.io/api/v1/getbalance',
        'https://faucetpay.io/api/v1/balance',
    ];

    foreach ($endpoints as $url) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query(['api_key' => FAUCETPAY_API_KEY, 'currency' => CURRENCY]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $httpCode !== 200) {
            continue;
        }

        $data = json_decode((string)$response, true);
        if (!is_array($data)) {
            continue;
        }

        $candidate = $data['balance'] ?? ($data['data']['balance'] ?? ($data['data'][CURRENCY] ?? null));
        $normalized = normalizeSolBalance($candidate);
        if ($normalized !== null) {
            return ['ok' => true, 'balance' => $normalized, 'raw' => $candidate];
        }
    }

    return $fallback;
}

$balanceInfo = fetchFaucetSolBalance();
$balanceSol = $balanceInfo['ok'] ? (float)$balanceInfo['balance'] : 0.0;
$balanceNano = (int)round($balanceSol * 1000000000);
$minNano = (int)round(((float)MIN_SOL_AMOUNT) * 1000000000);
$maxNano = (int)round(((float)MAX_SOL_AMOUNT) * 1000000000);
$hasEnoughForMin = !$balanceInfo['ok'] || $balanceSol >= (float)MIN_SOL_AMOUNT;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TurboSol Faucet</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root { --p:#9945FF; --g:#14F195; --b:#22d3ff; }
        body { min-height:100vh; color:#fff; background:radial-gradient(circle at 20% 10%,#2b1765,transparent 35%),radial-gradient(circle at 90% 80%,#12485b,transparent 35%),#090d1f; padding:20px; }
        .wrap { max-width:1050px; margin:0 auto; background:rgba(8,13,38,.76); border:1px solid rgba(153,69,255,.5); border-radius:24px; padding:24px; box-shadow:0 20px 60px rgba(0,0,0,.45); }
        .title { font-weight:900; text-align:center; font-size:clamp(2rem,4vw,3.2rem); background:linear-gradient(90deg,var(--g),var(--b),var(--p)); -webkit-background-clip:text; -webkit-text-fill-color:transparent; }
        .balance-box, .mini-box, .ref-zone, .ad-slot { background:rgba(255,255,255,.04); border:1px solid rgba(255,255,255,.18); border-radius:14px; padding:12px; }
        .label { font-size:.75rem; color:#a9b4ff; text-transform:uppercase; display:block; }
        .value { font-weight:800; font-size:1.12rem; }
        .stack { display:grid; gap:10px; }
        .minmax { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
        .ad-grid { display:grid; grid-template-columns:220px 1fr 220px; gap:10px; margin-top:14px; }
        .ad-slot { display:flex; align-items:center; justify-content:center; text-align:center; color:#b6c0ff; }
        .ad-vertical { min-height:320px; }
        .ad-horizontal { min-height:90px; }
        .btn-claim { border:0; border-radius:12px; font-weight:800; padding:.9rem; background:linear-gradient(90deg,var(--p),var(--b),var(--g)); color:#051422; }
        .turn { border:1px dashed rgba(255,255,255,.3); padding:8px; border-radius:12px; background:rgba(255,255,255,.03); }
        @media (max-width: 992px) { .ad-grid { grid-template-columns:1fr; } .ad-vertical { min-height:160px; } }
    </style>
</head>
<body>
<div class="wrap">
    <h1 class="title">TurboSol – Fast free SOL drops</h1>
    <p class="text-center text-light-emphasis mb-2">Claim every <?php echo (int)DEFAULT_CLAIM_INTERVAL_MIN; ?> minutes via FaucetPay</p>

    <div class="stack">
        <div class="balance-box">
            <span class="label">Faucet Balance</span>
            <span class="value">
                <?php echo $balanceInfo['ok'] ? number_format($balanceSol, 9, '.', '') . ' SOL' : 'N/A'; ?>
            </span>
            <div class="small text-info"><?php echo $balanceInfo['ok'] ? $balanceNano . ' nanoSOL' : 'Balance API unavailable'; ?></div>
        </div>

        <div class="minmax">
            <div class="mini-box">
                <span class="label">Min Claim</span>
                <span class="value"><?php echo number_format((float)MIN_SOL_AMOUNT, 9, '.', ''); ?> SOL</span>
                <div class="small text-info"><?php echo $minNano; ?> nanoSOL</div>
            </div>
            <div class="mini-box">
                <span class="label">Max Claim</span>
                <span class="value"><?php echo number_format((float)MAX_SOL_AMOUNT, 9, '.', ''); ?> SOL</span>
                <div class="small text-info"><?php echo $maxNano; ?> nanoSOL</div>
            </div>
        </div>
    </div>

    <div class="ad-grid">
        <div class="ad-slot ad-vertical">
            <?php if ($adVerticalLeftUrl !== ''): ?>
                <a href="<?php echo htmlspecialchars($adVerticalLeftUrl, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer" class="text-decoration-none text-info">Open Vertical Ad (Left)</a>
            <?php else: ?>
                Vertical Ad Slot (Left)
            <?php endif; ?>
        </div>

        <div>
            <div class="ad-slot ad-horizontal mb-2">
                <?php if ($adHorizontalUrl !== ''): ?>
                    <a href="<?php echo htmlspecialchars($adHorizontalUrl, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer" class="text-decoration-none text-info">Open Horizontal Ad</a>
                <?php else: ?>
                    Horizontal Ad Slot
                <?php endif; ?>
            </div>

            <?php if ($userProfile): ?>
                <div class="ref-zone mb-2">
                    <div><strong>Referral Zone</strong></div>
                    <div>Your referral code: <code><?php echo htmlspecialchars((string)$userProfile['referral_code'], ENT_QUOTES, 'UTF-8'); ?></code></div>
                    <div>Your level: <strong><?php echo (int)$userProfile['level']; ?></strong> (bonus +<?php echo number_format(max(0, ((int)$userProfile['level'] - 1) * 0.03), 2); ?>%)</div>
                    <div>Total claims: <strong><?php echo (int)$userProfile['total_claims']; ?></strong> | Referral earnings: <strong><?php echo number_format((float)$userProfile['balance'], 9, '.', ''); ?> SOL</strong></div>
                    <div>Referral link: <code><?php echo htmlspecialchars('https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/?ref=' . (string)$userProfile['referral_code'], ENT_QUOTES, 'UTF-8'); ?></code></div>
                </div>
            <?php endif; ?>

            <?php if (!$hasEnoughForMin): ?><div class="alert alert-danger">Faucet balance is below minimum claim.</div><?php endif; ?>
            <?php if ($error !== ''): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
            <?php if ($success !== ''): ?><div class="alert alert-success">Success! Sent: <?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?> SOL (<?php echo (int)round(((float)$success) * 1000000000); ?> nanoSOL)</div><?php endif; ?>

            <form id="claimForm" method="POST" action="claim.php" class="d-grid gap-3">
                <input type="email" class="form-control form-control-lg" name="email" placeholder="Your email (account identity)" value="<?php echo htmlspecialchars($prefillEmail, ENT_QUOTES, 'UTF-8'); ?>" required>
                <input type="text" class="form-control form-control-lg" name="faucetpay" placeholder="FaucetPay email or username" value="<?php echo htmlspecialchars($prefillFaucetpay, ENT_QUOTES, 'UTF-8'); ?>" required>
                <input type="text" class="form-control" name="ref" placeholder="Referral code (optional)" value="<?php echo htmlspecialchars($prefillRef, ENT_QUOTES, 'UTF-8'); ?>">

                <div class="turn"><div class="cf-turnstile" data-sitekey="<?php echo TURNSTILE_SITE_KEY; ?>"></div></div>
                <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>

                <button type="submit" id="claimButton" class="btn btn-claim btn-lg" <?php echo !$hasEnoughForMin ? 'disabled' : ''; ?>>Claim SOL</button>
            </form>
        </div>

        <div class="ad-slot ad-vertical">
            <?php if ($adVerticalRightUrl !== ''): ?>
                <a href="<?php echo htmlspecialchars($adVerticalRightUrl, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer" class="text-decoration-none text-info">Open Vertical Ad (Right)</a>
            <?php else: ?>
                Vertical Ad Slot (Right)
            <?php endif; ?>
        </div>
    </div>

    <footer class="text-center text-light-emphasis mt-4">Protected by Turnstile • © 2026 TurboSol</footer>
</div>

<script>
document.getElementById('claimForm').addEventListener('submit', function () {
    const button = document.getElementById('claimButton');
    button.disabled = true;
    button.textContent = 'Submitting...';
});
</script>
<script src="assets/timer.js"></script>
</body>
</html>
