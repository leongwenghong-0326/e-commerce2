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
	<style>
		:root {
			--ink: #1f1a15;
			--paper: rgba(255, 255, 255, 0.75);
			--accent: #da5a1b;
			--accent-strong: #b74009;
			--line: rgba(31, 26, 21, 0.16);
			--ok: #1f7a46;
			--error: #a31515;
			--warning: #9a5b07;
		}

		* {
			box-sizing: border-box;
		}

		body {
			margin: 0;
			min-height: 100vh;
			font-family: 'IBM Plex Sans', sans-serif;
			color: var(--ink);
			background:
				radial-gradient(circle at 15% 20%, rgba(218, 90, 27, 0.2), transparent 42%),
				radial-gradient(circle at 85% 82%, rgba(184, 64, 9, 0.22), transparent 35%),
				linear-gradient(145deg, #f7f0df 0%, #f4ede4 48%, #efe5d2 100%);
			padding: 0;
		}

		.shell {
			width: 100%;
			min-width: 1220px;
			margin: 0;
			display: grid;
			grid-template-columns: 280px minmax(0, 1fr);
			border: none;
			border-radius: 0;
			overflow: hidden;
			box-shadow: none;
			background: var(--paper);
			backdrop-filter: blur(8px);
		}

		.shell-scroll {
			width: 100%;
			overflow-x: auto;
			overflow-y: visible;
			padding-bottom: 8px;
		}

		.sidebar {
			padding: 30px 22px;
			border-right: 1px solid var(--line);
			min-height: 100vh;
			background:
				linear-gradient(180deg, rgba(255, 255, 255, 0.35), rgba(255, 255, 255, 0.12)),
				repeating-linear-gradient(135deg, transparent, transparent 12px, rgba(31, 26, 21, 0.03) 12px, rgba(31, 26, 21, 0.03) 24px);
		}

		.brand-tag {
			display: inline-block;
			font-size: 12px;
			font-weight: 600;
			letter-spacing: 0.12em;
			text-transform: uppercase;
			padding: 8px 12px;
			border-radius: 999px;
			border: 1px solid var(--line);
			background: rgba(255, 255, 255, 0.6);
			margin-bottom: 14px;
		}

		.sidebar h2 {
			margin: 0 0 18px;
			font-family: 'Syne', sans-serif;
			font-size: 1.6rem;
			letter-spacing: -0.01em;
		}

		.nav-links {
			display: grid;
			gap: 8px;
		}

		.nav-links a {
			text-decoration: none;
			color: var(--ink);
			display: flex;
			align-items: center;
			gap: 10px;
			font-weight: 500;
			padding: 11px 12px;
			border-radius: 10px;
			border: 1px solid transparent;
			transition: background-color 160ms ease, border-color 160ms ease, transform 160ms ease;
		}

		.nav-links a:hover {
			background: rgba(255, 255, 255, 0.72);
			border-color: var(--line);
			transform: translateX(2px);
		}

		.nav-links a.active {
			background: linear-gradient(135deg, var(--accent), var(--accent-strong));
			color: #fff;
			box-shadow: 0 8px 18px rgba(183, 64, 9, 0.28);
		}

		.main {
			padding: 30px;
		}

		.topbar {
			display: flex;
			justify-content: space-between;
			align-items: center;
			gap: 12px;
			padding-bottom: 18px;
			margin-bottom: 20px;
			border-bottom: 1px solid var(--line);
		}

		.topbar h1 {
			margin: 0;
			font-family: 'Syne', sans-serif;
			font-size: clamp(1.6rem, 2.5vw, 2.2rem);
			letter-spacing: -0.02em;
		}

		.admin-badge {
			font-size: 12px;
			font-weight: 700;
			letter-spacing: 0.08em;
			text-transform: uppercase;
			padding: 8px 12px;
			border-radius: 999px;
			color: #fff;
			background: linear-gradient(135deg, var(--accent), var(--accent-strong));
		}

		.notice {
			margin-bottom: 14px;
			border-radius: 12px;
			padding: 10px 12px;
			font-size: 14px;
			font-weight: 500;
		}

		.notice.success {
			border: 1px solid rgba(31, 122, 70, 0.35);
			background: rgba(31, 122, 70, 0.12);
			color: var(--ok);
		}

		.notice.error {
			border: 1px solid rgba(163, 21, 21, 0.35);
			background: rgba(163, 21, 21, 0.1);
			color: var(--error);
		}

		.stats {
			display: grid;
			grid-template-columns: repeat(6, minmax(0, 1fr));
			gap: 14px;
			margin-bottom: 18px;
		}

		.stat-card {
			border-radius: 14px;
			padding: 16px;
			color: #fff;
			box-shadow: 0 10px 24px rgba(49, 36, 20, 0.18);
		}

		.stat-card h3 {
			margin: 0;
			font-size: 0.95rem;
			font-weight: 600;
		}

		.stat-card p {
			margin: 10px 0 0;
			font-family: 'Syne', sans-serif;
			font-size: clamp(1.7rem, 3vw, 2.3rem);
			line-height: 1;
		}

		.stat-card.total {
			background: linear-gradient(135deg, #3f7ed7, #2f66b3);
		}

		.stat-card.pending {
			background: linear-gradient(135deg, #c98517, #9a5b07);
		}

		.stat-card.processing {
			background: linear-gradient(135deg, #466eb2, #305086);
		}

		.stat-card.delivered {
			background: linear-gradient(135deg, #29995a, #1f7a46);
		}

		.stat-card.shipped {
			background: linear-gradient(135deg, #2f9ea8, #227a83);
		}

		.stat-card.cancelled {
			background: linear-gradient(135deg, #bd3b3b, #8f2a2a);
		}

		.panel {
			border: 1px solid var(--line);
			border-radius: 16px;
			background: rgba(255, 255, 255, 0.7);
			overflow: hidden;
			margin-bottom: 16px;
		}

		.panel-header {
			padding: 14px;
			border-bottom: 1px solid var(--line);
			display: flex;
			gap: 10px;
			justify-content: space-between;
			align-items: center;
			flex-wrap: wrap;
		}

		.filter-form {
			display: flex;
			gap: 8px;
			flex-wrap: wrap;
			width: 100%;
			max-width: 760px;
		}

		input,
		select {
			border: 1px solid rgba(31, 26, 21, 0.22);
			border-radius: 12px;
			padding: 10px 12px;
			font-size: 14px;
			font-family: inherit;
			background: rgba(255, 255, 255, 0.9);
			outline: none;
		}

		input:focus,
		select:focus {
			border-color: rgba(218, 90, 27, 0.7);
			box-shadow: 0 0 0 4px rgba(218, 90, 27, 0.15);
		}

		.filter-form input {
			flex: 1;
			min-width: 220px;
		}

		.filter-form select {
			min-width: 170px;
		}

		.btn {
			border: none;
			border-radius: 12px;
			padding: 10px 14px;
			font-size: 14px;
			font-weight: 700;
			letter-spacing: 0.01em;
			cursor: pointer;
			text-decoration: none;
			display: inline-flex;
			align-items: center;
			justify-content: center;
			gap: 6px;
		}

		.btn-primary {
			color: #fff;
			background: linear-gradient(135deg, var(--accent), var(--accent-strong));
		}

		.btn-outline {
			color: var(--ink);
			border: 1px solid var(--line);
			background: rgba(255, 255, 255, 0.85);
		}

		.btn-save {
			padding: 8px 10px;
			font-size: 13px;
		}

		.table-wrap {
			width: 100%;
			overflow-x: auto;
		}

		table {
			width: 100%;
			border-collapse: collapse;
			min-width: 1200px;
		}

		thead th {
			text-align: left;
			padding: 12px 14px;
			font-size: 12px;
			letter-spacing: 0.05em;
			text-transform: uppercase;
			color: rgba(31, 26, 21, 0.82);
			border-bottom: 1px solid var(--line);
			background: rgba(255, 255, 255, 0.62);
		}

		tbody td {
			padding: 12px 14px;
			border-bottom: 1px solid rgba(31, 26, 21, 0.08);
			font-size: 14px;
			vertical-align: top;
		}

		tbody tr:hover {
			background: rgba(255, 255, 255, 0.55);
		}

		.order-id {
			font-family: monospace;
			font-size: 13px;
		}

		.status-pill {
			display: inline-block;
			padding: 5px 10px;
			border-radius: 999px;
			font-size: 12px;
			font-weight: 700;
			letter-spacing: 0.03em;
			text-transform: uppercase;
		}

		.status-pill.pending {
			color: var(--warning);
			border: 1px solid rgba(154, 91, 7, 0.34);
			background: rgba(154, 91, 7, 0.12);
		}

		.status-pill.processing,
		.status-pill.shipped {
			color: #274f8f;
			border: 1px solid rgba(39, 79, 143, 0.34);
			background: rgba(39, 79, 143, 0.12);
		}

		.status-pill.delivered {
			color: var(--ok);
			border: 1px solid rgba(31, 122, 70, 0.34);
			background: rgba(31, 122, 70, 0.12);
		}

		.status-pill.cancelled {
			color: var(--error);
			border: 1px solid rgba(163, 21, 21, 0.34);
			background: rgba(163, 21, 21, 0.12);
		}

		.status-form {
			display: flex;
			gap: 8px;
			align-items: center;
		}

		.muted {
			color: rgba(31, 26, 21, 0.7);
			font-size: 13px;
		}

		.filter-chip {
			display: inline-flex;
			align-items: center;
			gap: 6px;
			padding: 6px 10px;
			border-radius: 999px;
			font-size: 12px;
			font-weight: 700;
			line-height: 1;
			color: #fff;
			background: linear-gradient(135deg, var(--accent), var(--accent-strong));
			box-shadow: 0 6px 14px rgba(183, 64, 9, 0.24);
		}

		.items-text {
			max-width: 380px;
			line-height: 1.45;
		}

		.address-text {
			max-width: 340px;
			white-space: pre-wrap;
			line-height: 1.4;
		}

		.shipping-meta-row {
			margin-top: 4px;
			font-size: 12px;
			line-height: 1.4;
			color: rgba(31, 26, 21, 0.72);
		}

		.empty-state {
			padding: 28px;
			text-align: center;
			color: rgba(31, 26, 21, 0.78);
		}

		.pagination {
			display: flex;
			align-items: center;
			justify-content: center;
			gap: 6px;
			padding: 16px 14px;
			border-top: 1px solid var(--line);
		}

		.pagination-info {
			font-size: 13px;
			color: rgba(31, 26, 21, 0.7);
			margin-right: auto;
		}

		.pagination-controls {
			display: flex;
			align-items: center;
			gap: 6px;
		}

		.pagination-link,
		.pagination-btn {
			display: inline-flex;
			align-items: center;
			justify-content: center;
			min-width: 32px;
			height: 32px;
			border: 1px solid var(--line);
			border-radius: 8px;
			background: rgba(255, 255, 255, 0.8);
			color: var(--ink);
			text-decoration: none;
			font-size: 13px;
			font-weight: 500;
			cursor: pointer;
			transition: all 160ms ease;
		}

		.pagination-link:hover,
		.pagination-btn:hover {
			border-color: var(--accent);
			background: rgba(218, 90, 27, 0.08);
			color: var(--accent);
		}

		.pagination-link.active {
			border-color: var(--accent);
			background: linear-gradient(135deg, var(--accent), var(--accent-strong));
			color: #fff;
			box-shadow: 0 4px 12px rgba(183, 64, 9, 0.2);
		}

		.pagination-btn:disabled {
			opacity: 0.5;
			cursor: not-allowed;
		}

		@media (max-width: 960px) {
			.stats {
				grid-template-columns: repeat(3, minmax(0, 1fr));
			}
		}
	</style>
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
					<span class="muted"><?php echo $filteredTotal; ?> result(s)</span>
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
