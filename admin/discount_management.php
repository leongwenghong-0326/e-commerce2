<?php
include_once '../config/config.php';
include_once '../config/auth.php';
require_once '../discount_system.php';

if (empty($_SESSION['admin_csrf'])) {
    $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
}

$statusType = '';
$statusMessage = '';

if (isset($_GET['status'], $_GET['message'])) {
    $statusType = $_GET['status'] === 'ok' ? 'success' : 'danger';
    $statusMessage = trim((string) $_GET['message']);
}

try {
    ensureCampaignDiscountTable($pdo);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $csrf = trim((string) ($_POST['csrf'] ?? ''));
        if (!hash_equals($_SESSION['admin_csrf'], $csrf)) {
            throw new RuntimeException('Invalid security token. Please refresh and try again.');
        }

        $action = trim((string) ($_POST['action'] ?? 'save_campaign'));
        $selectedDiscountKey = trim((string) ($_POST['discount_key'] ?? 'none'));

        if ($action === 'history_activate') {
            setDiscountKeyActiveState($pdo, $selectedDiscountKey, true);
            header('Location: discount_management.php?status=ok&message=' . urlencode('Discount activated successfully.'));
            exit();
        }

        if ($action === 'history_deactivate') {
            setDiscountKeyActiveState($pdo, $selectedDiscountKey, false);
            header('Location: discount_management.php?status=ok&message=' . urlencode('Discount deactivated successfully.'));
            exit();
        }

        if ($action === 'history_update_priority') {
            $priority = (int) ($_POST['priority'] ?? 100);
            setDiscountPriority($pdo, $selectedDiscountKey, $priority);
            header('Location: discount_management.php?status=ok&message=' . urlencode('Discount priority updated successfully.'));
            exit();
        }

        if ($action === 'history_update_rules') {
            $priority = (int) ($_POST['priority'] ?? 100);
            $minSubtotal = (float) ($_POST['min_subtotal'] ?? 0);
            setDiscountPriority($pdo, $selectedDiscountKey, $priority);
            setDiscountMinSubtotal($pdo, $selectedDiscountKey, $minSubtotal);
            header('Location: discount_management.php?status=ok&message=' . urlencode('Discount rules updated successfully.'));
            exit();
        }

        if ($action === 'history_update_min_subtotal') {
            $minSubtotal = (float) ($_POST['min_subtotal'] ?? 0);
            setDiscountMinSubtotal($pdo, $selectedDiscountKey, $minSubtotal);
            header('Location: discount_management.php?status=ok&message=' . urlencode('Minimum order rule updated successfully.'));
            exit();
        }

        setDiscountKeyActiveState($pdo, $selectedDiscountKey, true);
        header('Location: discount_management.php?status=ok&message=' . urlencode('Discount activated successfully.'));
        exit();
    }

    $discountOptions = getDiscountOptions();
    $activeContexts = getActiveDiscountContexts($pdo);
    $activeContext = empty($activeContexts)
        ? getDiscountContextByKey('none')
        : $activeContexts[0];
    $activeLabelText = empty($activeContexts)
        ? 'No Discount'
        : implode(', ', array_map(static fn(array $ctx): string => (string) $ctx['label'], $activeContexts));

    $historyStmt = $pdo->query(
              'SELECT DiscountKey, IsActive, Priority, MinSubtotal, UpdatedDate
         FROM CampaignDiscounts
            ORDER BY Priority ASC, UpdatedDate DESC
         LIMIT 10'
    );
    $historyRows = $historyStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $statusType = 'danger';
    $statusMessage = $e->getMessage();
    $discountOptions = getDiscountOptions();
    $activeContexts = [];
    $activeContext = getDiscountContextByKey('none');
    $activeLabelText = 'No Discount';
    $historyRows = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Discount Management || E-Commerce</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@500;700;800&family=IBM+Plex+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="../asset/css/admin-base.css">
    <link rel="stylesheet" href="../asset/css/admin-layout-responsive.css">
    <link rel="stylesheet" href="../asset/css/admin-discount-management.css">
</head>
<body>
<div class="dashboard-scroll">
<div class="dashboard-shell">
    <aside class="sidebar">
        <span class="brand-tag">Admin Portal</span>
        <h2>Discount Management</h2>
        <nav class="nav-links">
            <a href="admin_dashboard.php"><i class="bi bi-speedometer2"></i> Control Panel</a>
            <a href="product_management.php"><i class="bi bi-box"></i> Product Management</a>
            <a href="category_management.php"><i class="bi bi-tags"></i> Category Management</a>
            <a href="member_management.php"><i class="bi bi-people"></i> Member Management</a>
            <a href="order_management.php"><i class="bi bi-cart"></i> Order Management</a>
            <a href="discount_management.php" class="active"><i class="bi bi-percent"></i> Discount Campaign</a>
            <a href="admin_logout.php"><i class="bi bi-gear"></i> Logout</a>
        </nav>
    </aside>

    <main class="main">
        <div class="topbar">
            <h1>Discount Campaign</h1>
            <span class="admin-badge">Admin</span>
        </div>

        <?php if ($statusMessage !== ''): ?>
            <div class="alert alert-<?php echo $statusType === 'success' ? 'success' : 'danger'; ?>" role="alert">
                <?php echo htmlspecialchars($statusMessage); ?>
            </div>
        <?php endif; ?>

        <section class="panel-card">
            <h2 class="h5 mb-3">Active Campaign Setup</h2>
            <form method="post" action="discount_management.php">
                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($_SESSION['admin_csrf']); ?>">

                <label class="form-label fw-semibold" for="discount_key">Select active discount strategy</label>
                <select class="form-select" id="discount_key" name="discount_key" required>
                    <?php foreach ($discountOptions as $discountKey => $discountMeta): ?>
                        <option value="<?php echo htmlspecialchars($discountKey); ?>" <?php echo $discountKey === $activeContext['key'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars((string) $discountMeta['label']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <button type="submit" class="btn btn-success mt-3">
                    <i class="bi bi-check-circle"></i> Save Campaign
                </button>
            </form>
        </section>

        <section class="panel-card mt-3">
            <h2 class="h5 mb-3">Current Live Strategies</h2>
            <div class="summary-grid">
                <div class="summary-item">
                    <span class="label">Campaigns</span>
                    <strong><?php echo htmlspecialchars($activeLabelText); ?></strong>
                </div>
                <div class="summary-item">
                    <span class="label">Type</span>
                    <strong><?php echo htmlspecialchars((string) strtoupper((string) $activeContext['type'])); ?></strong>
                </div>
                <div class="summary-item">
                    <span class="label">Value</span>
                    <strong><?php echo $activeContext['type'] === 'percentage' ? (number_format((float) $activeContext['value'], 0) . '%') : ('RM ' . number_format((float) $activeContext['value'], 2)); ?></strong>
                </div>
            </div>
        </section>

        <section class="panel-card mt-3">
            <h2 class="h5 mb-3">Recent Campaign History</h2>
            <div class="small text-muted mb-2">
                Rules guide: a discount is applied only when cart subtotal is greater than or equal to Min Order (RM), then all eligible discounts are applied by Priority (lower number first). Example: if Flat RM 50 has Min Order RM 200, it applies only at RM 200 and above.
            </div>
            <?php if (empty($historyRows)): ?>
                <p class="text-muted mb-0">No campaign history found.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Discount Key</th>
                                <th>Status</th>
                                <th>Priority</th>
                                <th>Min Order (RM)</th>
                                <th>Updated</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($historyRows as $row): ?>
                                <?php $ctx = getDiscountContextByKey((string) ($row['DiscountKey'] ?? 'none')); ?>
                                <?php $isActive = (int) ($row['IsActive'] ?? 0) === 1; ?>
                                <tr>
                                    <td><?php echo htmlspecialchars((string) $ctx['label']); ?></td>
                                    <td>
                                        <?php if ($isActive): ?>
                                            <span class="badge text-bg-success">Active</span>
                                        <?php else: ?>
                                            <span class="badge text-bg-secondary">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <form method="post" action="discount_management.php" class="d-inline-flex align-items-center gap-1 m-0">
                                            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($_SESSION['admin_csrf']); ?>">
                                            <input type="hidden" name="discount_key" value="<?php echo htmlspecialchars((string) $ctx['key']); ?>">
                                            <input type="number" name="priority" class="form-control form-control-sm priority-input" min="1" max="9999" value="<?php echo (int) ($row['Priority'] ?? getDefaultDiscountPriority((string) $ctx['key'])); ?>">
                                            <button type="submit" name="action" value="history_update_priority" class="btn btn-sm btn-outline-primary">Save</button>
                                        </form>
                                    </td>
                                    <td>
                                        <form method="post" action="discount_management.php" class="d-inline-flex align-items-center gap-1 m-0">
                                            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($_SESSION['admin_csrf']); ?>">
                                            <input type="hidden" name="discount_key" value="<?php echo htmlspecialchars((string) $ctx['key']); ?>">
                                            <input type="number" name="min_subtotal" class="form-control form-control-sm min-subtotal-input" min="0" step="0.01" value="<?php echo htmlspecialchars(number_format((float) ($row['MinSubtotal'] ?? getDefaultDiscountMinSubtotal((string) $ctx['key'])), 2, '.', '')); ?>">
                                            <button type="submit" name="action" value="history_update_min_subtotal" class="btn btn-sm btn-outline-primary">Save</button>
                                        </form>
                                    </td>
                                    <td><?php echo htmlspecialchars((string) ($row['UpdatedDate'] ?? '-')); ?></td>
                                    <td class="text-end">
                                        <form method="post" action="discount_management.php" class="d-inline-flex gap-1 m-0">
                                            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($_SESSION['admin_csrf']); ?>">
                                            <input type="hidden" name="discount_key" value="<?php echo htmlspecialchars((string) $ctx['key']); ?>">
                                            <button type="submit" name="action" value="history_activate" class="btn btn-sm btn-success">Activate</button>
                                            <button type="submit" name="action" value="history_deactivate" class="btn btn-sm btn-outline-danger">Deactivate</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </main>
</div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
