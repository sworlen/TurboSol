<?php
require 'config.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    header('Location: admin.php');
    exit;
}

$error = '';
$success = '';

if (!isset($_SESSION['admin']) || $_SESSION['admin'] !== true) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $password = $_POST['password'] ?? '';
        if ($password === ADMIN_PASSWORD) {
            $_SESSION['admin'] = true;
            header('Location: admin.php');
            exit;
        }
        $error = 'Neplatné admin heslo.';
    }
    ?>
    <!DOCTYPE html>
    <html lang="cs">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>TurboSol Admin Login</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    </head>
    <body class="bg-dark text-light d-flex align-items-center" style="min-height:100vh;">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-5">
                <div class="card shadow-lg">
                    <div class="card-body p-4">
                        <h1 class="h4 mb-3 text-dark">TurboSol Admin</h1>

                        <?php if ($error !== ''): ?>
                            <div class="alert alert-danger"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
                        <?php endif; ?>

                        <form method="POST" action="admin.php" class="d-grid gap-3">
                            <input type="password" class="form-control" name="password" placeholder="Admin heslo" required>
                            <button type="submit" class="btn btn-primary">Přihlásit se</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
    </body>
    </html>
    <?php
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_settings'])) {
    $interval = (int)($_POST['claim_interval_minutes'] ?? DEFAULT_CLAIM_INTERVAL_MIN);
    $minAmount = (float)($_POST['min_amount'] ?? MIN_SOL_AMOUNT);
    $maxAmount = (float)($_POST['max_amount'] ?? MAX_SOL_AMOUNT);
    $active = isset($_POST['faucet_active']) ? 1 : 0;

    if ($interval <= 0 || $minAmount <= 0 || $maxAmount <= 0 || $minAmount > $maxAmount) {
        $error = 'Neplatné hodnoty nastavení.';
    } else {
        $intervalSql = (int)$interval;
        $minSql = number_format($minAmount, 9, '.', '');
        $maxSql = number_format($maxAmount, 9, '.', '');
        $activeSql = (int)$active;

        $updateSql = "UPDATE settings
                      SET claim_interval_minutes = $intervalSql,
                          min_amount = $minSql,
                          max_amount = $maxSql,
                          faucet_active = $activeSql
                      WHERE id = 1";

        if ($conn->query($updateSql)) {
            $success = 'Nastavení bylo aktualizováno.';
        } else {
            $error = 'Aktualizace nastavení selhala.';
        }
    }
}

$settingsResult = $conn->query('SELECT * FROM settings WHERE id = 1 LIMIT 1');
$settings = $settingsResult && $settingsResult->num_rows > 0
    ? $settingsResult->fetch_assoc()
    : [
        'claim_interval_minutes' => DEFAULT_CLAIM_INTERVAL_MIN,
        'min_amount' => MIN_SOL_AMOUNT,
        'max_amount' => MAX_SOL_AMOUNT,
        'faucet_active' => 1,
    ];

$statsClaimsCount = 0;
$statsClaimsSum = '0.000000000';
$statsUsersCount = 0;

$claimsCountResult = $conn->query('SELECT COUNT(*) AS total_claims FROM claims');
if ($claimsCountResult) {
    $row = $claimsCountResult->fetch_assoc();
    $statsClaimsCount = (int)($row['total_claims'] ?? 0);
}

$claimsSumResult = $conn->query('SELECT COALESCE(SUM(amount), 0) AS total_amount FROM claims WHERE status = "sent"');
if ($claimsSumResult) {
    $row = $claimsSumResult->fetch_assoc();
    $statsClaimsSum = number_format((float)($row['total_amount'] ?? 0), 9, '.', '');
}

$usersCountResult = $conn->query('SELECT COUNT(*) AS total_users FROM users');
if ($usersCountResult) {
    $row = $usersCountResult->fetch_assoc();
    $statsUsersCount = (int)($row['total_users'] ?? 0);
}

$recentClaims = [];
$recentClaimsSql = 'SELECT c.id, c.user_id, u.wallet_address, c.amount, c.status, c.created_at
                    FROM claims c
                    LEFT JOIN users u ON u.id = c.user_id
                    ORDER BY c.id DESC
                    LIMIT 5';
$recentClaimsResult = $conn->query($recentClaimsSql);
if ($recentClaimsResult) {
    while ($claimRow = $recentClaimsResult->fetch_assoc()) {
        $recentClaims[] = $claimRow;
    }
}
?>
<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TurboSol Admin Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container">
        <span class="navbar-brand">TurboSol Admin</span>
        <div class="ms-auto">
            <a href="admin.php?logout=1" class="btn btn-outline-light btn-sm">Logout</a>
        </div>
    </div>
</nav>

<div class="container py-4">
    <h1 class="h3 mb-4">Správa faucetu</h1>

    <?php if ($error !== ''): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <?php if ($success !== ''): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-lg-6">
            <div class="card shadow-sm">
                <div class="card-header fw-semibold">Nastavení faucetu</div>
                <div class="card-body">
                    <form method="POST" action="admin.php" class="d-grid gap-3">
                        <input type="hidden" name="update_settings" value="1">

                        <div>
                            <label class="form-label">Claim interval (minuty)</label>
                            <input type="number" class="form-control" name="claim_interval_minutes" min="1" value="<?php echo (int)$settings['claim_interval_minutes']; ?>" required>
                        </div>

                        <div>
                            <label class="form-label">Min amount (SOL)</label>
                            <input type="number" class="form-control" name="min_amount" min="0.000000001" step="0.000000001" value="<?php echo htmlspecialchars((string)$settings['min_amount'], ENT_QUOTES, 'UTF-8'); ?>" required>
                        </div>

                        <div>
                            <label class="form-label">Max amount (SOL)</label>
                            <input type="number" class="form-control" name="max_amount" min="0.000000001" step="0.000000001" value="<?php echo htmlspecialchars((string)$settings['max_amount'], ENT_QUOTES, 'UTF-8'); ?>" required>
                        </div>

                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="faucet_active" id="faucet_active" <?php echo ((int)$settings['faucet_active'] === 1) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="faucet_active">Faucet aktivní</label>
                        </div>

                        <button type="submit" class="btn btn-primary">Uložit nastavení</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="row g-3">
                <div class="col-12">
                    <div class="card shadow-sm">
                        <div class="card-body">
                            <h2 class="h6 text-muted mb-1">Počet claimů</h2>
                            <div class="fs-4 fw-bold"><?php echo $statsClaimsCount; ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-12">
                    <div class="card shadow-sm">
                        <div class="card-body">
                            <h2 class="h6 text-muted mb-1">Celkem odesláno (SOL)</h2>
                            <div class="fs-4 fw-bold"><?php echo $statsClaimsSum; ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-12">
                    <div class="card shadow-sm">
                        <div class="card-body">
                            <h2 class="h6 text-muted mb-1">Počet uživatelů</h2>
                            <div class="fs-4 fw-bold"><?php echo $statsUsersCount; ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm mt-4">
        <div class="card-header fw-semibold">Posledních 5 claimů</div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-striped mb-0">
                    <thead>
                    <tr>
                        <th>ID</th>
                        <th>User ID</th>
                        <th>Wallet</th>
                        <th>Amount (SOL)</th>
                        <th>Status</th>
                        <th>Created</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (count($recentClaims) === 0): ?>
                        <tr>
                            <td colspan="6" class="text-center text-muted py-3">Zatím žádné claimy.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($recentClaims as $claim): ?>
                            <tr>
                                <td><?php echo (int)$claim['id']; ?></td>
                                <td><?php echo (int)$claim['user_id']; ?></td>
                                <td><?php echo htmlspecialchars((string)($claim['wallet_address'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo number_format((float)$claim['amount'], 9, '.', ''); ?></td>
                                <td><?php echo htmlspecialchars((string)$claim['status'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars((string)$claim['created_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
</body>
</html>
