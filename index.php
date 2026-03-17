<?php
require 'config.php';

$error = isset($_GET['error']) ? trim((string)$_GET['error']) : '';
$success = isset($_GET['success']) ? trim((string)$_GET['success']) : '';

function fetchFaucetSolBalance(): array
{
    $fallback = [
        'ok' => false,
        'balance' => null,
        'source' => 'unavailable',
    ];

    if (!defined('FAUCETPAY_API_KEY') || trim((string)FAUCETPAY_API_KEY) === '' || FAUCETPAY_API_KEY === 'your_faucetpay_api_key') {
        return $fallback;
    }

    if (!function_exists('curl_init')) {
        return $fallback;
    }

    $endpoints = [
        'https://faucetpay.io/api/v1/getbalance',
        'https://faucetpay.io/api/v1/balance',
    ];

    foreach ($endpoints as $endpoint) {
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'api_key' => FAUCETPAY_API_KEY,
                'currency' => CURRENCY,
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 12,
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

        $candidate = null;

        if (isset($data['balance'])) {
            $candidate = $data['balance'];
        } elseif (isset($data['data']['balance'])) {
            $candidate = $data['data']['balance'];
        } elseif (isset($data['data'][CURRENCY])) {
            $candidate = $data['data'][CURRENCY];
        }

        if ($candidate !== null && is_numeric((string)$candidate)) {
            return [
                'ok' => true,
                'balance' => (float)$candidate,
                'source' => 'faucetpay_api',
            ];
        }
    }

    return $fallback;
}

$balanceInfo = fetchFaucetSolBalance();
$balanceSol = $balanceInfo['ok'] ? (float)$balanceInfo['balance'] : 0.0;
$hasEnoughForMin = $balanceSol >= (float)MIN_SOL_AMOUNT;
?>
<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TurboSol Faucet</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root {
            --sol-purple: #9945FF;
            --sol-green: #14F195;
            --sol-blue: #00C2FF;
            --bg-1: #080a18;
            --bg-2: #121638;
        }

        body {
            min-height: 100vh;
            margin: 0;
            color: #fff;
            background:
                radial-gradient(circle at 15% 20%, rgba(153, 69, 255, .30), transparent 36%),
                radial-gradient(circle at 82% 70%, rgba(20, 241, 149, .22), transparent 38%),
                radial-gradient(circle at 72% 20%, rgba(0, 194, 255, .24), transparent 32%),
                linear-gradient(150deg, var(--bg-1), var(--bg-2));
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 22px;
        }

        .shell {
            width: 100%;
            max-width: 760px;
            background: rgba(10, 14, 34, .72);
            border: 1px solid rgba(153, 69, 255, .45);
            border-radius: 22px;
            padding: 26px;
            box-shadow: 0 0 0 1px rgba(20, 241, 149, .15), 0 20px 55px rgba(0, 0, 0, .45), 0 0 40px rgba(153, 69, 255, .30);
            backdrop-filter: blur(8px);
        }

        .brand {
            font-weight: 800;
            letter-spacing: .2px;
            text-align: center;
            margin: 0;
            font-size: clamp(2rem, 3.4vw, 3rem);
            background: linear-gradient(90deg, var(--sol-green), var(--sol-blue), var(--sol-purple));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            text-shadow: 0 0 32px rgba(20, 241, 149, .2);
        }

        .subtitle {
            text-align: center;
            color: #d9deff;
            margin-top: 8px;
            margin-bottom: 20px;
        }

        .stat-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 10px;
            margin-bottom: 18px;
        }

        .stat {
            border-radius: 14px;
            padding: 12px;
            background: rgba(255, 255, 255, .04);
            border: 1px solid rgba(255, 255, 255, .12);
        }

        .stat .label {
            display: block;
            font-size: .78rem;
            text-transform: uppercase;
            letter-spacing: .5px;
            color: #aeb7ff;
            margin-bottom: 2px;
        }

        .stat .value {
            font-weight: 700;
            font-size: 1.03rem;
        }

        .balance-ok { color: #14F195; }
        .balance-low { color: #ff8f9f; }

        .form-control {
            border-radius: 12px;
            border: 1px solid rgba(255, 255, 255, .22);
            background: rgba(255, 255, 255, .9);
            padding: .85rem 1rem;
        }

        .turnstile-wrap {
            background: rgba(255, 255, 255, .03);
            border: 1px dashed rgba(255, 255, 255, .25);
            border-radius: 12px;
            padding: 8px;
        }

        .btn-claim {
            border: 0;
            border-radius: 13px;
            padding: 0.95rem 1rem;
            font-size: 1.22rem;
            font-weight: 800;
            letter-spacing: .4px;
            color: #09111f;
            background: linear-gradient(100deg, var(--sol-purple), var(--sol-blue), var(--sol-green));
            box-shadow: 0 10px 24px rgba(20, 241, 149, .25), inset 0 0 18px rgba(255, 255, 255, .25);
            transition: transform .12s ease, opacity .12s ease;
        }

        .btn-claim:hover { transform: translateY(-1px); }
        .btn-claim:disabled { opacity: .7; cursor: not-allowed; }

        footer {
            margin-top: 20px;
            text-align: center;
            color: #aeb4ff;
            font-size: .92rem;
        }

        @media (max-width: 768px) {
            .stat-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<div class="shell">
    <header>
        <h1 class="brand">TurboSol – Rychlé SOL kapky zdarma</h1>
        <p class="subtitle">Claim každých <?php echo (int)DEFAULT_CLAIM_INTERVAL_MIN; ?> minut přes FaucetPay</p>
    </header>

    <section class="stat-grid">
        <div class="stat">
            <span class="label">Faucet balance (<?php echo htmlspecialchars(CURRENCY, ENT_QUOTES, 'UTF-8'); ?>)</span>
            <span class="value <?php echo $hasEnoughForMin ? 'balance-ok' : 'balance-low'; ?>">
                <?php if ($balanceInfo['ok']): ?>
                    <?php echo number_format($balanceSol, 9, '.', ''); ?>
                <?php else: ?>
                    N/A
                <?php endif; ?>
            </span>
        </div>
        <div class="stat">
            <span class="label">Min claim</span>
            <span class="value"><?php echo number_format((float)MIN_SOL_AMOUNT, 9, '.', ''); ?> <?php echo htmlspecialchars(CURRENCY, ENT_QUOTES, 'UTF-8'); ?></span>
        </div>
        <div class="stat">
            <span class="label">Max claim</span>
            <span class="value"><?php echo number_format((float)MAX_SOL_AMOUNT, 9, '.', ''); ?> <?php echo htmlspecialchars(CURRENCY, ENT_QUOTES, 'UTF-8'); ?></span>
        </div>
    </section>

    <?php if (!$balanceInfo['ok']): ?>
        <div class="alert alert-warning" role="alert">
            Balanc se nepodařilo načíst z FaucetPay API. Zkontroluj API klíč v <code>config.php</code>.
        </div>
    <?php elseif (!$hasEnoughForMin): ?>
        <div class="alert alert-danger" role="alert">
            Faucet balance je nižší než minimální claim. Doplň SOL na FaucetPay účtu.
        </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="alert alert-danger" role="alert">
            <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>

    <?php if ($success !== ''): ?>
        <div class="alert alert-success" role="alert">
            Úspěch! Odesláno: <?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?> <?php echo htmlspecialchars(CURRENCY, ENT_QUOTES, 'UTF-8'); ?>.
        </div>
    <?php endif; ?>

    <form id="claimForm" action="claim.php" method="POST" class="d-grid gap-3">
        <input type="text" class="form-control form-control-lg" name="wallet" placeholder="Solana adresa (např. ...)" required>
        <input type="text" class="form-control form-control-lg" name="faucetpay" placeholder="FaucetPay email nebo username" required>

        <div class="turnstile-wrap">
            <div class="cf-turnstile" data-sitekey="<?php echo TURNSTILE_SITE_KEY; ?>"></div>
        </div>
        <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>

        <button type="submit" id="claimButton" class="btn btn-claim btn-lg w-100" <?php echo ($balanceInfo['ok'] && !$hasEnoughForMin) ? 'disabled' : ''; ?>>Claim SOL</button>
    </form>

    <footer>
        Protected by Turnstile • © 2026 TurboSol
    </footer>
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
