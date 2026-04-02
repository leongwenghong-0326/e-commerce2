<?php
require_once 'config/config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: member_login.php');
    exit();
}

$userId = (string) $_SESSION['user_id'];
$currentPage = max(1, (int) ($_GET['page'] ?? 1));
$itemsPerPage = 10;

function parseShippingSnapshot(string $shippingAddress): array
{
    $shippingAddress = trim($shippingAddress);
    if ($shippingAddress === '') {
        return [
            'address' => '-',
            'payment' => '',
            'method' => '',
            'estimate' => '',
            'notes' => '',
        ];
    }

    $parts = preg_split('/\R\R+/', $shippingAddress, 2);
    $address = trim((string) ($parts[0] ?? ''));
    $metaBlock = trim((string) ($parts[1] ?? ''));

    $payment = '';
    $method = '';
    $estimate = '';
    $notes = '';

    if ($metaBlock !== '') {
        $lines = preg_split('/\R+/', $metaBlock);
        foreach ($lines as $line) {
            $line = trim((string) $line);
            if (str_starts_with($line, 'Payment Method: ')) {
                $payment = trim(substr($line, strlen('Payment Method: ')));
            } elseif (str_starts_with($line, 'Shipping Method: ')) {
                $method = trim(substr($line, strlen('Shipping Method: ')));
            } elseif (str_starts_with($line, 'Estimated Delivery: ')) {
                $estimate = trim(substr($line, strlen('Estimated Delivery: ')));
            } elseif (str_starts_with($line, 'Order Notes: ')) {
                $notes = trim(substr($line, strlen('Order Notes: ')));
            }
        }
    }

    return [
        'address' => $address !== '' ? $address : '-',
        'payment' => $payment,
        'method' => $method,
        'estimate' => $estimate,
        'notes' => $notes,
    ];
}

function getPaymentIconClass(string $paymentLabel): string
{
    $normalized = strtolower(trim($paymentLabel));
    if ($normalized === '') {
        return 'bi-wallet2';
    }
    if (str_contains($normalized, 'fpx') || str_contains($normalized, 'online banking')) {
        return 'bi-bank';
    }
    if (str_contains($normalized, 'card') || str_contains($normalized, 'credit') || str_contains($normalized, 'debit')) {
        return 'bi-credit-card-2-front';
    }
    if (str_contains($normalized, 'cash')) {
        return 'bi-cash-coin';
    }
    return 'bi-wallet2';
}

$countStmt = $pdo->prepare('SELECT COUNT(*) FROM Orders WHERE UserId = :user_id');
$countStmt->execute([':user_id' => $userId]);
$totalOrders = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalOrders / $itemsPerPage));
$currentPage = min($currentPage, $totalPages);
$offset = ($currentPage - 1) * $itemsPerPage;

$orderStmt = $pdo->prepare(
    'SELECT
        o.OrderId,
        o.TotalAmount,
        COALESCE(NULLIF(o.OrderStatus, ""), "Pending") AS OrderStatus,
        o.OrderDate,
        o.ShippingAddress,
        (
            SELECT COUNT(*)
            FROM OrderItems oi
            WHERE oi.OrderId = o.OrderId
        ) AS ItemLines,
        (
            SELECT COALESCE(SUM(oi.Quantity), 0)
            FROM OrderItems oi
            WHERE oi.OrderId = o.OrderId
        ) AS TotalQty,
        (
            SELECT GROUP_CONCAT(CONCAT(p.ProductName, " x", oi.Quantity) ORDER BY p.ProductName SEPARATOR ", ")
            FROM OrderItems oi
            JOIN Products p ON p.ProductId = oi.ProductId
            WHERE oi.OrderId = o.OrderId
        ) AS ItemSummary
     FROM Orders o
     WHERE o.UserId = :user_id
     ORDER BY o.OrderDate DESC
     LIMIT ' . $itemsPerPage . ' OFFSET ' . $offset
);
$orderStmt->execute([':user_id' => $userId]);
$orders = $orderStmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>E-commerce | My Orders</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
        <link rel="stylesheet" href="asset/css/member-theme.css">
    <link rel="stylesheet" href="asset/css/member-my-orders.css">
    
</head>
<body>
<?php include 'layout/nav.php'; ?>

<div class="page-shell">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <h1 class="h3 mb-0 page-title">My Orders</h1>
        <a href="products.php" class="btn btn-outline-success btn-sm">Continue Shopping</a>
    </div>

    <?php if (empty($orders)): ?>
        <div class="alert alert-info mb-0">You do not have any orders yet.</div>
    <?php else: ?>
        <?php foreach ($orders as $order): ?>
            <?php
            $status = strtolower((string) ($order['OrderStatus'] ?? 'pending'));
            $statusClass = 'status-' . preg_replace('/[^a-z]/', '', $status);
            $summary = trim((string) ($order['ItemSummary'] ?? ''));
            $shippingMeta = parseShippingSnapshot((string) ($order['ShippingAddress'] ?? ''));
            if ($summary === '') {
                $summary = 'No item details.';
            }
            ?>
            <article class="order-card">
                <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
                    <div>
                        <div class="order-id">Order: <?php echo htmlspecialchars((string) $order['OrderId']); ?></div>
                        <div class="meta">Date: <?php echo htmlspecialchars((string) $order['OrderDate']); ?></div>
                        <div class="meta">Items: <?php echo (int) ($order['ItemLines'] ?? 0); ?> line(s), <?php echo (int) ($order['TotalQty'] ?? 0); ?> qty</div>
                    </div>
                    <div class="text-end">
                        <div class="fw-semibold mb-1">RM <?php echo number_format((float) ($order['TotalAmount'] ?? 0), 2); ?></div>
                        <span class="status-pill <?php echo htmlspecialchars($statusClass); ?>"><?php echo htmlspecialchars((string) $order['OrderStatus']); ?></span>
                    </div>
                </div>
                <hr>
                <div class="small mb-2"><strong>Items:</strong> <?php echo htmlspecialchars($summary); ?></div>
                <div class="small text-muted"><strong>Shipping Address:</strong> <?php echo nl2br(htmlspecialchars((string) $shippingMeta['address'])); ?></div>
                <?php if ($shippingMeta['payment'] !== ''): ?>
                    <div class="small text-muted"><strong>Payment:</strong> <i class="bi <?php echo htmlspecialchars(getPaymentIconClass((string) $shippingMeta['payment'])); ?> me-1" aria-hidden="true"></i><?php echo htmlspecialchars((string) $shippingMeta['payment']); ?></div>
                <?php endif; ?>
                <?php if ($shippingMeta['method'] !== ''): ?>
                    <div class="small text-muted"><strong>Method:</strong> <?php echo htmlspecialchars((string) $shippingMeta['method']); ?></div>
                <?php endif; ?>
                <?php if ($shippingMeta['estimate'] !== ''): ?>
                    <div class="small text-muted"><strong>Delivery ETA:</strong> <?php echo htmlspecialchars((string) $shippingMeta['estimate']); ?></div>
                <?php endif; ?>
                <?php if ($shippingMeta['notes'] !== ''): ?>
                    <div class="small text-muted"><strong>Order Notes:</strong> <?php echo htmlspecialchars((string) $shippingMeta['notes']); ?></div>
                <?php endif; ?>
                <div class="mt-2">
                    <a class="btn btn-outline-primary btn-sm" href="order_detail.php?id=<?php echo urlencode((string) $order['OrderId']); ?>">View Details</a>
                </div>
            </article>
        <?php endforeach; ?>

        <?php if ($totalPages > 1): ?>
            <nav class="mt-3">
                <ul class="pagination justify-content-center mb-0">
                    <?php for ($page = 1; $page <= $totalPages; $page++): ?>
                        <li class="page-item <?php echo $page === $currentPage ? 'active' : ''; ?>">
                            <a class="page-link" href="my_orders.php?page=<?php echo $page; ?>"><?php echo $page; ?></a>
                        </li>
                    <?php endfor; ?>
                </ul>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

