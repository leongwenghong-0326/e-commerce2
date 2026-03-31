<?php
require_once 'config/config.php';

if (!isset($_SESSION['user_id'])) {
	$redirectTarget = (string) ($_SERVER['REQUEST_URI'] ?? 'cart.php');
	$loginUrl = 'member_login.php?' . http_build_query([
		'redirect' => $redirectTarget,
	]);
	header('Location: ' . $loginUrl);
	exit();
}

$userId = (string) $_SESSION['user_id'];

if (empty($_SESSION['cart_csrf'])) {
	$_SESSION['cart_csrf'] = bin2hex(random_bytes(32));
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

function redirectWithMessage(string $status, string $message): void
{
	$query = http_build_query([
		'status' => $status,
		'message' => $message,
	]);
	header('Location: cart.php?' . $query);
	exit();
}

function getProductForCart(PDO $pdo, string $productId): ?array
{
	$stmt = $pdo->prepare(
		'SELECT ProductId, ProductName, Price, StockQuantity
		 FROM Products
		 WHERE ProductId = :product_id
		 LIMIT 1'
	);
	$stmt->execute([':product_id' => $productId]);
	$row = $stmt->fetch(PDO::FETCH_ASSOC);
	return $row ?: null;
}

function getExistingCartRow(PDO $pdo, string $userId, string $productId): ?array
{
	$stmt = $pdo->prepare(
		'SELECT CartId, Quantity
		 FROM Carts
		 WHERE UserId = :user_id AND ProductId = :product_id
		 LIMIT 1'
	);
	$stmt->execute([
		':user_id' => $userId,
		':product_id' => $productId,
	]);
	$row = $stmt->fetch(PDO::FETCH_ASSOC);
	return $row ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$action = trim((string) ($_POST['action'] ?? ''));
	$csrf = (string) ($_POST['csrf'] ?? '');

	if (!hash_equals($_SESSION['cart_csrf'], $csrf)) {
		redirectWithMessage('error', 'Invalid request token. Please refresh and try again.');
	}

	try {
		if ($action === 'add_item') {
			$productId = trim((string) ($_POST['product_id'] ?? ''));
			$quantity = max(1, (int) ($_POST['quantity'] ?? 1));

			if ($productId === '') {
				throw new RuntimeException('Invalid product selected.');
			}

			$product = getProductForCart($pdo, $productId);
			if ($product === null) {
				throw new RuntimeException('Product not found.');
			}

			$stock = max(0, (int) ($product['StockQuantity'] ?? 0));
			if ($stock <= 0) {
				throw new RuntimeException('This product is out of stock.');
			}

			$existingRow = getExistingCartRow($pdo, $userId, $productId);
			if ($existingRow !== null) {
				$newQty = min($stock, (int) $existingRow['Quantity'] + $quantity);
				$update = $pdo->prepare('UPDATE Carts SET Quantity = :qty WHERE CartId = :cart_id');
				$update->execute([
					':qty' => $newQty,
					':cart_id' => $existingRow['CartId'],
				]);
			} else {
				$insertQty = min($stock, $quantity);
				$insert = $pdo->prepare(
					'INSERT INTO Carts (CartId, UserId, ProductId, Quantity, AddedDate)
					 VALUES (:cart_id, :user_id, :product_id, :qty, NOW())'
				);
				$insert->execute([
					':cart_id' => createUuidV4(),
					':user_id' => $userId,
					':product_id' => $productId,
					':qty' => $insertQty,
				]);
			}

			redirectWithMessage('ok', 'Item added to cart.');
		}

		if ($action === 'update_item') {
			$cartId = trim((string) ($_POST['cart_id'] ?? ''));
			$quantity = (int) ($_POST['quantity'] ?? 0);

			if ($cartId === '') {
				throw new RuntimeException('Invalid cart item.');
			}

			$itemStmt = $pdo->prepare(
				'SELECT c.CartId, c.ProductId, p.StockQuantity
				 FROM Carts c
				 JOIN Products p ON p.ProductId = c.ProductId
				 WHERE c.CartId = :cart_id AND c.UserId = :user_id
				 LIMIT 1'
			);
			$itemStmt->execute([
				':cart_id' => $cartId,
				':user_id' => $userId,
			]);
			$item = $itemStmt->fetch(PDO::FETCH_ASSOC);

			if (!$item) {
				throw new RuntimeException('Cart item not found.');
			}

			if ($quantity <= 0) {
				$deleteStmt = $pdo->prepare('DELETE FROM Carts WHERE CartId = :cart_id AND UserId = :user_id');
				$deleteStmt->execute([
					':cart_id' => $cartId,
					':user_id' => $userId,
				]);
				redirectWithMessage('ok', 'Item removed from cart.');
			}

			$stock = max(0, (int) ($item['StockQuantity'] ?? 0));
			if ($stock <= 0) {
				$deleteStmt = $pdo->prepare('DELETE FROM Carts WHERE CartId = :cart_id AND UserId = :user_id');
				$deleteStmt->execute([
					':cart_id' => $cartId,
					':user_id' => $userId,
				]);
				throw new RuntimeException('Product is now out of stock and was removed from your cart.');
			}

			$safeQty = min($stock, $quantity);
			$updateStmt = $pdo->prepare('UPDATE Carts SET Quantity = :qty WHERE CartId = :cart_id AND UserId = :user_id');
			$updateStmt->execute([
				':qty' => $safeQty,
				':cart_id' => $cartId,
				':user_id' => $userId,
			]);

			if ($safeQty < $quantity) {
				redirectWithMessage('ok', 'Quantity adjusted to available stock.');
			}

			redirectWithMessage('ok', 'Cart updated successfully.');
		}

		if ($action === 'remove_item') {
			$cartId = trim((string) ($_POST['cart_id'] ?? ''));
			if ($cartId === '') {
				throw new RuntimeException('Invalid cart item.');
			}

			$deleteStmt = $pdo->prepare('DELETE FROM Carts WHERE CartId = :cart_id AND UserId = :user_id');
			$deleteStmt->execute([
				':cart_id' => $cartId,
				':user_id' => $userId,
			]);

			redirectWithMessage('ok', 'Item removed from cart.');
		}

		if ($action === 'clear_cart') {
			$clearStmt = $pdo->prepare('DELETE FROM Carts WHERE UserId = :user_id');
			$clearStmt->execute([':user_id' => $userId]);
			redirectWithMessage('ok', 'Your cart is now empty.');
		}
	} catch (Throwable $e) {
		redirectWithMessage('error', $e->getMessage());
	}
}

if (isset($_GET['add'])) {
	$productId = trim((string) $_GET['add']);
	$quantity = max(1, (int) ($_GET['qty'] ?? 1));
	$buyNow = isset($_GET['buy_now']) && $_GET['buy_now'] === '1';

	if ($productId !== '') {
		try {
			$product = getProductForCart($pdo, $productId);
			if ($product === null) {
				throw new RuntimeException('Product not found.');
			}

			$stock = max(0, (int) ($product['StockQuantity'] ?? 0));
			if ($stock <= 0) {
				throw new RuntimeException('This product is out of stock.');
			}

			$existingRow = getExistingCartRow($pdo, $userId, $productId);
			if ($existingRow !== null) {
				$newQty = min($stock, (int) $existingRow['Quantity'] + $quantity);
				$update = $pdo->prepare('UPDATE Carts SET Quantity = :qty WHERE CartId = :cart_id');
				$update->execute([
					':qty' => $newQty,
					':cart_id' => $existingRow['CartId'],
				]);
			} else {
				$insertQty = min($stock, $quantity);
				$insert = $pdo->prepare(
					'INSERT INTO Carts (CartId, UserId, ProductId, Quantity, AddedDate)
					 VALUES (:cart_id, :user_id, :product_id, :qty, NOW())'
				);
				$insert->execute([
					':cart_id' => createUuidV4(),
					':user_id' => $userId,
					':product_id' => $productId,
					':qty' => $insertQty,
				]);
			}

			if ($buyNow) {
				header('Location: checkout.php');
				exit();
			}

			redirectWithMessage('ok', 'Item added to cart.');
		} catch (Throwable $e) {
			redirectWithMessage('error', $e->getMessage());
		}
	}
}

$statusType = '';
$statusMessage = '';
if (isset($_GET['status'], $_GET['message'])) {
	$statusType = $_GET['status'] === 'ok' ? 'success' : 'error';
	$statusMessage = (string) $_GET['message'];
}

$cartStmt = $pdo->prepare(
	'SELECT
		c.CartId,
		c.ProductId,
		c.Quantity,
		c.AddedDate,
		p.ProductName,
		p.Price,
		p.StockQuantity,
		(
			SELECT pi.ImageUrl
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
	$lineTotal = (float) $item['Price'] * (int) $item['Quantity'];
	$subtotal += $lineTotal;
}

$shippingFee = 0.0;
$grandTotal = $subtotal + $shippingFee;
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>E-commerce | Cart</title>
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet">
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
	    <link rel="stylesheet" href="asset/css/member-theme.css">
    <style>
		:root {
			--bg-start: #f4fbf8;
			--bg-end: #e8f4ff;
			--ink: #1b2530;
			--panel: rgba(255, 255, 255, 0.84);
			--line: rgba(27, 37, 48, 0.14);
			--accent: #0f8f6f;
			--accent-strong: #0b6f56;
		}

		body {
			font-family: 'Outfit', sans-serif;
			color: var(--ink);
			background:
				radial-gradient(circle at 10% 15%, rgba(15, 143, 111, 0.22), transparent 40%),
				radial-gradient(circle at 90% 80%, rgba(39, 124, 198, 0.18), transparent 35%),
				linear-gradient(135deg, var(--bg-start), var(--bg-end));
		}

		.page-shell {
			max-width: 1120px;
			margin: 22px auto 38px;
			padding: 0 14px;
		}

		.cart-header {
			background: var(--panel);
			border: 1px solid var(--line);
			border-radius: 16px;
			padding: 18px;
			margin-bottom: 16px;
			backdrop-filter: blur(8px);
			box-shadow: 0 14px 30px rgba(10, 36, 60, 0.08);
		}

		.cart-title {
			font-family: 'Space Grotesk', sans-serif;
			font-size: clamp(1.35rem, 3vw, 2rem);
			font-weight: 700;
			margin: 0;
		}

		.cart-grid {
			display: grid;
			grid-template-columns: 1.3fr 0.7fr;
			gap: 16px;
		}

		.panel {
			background: var(--panel);
			border: 1px solid var(--line);
			border-radius: 16px;
			overflow: hidden;
			backdrop-filter: blur(8px);
			box-shadow: 0 14px 30px rgba(10, 36, 60, 0.08);
		}

		.panel-head {
			display: flex;
			align-items: center;
			justify-content: space-between;
			gap: 10px;
			padding: 14px 16px;
			border-bottom: 1px solid #e8f0eb;
		}

		.panel-head h2 {
			margin: 0;
			font-size: 1rem;
			font-weight: 700;
		}

		.cart-row {
			display: grid;
			grid-template-columns: 88px minmax(0, 1fr);
			gap: 12px;
			padding: 14px 16px;
			border-bottom: 1px solid #edf3ef;
		}

		.cart-row:last-child {
			border-bottom: none;
		}

		.cart-img {
			width: 88px;
			height: 88px;
			border-radius: 12px;
			object-fit: cover;
			border: 1px solid var(--line);
			background: #f4f8f6;
		}

		.item-name {
			font-weight: 700;
			margin-bottom: 4px;
		}

		.item-meta {
			font-size: 13px;
			color: #5d6d7c;
			margin-bottom: 10px;
		}

		.qty-wrap {
			display: flex;
			align-items: center;
			gap: 8px;
			flex-wrap: wrap;
		}

		.qty-input {
			width: 92px;
		}

		.summary-body {
			padding: 16px;
		}

		.sum-row {
			display: flex;
			justify-content: space-between;
			align-items: center;
			gap: 8px;
			margin-bottom: 10px;
			font-size: 14px;
		}

		.sum-row.total {
			font-size: 1rem;
			font-weight: 700;
			border-top: 1px dashed #d8e7df;
			padding-top: 12px;
			margin-top: 12px;
		}

		.empty {
			padding: 26px 18px;
			text-align: center;
		}

		.empty i {
			font-size: 2rem;
			color: #9ab3a7;
		}

		.btn-success {
			border: none;
			border-radius: 12px;
			background: linear-gradient(135deg, var(--accent), var(--accent-strong));
			font-weight: 700;
		}

		.btn-success:hover {
			box-shadow: 0 8px 16px rgba(11, 111, 86, 0.3);
		}

		@media (max-width: 992px) {
			.cart-grid {
				grid-template-columns: 1fr;
			}
		}
	</style>
</head>
<body>
<?php include 'layout/nav.php'; ?>

<div class="page-shell">
	<section class="cart-header d-flex flex-wrap align-items-center justify-content-between gap-3">
		<h1 class="cart-title">Your Cart</h1>
		<a href="products.php" class="btn btn-outline-success"><i class="bi bi-arrow-left"></i> Continue Shopping</a>
	</section>

	<?php if ($statusMessage !== ''): ?>
		<div class="alert <?php echo $statusType === 'success' ? 'alert-success' : 'alert-danger'; ?>" role="alert">
			<?php echo htmlspecialchars($statusMessage); ?>
		</div>
	<?php endif; ?>

	<div class="cart-grid">
		<section class="panel">
			<header class="panel-head">
				<h2>Cart Items (<?php echo count($cartItems); ?>)</h2>
				<?php if (!empty($cartItems)): ?>
					<form method="post" action="cart.php" class="m-0">
						<input type="hidden" name="csrf" value="<?php echo htmlspecialchars($_SESSION['cart_csrf']); ?>">
						<input type="hidden" name="action" value="clear_cart">
						<button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('Clear all items from cart?');">Clear Cart</button>
					</form>
				<?php endif; ?>
			</header>

			<?php if (empty($cartItems)): ?>
				<div class="empty">
					<i class="bi bi-cart"></i>
					<h3 class="h5 mt-3 mb-2">Your cart is empty</h3>
					<p class="text-muted mb-3">Add products to your cart and they will appear here.</p>
					<a href="products.php" class="btn btn-success">Browse Products</a>
				</div>
			<?php else: ?>
				<?php foreach ($cartItems as $item): ?>
					<?php
					$qty = (int) $item['Quantity'];
					$price = (float) $item['Price'];
					$stock = max(0, (int) ($item['StockQuantity'] ?? 0));
					$lineTotal = $qty * $price;
					$image = trim((string) ($item['ProductImage'] ?? ''));
					$imageSrc = $image !== '' ? $image : 'asset/image/default_avatar.png';
					?>
					<article class="cart-row">
						<img class="cart-img" src="<?php echo htmlspecialchars($imageSrc); ?>" alt="<?php echo htmlspecialchars((string) $item['ProductName']); ?>">
						<div>
							<div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
								<div>
									<div class="item-name"><?php echo htmlspecialchars((string) $item['ProductName']); ?></div>
									<div class="item-meta">RM <?php echo number_format($price, 2); ?> each</div>
								</div>
								<div class="fw-semibold">RM <?php echo number_format($lineTotal, 2); ?></div>
							</div>

							<div class="qty-wrap">
								<form method="post" action="cart.php" class="d-flex align-items-center gap-2 flex-wrap m-0">
									<input type="hidden" name="csrf" value="<?php echo htmlspecialchars($_SESSION['cart_csrf']); ?>">
									<input type="hidden" name="action" value="update_item">
									<input type="hidden" name="cart_id" value="<?php echo htmlspecialchars((string) $item['CartId']); ?>">
									<input
										type="number"
										class="form-control qty-input"
										name="quantity"
										min="1"
										max="<?php echo $stock; ?>"
										value="<?php echo $qty; ?>"
										required
									>
									<button type="submit" class="btn btn-sm btn-outline-primary">Update</button>
								</form>

								<form method="post" action="cart.php" class="m-0">
									<input type="hidden" name="csrf" value="<?php echo htmlspecialchars($_SESSION['cart_csrf']); ?>">
									<input type="hidden" name="action" value="remove_item">
									<input type="hidden" name="cart_id" value="<?php echo htmlspecialchars((string) $item['CartId']); ?>">
									<button type="submit" class="btn btn-sm btn-outline-danger">Remove</button>
								</form>
							</div>

							<div class="item-meta mt-2 mb-0">
								Available stock: <?php echo $stock; ?>
							</div>
						</div>
					</article>
				<?php endforeach; ?>
			<?php endif; ?>
		</section>

		<aside class="panel">
			<header class="panel-head">
				<h2>Order Summary</h2>
			</header>
			<div class="summary-body">
				<div class="sum-row">
					<span>Subtotal</span>
					<strong>RM <?php echo number_format($subtotal, 2); ?></strong>
				</div>
				<div class="sum-row">
					<span>Shipping</span>
					<strong><?php echo $shippingFee > 0 ? 'RM ' . number_format($shippingFee, 2) : 'Free'; ?></strong>
				</div>
				<div class="sum-row total">
					<span>Total</span>
					<strong>RM <?php echo number_format($grandTotal, 2); ?></strong>
				</div>

				<?php if (empty($cartItems)): ?>
					<button type="button" class="btn btn-success w-100 mt-3" disabled>Proceed to Checkout</button>
				<?php else: ?>
					<a class="btn btn-success w-100 mt-3" href="checkout.php">Proceed to Checkout</a>
				<?php endif; ?>
				<div class="text-muted small mt-2">Payment is excluded in this basic version.</div>
			</div>
		</aside>
	</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
