<?php
include_once '../config/config.php';
include_once '../config/auth.php';

if (empty($_SESSION['admin_csrf'])) {
	$_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
}

$statusMessage = '';
$statusType = '';

$search = trim($_GET['q'] ?? '');
$editId = trim($_GET['edit'] ?? '');
$currentPage = max(1, (int) ($_GET['page'] ?? 1));
$itemsPerPage = 25;

$stockFilter = strtolower(trim($_GET['stock_filter'] ?? 'all'));
$allowedStockFilters = ['all', 'in_stock', 'low_stock', 'out_of_stock'];
if (!in_array($stockFilter, $allowedStockFilters, true)) {
	$stockFilter = 'all';
}

$statusFilter = strtolower(trim($_GET['status_filter'] ?? 'all'));
$allowedStatusFilters = ['all', 'active', 'inactive'];
if (!in_array($statusFilter, $allowedStatusFilters, true)) {
	$statusFilter = 'all';
}

$minPriceRaw = trim($_GET['min_price'] ?? '');
$maxPriceRaw = trim($_GET['max_price'] ?? '');
$minPrice = ($minPriceRaw !== '' && is_numeric($minPriceRaw) && (float) $minPriceRaw >= 0)
	? (float) $minPriceRaw
	: null;
$maxPrice = ($maxPriceRaw !== '' && is_numeric($maxPriceRaw) && (float) $maxPriceRaw >= 0)
	? (float) $maxPriceRaw
	: null;
if ($minPrice !== null && $maxPrice !== null && $minPrice > $maxPrice) {
	[$minPrice, $maxPrice] = [$maxPrice, $minPrice];
}

$sortBy = strtolower(trim($_GET['sort_by'] ?? 'newest'));
$sortDir = strtolower(trim($_GET['sort_dir'] ?? 'desc'));
if (!in_array($sortDir, ['asc', 'desc'], true)) {
	$sortDir = 'desc';
}

$projectRoot = dirname(__DIR__);
$productUploadDirRel = 'uploads/products';
$productUploadDirAbs = $projectRoot . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'products';

function hasProductColumn(PDO $pdo, string $columnName): bool
{
	static $columnCache = [];

	if (array_key_exists($columnName, $columnCache)) {
		return $columnCache[$columnName];
	}

	$stmt = $pdo->prepare(
		"SELECT 1
		 FROM INFORMATION_SCHEMA.COLUMNS
		 WHERE TABLE_SCHEMA = DATABASE()
		   AND TABLE_NAME = 'Products'
		   AND COLUMN_NAME = ?
		 LIMIT 1"
	);
	$stmt->execute([$columnName]);
	$columnCache[$columnName] = (bool) $stmt->fetchColumn();

	return $columnCache[$columnName];
}

function uploadProductImage(array $file, string $uploadDirAbs, string $uploadDirRel): string
{
	if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
		return '';
	}

	if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
		throw new RuntimeException('Image upload failed. Please try again.');
	}

	$maxBytes = 5 * 1024 * 1024;
	if (($file['size'] ?? 0) > $maxBytes) {
		throw new RuntimeException('Image is too large. Maximum allowed size is 5MB.');
	}

	$finfo = finfo_open(FILEINFO_MIME_TYPE);
	$mime = $finfo ? finfo_file($finfo, $file['tmp_name']) : '';
	if ($finfo) {
		finfo_close($finfo);
	}

	$allowedMime = [
		'image/jpeg' => 'jpg',
		'image/png' => 'png',
		'image/webp' => 'webp',
		'image/gif' => 'gif',
	];

	if (!isset($allowedMime[$mime])) {
		throw new RuntimeException('Only JPG, PNG, WEBP, or GIF images are allowed.');
	}

	if (!is_dir($uploadDirAbs) && !mkdir($uploadDirAbs, 0775, true) && !is_dir($uploadDirAbs)) {
		throw new RuntimeException('Unable to create product upload directory.');
	}

	$filename = 'product_' . bin2hex(random_bytes(16)) . '.' . $allowedMime[$mime];
	$targetAbs = $uploadDirAbs . DIRECTORY_SEPARATOR . $filename;

	if (!move_uploaded_file($file['tmp_name'], $targetAbs)) {
		throw new RuntimeException('Unable to save uploaded image.');
	}

	return $uploadDirRel . '/' . $filename;
}

function deleteProductImageFile(string $relativePath, string $projectRoot, string $uploadDirRel): void
{
	$normalized = str_replace('\\', '/', trim($relativePath));
	$allowedPrefix = rtrim($uploadDirRel, '/') . '/';
	if (strpos($normalized, $allowedPrefix) !== 0) {
		return;
	}

	$absolute = $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalized);
	if (is_file($absolute)) {
		@unlink($absolute);
	}
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$action = $_POST['action'] ?? '';
	$csrf = $_POST['csrf'] ?? '';

	if (!hash_equals($_SESSION['admin_csrf'], $csrf)) {
		$statusMessage = 'Invalid security token. Please refresh and try again.';
		$statusType = 'error';
	} else {
		try {
			if ($action === 'add_product') {
				$name = trim($_POST['product_name'] ?? '');
				$description = trim($_POST['description'] ?? '');
				$priceRaw = trim($_POST['price'] ?? '');
				$stockRaw = trim($_POST['stock_quantity'] ?? '');

				if ($name === '') {
					throw new RuntimeException('Product name is required.');
				}
				if ($priceRaw === '' || !is_numeric($priceRaw) || (float) $priceRaw < 0) {
					throw new RuntimeException('Price must be a valid non-negative number.');
				}
				if ($stockRaw === '' || !preg_match('/^\d+$/', $stockRaw)) {
					throw new RuntimeException('Stock quantity must be a valid non-negative integer.');
				}

				$imagePath = uploadProductImage($_FILES['product_image'] ?? [], $productUploadDirAbs, $productUploadDirRel);
				$productId = (string) $pdo->query('SELECT UUID()')->fetchColumn();

				$insertSql = 'INSERT INTO Products (ProductId, ProductName, Description, Price, StockQuantity, CreateDate)
							  VALUES (:product_id, :name, :description, :price, :stock, NOW())';
				$insertStmt = $pdo->prepare($insertSql);
				$insertStmt->execute([
					':product_id' => $productId,
					':name' => $name,
					':description' => $description,
					':price' => number_format((float) $priceRaw, 2, '.', ''),
					':stock' => (int) $stockRaw,
				]);

				if ($imagePath !== '') {
					$imageInsert = $pdo->prepare('INSERT INTO ProductImages (ImageId, ProductId, ImageUrl, IsPrimary)
											 VALUES (UUID(), :product_id, :image_url, 1)');
					$imageInsert->execute([
						':product_id' => $productId,
						':image_url' => $imagePath,
					]);
				}

				$query = http_build_query([
					'status' => 'ok',
					'message' => 'Product added successfully.',
				]);
				header('Location: product_management.php?' . $query);
				exit;
			}

			if ($action === 'update_product') {
				$productId = trim($_POST['product_id'] ?? '');
				$name = trim($_POST['product_name'] ?? '');
				$description = trim($_POST['description'] ?? '');
				$priceRaw = trim($_POST['price'] ?? '');
				$stockRaw = trim($_POST['stock_quantity'] ?? '');

				if ($productId === '') {
					throw new RuntimeException('Invalid product identifier.');
				}
				if ($name === '') {
					throw new RuntimeException('Product name is required.');
				}
				if ($priceRaw === '' || !is_numeric($priceRaw) || (float) $priceRaw < 0) {
					throw new RuntimeException('Price must be a valid non-negative number.');
				}
				if ($stockRaw === '' || !preg_match('/^\d+$/', $stockRaw)) {
					throw new RuntimeException('Stock quantity must be a valid non-negative integer.');
				}

				$newImagePath = uploadProductImage($_FILES['product_image'] ?? [], $productUploadDirAbs, $productUploadDirRel);

				$updateSql = 'UPDATE Products
							  SET ProductName = :name,
								  Description = :description,
								  Price = :price,
								  StockQuantity = :stock
							  WHERE ProductId = :product_id';
				$updateStmt = $pdo->prepare($updateSql);
				$updateStmt->execute([
					':name' => $name,
					':description' => $description,
					':price' => number_format((float) $priceRaw, 2, '.', ''),
					':stock' => (int) $stockRaw,
					':product_id' => $productId,
				]);

				if ($updateStmt->rowCount() === 0) {
					$existsStmt = $pdo->prepare('SELECT COUNT(*) FROM Products WHERE ProductId = :product_id');
					$existsStmt->execute([':product_id' => $productId]);
					if ((int) $existsStmt->fetchColumn() === 0) {
						throw new RuntimeException('Product not found.');
					}
				}

				if ($newImagePath !== '') {
					$oldImageStmt = $pdo->prepare('SELECT ImageUrl FROM ProductImages WHERE ProductId = :product_id AND IsPrimary = 1 LIMIT 1');
					$oldImageStmt->execute([':product_id' => $productId]);
					$oldImagePath = (string) ($oldImageStmt->fetchColumn() ?: '');

					$pdo->beginTransaction();

					$unsetPrimaryStmt = $pdo->prepare('UPDATE ProductImages SET IsPrimary = 0 WHERE ProductId = :product_id');
					$unsetPrimaryStmt->execute([':product_id' => $productId]);

					$insertImageStmt = $pdo->prepare('INSERT INTO ProductImages (ImageId, ProductId, ImageUrl, IsPrimary)
													VALUES (UUID(), :product_id, :image_url, 1)');
					$insertImageStmt->execute([
						':product_id' => $productId,
						':image_url' => $newImagePath,
					]);

					$pdo->commit();

					if ($oldImagePath !== '') {
						deleteProductImageFile($oldImagePath, $projectRoot, $productUploadDirRel);
					}
				}

				$query = http_build_query([
					'status' => 'ok',
					'message' => 'Product updated successfully.',
				]);
				header('Location: product_management.php?' . $query);
				exit;
			}

			if ($action === 'delete_product') {
				$productId = trim($_POST['product_id'] ?? '');
				if ($productId === '') {
					throw new RuntimeException('Invalid product identifier.');
				}

				$imageRowsStmt = $pdo->prepare('SELECT ImageUrl FROM ProductImages WHERE ProductId = :product_id');
				$imageRowsStmt->execute([':product_id' => $productId]);
				$imageRows = $imageRowsStmt->fetchAll(PDO::FETCH_ASSOC);

				$usageSql = 'SELECT
								(SELECT COUNT(*) FROM OrderItems WHERE ProductId = :pid_order) AS order_count,
								(SELECT COUNT(*) FROM Carts WHERE ProductId = :pid_cart) AS cart_count';
				$usageStmt = $pdo->prepare($usageSql);
				$usageStmt->execute([
					':pid_order' => $productId,
					':pid_cart' => $productId,
				]);
				$usage = $usageStmt->fetch(PDO::FETCH_ASSOC) ?: ['order_count' => 0, 'cart_count' => 0];

				if ((int) $usage['order_count'] > 0) {
					throw new RuntimeException('Cannot delete product because it is linked to existing orders.');
				}

				$pdo->beginTransaction();

				$deleteImages = $pdo->prepare('DELETE FROM ProductImages WHERE ProductId = :product_id');
				$deleteImages->execute([':product_id' => $productId]);

				$deleteCart = $pdo->prepare('DELETE FROM Carts WHERE ProductId = :product_id');
				$deleteCart->execute([':product_id' => $productId]);

				$deleteProduct = $pdo->prepare('DELETE FROM Products WHERE ProductId = :product_id');
				$deleteProduct->execute([':product_id' => $productId]);

				if ($deleteProduct->rowCount() === 0) {
					$pdo->rollBack();
					throw new RuntimeException('Product not found.');
				}

				$pdo->commit();

				foreach ($imageRows as $imageRow) {
					$imagePath = (string) ($imageRow['ImageUrl'] ?? '');
					if ($imagePath !== '') {
						deleteProductImageFile($imagePath, $projectRoot, $productUploadDirRel);
					}
				}

				$query = http_build_query([
					'status' => 'ok',
					'message' => (int) $usage['cart_count'] > 0
						? 'Product deleted. Existing cart items were removed.'
						: 'Product deleted successfully.',
				]);
				header('Location: product_management.php?' . $query);
				exit;
			}
		} catch (Throwable $e) {
			if ($pdo->inTransaction()) {
				$pdo->rollBack();
			}
			$statusMessage = $e->getMessage();
			$statusType = 'error';
		}
	}
}

if (isset($_GET['status'], $_GET['message'])) {
	$statusType = $_GET['status'] === 'ok' ? 'success' : 'error';
	$statusMessage = (string) $_GET['message'];
}

$editProduct = null;
if ($editId !== '') {
	$editStmt = $pdo->prepare('SELECT
								ProductId,
								ProductName,
								Description,
								Price,
								StockQuantity,
								(
									SELECT ImageUrl
									FROM ProductImages pi
									WHERE pi.ProductId = Products.ProductId
									AND pi.IsPrimary = 1
									ORDER BY pi.ImageId DESC
									LIMIT 1
								) AS PrimaryImageUrl
							FROM Products
							WHERE ProductId = :id
							LIMIT 1');
	$editStmt->execute([':id' => $editId]);
	$editProduct = $editStmt->fetch(PDO::FETCH_ASSOC) ?: null;

	if ($editProduct === null) {
		$statusMessage = 'Selected product was not found.';
		$statusType = 'error';
	}
}

try {
	$hasProductActiveColumn = hasProductColumn($pdo, 'IsActive');
	if ($hasProductActiveColumn) {
		$allowedSortColumns = [
			'newest' => 'p.CreateDate',
			'name' => 'p.ProductName',
			'price' => 'p.Price',
			'stock' => 'p.StockQuantity',
			'active' => 'p.IsActive',
		];
	} else {
		$allowedSortColumns = [
			'newest' => 'p.CreateDate',
			'name' => 'p.ProductName',
			'price' => 'p.Price',
			'stock' => 'p.StockQuantity',
		];
		if ($statusFilter !== 'all') {
			$statusFilter = 'all';
		}
	}

	if (!isset($allowedSortColumns[$sortBy])) {
		$sortBy = 'newest';
	}

	$countSql = 'SELECT
					COUNT(*) AS total_products,
					SUM(CASE WHEN StockQuantity = 0 THEN 1 ELSE 0 END) AS out_of_stock,
					SUM(CASE WHEN StockQuantity > 0 AND StockQuantity <= 5 THEN 1 ELSE 0 END) AS low_stock
				 FROM Products';
	$countStmt = $pdo->query($countSql);
	$counts = $countStmt->fetch(PDO::FETCH_ASSOC) ?: [
		'total_products' => 0,
		'out_of_stock' => 0,
		'low_stock' => 0,
	];

	$listSql = 'SELECT p.ProductId, p.ProductName, p.Description, p.Price, p.StockQuantity, p.CreateDate,
					' . ($hasProductActiveColumn ? 'p.IsActive,' : '1 AS IsActive,') . '
					(
						SELECT ImageUrl
						FROM ProductImages pi
						WHERE pi.ProductId = p.ProductId
						AND pi.IsPrimary = 1
						ORDER BY pi.ImageId DESC
						LIMIT 1
					) AS PrimaryImageUrl
				FROM Products p
				WHERE 1=1';

	$listCountSql = 'SELECT COUNT(*) FROM Products p WHERE 1=1';

	$params = [];
	if ($search !== '') {
		$listSql .= ' AND (
			p.ProductName LIKE :keyword_name_prefix
			OR p.ProductName LIKE :keyword_name_contains
			OR p.Description LIKE :keyword_description_contains
		)';
		$listCountSql .= ' AND (
			p.ProductName LIKE :keyword_name_prefix
			OR p.ProductName LIKE :keyword_name_contains
			OR p.Description LIKE :keyword_description_contains
		)';
		$keywordEscaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search);
		$params[':keyword_name_prefix'] = $keywordEscaped . '%';
		$params[':keyword_name_contains'] = '%' . $keywordEscaped . '%';
		$params[':keyword_description_contains'] = '%' . $keywordEscaped . '%';
	}

	if ($stockFilter === 'in_stock') {
		$listSql .= ' AND p.StockQuantity > 0';
		$listCountSql .= ' AND p.StockQuantity > 0';
	} elseif ($stockFilter === 'low_stock') {
		$listSql .= ' AND p.StockQuantity BETWEEN 1 AND 5';
		$listCountSql .= ' AND p.StockQuantity BETWEEN 1 AND 5';
	} elseif ($stockFilter === 'out_of_stock') {
		$listSql .= ' AND p.StockQuantity = 0';
		$listCountSql .= ' AND p.StockQuantity = 0';
	}

	if ($minPrice !== null) {
		$listSql .= ' AND p.Price >= :min_price';
		$listCountSql .= ' AND p.Price >= :min_price';
		$params[':min_price'] = number_format($minPrice, 2, '.', '');
	}
	if ($maxPrice !== null) {
		$listSql .= ' AND p.Price <= :max_price';
		$listCountSql .= ' AND p.Price <= :max_price';
		$params[':max_price'] = number_format($maxPrice, 2, '.', '');
	}

	if ($hasProductActiveColumn) {
		if ($statusFilter === 'active') {
			$listSql .= ' AND p.IsActive = 1';
			$listCountSql .= ' AND p.IsActive = 1';
		} elseif ($statusFilter === 'inactive') {
			$listSql .= ' AND p.IsActive = 0';
			$listCountSql .= ' AND p.IsActive = 0';
		}
	}

	$listCountStmt = $pdo->prepare($listCountSql);
	$listCountStmt->execute($params);
	$filteredTotal = (int) $listCountStmt->fetchColumn();
	$totalPages = max(1, (int) ceil($filteredTotal / $itemsPerPage));
	$currentPage = min($currentPage, $totalPages);
	$offset = ($currentPage - 1) * $itemsPerPage;

	$listSql .= ' ORDER BY ' . $allowedSortColumns[$sortBy] . ' ' . strtoupper($sortDir) . ' LIMIT ' . $itemsPerPage . ' OFFSET ' . $offset;
	$listStmt = $pdo->prepare($listSql);
	$listStmt->execute($params);
	$products = $listStmt->fetchAll(PDO::FETCH_ASSOC);

	$queryBase = [
		'q' => $search,
		'stock_filter' => $stockFilter,
		'status_filter' => $statusFilter,
		'min_price' => $minPrice !== null ? number_format($minPrice, 2, '.', '') : '',
		'max_price' => $maxPrice !== null ? number_format($maxPrice, 2, '.', '') : '',
		'sort_by' => $sortBy,
		'sort_dir' => $sortDir,
	];

	$buildListUrl = function (array $overrides = []) use ($queryBase): string {
		$params = array_merge($queryBase, $overrides);

		if (($params['q'] ?? '') === '') {
			unset($params['q']);
		}
		if (($params['stock_filter'] ?? 'all') === 'all') {
			unset($params['stock_filter']);
		}
		if (($params['status_filter'] ?? 'all') === 'all') {
			unset($params['status_filter']);
		}
		if (($params['min_price'] ?? '') === '') {
			unset($params['min_price']);
		}
		if (($params['max_price'] ?? '') === '') {
			unset($params['max_price']);
		}
		if (($params['sort_by'] ?? 'newest') === 'newest') {
			unset($params['sort_by']);
		}
		if (($params['sort_dir'] ?? 'desc') === 'desc') {
			unset($params['sort_dir']);
		}
		if (($params['page'] ?? null) === 1) {
			unset($params['page']);
		}
		if (($params['edit'] ?? '') === '') {
			unset($params['edit']);
		}

		return 'product_management.php' . (!empty($params) ? '?' . http_build_query($params) : '');
	};
} catch (Exception $e) {
	die('Error fetching product data: ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Product Management || E-Commerce</title>
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link href="https://fonts.googleapis.com/css2?family=Syne:wght@500;700;800&family=IBM+Plex+Sans:wght@400;500;600&display=swap" rel="stylesheet">
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
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
			padding: 24px;
		}

		.shell {
			width: min(1300px, 100%);
			margin: 0 auto;
			display: grid;
			grid-template-columns: 280px minmax(0, 1fr);
			border: 1px solid var(--line);
			border-radius: 26px;
			overflow: hidden;
			box-shadow: 0 18px 50px rgba(49, 36, 20, 0.15);
			background: var(--paper);
			backdrop-filter: blur(8px);
		}

		.sidebar {
			padding: 30px 22px;
			border-right: 1px solid var(--line);
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
			grid-template-columns: repeat(3, minmax(0, 1fr));
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

		.stat-card.low {
			background: linear-gradient(135deg, #c98517, #9a5b07);
		}

		.stat-card.out {
			background: linear-gradient(135deg, #d15038, #a92b1b);
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

		.panel-title {
			margin: 0;
			font-size: 1rem;
			font-weight: 700;
		}

		.form-grid {
			padding: 14px;
			display: grid;
			grid-template-columns: repeat(2, minmax(0, 1fr));
			gap: 12px;
		}

		.field {
			display: grid;
			gap: 6px;
		}

		.field.full {
			grid-column: 1 / -1;
		}

		label {
			font-size: 13px;
			font-weight: 600;
		}

		input,
		textarea {
			width: 100%;
			border: 1px solid rgba(31, 26, 21, 0.22);
			border-radius: 12px;
			padding: 10px 12px;
			font-size: 14px;
			font-family: inherit;
			background: rgba(255, 255, 255, 0.9);
			outline: none;
		}

		input:focus,
		textarea:focus {
			border-color: rgba(218, 90, 27, 0.7);
			box-shadow: 0 0 0 4px rgba(218, 90, 27, 0.15);
		}

		textarea {
			min-height: 110px;
			resize: vertical;
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

		.btn-danger {
			color: #fff;
			background: linear-gradient(135deg, #cc4d35, #9f2418);
		}

		.btn-secondary {
			color: #fff;
			background: linear-gradient(135deg, #4e6f95, #35506f);
		}

		.search-form {
			display: flex;
			gap: 8px;
			flex-wrap: wrap;
			width: 100%;
			max-width: 580px;
		}

		.search-form input {
			flex: 1;
			min-width: 200px;
		}

		.search-form select {
			border: 1px solid rgba(31, 26, 21, 0.22);
			border-radius: 12px;
			padding: 10px 12px;
			font-size: 14px;
			font-family: inherit;
			background: rgba(255, 255, 255, 0.9);
			outline: none;
		}

		.search-form select:focus {
			border-color: rgba(218, 90, 27, 0.7);
			box-shadow: 0 0 0 4px rgba(218, 90, 27, 0.15);
		}

		.table-wrap {
			width: 100%;
			overflow-x: auto;
		}

		table {
			width: 100%;
			border-collapse: collapse;
			min-width: 980px;
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

		th.sortable a {
			color: inherit;
			text-decoration: none;
			display: inline-flex;
			align-items: center;
			gap: 6px;
		}

		th.sortable a:hover {
			color: var(--accent);
		}

		tbody td {
			padding: 12px 14px;
			border-bottom: 1px solid rgba(31, 26, 21, 0.08);
			font-size: 14px;
			vertical-align: middle;
		}

		tbody tr:hover {
			background: rgba(255, 255, 255, 0.55);
		}

		.stock-pill {
			display: inline-block;
			padding: 5px 10px;
			border-radius: 999px;
			font-size: 12px;
			font-weight: 700;
			letter-spacing: 0.03em;
			text-transform: uppercase;
		}

		.stock-pill.ok {
			color: var(--ok);
			border: 1px solid rgba(31, 122, 70, 0.34);
			background: rgba(31, 122, 70, 0.12);
		}

		.stock-pill.low {
			color: var(--warning);
			border: 1px solid rgba(154, 91, 7, 0.34);
			background: rgba(154, 91, 7, 0.12);
		}

		.stock-pill.out {
			color: var(--error);
			border: 1px solid rgba(163, 21, 21, 0.34);
			background: rgba(163, 21, 21, 0.12);
		}

		.actions {
			display: flex;
			gap: 8px;
			align-items: center;
		}

		.inline-form {
			margin: 0;
		}

		.muted {
			color: rgba(31, 26, 21, 0.7);
			font-size: 13px;
		}

		.empty-state {
			padding: 28px;
			text-align: center;
			color: rgba(31, 26, 21, 0.78);
		}

		.image-preview {
			width: 88px;
			height: 88px;
			border-radius: 12px;
			border: 1px solid var(--line);
			object-fit: cover;
			background: rgba(255, 255, 255, 0.8);
		}

		.current-image-wrap {
			display: flex;
			align-items: center;
			gap: 12px;
		}

		.current-image-meta {
			font-size: 13px;
			color: rgba(31, 26, 21, 0.74);
		}

		.table-image {
			width: 54px;
			height: 54px;
			border-radius: 10px;
			object-fit: cover;
			border: 1px solid var(--line);
			background: rgba(255, 255, 255, 0.8);
		}

		.no-image {
			font-size: 12px;
			color: rgba(31, 26, 21, 0.6);
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

		@media (max-width: 1100px) {
			.form-grid {
				grid-template-columns: 1fr;
			}
		}

		@media (max-width: 960px) {
			.shell {
				grid-template-columns: 1fr;
			}

			.sidebar {
				border-right: none;
				border-bottom: 1px solid var(--line);
			}

			.stats {
				grid-template-columns: 1fr;
			}
		}

		@media (max-width: 640px) {
			body {
				padding: 14px;
			}

			.main,
			.sidebar {
				padding: 22px 16px;
			}

			.topbar {
				align-items: flex-start;
				flex-direction: column;
			}

			.search-form {
				max-width: none;
			}
		}
	</style>
</head>
<body>
<div class="shell">
	<aside class="sidebar">
		<span class="brand-tag">Admin Portal</span>
		<h2>Product Management</h2>
		<nav class="nav-links">
			<a href="admin_dashboard.php"><i class="bi bi-speedometer2"></i> Control Panel</a>
			<a href="product_management.php" class="active"><i class="bi bi-box"></i> Product Management</a>
			<a href="member_management.php"><i class="bi bi-people"></i> Member Management</a>
			<a href="order_management.php"><i class="bi bi-cart"></i> Order Management</a>
			<a href="admin_logout.php"><i class="bi bi-gear"></i> Logout</a>
		</nav>
	</aside>

	<main class="main">
		<div class="topbar">
			<h1>Products</h1>
			<span class="admin-badge">Admin</span>
		</div>

		<?php if ($statusMessage !== ''): ?>
			<div class="notice <?php echo htmlspecialchars($statusType ?: 'error'); ?>">
				<?php echo htmlspecialchars($statusMessage); ?>
			</div>
		<?php endif; ?>

		<section class="stats">
			<article class="stat-card total">
				<h3>Total Products</h3>
				<p><?php echo (int) ($counts['total_products'] ?? 0); ?></p>
			</article>
			<article class="stat-card low">
				<h3>Low Stock (1-5)</h3>
				<p><?php echo (int) ($counts['low_stock'] ?? 0); ?></p>
			</article>
			<article class="stat-card out">
				<h3>Out of Stock</h3>
				<p><?php echo (int) ($counts['out_of_stock'] ?? 0); ?></p>
			</article>
		</section>

		<section class="panel">
			<div class="panel-header">
				<h2 class="panel-title"><?php echo $editProduct ? 'Edit Product' : 'Add Product'; ?></h2>
				<?php if ($editProduct): ?>
					<a class="btn btn-outline" href="product_management.php"><i class="bi bi-x-circle"></i> Cancel Edit</a>
				<?php endif; ?>
			</div>

			<form method="post" enctype="multipart/form-data" action="<?php echo htmlspecialchars($buildListUrl()); ?>" class="form-grid">
				<input type="hidden" name="csrf" value="<?php echo htmlspecialchars($_SESSION['admin_csrf']); ?>">
				<input type="hidden" name="action" value="<?php echo $editProduct ? 'update_product' : 'add_product'; ?>">
				<?php if ($editProduct): ?>
					<input type="hidden" name="product_id" value="<?php echo htmlspecialchars((string) $editProduct['ProductId']); ?>">
				<?php endif; ?>

				<div class="field">
					<label for="product_name">Product Name</label>
					<input
						id="product_name"
						type="text"
						name="product_name"
						maxlength="255"
						required
						value="<?php echo htmlspecialchars((string) ($editProduct['ProductName'] ?? '')); ?>"
					>
				</div>

				<div class="field">
					<label for="price">Price (RM)</label>
					<input
						id="price"
						type="number"
						name="price"
						step="0.01"
						min="0"
						required
						value="<?php echo htmlspecialchars((string) ($editProduct['Price'] ?? '')); ?>"
					>
				</div>

				<div class="field">
					<label for="stock_quantity">Stock Quantity</label>
					<input
						id="stock_quantity"
						type="number"
						name="stock_quantity"
						step="1"
						min="0"
						required
						value="<?php echo htmlspecialchars((string) ($editProduct['StockQuantity'] ?? 0)); ?>"
					>
				</div>

				<div class="field full">
					<label for="description">Description</label>
					<textarea id="description" name="description" placeholder="Optional product details..."><?php echo htmlspecialchars((string) ($editProduct['Description'] ?? '')); ?></textarea>
				</div>

				<div class="field full">
					<label for="product_image">Product Image (JPG, PNG, WEBP, GIF up to 5MB)</label>
					<input id="product_image" type="file" name="product_image" accept="image/jpeg,image/png,image/webp,image/gif">
				</div>

				<?php if (!empty($editProduct['PrimaryImageUrl'])): ?>
					<div class="field full">
						<div class="current-image-wrap">
							<img class="image-preview" src="../<?php echo htmlspecialchars((string) $editProduct['PrimaryImageUrl']); ?>" alt="Current product image">
							<div class="current-image-meta">Current primary image. Upload a new file to replace it.</div>
						</div>
					</div>
				<?php endif; ?>

				<div class="field full">
					<button class="btn btn-primary" type="submit">
						<i class="bi <?php echo $editProduct ? 'bi-pencil-square' : 'bi-plus-circle'; ?>"></i>
						<?php echo $editProduct ? 'Update Product' : 'Add Product'; ?>
					</button>
				</div>
			</form>
		</section>

		<section class="panel">
			<div class="panel-header">
				<form class="search-form" method="get" action="product_management.php">
					<?php if ($editId !== ''): ?>
						<input type="hidden" name="edit" value="<?php echo htmlspecialchars($editId); ?>">
					<?php endif; ?>
					<input
						type="text"
						name="q"
						value="<?php echo htmlspecialchars($search); ?>"
						placeholder="Search by product name or description"
					>
					<select name="stock_filter">
						<option value="all" <?php echo $stockFilter === 'all' ? 'selected' : ''; ?>>All Stock</option>
						<option value="in_stock" <?php echo $stockFilter === 'in_stock' ? 'selected' : ''; ?>>In Stock</option>
						<option value="low_stock" <?php echo $stockFilter === 'low_stock' ? 'selected' : ''; ?>>Low Stock (1-5)</option>
						<option value="out_of_stock" <?php echo $stockFilter === 'out_of_stock' ? 'selected' : ''; ?>>Out of Stock</option>
					</select>
					<?php if ($hasProductActiveColumn): ?>
						<select name="status_filter">
							<option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>All Statuses</option>
							<option value="active" <?php echo $statusFilter === 'active' ? 'selected' : ''; ?>>Active</option>
							<option value="inactive" <?php echo $statusFilter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
						</select>
					<?php endif; ?>
					<input type="number" step="0.01" min="0" name="min_price" placeholder="Min RM" value="<?php echo htmlspecialchars($minPriceRaw); ?>">
					<input type="number" step="0.01" min="0" name="max_price" placeholder="Max RM" value="<?php echo htmlspecialchars($maxPriceRaw); ?>">
					<select name="sort_by">
						<option value="newest" <?php echo $sortBy === 'newest' ? 'selected' : ''; ?>>Newest</option>
						<option value="name" <?php echo $sortBy === 'name' ? 'selected' : ''; ?>>Name</option>
						<option value="price" <?php echo $sortBy === 'price' ? 'selected' : ''; ?>>Price</option>
						<option value="stock" <?php echo $sortBy === 'stock' ? 'selected' : ''; ?>>Stock</option>
						<?php if ($hasProductActiveColumn): ?>
							<option value="active" <?php echo $sortBy === 'active' ? 'selected' : ''; ?>>Status</option>
						<?php endif; ?>
					</select>
					<select name="sort_dir">
						<option value="desc" <?php echo $sortDir === 'desc' ? 'selected' : ''; ?>>Desc</option>
						<option value="asc" <?php echo $sortDir === 'asc' ? 'selected' : ''; ?>>Asc</option>
					</select>
					<button class="btn btn-primary" type="submit"><i class="bi bi-search"></i> Search</button>
					<?php if ($search !== '' || $stockFilter !== 'all' || $statusFilter !== 'all' || $minPriceRaw !== '' || $maxPriceRaw !== '' || $sortBy !== 'newest' || $sortDir !== 'desc'): ?>
						<a class="btn btn-outline" href="product_management.php"><i class="bi bi-x-circle"></i> Clear</a>
					<?php endif; ?>
				</form>
				<span class="muted"><?php echo $filteredTotal; ?> result(s)</span>
			</div>

			<?php if (empty($products)): ?>
				<div class="empty-state">
					<p>No products found for the current filter.</p>
				</div>
			<?php else: ?>
				<div class="table-wrap">
					<table>
						<thead>
							<tr>
								<th>Image</th>
								<th class="sortable"><a href="<?php echo htmlspecialchars($buildListUrl(['sort_by' => 'name', 'sort_dir' => ($sortBy === 'name' && $sortDir === 'asc') ? 'desc' : 'asc', 'page' => 1])); ?>">Name<?php if ($sortBy === 'name') echo $sortDir === 'asc' ? ' ▲' : ' ▼'; ?></a></th>
								<th>Description</th>
								<th class="sortable"><a href="<?php echo htmlspecialchars($buildListUrl(['sort_by' => 'price', 'sort_dir' => ($sortBy === 'price' && $sortDir === 'asc') ? 'desc' : 'asc', 'page' => 1])); ?>">Price<?php if ($sortBy === 'price') echo $sortDir === 'asc' ? ' ▲' : ' ▼'; ?></a></th>
								<th class="sortable"><a href="<?php echo htmlspecialchars($buildListUrl(['sort_by' => 'stock', 'sort_dir' => ($sortBy === 'stock' && $sortDir === 'asc') ? 'desc' : 'asc', 'page' => 1])); ?>">Stock<?php if ($sortBy === 'stock') echo $sortDir === 'asc' ? ' ▲' : ' ▼'; ?></a></th>
								<th class="sortable"><a href="<?php echo htmlspecialchars($buildListUrl(['sort_by' => 'newest', 'sort_dir' => ($sortBy === 'newest' && $sortDir === 'asc') ? 'desc' : 'asc', 'page' => 1])); ?>">Created<?php if ($sortBy === 'newest') echo $sortDir === 'asc' ? ' ▲' : ' ▼'; ?></a></th>
								<th>Actions</th>
							</tr>
						</thead>
						<tbody>
						<?php foreach ($products as $product): ?>
							<?php
							$stock = (int) ($product['StockQuantity'] ?? 0);
							$stockClass = 'ok';
							if ($stock === 0) {
								$stockClass = 'out';
							} elseif ($stock <= 5) {
								$stockClass = 'low';
							}

							$description = trim((string) ($product['Description'] ?? ''));
							if ($description === '') {
								$description = '-';
							}

							$editUrl = $buildListUrl([
								'edit' => (string) $product['ProductId'],
								'page' => $currentPage,
							]);
							?>
							<tr>
								<td>
									<?php if (!empty($product['PrimaryImageUrl'])): ?>
										<img class="table-image" src="../<?php echo htmlspecialchars((string) $product['PrimaryImageUrl']); ?>" alt="Product image">
									<?php else: ?>
										<span class="no-image">No image</span>
									<?php endif; ?>
								</td>
								<td><?php echo htmlspecialchars((string) $product['ProductName']); ?></td>
								<td><?php echo htmlspecialchars($description); ?></td>
								<td>RM <?php echo number_format((float) $product['Price'], 2); ?></td>
								<td>
									<span class="stock-pill <?php echo $stockClass; ?>">
										<?php echo $stock; ?> units
									</span>
								</td>
								<td><?php echo htmlspecialchars((string) ($product['CreateDate'] ?? '-')); ?></td>
								<td>
									<div class="actions">
										<a class="btn btn-secondary" href="<?php echo htmlspecialchars($editUrl); ?>">
											<i class="bi bi-pencil-square"></i> Edit
										</a>

										<form class="inline-form" method="post" action="<?php echo htmlspecialchars($buildListUrl(['page' => $currentPage])); ?>" onsubmit="return confirm('Delete this product? This cannot be undone.');">
											<input type="hidden" name="csrf" value="<?php echo htmlspecialchars($_SESSION['admin_csrf']); ?>">
											<input type="hidden" name="action" value="delete_product">
											<input type="hidden" name="product_id" value="<?php echo htmlspecialchars((string) $product['ProductId']); ?>">
											<button class="btn btn-danger" type="submit"><i class="bi bi-trash"></i> Delete</button>
										</form>
									</div>
								</td>
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
</body>
</html>