<?php
require 'config.php';

$error = isset($_GET['error']) ? trim((string)$_GET['error']) : '';
$success = isset($_GET['success']) ? trim((string)$_GET['success']) : '';
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
            --bg-dark: #0f1020;
        }

        body {
            min-height: 100vh;
            background: radial-gradient(circle at top, #1b1d3b 0%, var(--bg-dark) 55%);
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }

        .faucet-card {
            width: 100%;
            max-width: 640px;
            background: rgba(255, 255, 255, 0.06);
            border: 1px solid rgba(153, 69, 255, 0.35);
            border-radius: 18px;
            backdrop-filter: blur(6px);
            box-shadow: 0 12px 32px rgba(20, 241, 149, 0.15);
        }

        .brand-title {
            color: var(--sol-green);
            font-weight: 700;
        }

        .brand-subtitle {
            color: #d4d8ff;
        }

        .form-control {
            border-radius: 12px;
            background: rgba(255, 255, 255, 0.92);
        }

        .btn-claim {
            border: 0;
            border-radius: 12px;
            font-size: 1.15rem;
            font-weight: 700;
            padding: 0.9rem 1rem;
            color: #121212;
            background: linear-gradient(90deg, var(--sol-purple), var(--sol-green));
            transition: transform .12s ease, box-shadow .12s ease, opacity .12s ease;
        }

        .btn-claim:hover {
            transform: translateY(-1px);
            box-shadow: 0 10px 20px rgba(20, 241, 149, 0.25);
        }

        .btn-claim:disabled {
            opacity: .75;
            cursor: not-allowed;
        }

        footer {
            color: #aeb4ff;
            font-size: 0.9rem;
        }
    </style>
</head>
<body>
<div class="faucet-card p-4 p-md-5">
    <header class="text-center mb-4">
        <h1 class="brand-title mb-2">TurboSol – Rychlé SOL kapky zdarma</h1>
        <p class="brand-subtitle mb-0">Claim každých <?php echo (int) DEFAULT_CLAIM_INTERVAL_MIN; ?> minut přes FaucetPay</p>
    </header>

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

        <div class="cf-turnstile" data-sitekey="<?php echo TURNSTILE_SITE_KEY; ?>"></div>
        <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>

        <button type="submit" id="claimButton" class="btn btn-claim btn-lg w-100">Claim SOL</button>
    </form>

    <footer class="text-center mt-4">
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
