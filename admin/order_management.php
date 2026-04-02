<?php
include_once '../config/config.php';
include_once '../config/auth.php';

if (empty($_SESSION['admin_csrf'])) {
	$_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
}

$statusMessage = '';
$statusType = '';

$allowedStatuses = ['Pending', 'Processing', 'Shipped', 'Delivered', 'Cancelled'];
$shippingMethodOptions = [
	'standard' => 'Standard Delivery',
	'express' => 'Express Delivery',
	'scheduled' => 'Scheduled Delivery',
];
$paymentMethodOptions = [
	'cod' => 'Cash on Delivery',
	'fpx' => 'Online Banking (FPX)',
	'card' => 'Credit / Debit Card',
];

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

$search = trim($_GET['q'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');
$shippingMethodFilter = trim($_GET['shipping_method'] ?? '');
$paymentMethodFilter = trim($_GET['payment_method'] ?? '');
$currentPage = max(1, (int) ($_GET['page'] ?? 1));
$itemsPerPage = 25;
if ($statusFilter !== '' && !in_array($statusFilter, $allowedStatuses, true)) {
	$statusFilter = '';
}
if ($shippingMethodFilter !== '' && !array_key_exists($shippingMethodFilter, $shippingMethodOptions)) {
	$shippingMethodFilter = '';
}
$activeShippingMethodLabel = $shippingMethodFilter !== '' ? $shippingMethodOptions[$shippingMethodFilter] : '';
if ($paymentMethodFilter !== '' && !array_key_exists($paymentMethodFilter, $paymentMethodOptions)) {
	$paymentMethodFilter = '';
}
$activePaymentMethodLabel = $paymentMethodFilter !== '' ? $paymentMethodOptions[$paymentMethodFilter] : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$action = $_POST['action'] ?? '';
	$csrf = $_POST['csrf'] ?? '';

	if (!hash_equals($_SESSION['admin_csrf'], $csrf)) {
		$statusMessage = 'Invalid security token. Please refresh and try again.';
		$statusType = 'error';
	} elseif ($action === 'update_order_status') {
		$orderId = trim($_POST['order_id'] ?? '');
		$newStatus = trim($_POST['order_status'] ?? '');

		$returnSearch = trim($_POST['return_q'] ?? '');
		$returnStatus = trim($_POST['return_status'] ?? '');
		$returnShippingMethod = trim($_POST['return_shipping_method'] ?? '');
		$returnPaymentMethod = trim($_POST['return_payment_method'] ?? '');
		$returnPage = max(1, (int) ($_POST['return_page'] ?? 1));
		if ($returnStatus !== '' && !in_array($returnStatus, $allowedStatuses, true)) {
			$returnStatus = '';
		}
		if ($returnShippingMethod !== '' && !array_key_exists($returnShippingMethod, $shippingMethodOptions)) {
			$returnShippingMethod = '';
		}
		if ($returnPaymentMethod !== '' && !array_key_exists($returnPaymentMethod, $paymentMethodOptions)) {
			$returnPaymentMethod = '';
		}

		try {
			if ($orderId === '') {
				throw new RuntimeException('Invalid order identifier.');
			}
			if (!in_array($newStatus, $allowedStatuses, true)) {
				throw new RuntimeException('Invalid order status selected.');
			}

			$checkStmt = $pdo->prepare('SELECT COUNT(*) FROM Orders WHERE OrderId = :order_id');
			$checkStmt->execute([':order_id' => $orderId]);
			if ((int) $checkStmt->fetchColumn() === 0) {
				throw new RuntimeException('Order not found.');
			}

			$updateStmt = $pdo->prepare('UPDATE Orders SET OrderStatus = :order_status WHERE OrderId = :order_id');
			$updateStmt->execute([
				':order_status' => $newStatus,
				':order_id' => $orderId,
			]);

			$query = [
				'flash_status' => 'ok',
				'message' => 'Order status updated successfully.',
			];
			if ($returnSearch !== '') {
				$query['q'] = $returnSearch;
			}
			if ($returnStatus !== '') {
				$query['status'] = $returnStatus;
			}
			if ($returnShippingMethod !== '') {
				$query['shipping_method'] = $returnShippingMethod;
			}
			if ($returnPaymentMethod !== '') {
				$query['payment_method'] = $returnPaymentMethod;
			}
			if ($returnPage > 1) {
				$query['page'] = $returnPage;
			}

			header('Location: order_management.php?' . http_build_query($query));
			exit;
		} catch (Exception $e) {
			$statusMessage = $e->getMessage();
			$statusType = 'error';
		}
	}
}

if (isset($_GET['flash_status'], $_GET['message'])) {
	$statusType = $_GET['flash_status'] === 'ok' ? 'success' : 'error';
	$statusMessage = (string) $_GET['message'];
}

try {
	$countSql = "SELECT
					COUNT(*) AS total_orders,
					SUM(CASE WHEN COALESCE(NULLIF(OrderStatus, ''), 'Pending') = 'Pending' THEN 1 ELSE 0 END) AS pending_orders,
					SUM(CASE WHEN COALESCE(NULLIF(OrderStatus, ''), 'Pending') = 'Processing' THEN 1 ELSE 0 END) AS processing_orders,
					SUM(CASE WHEN COALESCE(NULLIF(OrderStatus, ''), 'Pending') = 'Shipped' THEN 1 ELSE 0 END) AS shipped_orders,
					SUM(CASE WHEN COALESCE(NULLIF(OrderStatus, ''), 'Pending') = 'Delivered' THEN 1 ELSE 0 END) AS delivered_orders,
					SUM(CASE WHEN COALESCE(NULLIF(OrderStatus, ''), 'Pending') = 'Cancelled' THEN 1 ELSE 0 END) AS cancelled_orders
				 FROM Orders";
	$countStmt = $pdo->query($countSql);
	$counts = $countStmt->fetch(PDO::FETCH_ASSOC) ?: [
		'total_orders' => 0,
		'pending_orders' => 0,
		'processing_orders' => 0,
		'shipped_orders' => 0,
		'delivered_orders' => 0,
		'cancelled_orders' => 0,
	];

	$listSql = "SELECT
				o.OrderId,
				o.TotalAmount,
				COALESCE(NULLIF(o.OrderStatus, ''), 'Pending') AS OrderStatus,
				o.OrderDate,
				o.ShippingAddress,
				u.Email,
				up.FirstName,
				up.LastName,
				(
					SELECT COUNT(*)
					FROM OrderItems oi
					WHERE oi.OrderId = o.OrderId
				) AS ItemCount,
				(
					SELECT COALESCE(SUM(oi.Quantity), 0)
					FROM OrderItems oi
					WHERE oi.OrderId = o.OrderId
				) AS TotalQuantity,
				(
					SELECT GROUP_CONCAT(CONCAT(p.ProductName, ' x', oi.Quantity) ORDER BY p.ProductName SEPARATOR ', ')
					FROM OrderItems oi
					JOIN Products p ON p.ProductId = oi.ProductId
					WHERE oi.OrderId = o.OrderId
				) AS ItemSummary
				FROM Orders o
				LEFT JOIN Users u ON u.UserId = o.UserId
				LEFT JOIN UserProfile up ON up.UserId = u.UserId
				WHERE 1=1";

	$listCountSql = "SELECT COUNT(*)
					FROM Orders o
					LEFT JOIN Users u ON u.UserId = o.UserId
					LEFT JOIN UserProfile up ON up.UserId = u.UserId
					WHERE 1=1";

	$params = [];
	if ($search !== '') {
		$listSql .= " AND (
			o.OrderId LIKE :q_order
			OR u.Email LIKE :q_email
			OR up.FirstName LIKE :q_first_name
			OR up.LastName LIKE :q_last_name
			OR o.ShippingAddress LIKE :q_shipping
		)";
		$listCountSql .= " AND (
			o.OrderId LIKE :q_order
			OR u.Email LIKE :q_email
			OR up.FirstName LIKE :q_first_name
			OR up.LastName LIKE :q_last_name
			OR o.ShippingAddress LIKE :q_shipping
		)";
		$keyword = '%' . $search . '%';
		$params[':q_order'] = $keyword;
		$params[':q_email'] = $keyword;
		$params[':q_first_name'] = $keyword;
		$params[':q_last_name'] = $keyword;
		$params[':q_shipping'] = $keyword;
	}

	if ($statusFilter !== '') {
		$listSql .= " AND COALESCE(NULLIF(o.OrderStatus, ''), 'Pending') = :status_filter";
		$listCountSql .= " AND COALESCE(NULLIF(o.OrderStatus, ''), 'Pending') = :status_filter";
		$params[':status_filter'] = $statusFilter;
	}

	if ($shippingMethodFilter !== '') {
		$listSql .= " AND o.ShippingAddress LIKE :shipping_method_filter";
		$listCountSql .= " AND o.ShippingAddress LIKE :shipping_method_filter";
		$params[':shipping_method_filter'] = '%Shipping Method: ' . $shippingMethodOptions[$shippingMethodFilter] . '%';
	}

	if ($paymentMethodFilter !== '') {
		$listSql .= " AND o.ShippingAddress LIKE :payment_method_filter";
		$listCountSql .= " AND o.ShippingAddress LIKE :payment_method_filter";
		$params[':payment_method_filter'] = '%Payment Method: ' . $paymentMethodOptions[$paymentMethodFilter] . '%';
	}

	$listCountStmt = $pdo->prepare($listCountSql);
	$listCountStmt->execute($params);
	$filteredTotal = (int) $listCountStmt->fetchColumn();
	$totalPages = max(1, (int) ceil($filteredTotal / $itemsPerPage));
	$currentPage = min($currentPage, $totalPages);
	$offset = ($currentPage - 1) * $itemsPerPage;

	$listSql .= ' ORDER BY o.OrderDate DESC LIMIT ' . $itemsPerPage . ' OFFSET ' . $offset;
	$listStmt = $pdo->prepare($listSql);
	$listStmt->execute($params);
	$orders = $listStmt->fetchAll(PDO::FETCH_ASSOC);

	$buildListUrl = function (array $overrides = []) use ($search, $statusFilter, $shippingMethodFilter, $paymentMethodFilter): string {
		$query = [
			'q' => $search,
			'status' => $statusFilter,
			'shipping_method' => $shippingMethodFilter,
			'payment_method' => $paymentMethodFilter,
		];
		$query = array_merge($query, $overrides);

		if (($query['q'] ?? '') === '') {
			unset($query['q']);
		}
		if (($query['status'] ?? '') === '') {
			unset($query['status']);
		}
		if (($query['shipping_method'] ?? '') === '') {
			unset($query['shipping_method']);
		}
		if (($query['payment_method'] ?? '') === '') {
			unset($query['payment_method']);
		}
		if (($query['page'] ?? null) === 1) {
			unset($query['page']);
		}

		return 'order_management.php' . (!empty($query) ? '?' . http_build_query($query) : '');
	};
} catch (Exception $e) {
	die('Error fetching order data: ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Order Management || E-Commerce</title>
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link href="https://fonts.googleapis.com/css2?family=Syne:wght@500;700;800&family=IBM+Plex+Sans:wght@400;500;600&display=swap" rel="stylesheet">
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
	<link rel="stylesheet" href="../asset/css/admin-base.css">
	<link rel="stylesheet" href="../asset/css/admin-layout-responsive.css">
    <link rel="stylesheet" href="../asset/css/admin-order-management.css">
	
</head>
<body>
<div class="shell-scroll">
<div class="shell">
	<aside class="sidebar">
		<span class="brand-tag">Admin Portal</span>
		<h2>Order Management</h2>
		<nav class="nav-links">
			<a href="admin_dashboard.php"><i class="bi bi-speedometer2"></i> Control Panel</a>
			<a href="product_management.php"><i class="bi bi-box"></i> Product Management</a>
			<a href="category_management.php"><i class="bi bi-tags"></i> Category Management</a>
			<a href="member_management.php"><i class="bi bi-people"></i> Member Management</a>
			<a href="order_management.php" class="active"><i class="bi bi-cart"></i> Order Management</a>
			<a href="admin_logout.php"><i class="bi bi-gear"></i> Logout</a>
		</nav>
	</aside>

	<main class="main">
		<div class="topbar">
			<h1>Orders</h1>
			<span class="admin-badge">Admin</span>
		</div>

		<?php if ($statusMessage !== ''): ?>
			<div class="notice <?php echo htmlspecialchars($statusType ?: 'error'); ?>">
				<?php echo htmlspecialchars($statusMessage); ?>
			</div>
		<?php endif; ?>

		<section class="stats">
			<article class="stat-card total">
				<h3>Total Orders</h3>
				<p><?php echo (int) ($counts['total_orders'] ?? 0); ?></p>
			</article>
			<article class="stat-card pending">
				<h3>Pending</h3>
				<p><?php echo (int) ($counts['pending_orders'] ?? 0); ?></p>
			</article>
			<article class="stat-card processing">
				<h3>Processing</h3>
				<p><?php echo (int) ($counts['processing_orders'] ?? 0); ?></p>
			</article>
			<article class="stat-card shipped">
				<h3>Shipped</h3>
				<p><?php echo (int) ($counts['shipped_orders'] ?? 0); ?></p>
			</article>
			<article class="stat-card delivered">
				<h3>Delivered</h3>
				<p><?php echo (int) ($counts['delivered_orders'] ?? 0); ?></p>
			</article>
			<article class="stat-card cancelled">
				<h3>Cancelled</h3>
				<p><?php echo (int) ($counts['cancelled_orders'] ?? 0); ?></p>
			</article>
		</section>

		<section class="panel">
			<div class="panel-header">
				<form class="filter-form" method="get" action="order_management.php">
					<input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search by order id, customer, email, or shipping address">
					<select name="status">
						<option value="">All Statuses</option>
						<?php foreach ($allowedStatuses as $statusOption): ?>
							<option value="<?php echo htmlspecialchars($statusOption); ?>" <?php echo $statusFilter === $statusOption ? 'selected' : ''; ?>>
								<?php echo htmlspecialchars($statusOption); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<select name="shipping_method">
						<option value="">All Shipping Methods</option>
						<?php foreach ($shippingMethodOptions as $methodKey => $methodLabel): ?>
							<option value="<?php echo htmlspecialchars($methodKey); ?>" <?php echo $shippingMethodFilter === $methodKey ? 'selected' : ''; ?>>
								<?php echo htmlspecialchars($methodLabel); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<select name="payment_method">
						<option value="">All Payment Methods</option>
						<?php foreach ($paymentMethodOptions as $methodKey => $methodLabel): ?>
							<option value="<?php echo htmlspecialchars($methodKey); ?>" <?php echo $paymentMethodFilter === $methodKey ? 'selected' : ''; ?>>
								<?php echo htmlspecialchars($methodLabel); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<button class="btn btn-primary" type="submit"><i class="bi bi-search"></i> Filter</button>
					<?php if ($search !== '' || $statusFilter !== '' || $shippingMethodFilter !== '' || $paymentMethodFilter !== ''): ?>
						<a class="btn btn-outline" href="order_management.php"><i class="bi bi-x-circle"></i> Clear</a>
					<?php endif; ?>
				</form>
				<div class="d-flex align-items-center gap-2 flex-wrap justify-content-end">
					<span class="muted"><?php echo $filteredTotal; ?> order(s)</span>
					<?php if ($activeShippingMethodLabel !== ''): ?>
						<span class="filter-chip"><i class="bi bi-truck"></i> <?php echo htmlspecialchars($activeShippingMethodLabel); ?></span>
					<?php endif; ?>
					<?php if ($activePaymentMethodLabel !== ''): ?>
						<span class="filter-chip"><i class="bi bi-credit-card"></i> <?php echo htmlspecialchars($activePaymentMethodLabel); ?></span>
					<?php endif; ?>
				</div>
			</div>

			<?php if (empty($orders)): ?>
				<div class="empty-state">
					<p>No orders found for the current filter.</p>
				</div>
			<?php else: ?>
				<div class="table-wrap table-responsive">
					<table class="table table-hover align-middle mb-0">
						<thead>
							<tr>
								<th>Order</th>
								<th>Customer</th>
								<th>Items</th>
								<th>Total</th>
								<th>Status</th>
								<th>Shipping Address</th>
								<th>Ordered At</th>
							</tr>
						</thead>
						<tbody>
						<?php foreach ($orders as $order): ?>
							<?php
							$customerName = trim((string) (($order['FirstName'] ?? '') . ' ' . ($order['LastName'] ?? '')));
							if ($customerName === '') {
								$customerName = 'No profile name';
							}

							$currentStatus = (string) ($order['OrderStatus'] ?? 'Pending');
							$statusClass = strtolower($currentStatus);

							$itemSummary = trim((string) ($order['ItemSummary'] ?? ''));
							if ($itemSummary === '') {
								$itemSummary = 'No order items available';
							}

							$shippingAddress = trim((string) ($order['ShippingAddress'] ?? ''));
							$shippingMeta = parseShippingSnapshot($shippingAddress);
							?>
							<tr>
								<td>
									<div class="order-id"><?php echo htmlspecialchars((string) $order['OrderId']); ?></div>
								</td>
								<td>
									<div><?php echo htmlspecialchars($customerName); ?></div>
									<div class="muted"><?php echo htmlspecialchars((string) ($order['Email'] ?? '-')); ?></div>
								</td>
								<td>
									<div class="items-text"><?php echo htmlspecialchars($itemSummary); ?></div>
									<div class="muted"><?php echo (int) ($order['ItemCount'] ?? 0); ?> item line(s), <?php echo (int) ($order['TotalQuantity'] ?? 0); ?> qty total</div>
								</td>
								<td>RM <?php echo number_format((float) ($order['TotalAmount'] ?? 0), 2); ?></td>
								<td>
									<div class="status-pill <?php echo htmlspecialchars($statusClass); ?>"><?php echo htmlspecialchars($currentStatus); ?></div>
									<form class="status-form" method="post" action="order_management.php">
										<input type="hidden" name="csrf" value="<?php echo htmlspecialchars($_SESSION['admin_csrf']); ?>">
										<input type="hidden" name="action" value="update_order_status">
										<input type="hidden" name="order_id" value="<?php echo htmlspecialchars((string) $order['OrderId']); ?>">
										<input type="hidden" name="return_q" value="<?php echo htmlspecialchars($search); ?>">
										<input type="hidden" name="return_status" value="<?php echo htmlspecialchars($statusFilter); ?>">
										<input type="hidden" name="return_shipping_method" value="<?php echo htmlspecialchars($shippingMethodFilter); ?>">
										<input type="hidden" name="return_payment_method" value="<?php echo htmlspecialchars($paymentMethodFilter); ?>">
										<input type="hidden" name="return_page" value="<?php echo (int) $currentPage; ?>">
										<select name="order_status" aria-label="Update order status for <?php echo htmlspecialchars((string) $order['OrderId']); ?>">
											<?php foreach ($allowedStatuses as $statusOption): ?>
												<option value="<?php echo htmlspecialchars($statusOption); ?>" <?php echo $currentStatus === $statusOption ? 'selected' : ''; ?>>
													<?php echo htmlspecialchars($statusOption); ?>
												</option>
											<?php endforeach; ?>
										</select>
										<button class="btn btn-primary btn-save" type="submit">Save</button>
									</form>
								</td>
								<td>
									<div class="address-text"><?php echo nl2br(htmlspecialchars((string) $shippingMeta['address'])); ?></div>
									<?php if ($shippingMeta['method'] !== ''): ?>
										<div class="shipping-meta-row"><strong>Method:</strong> <?php echo htmlspecialchars((string) $shippingMeta['method']); ?></div>
									<?php endif; ?>
									<?php if ($shippingMeta['payment'] !== ''): ?>
										<div class="shipping-meta-row"><strong>Payment:</strong> <i class="bi <?php echo htmlspecialchars(getPaymentIconClass((string) $shippingMeta['payment'])); ?> me-1" aria-hidden="true"></i><?php echo htmlspecialchars((string) $shippingMeta['payment']); ?></div>
									<?php endif; ?>
									<?php if ($shippingMeta['estimate'] !== ''): ?>
										<div class="shipping-meta-row"><strong>Delivery ETA:</strong> <?php echo htmlspecialchars((string) $shippingMeta['estimate']); ?></div>
									<?php endif; ?>
									<?php if ($shippingMeta['notes'] !== ''): ?>
										<div class="shipping-meta-row"><strong>Notes:</strong> <?php echo nl2br(htmlspecialchars((string) $shippingMeta['notes'])); ?></div>
									<?php endif; ?>
								</td>
								<td><?php echo htmlspecialchars((string) ($order['OrderDate'] ?? '-')); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>

			<?php if ($totalPages > 1): ?>
			<div class="pagination">
				<span class="pagination-info">
					Page <?php echo $currentPage; ?> of <?php echo $totalPages; ?>
					(<?php echo $filteredTotal; ?> total)
				</span>
				<div class="pagination-controls">
					<?php if ($currentPage > 1): ?>
						<a href="<?php echo htmlspecialchars($buildListUrl(['page' => 1])); ?>" class="pagination-link" title="First page"><i class="bi bi-chevron-double-left"></i></a>
						<a href="<?php echo htmlspecialchars($buildListUrl(['page' => $currentPage - 1])); ?>" class="pagination-link" title="Previous page"><i class="bi bi-chevron-left"></i></a>
					<?php else: ?>
						<button class="pagination-btn" disabled><i class="bi bi-chevron-double-left"></i></button>
						<button class="pagination-btn" disabled><i class="bi bi-chevron-left"></i></button>
					<?php endif; ?>

					<?php
					$startPage = max(1, $currentPage - 1);
					$endPage = min($totalPages, $currentPage + 1);
					if ($currentPage <= 2) {
						$endPage = min($totalPages, 3);
					}
					if ($currentPage >= $totalPages - 1) {
						$startPage = max(1, $totalPages - 2);
					}
					for ($page = $startPage; $page <= $endPage; $page++):
						$isCurrentPage = $page === $currentPage;
					?>
						<a href="<?php echo htmlspecialchars($buildListUrl(['page' => $page])); ?>" class="pagination-link <?php echo $isCurrentPage ? 'active' : ''; ?>"><?php echo $page; ?></a>
					<?php endfor; ?>

					<?php if ($currentPage < $totalPages): ?>
						<a href="<?php echo htmlspecialchars($buildListUrl(['page' => $currentPage + 1])); ?>" class="pagination-link" title="Next page"><i class="bi bi-chevron-right"></i></a>
						<a href="<?php echo htmlspecialchars($buildListUrl(['page' => $totalPages])); ?>" class="pagination-link" title="Last page"><i class="bi bi-chevron-double-right"></i></a>
					<?php else: ?>
						<button class="pagination-btn" disabled><i class="bi bi-chevron-right"></i></button>
						<button class="pagination-btn" disabled><i class="bi bi-chevron-double-right"></i></button>
					<?php endif; ?>
				</div>
			</div>
			<?php endif; ?>
		</section>
	</main>
</div>
</div>
<script>
(function () {
	const textInputs = document.querySelectorAll('input:not([type="hidden"]):not([type="checkbox"]):not([type="radio"]):not([type="file"])');
	const selects = document.querySelectorAll('select');

	textInputs.forEach(function (el) {
		el.classList.add('form-control');
	});

	selects.forEach(function (el) {
		el.classList.add('form-select');
	});
})();
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
