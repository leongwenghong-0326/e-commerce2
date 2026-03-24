<?php
require_once 'config/config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: member_login.php');
    exit();
}

$userId = (string) $_SESSION['user_id'];

if (empty($_SESSION['checkout_csrf'])) {
    $_SESSION['checkout_csrf'] = bin2hex(random_bytes(32));
}

function createUuidV4(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);
    return sprintf(
        '%s-%s-%s-%s-%s',
        substr($hex, 0, 8),
        substr($hex, 8, 4),
        substr($hex, 12, 4),
        substr($hex, 16, 4),
        substr($hex, 20, 12)
    );
}

function hasAddressColumn(PDO $pdo, string $columnName): bool
{
    static $cache = [];
    if (array_key_exists($columnName, $cache)) {
        return $cache[$columnName];
    }

    $stmt = $pdo->prepare(
        "SELECT 1
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'Addresses'
           AND COLUMN_NAME = ?
         LIMIT 1"
    );
    $stmt->execute([$columnName]);
    $cache[$columnName] = (bool) $stmt->fetchColumn();
    return $cache[$columnName];
}

function buildShippingAddress(array $address, bool $hasDetailed): string
{
    if ($hasDetailed) {
        $parts = [
            trim((string) ($address['AddressLine1'] ?? '')),
            trim((string) ($address['AddressLine2'] ?? '')),
            trim((string) ($address['Postcode'] ?? '')),
            trim((string) ($address['City'] ?? '')),
            trim((string) ($address['States'] ?? '')),
        ];
        $parts = array_values(array_filter($parts, static fn($p) => $p !== ''));
        if (!empty($parts)) {
            return implode(', ', $parts);
        }
    }

    return trim((string) ($address['FullAddress'] ?? ''));
}

function redirectCheckout(string $status, string $message): void
{
    $q = http_build_query([
        'status' => $status,
        'message' => $message,
    ]);
    header('Location: checkout.php?' . $q);
    exit();
}

$hasDetailedAddress = hasAddressColumn($pdo, 'AddressLine1')
    && hasAddressColumn($pdo, 'AddressLine2')
    && hasAddressColumn($pdo, 'States')
    && hasAddressColumn($pdo, 'City')
    && hasAddressColumn($pdo, 'Postcode');

$addressSql = 'SELECT AddressId, RecipientName, PhoneNumber, FullAddress, IsDefault';
if ($hasDetailedAddress) {
    $addressSql .= ', AddressLine1, AddressLine2, States, City, Postcode';
}
$addressSql .= ' FROM Addresses WHERE UserId = :user_id ORDER BY IsDefault DESC, AddressId DESC';
$addressStmt = $pdo->prepare($addressSql);
$addressStmt->execute([':user_id' => $userId]);
$addresses = $addressStmt->fetchAll(PDO::FETCH_ASSOC);

$cartStmt = $pdo->prepare(
    'SELECT c.CartId, c.ProductId, c.Quantity, p.ProductName, p.Price, p.StockQuantity,
            (
                SELECT ImageUrl
                FROM ProductImages pi
                WHERE pi.ProductId = p.ProductId
                ORDER BY pi.IsPrimary DESC, pi.ImageId DESC
                LIMIT 1
            ) AS ProductImage
     FROM Carts c
     JOIN Products p ON p.ProductId = c.ProductId
     WHERE c.UserId = :user_id
     ORDER BY c.AddedDate DESC'
);
$cartStmt->execute([':user_id' => $userId]);
$cartItems = $cartStmt->fetchAll(PDO::FETCH_ASSOC);

$subtotal = 0.0;
foreach ($cartItems as $item) {
    $subtotal += ((float) $item['Price'] * (int) $item['Quantity']);
}
$shippingFee = 0.0;
$totalAmount = $subtotal + $shippingFee;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = (string) ($_POST['csrf'] ?? '');
    if (!hash_equals($_SESSION['checkout_csrf'], $csrf)) {
        redirectCheckout('error', 'Invalid request token. Please refresh and try again.');
    }

    try {
        if (empty($cartItems)) {
            throw new RuntimeException('Your cart is empty.');
        }

        $addressId = trim((string) ($_POST['address_id'] ?? ''));
        if ($addressId === '') {
            throw new RuntimeException('Please select a shipping address.');
        }

        $selectedAddress = null;
        foreach ($addresses as $addr) {
            if ((string) $addr['AddressId'] === $addressId) {
                $selectedAddress = $addr;
                break;
            }
        }

        if ($selectedAddress === null) {
            throw new RuntimeException('Selected address is invalid.');
        }

        $pdo->beginTransaction();

        $lockedItemsStmt = $pdo->prepare(
            'SELECT c.CartId, c.ProductId, c.Quantity, p.ProductName, p.Price, p.StockQuantity
             FROM Carts c
             JOIN Products p ON p.ProductId = c.ProductId
             WHERE c.UserId = :user_id
             FOR UPDATE'
        );
        $lockedItemsStmt->execute([':user_id' => $userId]);
        $lockedItems = $lockedItemsStmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($lockedItems)) {
            $pdo->rollBack();
            throw new RuntimeException('Your cart is empty.');
        }

        $finalTotal = 0.0;
        foreach ($lockedItems as $line) {
            $qty = max(1, (int) $line['Quantity']);
            $stock = max(0, (int) $line['StockQuantity']);
            if ($stock < $qty) {
                $pdo->rollBack();
                throw new RuntimeException('Insufficient stock for ' . (string) $line['ProductName'] . '.');
            }
            $finalTotal += ((float) $line['Price'] * $qty);
        }

        $orderId = createUuidV4();
        $shippingAddress = buildShippingAddress($selectedAddress, $hasDetailedAddress);

        $insertOrder = $pdo->prepare(
            'INSERT INTO Orders (OrderId, UserId, AddressId, TotalAmount, OrderStatus, OrderDate, ShippingAddress)
             VALUES (:order_id, :user_id, :address_id, :total_amount, :order_status, NOW(), :shipping_address)'
        );
        $insertOrder->execute([
            ':order_id' => $orderId,
            ':user_id' => $userId,
            ':address_id' => $addressId,
            ':total_amount' => number_format($finalTotal, 2, '.', ''),
            ':order_status' => 'Pending',
            ':shipping_address' => $shippingAddress,
        ]);

        $insertItem = $pdo->prepare(
            'INSERT INTO OrderItems (OrderItemId, OrderId, ProductId, Quantity, UnitPrice)
             VALUES (:order_item_id, :order_id, :product_id, :qty, :unit_price)'
        );
        $updateStock = $pdo->prepare('UPDATE Products SET StockQuantity = StockQuantity - :qty WHERE ProductId = :product_id');

        foreach ($lockedItems as $line) {
            $qty = max(1, (int) $line['Quantity']);
            $insertItem->execute([
                ':order_item_id' => createUuidV4(),
                ':order_id' => $orderId,
                ':product_id' => (string) $line['ProductId'],
                ':qty' => $qty,
                ':unit_price' => number_format((float) $line['Price'], 2, '.', ''),
            ]);
            $updateStock->execute([
                ':qty' => $qty,
                ':product_id' => (string) $line['ProductId'],
            ]);
        }

        $clearCart = $pdo->prepare('DELETE FROM Carts WHERE UserId = :user_id');
        $clearCart->execute([':user_id' => $userId]);

        $pdo->commit();

        header('Location: checkout.php?success=1&order=' . urlencode($orderId));
        exit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        redirectCheckout('error', $e->getMessage());
    }
}

$statusType = '';
$statusMessage = '';
if (isset($_GET['status'], $_GET['message'])) {
    $statusType = $_GET['status'] === 'ok' ? 'success' : 'error';
    $statusMessage = (string) $_GET['message'];
}

$successOrderId = isset($_GET['success'], $_GET['order']) && $_GET['success'] === '1'
    ? trim((string) $_GET['order'])
    : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>E-commerce | Checkout</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
    <style>
        body {
            font-family: 'Outfit', sans-serif;
            background: #f8fbf9;
            color: #1b2530;
        }
        .page-shell {
            max-width: 1120px;
            margin: 24px auto 40px;
            padding: 0 14px;
        }
        .panel {
            background: #fff;
            border: 1px solid #e2ede9;
            border-radius: 16px;
            overflow: hidden;
        }
        .panel-head {
            padding: 14px 16px;
            border-bottom: 1px solid #e8f0eb;
            font-weight: 700;
        }
        .address-card {
            border: 1px solid #dae8e2;
            border-radius: 12px;
            padding: 12px;
            margin-bottom: 10px;
        }
        .line-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 8px;
            padding: 10px 0;
            border-bottom: 1px solid #edf3ef;
        }
        .line-item:last-child {
            border-bottom: none;
        }
    </style>
</head>
<body>
<?php include 'layout/nav.php'; ?>

<div class="page-shell">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <h1 class="h3 mb-0" style="font-family: 'Space Grotesk', sans-serif;">Checkout</h1>
        <a href="cart.php" class="btn btn-outline-success btn-sm"><i class="bi bi-arrow-left"></i> Back to Cart</a>
    </div>

    <?php if ($statusMessage !== ''): ?>
        <div class="alert <?php echo $statusType === 'success' ? 'alert-success' : 'alert-danger'; ?>" role="alert">
            <?php echo htmlspecialchars($statusMessage); ?>
        </div>
    <?php endif; ?>

    <?php if ($successOrderId !== ''): ?>
        <div class="alert alert-success">
            <h2 class="h5 mb-2">Order Created Successfully</h2>
            <p class="mb-2">Your order has been placed and is pending fulfillment.</p>
            <div><strong>Order ID:</strong> <?php echo htmlspecialchars($successOrderId); ?></div>
            <div class="mt-3">
                <a href="products.php" class="btn btn-success btn-sm">Continue Shopping</a>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($successOrderId === ''): ?>
        <div class="row g-3">
            <div class="col-12 col-lg-7">
                <section class="panel">
                    <header class="panel-head">Shipping Address</header>
                    <div class="p-3">
                        <?php if (empty($addresses)): ?>
                            <div class="alert alert-warning mb-0">
                                No address found. Please add an address in <a href="userProfile.php">your profile</a>.
                            </div>
                        <?php else: ?>
                            <form id="checkoutForm" method="post" action="checkout.php">
                                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($_SESSION['checkout_csrf']); ?>">

                                <?php foreach ($addresses as $addr): ?>
                                    <?php
                                    $addressText = buildShippingAddress($addr, $hasDetailedAddress);
                                    $isDefault = (int) ($addr['IsDefault'] ?? 0) === 1;
                                    ?>
                                    <label class="address-card d-block" for="addr_<?php echo htmlspecialchars((string) $addr['AddressId']); ?>">
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="address_id" id="addr_<?php echo htmlspecialchars((string) $addr['AddressId']); ?>" value="<?php echo htmlspecialchars((string) $addr['AddressId']); ?>" <?php echo $isDefault ? 'checked' : ''; ?> required>
                                            <span class="form-check-label fw-semibold">
                                                <?php echo htmlspecialchars((string) ($addr['RecipientName'] ?? '')); ?>
                                                <?php if ($isDefault): ?>
                                                    <span class="badge text-bg-success ms-2">Default</span>
                                                <?php endif; ?>
                                            </span>
                                        </div>
                                        <div class="small text-muted mt-1 ms-4">
                                            <?php echo htmlspecialchars((string) ($addr['PhoneNumber'] ?? '')); ?><br>
                                            <?php echo htmlspecialchars($addressText !== '' ? $addressText : '-'); ?>
                                        </div>
                                    </label>
                                <?php endforeach; ?>

                                <button type="button" id="openConfirmCheckout" class="btn btn-success mt-2" <?php echo empty($cartItems) ? 'disabled' : ''; ?> data-bs-toggle="modal" data-bs-target="#confirmCheckoutModal">Place Order</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </section>
            </div>

            <div class="col-12 col-lg-5">
                <section class="panel">
                    <header class="panel-head">Order Summary</header>
                    <div class="p-3">
                        <?php if (empty($cartItems)): ?>
                            <p class="text-muted mb-0">Your cart is empty.</p>
                        <?php else: ?>
                            <?php foreach ($cartItems as $item): ?>
                                <?php
                                $lineTotal = (float) $item['Price'] * (int) $item['Quantity'];
                                ?>
                                <div class="line-item">
                                    <div>
                                        <div class="fw-semibold"><?php echo htmlspecialchars((string) $item['ProductName']); ?></div>
                                        <div class="small text-muted">Qty: <?php echo (int) $item['Quantity']; ?></div>
                                    </div>
                                    <div>RM <?php echo number_format($lineTotal, 2); ?></div>
                                </div>
                            <?php endforeach; ?>

                            <div class="d-flex justify-content-between mt-3">
                                <span>Subtotal</span>
                                <strong>RM <?php echo number_format($subtotal, 2); ?></strong>
                            </div>
                            <div class="d-flex justify-content-between mt-1">
                                <span>Shipping</span>
                                <strong><?php echo $shippingFee > 0 ? 'RM ' . number_format($shippingFee, 2) : 'Free'; ?></strong>
                            </div>
                            <hr>
                            <div class="d-flex justify-content-between">
                                <span class="fw-semibold">Total</span>
                                <strong>RM <?php echo number_format($totalAmount, 2); ?></strong>
                            </div>
                            <div class="small text-muted mt-2">Payment is excluded in this basic version.</div>
                        <?php endif; ?>
                    </div>
                </section>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php if ($successOrderId === '' && !empty($addresses) && !empty($cartItems)): ?>
<div class="modal fade" id="confirmCheckoutModal" tabindex="-1" aria-labelledby="confirmCheckoutLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title fs-5" id="confirmCheckoutLabel">Confirm Order</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                Payment is excluded in this basic version. Confirm to create your order now?
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" id="confirmPlaceOrder" class="btn btn-success">Yes, Place Order</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function () {
    const confirmBtn = document.getElementById('confirmPlaceOrder');
    const form = document.getElementById('checkoutForm');
    if (!confirmBtn || !form) {
        return;
    }

    confirmBtn.addEventListener('click', function () {
        form.submit();
    });
})();
</script>
</body>
</html>
