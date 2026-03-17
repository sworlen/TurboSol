<?php
require 'config.php';

$error = isset($_GET['error']) ? trim((string)$_GET['error']) : '';
$success = isset($_GET['success']) ? trim((string)$_GET['success']) : '';

$prefillEmail = trim((string)($_GET['email'] ?? ''));
$prefillFaucetpay = trim((string)($_GET['faucetpay'] ?? ''));
$prefillRef = trim((string)($_GET['ref'] ?? ''));

$userProfile = null;
if (filter_var($prefillEmail, FILTER_VALIDATE_EMAIL)) {
    $emailEsc = mysqli_real_escape_string($conn, $prefillEmail);
    $profileResult = $conn->query("SELECT referral_code, balance, total_claims, level FROM users WHERE email = '$emailEsc' LIMIT 1");
    if ($profileResult && $profileResult->num_rows > 0) {
        $userProfile = $profileResult->fetch_assoc();
    }
}

function fetchFaucetSolBalance(): array
{
    $fallback = ['ok' => false, 'balance' => null];
    if (!function_exists('curl_init') || trim((string)FAUCETPAY_API_KEY) === '' || FAUCETPAY_API_KEY === 'your_faucetpay_api_key') {
        return $fallback;
    }

    $ch = curl_init('https://faucetpay.io/api/v1/getbalance');
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
        return $fallback;
    }

    $data = json_decode((string)$response, true);
    $balance = $data['balance'] ?? ($data['data']['balance'] ?? null);
    if ($balance !== null && is_numeric((string)$balance)) {
        return ['ok' => true, 'balance' => (float)$balance];
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
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TurboSol Faucet</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root { --p:#9945FF; --g:#14F195; --b:#22d3ff; }
        body{min-height:100vh;color:#fff;background:radial-gradient(circle at 20% 10%,#2b1765,transparent 35%),radial-gradient(circle at 90% 80%,#12485b,transparent 35%),#090d1f;padding:20px}
        .wrap{max-width:950px;margin:0 auto;background:rgba(8,13,38,.76);border:1px solid rgba(153,69,255,.5);border-radius:24px;padding:24px;box-shadow:0 20px 60px rgba(0,0,0,.45)}
        .title{font-weight:900;text-align:center;font-size:clamp(2rem,4vw,3.2rem);background:linear-gradient(90deg,var(--g),var(--b),var(--p));-webkit-background-clip:text;-webkit-text-fill-color:transparent}
        .stats{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin:16px 0}
        .box{background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.18);border-radius:14px;padding:12px}
        .label{font-size:.75rem;color:#a9b4ff;text-transform:uppercase;display:block}
        .v{font-weight:800;font-size:1.05rem}
        .ads{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:12px}
        .ad-slot{height:90px;border:1px dashed rgba(255,255,255,.35);border-radius:12px;display:flex;align-items:center;justify-content:center;background:rgba(255,255,255,.03);color:#b6c0ff;font-size:.9rem}
        .ref-zone{background:rgba(20,241,149,.08);border:1px solid rgba(20,241,149,.35);border-radius:12px;padding:12px;margin:12px 0}
        .btn-claim{border:0;border-radius:12px;font-weight:800;padding:.9rem;background:linear-gradient(90deg,var(--p),var(--b),var(--g));color:#051422}
        .turn{border:1px dashed rgba(255,255,255,.3);padding:8px;border-radius:12px;background:rgba(255,255,255,.03)}
        @media (max-width: 800px){.stats,.ads{grid-template-columns:1fr}}
    </style>
</head>
<body>
<div class="wrap">
    <h1 class="title">TurboSol – Rychlé SOL kapky zdarma</h1>
    <p class="text-center text-light-emphasis mb-2">Claim každých <?php echo (int)DEFAULT_CLAIM_INTERVAL_MIN; ?> minut přes FaucetPay</p>

    <div class="stats">
        <div class="box"><span class="label">Faucet balance</span><span class="v"><?php echo $balanceInfo['ok'] ? number_format($balanceSol, 9, '.', '') . ' SOL' : 'N/A'; ?></span><div class="small text-info"><?php echo $balanceInfo['ok'] ? $balanceNano . ' nanoSOL' : ''; ?></div></div>
        <div class="box"><span class="label">Min claim</span><span class="v"><?php echo number_format((float)MIN_SOL_AMOUNT, 9, '.', ''); ?> SOL</span><div class="small text-info"><?php echo $minNano; ?> nanoSOL</div></div>
        <div class="box"><span class="label">Max claim</span><span class="v"><?php echo number_format((float)MAX_SOL_AMOUNT, 9, '.', ''); ?> SOL</span><div class="small text-info"><?php echo $maxNano; ?> nanoSOL</div></div>
    </div>

    <div class="ads">
        <div class="ad-slot">Reklama 728x90 / Banner #1</div>
        <div class="ad-slot">Reklama 728x90 / Banner #2</div>
    </div>

    <?php if ($userProfile): ?>
        <div class="ref-zone">
            <div><strong>Referral zóna</strong></div>
            <div>Tvoje referral code: <code><?php echo htmlspecialchars((string)$userProfile['referral_code'], ENT_QUOTES, 'UTF-8'); ?></code></div>
            <div>Tvoje level: <strong><?php echo (int)$userProfile['level']; ?></strong> (bonus +<?php echo number_format(max(0, ((int)$userProfile['level'] - 1) * 0.03), 2); ?>%)</div>
            <div>Claimů celkem: <strong><?php echo (int)$userProfile['total_claims']; ?></strong> | Referral earnings: <strong><?php echo number_format((float)$userProfile['balance'], 9, '.', ''); ?> SOL</strong></div>
            <div>Referral link: <code><?php echo htmlspecialchars('https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/?ref=' . (string)$userProfile['referral_code'], ENT_QUOTES, 'UTF-8'); ?></code></div>
        </div>
    <?php endif; ?>

    <?php if (!$hasEnoughForMin): ?>
        <div class="alert alert-danger">Faucet balance je menší než minimum claimu.</div>
    <?php endif; ?>
    <?php if ($error !== ''): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
    <?php if ($success !== ''): ?><div class="alert alert-success">Úspěch! Odesláno: <?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?> SOL (<?php echo (int)round(((float)$success) * 1000000000); ?> nanoSOL)</div><?php endif; ?>

    <form id="claimForm" method="POST" action="claim.php" class="d-grid gap-3">
        <input type="email" class="form-control form-control-lg" name="email" placeholder="Tvůj email (login identity)" value="<?php echo htmlspecialchars($prefillEmail, ENT_QUOTES, 'UTF-8'); ?>" required>
        <input type="text" class="form-control form-control-lg" name="faucetpay" placeholder="FaucetPay email nebo username (výplata)" value="<?php echo htmlspecialchars($prefillFaucetpay, ENT_QUOTES, 'UTF-8'); ?>" required>
        <input type="text" class="form-control" name="ref" placeholder="Referral code (volitelné)" value="<?php echo htmlspecialchars($prefillRef, ENT_QUOTES, 'UTF-8'); ?>">

        <div class="turn"><div class="cf-turnstile" data-sitekey="<?php echo TURNSTILE_SITE_KEY; ?>"></div></div>
        <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>

        <button type="submit" id="claimButton" class="btn btn-claim btn-lg" <?php echo !$hasEnoughForMin ? 'disabled' : ''; ?>>Claim SOL</button>
    </form>

    <footer class="text-center text-light-emphasis mt-4">Protected by Turnstile • © 2026 TurboSol</footer>
</div>

<script>
document.getElementById('claimForm').addEventListener('submit', function () {
    const button = document.getElementById('claimButton');
    button.disabled = true;
    button.textContent = 'Odesílám...';
});
</script>
<script src="assets/timer.js"></script>
</body>
</html>
