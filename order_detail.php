<?php
require_once 'config/config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: member_login.php');
    exit();
}

$userId = (string) $_SESSION['user_id'];
$orderId = trim((string) ($_GET['id'] ?? ''));
if ($orderId === '') {
    header('Location: my_orders.php');
    exit();
}

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

$orderStmt = $pdo->prepare(
    'SELECT
        o.OrderId,
        o.TotalAmount,
        COALESCE(NULLIF(o.OrderStatus, ""), "Pending") AS OrderStatus,
        o.OrderDate,
        o.ShippingAddress,
        o.AddressId,
        a.RecipientName,
        a.PhoneNumber
     FROM Orders o
     LEFT JOIN Addresses a ON a.AddressId = o.AddressId
     WHERE o.OrderId = :order_id
       AND o.UserId = :user_id
     LIMIT 1'
);
$orderStmt->execute([
    ':order_id' => $orderId,
    ':user_id' => $userId,
]);
$order = $orderStmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    header('Location: my_orders.php');
    exit();
}

$itemStmt = $pdo->prepare(
    'SELECT
        oi.OrderItemId,
        oi.ProductId,
        oi.Quantity,
        oi.UnitPrice,
        p.ProductName,
        (
            SELECT ImageUrl
            FROM ProductImages pi
            WHERE pi.ProductId = p.ProductId
            ORDER BY pi.IsPrimary DESC, pi.ImageId DESC
            LIMIT 1
        ) AS ProductImage
     FROM OrderItems oi
     LEFT JOIN Products p ON p.ProductId = oi.ProductId
     WHERE oi.OrderId = :order_id
     ORDER BY oi.OrderItemId ASC'
);
$itemStmt->execute([':order_id' => $orderId]);
$orderItems = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

$totalQty = 0;
foreach ($orderItems as $line) {
    $totalQty += (int) ($line['Quantity'] ?? 0);
}

$status = strtolower((string) ($order['OrderStatus'] ?? 'pending'));
$statusClass = 'status-' . preg_replace('/[^a-z]/', '', $status);
$shippingMeta = parseShippingSnapshot((string) ($order['ShippingAddress'] ?? ''));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>E-commerce | Order Detail</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
        <link rel="stylesheet" href="asset/css/member-theme.css">
    <link rel="stylesheet" href="asset/css/member-order-detail.css">
    
</head>
<body>
<?php include 'layout/nav.php'; ?>

<div class="page-shell">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <h1 class="h3 mb-0 page-title">Order Detail</h1>
        <a href="my_orders.php" class="btn btn-outline-success btn-sm">Back to My Orders</a>
    </div>

    <section class="panel mb-3">
        <header class="panel-head d-flex justify-content-between align-items-start flex-wrap gap-2">
            <div>
                <div class="fw-semibold">Order ID</div>
                <div class="text-break"><?php echo htmlspecialchars((string) $order['OrderId']); ?></div>
            </div>
            <span class="status-pill <?php echo htmlspecialchars($statusClass); ?>"><?php echo htmlspecialchars((string) $order['OrderStatus']); ?></span>
        </header>
        <div class="p-3">
            <div class="row g-3">
                <div class="col-12 col-md-6">
                    <div class="small text-muted">Order Date</div>
                    <div><?php echo htmlspecialchars((string) ($order['OrderDate'] ?? '-')); ?></div>
                </div>
                <div class="col-12 col-md-6">
                    <div class="small text-muted">Total Amount</div>
                    <div class="fw-semibold">RM <?php echo number_format((float) ($order['TotalAmount'] ?? 0), 2); ?></div>
                </div>
                <div class="col-12 col-md-6">
                    <div class="small text-muted">Recipient</div>
                    <div><?php echo htmlspecialchars((string) (($order['RecipientName'] ?? '') !== '' ? $order['RecipientName'] : '-')); ?></div>
                </div>
                <div class="col-12 col-md-6">
                    <div class="small text-muted">Phone</div>
                    <div><?php echo htmlspecialchars((string) (($order['PhoneNumber'] ?? '') !== '' ? $order['PhoneNumber'] : '-')); ?></div>
                </div>
                <div class="col-12">
                    <div class="small text-muted">Shipping Address</div>
                    <div><?php echo nl2br(htmlspecialchars((string) $shippingMeta['address'])); ?></div>
                </div>
                <div class="col-12 col-md-6">
                    <div class="small text-muted">Payment Method</div>
                    <div>
                        <?php if ($shippingMeta['payment'] !== ''): ?>
                            <i class="bi <?php echo htmlspecialchars(getPaymentIconClass((string) $shippingMeta['payment'])); ?> me-1" aria-hidden="true"></i><?php echo htmlspecialchars((string) $shippingMeta['payment']); ?>
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-12 col-md-6">
                    <div class="small text-muted">Shipping Method</div>
                    <div><?php echo htmlspecialchars((string) ($shippingMeta['method'] !== '' ? $shippingMeta['method'] : '-')); ?></div>
                </div>
                <div class="col-12 col-md-6">
                    <div class="small text-muted">Delivery ETA</div>
                    <div><?php echo htmlspecialchars((string) ($shippingMeta['estimate'] !== '' ? $shippingMeta['estimate'] : '-')); ?></div>
                </div>
                <div class="col-12">
                    <div class="small text-muted">Order Notes</div>
                    <div><?php echo nl2br(htmlspecialchars((string) ($shippingMeta['notes'] !== '' ? $shippingMeta['notes'] : '-'))); ?></div>
                </div>
            </div>
        </div>
    </section>

    <section class="panel">
        <header class="panel-head d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div class="fw-semibold">Items (<?php echo count($orderItems); ?> line(s), <?php echo $totalQty; ?> qty)</div>
        </header>

        <?php if (empty($orderItems)): ?>
            <div class="p-3 text-muted">No items found for this order.</div>
        <?php else: ?>
            <?php foreach ($orderItems as $item): ?>
                <?php
                $qty = max(1, (int) ($item['Quantity'] ?? 1));
                $unitPrice = (float) ($item['UnitPrice'] ?? 0);
                $lineTotal = $qty * $unitPrice;
                $image = trim((string) ($item['ProductImage'] ?? ''));
                $imageSrc = $image !== '' ? $image : 'asset/image/default_avatar.png';
                ?>
                <article class="line-item">
                    <img class="item-image" src="<?php echo htmlspecialchars($imageSrc); ?>" alt="<?php echo htmlspecialchars((string) ($item['ProductName'] ?? 'Product')); ?>">
                    <div>
                        <div class="item-name"><?php echo htmlspecialchars((string) (($item['ProductName'] ?? '') !== '' ? $item['ProductName'] : 'Product unavailable')); ?></div>
                        <div class="item-meta">Qty: <?php echo $qty; ?> x RM <?php echo number_format($unitPrice, 2); ?></div>
                    </div>
                    <div class="item-total">RM <?php echo number_format($lineTotal, 2); ?></div>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

