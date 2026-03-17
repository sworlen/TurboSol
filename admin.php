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
        $error = 'Invalid admin password.';
    }
    ?>
    <!DOCTYPE html>
    <html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>TurboSol Admin Login</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"></head>
    <body class="bg-dark text-light d-flex align-items-center" style="min-height:100vh;"><div class="container"><div class="row justify-content-center"><div class="col-md-5"><div class="card shadow-lg"><div class="card-body p-4"><h1 class="h4 mb-3 text-dark">TurboSol Admin</h1><?php if ($error !== ''): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?><form method="POST" action="admin.php" class="d-grid gap-3"><input type="password" class="form-control" name="password" placeholder="Admin password" required><button type="submit" class="btn btn-primary">Login</button></form></div></div></div></div></div></body></html>
    <?php
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_settings'])) {
    $interval = (int)($_POST['claim_interval_minutes'] ?? DEFAULT_CLAIM_INTERVAL_MIN);
    $minAmount = (float)($_POST['min_amount'] ?? MIN_SOL_AMOUNT);
    $maxAmount = (float)($_POST['max_amount'] ?? MAX_SOL_AMOUNT);
    $active = isset($_POST['faucet_active']) ? 1 : 0;

    $adHorizontalUrl = trim((string)($_POST['ad_horizontal_url'] ?? ''));
    $adVerticalLeftUrl = trim((string)($_POST['ad_vertical_left_url'] ?? ''));
    $adVerticalRightUrl = trim((string)($_POST['ad_vertical_right_url'] ?? ''));

    if ($interval <= 0 || $minAmount <= 0 || $maxAmount <= 0 || $minAmount > $maxAmount) {
        $error = 'Invalid settings values.';
    } else {
        $minSql = number_format($minAmount, 9, '.', '');
        $maxSql = number_format($maxAmount, 9, '.', '');

        $adHorizontalEsc = mysqli_real_escape_string($conn, $adHorizontalUrl);
        $adVerticalLeftEsc = mysqli_real_escape_string($conn, $adVerticalLeftUrl);
        $adVerticalRightEsc = mysqli_real_escape_string($conn, $adVerticalRightUrl);

        $updateSql = "UPDATE settings
            SET claim_interval_minutes = $interval,
                min_amount = $minSql,
                max_amount = $maxSql,
                faucet_active = $active,
                ad_horizontal_url = '$adHorizontalEsc',
                ad_vertical_left_url = '$adVerticalLeftEsc',
                ad_vertical_right_url = '$adVerticalRightEsc'
            WHERE id = 1";

        if ($conn->query($updateSql)) {
            $success = 'Settings updated.';
        } else {
            $error = 'Settings update failed. Run latest db.sql migration first.';
        }
    }
}

$settingsResult = $conn->query('SELECT * FROM settings WHERE id = 1 LIMIT 1');
$settings = $settingsResult && $settingsResult->num_rows > 0 ? $settingsResult->fetch_assoc() : [
    'claim_interval_minutes' => DEFAULT_CLAIM_INTERVAL_MIN,
    'min_amount' => MIN_SOL_AMOUNT,
    'max_amount' => MAX_SOL_AMOUNT,
    'faucet_active' => 1,
    'ad_horizontal_url' => '',
    'ad_vertical_left_url' => '',
    'ad_vertical_right_url' => '',
];

$statsClaimsCount = (int)(($conn->query('SELECT COUNT(*) AS c FROM claims')->fetch_assoc()['c']) ?? 0);
$statsClaimsSum = number_format((float)(($conn->query('SELECT COALESCE(SUM(amount),0) AS s FROM claims WHERE status="sent"')->fetch_assoc()['s']) ?? 0), 9, '.', '');
$statsUsersCount = (int)(($conn->query('SELECT COUNT(*) AS c FROM users')->fetch_assoc()['c']) ?? 0);
$statsMaxLevel = (int)(($conn->query('SELECT COALESCE(MAX(level),1) AS m FROM users')->fetch_assoc()['m']) ?? 1);

$recentClaims = [];
$recentClaimsSql = 'SELECT c.id, c.user_id, u.email, u.level, c.amount, c.status, c.created_at
                    FROM claims c
                    LEFT JOIN users u ON u.id = c.user_id
                    ORDER BY c.id DESC
                    LIMIT 10';
$recentClaimsResult = $conn->query($recentClaimsSql);
if ($recentClaimsResult) {
    while ($claimRow = $recentClaimsResult->fetch_assoc()) {
        $recentClaims[] = $claimRow;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>TurboSol Admin Panel</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"></head>
<body class="bg-light">
<nav class="navbar navbar-expand-lg navbar-dark bg-dark"><div class="container"><span class="navbar-brand">TurboSol Admin</span><div class="ms-auto"><a href="admin.php?logout=1" class="btn btn-outline-light btn-sm">Logout</a></div></div></nav>
<div class="container py-4">
    <h1 class="h3 mb-4">Faucet Settings</h1>

    <?php if ($error !== ''): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
    <?php if ($success !== ''): ?><div class="alert alert-success"><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>

    <div class="row g-4">
        <div class="col-lg-6">
            <div class="card shadow-sm"><div class="card-header fw-semibold">Claim + Ads Configuration</div><div class="card-body">
                <form method="POST" action="admin.php" class="d-grid gap-3">
                    <input type="hidden" name="update_settings" value="1">
                    <div><label class="form-label">Claim Interval (minutes)</label><input type="number" class="form-control" name="claim_interval_minutes" min="1" value="<?php echo (int)$settings['claim_interval_minutes']; ?>" required></div>
                    <div><label class="form-label">Min amount (SOL)</label><input type="number" class="form-control" name="min_amount" min="0.000000001" step="0.000000001" value="<?php echo htmlspecialchars((string)$settings['min_amount'], ENT_QUOTES, 'UTF-8'); ?>" required></div>
                    <div><label class="form-label">Max amount (SOL)</label><input type="number" class="form-control" name="max_amount" min="0.000000001" step="0.000000001" value="<?php echo htmlspecialchars((string)$settings['max_amount'], ENT_QUOTES, 'UTF-8'); ?>" required></div>
                    <div class="form-check"><input class="form-check-input" type="checkbox" name="faucet_active" id="faucet_active" <?php echo ((int)$settings['faucet_active'] === 1) ? 'checked' : ''; ?>><label class="form-check-label" for="faucet_active">Faucet Active</label></div>

                    <hr>
                    <div><label class="form-label">Horizontal Ad Link</label><input type="url" class="form-control" name="ad_horizontal_url" placeholder="https://your-ad-link.example" value="<?php echo htmlspecialchars((string)$settings['ad_horizontal_url'], ENT_QUOTES, 'UTF-8'); ?>"></div>
                    <div><label class="form-label">Vertical Ad Link (Left)</label><input type="url" class="form-control" name="ad_vertical_left_url" placeholder="https://your-left-ad.example" value="<?php echo htmlspecialchars((string)$settings['ad_vertical_left_url'], ENT_QUOTES, 'UTF-8'); ?>"></div>
                    <div><label class="form-label">Vertical Ad Link (Right)</label><input type="url" class="form-control" name="ad_vertical_right_url" placeholder="https://your-right-ad.example" value="<?php echo htmlspecialchars((string)$settings['ad_vertical_right_url'], ENT_QUOTES, 'UTF-8'); ?>"></div>

                    <button type="submit" class="btn btn-primary">Save Settings</button>
                </form>
            </div></div>
        </div>

        <div class="col-lg-6"><div class="row g-3">
            <div class="col-12"><div class="card shadow-sm"><div class="card-body"><h2 class="h6 text-muted mb-1">Claims Count</h2><div class="fs-4 fw-bold"><?php echo $statsClaimsCount; ?></div></div></div></div>
            <div class="col-12"><div class="card shadow-sm"><div class="card-body"><h2 class="h6 text-muted mb-1">Total Sent (SOL)</h2><div class="fs-4 fw-bold"><?php echo $statsClaimsSum; ?></div></div></div></div>
            <div class="col-12"><div class="card shadow-sm"><div class="card-body"><h2 class="h6 text-muted mb-1">Users Count</h2><div class="fs-4 fw-bold"><?php echo $statsUsersCount; ?></div></div></div></div>
            <div class="col-12"><div class="card shadow-sm"><div class="card-body"><h2 class="h6 text-muted mb-1">Highest Level</h2><div class="fs-4 fw-bold"><?php echo $statsMaxLevel; ?></div></div></div></div>
        </div></div>
    </div>

    <div class="card shadow-sm mt-4"><div class="card-header fw-semibold">Last 10 Claims</div><div class="card-body p-0"><div class="table-responsive"><table class="table table-striped mb-0"><thead><tr><th>ID</th><th>User ID</th><th>Email</th><th>Level</th><th>Amount (SOL)</th><th>Status</th><th>Created</th></tr></thead><tbody>
    <?php if (count($recentClaims) === 0): ?><tr><td colspan="7" class="text-center text-muted py-3">No claims yet.</td></tr><?php else: ?>
        <?php foreach ($recentClaims as $claim): ?>
            <tr><td><?php echo (int)$claim['id']; ?></td><td><?php echo (int)$claim['user_id']; ?></td><td><?php echo htmlspecialchars((string)($claim['email'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></td><td><?php echo (int)($claim['level'] ?? 1); ?></td><td><?php echo number_format((float)$claim['amount'], 9, '.', ''); ?></td><td><?php echo htmlspecialchars((string)$claim['status'], ENT_QUOTES, 'UTF-8'); ?></td><td><?php echo htmlspecialchars((string)$claim['created_at'], ENT_QUOTES, 'UTF-8'); ?></td></tr>
        <?php endforeach; ?>
    <?php endif; ?>
    </tbody></table></div></div></div>
</div>
</body>
</html>
