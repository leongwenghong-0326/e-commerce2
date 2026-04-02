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
$maxFileUploads = max(1, (int) ini_get('max_file_uploads'));
$hasProductCategoryIdColumn = hasProductColumn($pdo, 'CategoryId');
$hasCategoryTable = hasTable($pdo, 'category');
$supportsCategoryFeature = $hasProductCategoryIdColumn && $hasCategoryTable;
$categoryOptions = [];
$categoryIdByName = [];
if ($supportsCategoryFeature) {
	$categoryOptionMap = [];
	if ($hasCategoryTable) {
		$categoryRowsStmt = $pdo->query("SELECT CategoryId, CategoryName FROM category ORDER BY CategoryName ASC");
		$categoryRows = $categoryRowsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
		foreach ($categoryRows as $categoryRow) {
			$categoryName = trim((string) ($categoryRow['CategoryName'] ?? ''));
			$categoryId = trim((string) ($categoryRow['CategoryId'] ?? ''));
			if ($categoryName === '' || $categoryId === '') {
				continue;
			}

			$normalizedName = strtolower($categoryName);
			$categoryOptionMap[$normalizedName] = $categoryName;
			$categoryIdByName[$categoryName] = $categoryId;
		}
	}

	$categoryOptions = array_values($categoryOptionMap);
	natcasesort($categoryOptions);
	$categoryOptions = array_values($categoryOptions);
}

$categoryFilter = trim((string) ($_GET['category_filter'] ?? 'all'));
if (!$supportsCategoryFeature) {
	$categoryFilter = 'all';
}
if ($categoryFilter === 'uncategorized') {
	$categoryFilter = 'all';
}

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

function hasTable(PDO $pdo, string $tableName): bool
{
	static $tableCache = [];

	if (array_key_exists($tableName, $tableCache)) {
		return $tableCache[$tableName];
	}

	$stmt = $pdo->prepare(
		"SELECT 1
		 FROM INFORMATION_SCHEMA.TABLES
		 WHERE TABLE_SCHEMA = DATABASE()
		   AND TABLE_NAME = ?
		 LIMIT 1"
	);
	$stmt->execute([$tableName]);
	$tableCache[$tableName] = (bool) $stmt->fetchColumn();

	return $tableCache[$tableName];
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

function uploadProductImages(array $files, string $uploadDirAbs, string $uploadDirRel): array
{
	$uploadedPaths = [];

	if (!isset($files['name'])) {
		return $uploadedPaths;
	}

	if (is_array($files['name'])) {
		$total = count($files['name']);
		for ($i = 0; $i < $total; $i++) {
			$currentFile = [
				'name' => $files['name'][$i] ?? '',
				'type' => $files['type'][$i] ?? '',
				'tmp_name' => $files['tmp_name'][$i] ?? '',
				'error' => $files['error'][$i] ?? UPLOAD_ERR_NO_FILE,
				'size' => $files['size'][$i] ?? 0,
			];

			$path = uploadProductImage($currentFile, $uploadDirAbs, $uploadDirRel);
			if ($path !== '') {
				$uploadedPaths[] = $path;
			}
		}

		return $uploadedPaths;
	}

	$path = uploadProductImage($files, $uploadDirAbs, $uploadDirRel);
	if ($path !== '') {
		$uploadedPaths[] = $path;
	}

	return $uploadedPaths;
}

function countSelectedUploadFiles(array $files): int
{
	if (!isset($files['name'])) {
		return 0;
	}

	if (is_array($files['name'])) {
		$count = 0;
		foreach ($files['name'] as $name) {
			if (trim((string) $name) !== '') {
				$count++;
			}
		}

		return $count;
	}

	return trim((string) $files['name']) !== '' ? 1 : 0;
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
			if ($action === 'delete_product_image') {
				$productId = trim($_POST['product_id'] ?? '');
				$imageId = trim($_POST['image_id'] ?? '');

				if ($productId === '' || $imageId === '') {
					throw new RuntimeException('Invalid image delete request.');
				}

				$targetImageStmt = $pdo->prepare('SELECT ImageId, ImageUrl, IsPrimary
												   FROM ProductImages
												   WHERE ImageId = :image_id
												   AND ProductId = :product_id
												   LIMIT 1');
				$targetImageStmt->execute([
					':image_id' => $imageId,
					':product_id' => $productId,
				]);
				$targetImage = $targetImageStmt->fetch(PDO::FETCH_ASSOC);

				if (!$targetImage) {
					throw new RuntimeException('Image not found for this product.');
				}

				$pdo->beginTransaction();

				$deleteImageStmt = $pdo->prepare('DELETE FROM ProductImages WHERE ImageId = :image_id AND ProductId = :product_id');
				$deleteImageStmt->execute([
					':image_id' => $imageId,
					':product_id' => $productId,
				]);

				if ($deleteImageStmt->rowCount() === 0) {
					$pdo->rollBack();
					throw new RuntimeException('Image was not deleted. Please try again.');
				}

				if ((int) ($targetImage['IsPrimary'] ?? 0) === 1) {
					$newPrimaryStmt = $pdo->prepare('SELECT ImageId
													  FROM ProductImages
													  WHERE ProductId = :product_id
													  ORDER BY ImageId DESC
													  LIMIT 1');
					$newPrimaryStmt->execute([':product_id' => $productId]);
					$newPrimaryId = (string) ($newPrimaryStmt->fetchColumn() ?: '');

					if ($newPrimaryId !== '') {
						$setPrimaryStmt = $pdo->prepare('UPDATE ProductImages SET IsPrimary = 1 WHERE ImageId = :image_id');
						$setPrimaryStmt->execute([':image_id' => $newPrimaryId]);
					}
				}

				$pdo->commit();

				$imagePath = (string) ($targetImage['ImageUrl'] ?? '');
				if ($imagePath !== '') {
					deleteProductImageFile($imagePath, $projectRoot, $productUploadDirRel);
				}

				$query = http_build_query([
					'status' => 'ok',
					'message' => 'Product image deleted successfully.',
					'edit' => $productId,
				]);
				header('Location: product_management.php?' . $query);
				exit;
			}

			if ($action === 'add_product') {
				$name = trim($_POST['product_name'] ?? '');
				$description = trim($_POST['description'] ?? '');
				$category = $supportsCategoryFeature ? trim((string) ($_POST['category'] ?? '')) : '';
				$categoryId = null;
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
				if ($supportsCategoryFeature && $category === '') {
					throw new RuntimeException('Category is required.');
				}
				if ($supportsCategoryFeature && !in_array($category, $categoryOptions, true)) {
					throw new RuntimeException('Please select a valid category.');
				}
				if ($hasProductCategoryIdColumn && $hasCategoryTable) {
					$categoryId = $categoryIdByName[$category] ?? null;
					if ($categoryId === null) {
						throw new RuntimeException('Selected category does not exist in category table.');
					}
				}

				$selectedImageCount = countSelectedUploadFiles($_FILES['product_images'] ?? []);
				if ($selectedImageCount > $maxFileUploads) {
					throw new RuntimeException('Too many images selected. You can upload up to ' . $maxFileUploads . ' images at once.');
				}

				$imagePaths = uploadProductImages($_FILES['product_images'] ?? [], $productUploadDirAbs, $productUploadDirRel);
				$productId = (string) $pdo->query('SELECT UUID()')->fetchColumn();

				if ($hasProductCategoryIdColumn) {
					$insertSql = 'INSERT INTO Products (ProductId, ProductName, CategoryId, Description, Price, StockQuantity, CreateDate)
									  VALUES (:product_id, :name, :category_id, :description, :price, :stock, NOW())';
				} else {
					$insertSql = 'INSERT INTO Products (ProductId, ProductName, Description, Price, StockQuantity, CreateDate)
									  VALUES (:product_id, :name, :description, :price, :stock, NOW())';
				}
				$insertStmt = $pdo->prepare($insertSql);
				$insertParams = [
					':product_id' => $productId,
					':name' => $name,
					':description' => $description,
					':price' => number_format((float) $priceRaw, 2, '.', ''),
					':stock' => (int) $stockRaw,
				];
				if ($hasProductCategoryIdColumn) {
					$insertParams[':category_id'] = $categoryId;
				}
				$insertStmt->execute($insertParams);

				if (!empty($imagePaths)) {
					$imageInsert = $pdo->prepare('INSERT INTO ProductImages (ImageId, ProductId, ImageUrl, IsPrimary)
										 VALUES (UUID(), :product_id, :image_url, :is_primary)');
					foreach ($imagePaths as $index => $imagePath) {
						$imageInsert->execute([
							':product_id' => $productId,
							':image_url' => $imagePath,
							':is_primary' => $index === 0 ? 1 : 0,
						]);
					}
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
				$category = $supportsCategoryFeature ? trim((string) ($_POST['category'] ?? '')) : '';
				$categoryId = null;
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
				if ($supportsCategoryFeature && $category === '') {
					throw new RuntimeException('Category is required.');
				}
				if ($supportsCategoryFeature && !in_array($category, $categoryOptions, true)) {
					throw new RuntimeException('Please select a valid category.');
				}
				if ($hasProductCategoryIdColumn && $hasCategoryTable) {
					$categoryId = $categoryIdByName[$category] ?? null;
					if ($categoryId === null) {
						throw new RuntimeException('Selected category does not exist in category table.');
					}
				}

				$selectedImageCount = countSelectedUploadFiles($_FILES['product_images'] ?? []);
				if ($selectedImageCount > $maxFileUploads) {
					throw new RuntimeException('Too many images selected. You can upload up to ' . $maxFileUploads . ' images at once.');
				}

				$newImagePaths = uploadProductImages($_FILES['product_images'] ?? [], $productUploadDirAbs, $productUploadDirRel);

				if ($hasProductCategoryIdColumn) {
					$updateSql = 'UPDATE Products
								  SET ProductName = :name,
									  CategoryId = :category_id,
									  Description = :description,
									  Price = :price,
									  StockQuantity = :stock
								  WHERE ProductId = :product_id';
				} else {
					$updateSql = 'UPDATE Products
								  SET ProductName = :name,
									  Description = :description,
									  Price = :price,
									  StockQuantity = :stock
								  WHERE ProductId = :product_id';
				}
				$updateStmt = $pdo->prepare($updateSql);
				$updateParams = [
					':name' => $name,
					':description' => $description,
					':price' => number_format((float) $priceRaw, 2, '.', ''),
					':stock' => (int) $stockRaw,
					':product_id' => $productId,
				];
				if ($hasProductCategoryIdColumn) {
					$updateParams[':category_id'] = $categoryId;
				}
				$updateStmt->execute($updateParams);

				if ($updateStmt->rowCount() === 0) {
					$existsStmt = $pdo->prepare('SELECT COUNT(*) FROM Products WHERE ProductId = :product_id');
					$existsStmt->execute([':product_id' => $productId]);
					if ((int) $existsStmt->fetchColumn() === 0) {
						throw new RuntimeException('Product not found.');
					}
				}

				if (!empty($newImagePaths)) {
					$pdo->beginTransaction();

					$primaryCountStmt = $pdo->prepare('SELECT COUNT(*) FROM ProductImages WHERE ProductId = :product_id AND IsPrimary = 1');
					$primaryCountStmt->execute([':product_id' => $productId]);
					$hasPrimary = (int) $primaryCountStmt->fetchColumn() > 0;

					$insertImageStmt = $pdo->prepare('INSERT INTO ProductImages (ImageId, ProductId, ImageUrl, IsPrimary)
													VALUES (UUID(), :product_id, :image_url, :is_primary)');
					foreach ($newImagePaths as $index => $imagePath) {
						$insertImageStmt->execute([
							':product_id' => $productId,
							':image_url' => $imagePath,
							':is_primary' => (!$hasPrimary && $index === 0) ? 1 : 0,
						]);
					}

					$pdo->commit();
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
								' . ($supportsCategoryFeature
									? "COALESCE(NULLIF(TRIM(c.CategoryName), ''), 'Uncategorized') AS Category,"
									: "'' AS Category,") . '
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
							' . (($hasProductCategoryIdColumn && $hasCategoryTable) ? 'LEFT JOIN category c ON c.CategoryId = Products.CategoryId' : '') . '
							WHERE ProductId = :id
							LIMIT 1');
	$editStmt->execute([':id' => $editId]);
	$editProduct = $editStmt->fetch(PDO::FETCH_ASSOC) ?: null;

	if ($editProduct !== null) {
		$imagesStmt = $pdo->prepare('SELECT ImageId, ImageUrl, IsPrimary
									  FROM ProductImages
									  WHERE ProductId = :id
									  ORDER BY IsPrimary DESC, ImageId DESC');
		$imagesStmt->execute([':id' => $editId]);
		$editProduct['Images'] = $imagesStmt->fetchAll(PDO::FETCH_ASSOC);
	}

	if ($editProduct === null) {
		$statusMessage = 'Selected product was not found.';
		$statusType = 'error';
	}
}

try {
	$hasProductActiveColumn = hasProductColumn($pdo, 'IsActive');
	$availableCategories = $categoryOptions;
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
	if ($supportsCategoryFeature) {
		$allowedSortColumns['category'] = 'CategoryName';
	} else {
		$categoryFilter = 'all';
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

	$listSql = 'SELECT p.ProductId, p.ProductName,
					' . ($supportsCategoryFeature
						? "COALESCE(NULLIF(TRIM(c.CategoryName), ''), 'Uncategorized') AS CategoryName,"
						: "'Uncategorized' AS CategoryName,") . '
					p.Description, p.Price, p.StockQuantity, p.CreateDate,
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
				' . (($hasProductCategoryIdColumn && $hasCategoryTable) ? 'LEFT JOIN category c ON c.CategoryId = p.CategoryId' : '') . '
				WHERE 1=1';

	$listCountSql = 'SELECT COUNT(*) FROM Products p
					' . (($hasProductCategoryIdColumn && $hasCategoryTable) ? 'LEFT JOIN category c ON c.CategoryId = p.CategoryId' : '') . '
					WHERE 1=1';

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

	if ($supportsCategoryFeature && $categoryFilter !== 'all') {
		$listSql .= " AND COALESCE(NULLIF(TRIM(c.CategoryName), ''), 'Uncategorized') = :category_filter";
		$listCountSql .= " AND COALESCE(NULLIF(TRIM(c.CategoryName), ''), 'Uncategorized') = :category_filter";
		$params[':category_filter'] = $categoryFilter;
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

	$productImagesByProductId = [];
	if (!empty($products)) {
		$productIds = [];
		foreach ($products as $productRow) {
			$productIds[] = (string) ($productRow['ProductId'] ?? '');
		}
		$productIds = array_values(array_filter(array_unique($productIds)));

		if (!empty($productIds)) {
			$placeholders = implode(',', array_fill(0, count($productIds), '?'));
			$imageListStmt = $pdo->prepare('SELECT ProductId, ImageUrl, IsPrimary
											FROM ProductImages
											WHERE ProductId IN (' . $placeholders . ')
											ORDER BY ProductId, IsPrimary DESC, ImageId DESC');
			$imageListStmt->execute($productIds);
			while ($imageRow = $imageListStmt->fetch(PDO::FETCH_ASSOC)) {
				$productId = (string) ($imageRow['ProductId'] ?? '');
				if ($productId === '') {
					continue;
				}
				if (!isset($productImagesByProductId[$productId])) {
					$productImagesByProductId[$productId] = [];
				}
				$productImagesByProductId[$productId][] = [
					'ImageUrl' => (string) ($imageRow['ImageUrl'] ?? ''),
					'IsPrimary' => (int) ($imageRow['IsPrimary'] ?? 0),
				];
			}
		}
	}

	$queryBase = [
		'q' => $search,
		'category_filter' => $categoryFilter,
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
		if (($params['category_filter'] ?? 'all') === 'all') {
			unset($params['category_filter']);
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
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link href="https://fonts.googleapis.com/css2?family=Syne:wght@500;700;800&family=IBM+Plex+Sans:wght@400;500;600&display=swap" rel="stylesheet">
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
	<link rel="stylesheet" href="../asset/css/admin-base.css">
	<link rel="stylesheet" href="../asset/css/admin-layout-responsive.css">
    <link rel="stylesheet" href="../asset/css/admin-product-management.css">
	
</head>
<body>
<div class="shell-scroll">
<div class="shell">
	<aside class="sidebar">
		<span class="brand-tag">Admin Portal</span>
		<h2>Product Management</h2>
		<nav class="nav-links">
			<a href="admin_dashboard.php"><i class="bi bi-speedometer2"></i> Control Panel</a>
			<a href="product_management.php" class="active"><i class="bi bi-box"></i> Product Management</a>
			<a href="category_management.php"><i class="bi bi-tags"></i> Category Management</a>
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

				<?php if ($supportsCategoryFeature): ?>
				<div class="field">
					<label for="category">Category</label>
					<select id="category" name="category" required>
						<option value="" disabled <?php echo ((string) ($editProduct['Category'] ?? '')) === '' ? 'selected' : ''; ?>>Select category</option>
						<?php foreach ($categoryOptions as $categoryOption): ?>
							<option value="<?php echo htmlspecialchars((string) $categoryOption); ?>" <?php echo ((string) ($editProduct['Category'] ?? '')) === (string) $categoryOption ? 'selected' : ''; ?>><?php echo htmlspecialchars((string) $categoryOption); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<?php endif; ?>

				<div class="field full">
					<label for="description">Description</label>
					<textarea id="description" name="description" placeholder="Optional product details..."><?php echo htmlspecialchars((string) ($editProduct['Description'] ?? '')); ?></textarea>
				</div>

				<div class="field full">
					<label for="product_images">Product Images (JPG, PNG, WEBP, GIF up to 5MB each, max <?php echo $maxFileUploads; ?> files per upload)</label>
					<input id="product_images" type="file" name="product_images[]" accept="image/jpeg,image/png,image/webp,image/gif" multiple>
					<div class="upload-help">You can select files multiple times before submit. New selections are added to the current queue.</div>
					<div id="upload_selected_count" class="upload-help"></div>
					<div id="upload_list" class="upload-list"></div>
				</div>

				<?php if (!empty($editProduct['Images']) && is_array($editProduct['Images'])): ?>
					<div class="field full">
						<div class="current-image-wrap current-image-wrap-top">
							<div class="image-grid">
								<?php foreach ($editProduct['Images'] as $image): ?>
									<div class="image-chip">
										<img class="image-preview zoomable-image" src="../<?php echo htmlspecialchars((string) ($image['ImageUrl'] ?? '')); ?>" alt="Product image" data-zoomable="1">
										<span><?php echo !empty($image['IsPrimary']) ? 'Primary' : 'Gallery'; ?></span>
										<button
											class="btn btn-danger js-delete-image"
											type="button"
											data-image-id="<?php echo htmlspecialchars((string) ($image['ImageId'] ?? '')); ?>"
											data-product-id="<?php echo htmlspecialchars((string) $editProduct['ProductId']); ?>"
										>
											<i class="bi bi-trash"></i> Delete Image
										</button>
									</div>
								<?php endforeach; ?>
							</div>
							<div class="current-image-meta">Uploading new files will add to this gallery. Existing images stay unchanged.</div>
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
					<?php if ($supportsCategoryFeature): ?>
						<select name="category_filter">
							<option value="all" <?php echo $categoryFilter === 'all' ? 'selected' : ''; ?>>All Categories</option>
							<?php foreach ($availableCategories as $availableCategory): ?>
								<option value="<?php echo htmlspecialchars((string) $availableCategory); ?>" <?php echo $categoryFilter === (string) $availableCategory ? 'selected' : ''; ?>><?php echo htmlspecialchars((string) $availableCategory); ?></option>
							<?php endforeach; ?>
						</select>
					<?php endif; ?>
					<?php if ($hasProductActiveColumn): ?>
						<select name="status_filter">
							<option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>All Statuses</option>
							<option value="active" <?php echo $statusFilter === 'active' ? 'selected' : ''; ?>>Active</option>
							<option value="inactive" <?php echo $statusFilter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
						</select>
					<?php endif; ?>
					<input type="number" step="0.01" min="0" name="min_price" placeholder="Min RM" value="<?php echo htmlspecialchars($minPriceRaw); ?>">
					<input type="number" step="0.01" min="0" name="max_price" placeholder="Max RM" value="<?php echo htmlspecialchars($maxPriceRaw); ?>">
					<?php if ($hasProductActiveColumn): ?>
						<select name="sort_by">
							<option value="active" <?php echo $sortBy === 'active' ? 'selected' : ''; ?>>Status</option>
						</select>
					<?php endif; ?>
					<select name="sort_dir">
						<option value="desc" <?php echo $sortDir === 'desc' ? 'selected' : ''; ?>>Desc</option>
						<option value="asc" <?php echo $sortDir === 'asc' ? 'selected' : ''; ?>>Asc</option>
					</select>
					<button class="btn btn-primary" type="submit"><i class="bi bi-search"></i> Search</button>
					<?php if ($search !== '' || $categoryFilter !== 'all' || $stockFilter !== 'all' || $statusFilter !== 'all' || $minPriceRaw !== '' || $maxPriceRaw !== '' || $sortBy !== 'newest' || $sortDir !== 'desc'): ?>
						<a class="btn btn-outline" href="product_management.php"><i class="bi bi-x-circle"></i> Clear</a>
					<?php endif; ?>
				</form>
				<span class="muted"><?php echo $filteredTotal; ?> product(s)</span>
			</div>

			<?php if (empty($products)): ?>
				<div class="empty-state">
					<p>No products found for the current filter.</p>
				</div>
			<?php else: ?>
				<div class="table-wrap table-responsive">
					<table class="table table-hover align-middle mb-0">
						<thead>
							<tr>
								<th>Image</th>
								<th class="sortable"><a href="<?php echo htmlspecialchars($buildListUrl(['sort_by' => 'name', 'sort_dir' => ($sortBy === 'name' && $sortDir === 'asc') ? 'desc' : 'asc', 'page' => 1])); ?>">Name<?php if ($sortBy === 'name') echo $sortDir === 'asc' ? ' ▲' : ' ▼'; ?></a></th>
								<?php if ($supportsCategoryFeature): ?>
									<th class="sortable"><a href="<?php echo htmlspecialchars($buildListUrl(['sort_by' => 'category', 'sort_dir' => ($sortBy === 'category' && $sortDir === 'asc') ? 'desc' : 'asc', 'page' => 1])); ?>">Category<?php if ($sortBy === 'category') echo $sortDir === 'asc' ? ' ▲' : ' ▼'; ?></a></th>
								<?php else: ?>
									<th>Category</th>
								<?php endif; ?>
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

							$productId = (string) ($product['ProductId'] ?? '');
							$productImages = $productImagesByProductId[$productId] ?? [];
							$primaryImage = null;
							$secondaryImages = [];

							if (!empty($productImages)) {
								$primaryImage = $productImages[0];
								$secondaryImages = array_slice($productImages, 1);
							}

							$secondaryDisplay = array_slice($secondaryImages, 0, 3);
							$extraImageCount = max(0, count($secondaryImages) - count($secondaryDisplay));
							$firstHiddenImage = null;
							if ($extraImageCount > 0) {
								$firstHiddenImage = $secondaryImages[count($secondaryDisplay)] ?? null;
							}

							$gallerySources = [];
							foreach ($productImages as $galleryImage) {
								$galleryUrl = trim((string) ($galleryImage['ImageUrl'] ?? ''));
								if ($galleryUrl !== '') {
									$gallerySources[] = '../' . $galleryUrl;
								}
							}
							if (empty($gallerySources) && !empty($product['PrimaryImageUrl'])) {
								$gallerySources[] = '../' . (string) $product['PrimaryImageUrl'];
							}
							$galleryJson = htmlspecialchars(json_encode($gallerySources, JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8');

							$editUrl = $buildListUrl([
								'edit' => $productId,
								'page' => $currentPage,
							]);
							?>
							<tr>
								<td>
									<?php if ($primaryImage !== null): ?>
										<div class="product-image-card">
											<img
												class="product-image-primary zoomable-image"
												src="../<?php echo htmlspecialchars((string) ($primaryImage['ImageUrl'] ?? '')); ?>"
												alt="Primary product image"
												data-zoomable="1"
												data-gallery="<?php echo $galleryJson; ?>"
												data-gallery-index="0"
											>
											<?php if (!empty($secondaryDisplay) || $extraImageCount > 0): ?>
												<div class="product-image-secondary">
													<?php foreach ($secondaryDisplay as $secondaryIndex => $img): ?>
														<img class="zoomable-image" src="../<?php echo htmlspecialchars((string) ($img['ImageUrl'] ?? '')); ?>" alt="Product gallery image" data-zoomable="1" data-gallery="<?php echo $galleryJson; ?>" data-gallery-index="<?php echo 1 + $secondaryIndex; ?>">
													<?php endforeach; ?>
													<?php if ($extraImageCount > 0): ?>
														<button
															type="button"
															class="image-count-badge"
															data-zoom-src="../<?php echo htmlspecialchars((string) ($firstHiddenImage['ImageUrl'] ?? '')); ?>"
															data-zoom-alt="Hidden product image"
															data-gallery="<?php echo $galleryJson; ?>"
															data-gallery-index="<?php echo 1 + count($secondaryDisplay); ?>"
															title="View hidden image"
														>+<?php echo $extraImageCount; ?></button>
													<?php endif; ?>
												</div>
											<?php endif; ?>
										</div>
									<?php elseif (!empty($product['PrimaryImageUrl'])): ?>
										<div class="product-image-card">
											<img class="product-image-primary zoomable-image" src="../<?php echo htmlspecialchars((string) $product['PrimaryImageUrl']); ?>" alt="Primary product image" data-zoomable="1" data-gallery="<?php echo $galleryJson; ?>" data-gallery-index="0">
										</div>
									<?php else: ?>
										<span class="no-image">No image</span>
									<?php endif; ?>
								</td>
								<td><?php echo htmlspecialchars((string) $product['ProductName']); ?></td>
								<td><?php echo htmlspecialchars((string) ($product['CategoryName'] ?? 'Uncategorized')); ?></td>
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
</div>
<?php if ($editProduct): ?>
<form id="delete_image_form" class="hidden-delete-image-form" method="post" action="<?php echo htmlspecialchars($buildListUrl()); ?>">
	<input type="hidden" name="csrf" value="<?php echo htmlspecialchars($_SESSION['admin_csrf']); ?>">
	<input type="hidden" name="action" value="delete_product_image">
	<input type="hidden" id="delete_image_product_id" name="product_id" value="<?php echo htmlspecialchars((string) $editProduct['ProductId']); ?>">
	<input type="hidden" id="delete_image_image_id" name="image_id" value="">
</form>
<?php endif; ?>
<div id="product_image_lightbox" class="lightbox" aria-hidden="true">
	<div class="lightbox-content">
		<button id="product_image_lightbox_prev" class="lightbox-nav prev" type="button" aria-label="Previous image"><i class="bi bi-chevron-left"></i></button>
		<button id="product_image_lightbox_next" class="lightbox-nav next" type="button" aria-label="Next image"><i class="bi bi-chevron-right"></i></button>
		<button id="product_image_lightbox_close" class="lightbox-close" type="button" aria-label="Close image viewer">&times;</button>
		<img id="product_image_lightbox_img" class="lightbox-image" src="" alt="Product image preview">
		<div id="product_image_lightbox_counter" class="lightbox-counter"></div>
	</div>
</div>
<script>
(function () {
	const input = document.getElementById('product_images');
	const list = document.getElementById('upload_list');
	const countLabel = document.getElementById('upload_selected_count');
	if (!input || !list || !countLabel || typeof DataTransfer === 'undefined') {
		return;
	}

	const maxFiles = <?php echo (int) $maxFileUploads; ?>;
	const dt = new DataTransfer();

	function fileKey(file) {
		return [file.name, file.size, file.lastModified, file.type].join('|');
	}

	function formatSize(bytes) {
		if (!Number.isFinite(bytes) || bytes < 0) {
			return '0 B';
		}
		if (bytes < 1024) {
			return bytes + ' B';
		}
		const kb = bytes / 1024;
		if (kb < 1024) {
			return kb.toFixed(1) + ' KB';
		}
		return (kb / 1024).toFixed(2) + ' MB';
	}

	function syncInput() {
		input.files = dt.files;
	}

	function renderList() {
		list.innerHTML = '';
		const files = Array.from(dt.files);
		countLabel.textContent = files.length > 0
			? files.length + ' image(s) selected'
			: 'No images selected yet.';

		files.forEach(function (file, index) {
			const row = document.createElement('div');
			row.className = 'upload-list-item';

			const text = document.createElement('span');
			text.textContent = file.name + ' (' + formatSize(file.size) + ')';

			const removeBtn = document.createElement('button');
			removeBtn.type = 'button';
			removeBtn.textContent = 'Remove';
			removeBtn.addEventListener('click', function () {
				const remaining = Array.from(dt.files).filter(function (_, i) {
					return i !== index;
				});
				dt.items.clear();
				remaining.forEach(function (f) {
					dt.items.add(f);
				});
				syncInput();
				renderList();
			});

			row.appendChild(text);
			row.appendChild(removeBtn);
			list.appendChild(row);
		});
	}

	input.addEventListener('change', function () {
		const existing = new Set(Array.from(dt.files).map(fileKey));
		const picked = Array.from(input.files || []);

		picked.forEach(function (file) {
			if (dt.files.length >= maxFiles) {
				return;
			}
			const key = fileKey(file);
			if (!existing.has(key)) {
				dt.items.add(file);
				existing.add(key);
			}
		});

		syncInput();
		renderList();
	});

	renderList();
})();

(function () {
	const lightbox = document.getElementById('product_image_lightbox');
	const lightboxImg = document.getElementById('product_image_lightbox_img');
	const closeBtn = document.getElementById('product_image_lightbox_close');
	const prevBtn = document.getElementById('product_image_lightbox_prev');
	const nextBtn = document.getElementById('product_image_lightbox_next');
	const counter = document.getElementById('product_image_lightbox_counter');
	const zoomableImages = document.querySelectorAll('img[data-zoomable="1"]');
	const zoomButtons = document.querySelectorAll('[data-zoom-src]');

	if (!lightbox || !lightboxImg || !closeBtn || !prevBtn || !nextBtn || !counter || (zoomableImages.length === 0 && zoomButtons.length === 0)) {
		return;
	}

	let currentGallery = [];
	let currentIndex = 0;

	function parseGallery(raw) {
		if (!raw) {
			return [];
		}
		try {
			const parsed = JSON.parse(raw);
			if (!Array.isArray(parsed)) {
				return [];
			}
			return parsed.filter(function (item) {
				return typeof item === 'string' && item.trim() !== '';
			});
		} catch (error) {
			return [];
		}
	}

	function syncLightboxView(alt) {
		if (!currentGallery[currentIndex]) {
			return;
		}
		lightboxImg.src = currentGallery[currentIndex];
		lightboxImg.alt = alt || 'Product image preview';
		counter.textContent = currentGallery.length > 1
			? 'Image ' + (currentIndex + 1) + ' of ' + currentGallery.length
			: 'Image 1 of 1';
		prevBtn.disabled = currentGallery.length <= 1 || currentIndex === 0;
		nextBtn.disabled = currentGallery.length <= 1 || currentIndex >= currentGallery.length - 1;
	}

	function openLightbox(src, alt, gallery, index) {
		const normalizedGallery = Array.isArray(gallery)
			? gallery.filter(function (item) {
				return typeof item === 'string' && item.trim() !== '';
			})
			: [];

		if (normalizedGallery.length === 0 && src) {
			currentGallery = [src];
			currentIndex = 0;
		} else if (normalizedGallery.length > 0) {
			currentGallery = normalizedGallery;
			const requestedIndex = Number.isFinite(index) ? index : 0;
			currentIndex = Math.max(0, Math.min(requestedIndex, currentGallery.length - 1));
		} else {
			return;
		}

		syncLightboxView(alt);
		lightbox.classList.add('open');
		lightbox.setAttribute('aria-hidden', 'false');
		document.body.style.overflow = 'hidden';
	}

	function closeLightbox() {
		lightbox.classList.remove('open');
		lightbox.setAttribute('aria-hidden', 'true');
		lightboxImg.src = '';
		currentGallery = [];
		currentIndex = 0;
		document.body.style.overflow = '';
	}

	function moveLightbox(step) {
		if (currentGallery.length <= 1) {
			return;
		}
		const nextIndex = currentIndex + step;
		if (nextIndex < 0 || nextIndex >= currentGallery.length) {
			return;
		}
		currentIndex = nextIndex;
		syncLightboxView(lightboxImg.alt || 'Product image preview');
	}

	zoomableImages.forEach(function (img) {
		img.addEventListener('click', function () {
			const gallery = parseGallery(img.getAttribute('data-gallery') || '');
			const index = parseInt(img.getAttribute('data-gallery-index') || '0', 10);
			openLightbox(img.getAttribute('src') || '', img.getAttribute('alt') || 'Product image', gallery, Number.isNaN(index) ? 0 : index);
		});
	});

	zoomButtons.forEach(function (button) {
		button.addEventListener('click', function () {
			const gallery = parseGallery(button.getAttribute('data-gallery') || '');
			const index = parseInt(button.getAttribute('data-gallery-index') || '0', 10);
			openLightbox(button.getAttribute('data-zoom-src') || '', button.getAttribute('data-zoom-alt') || 'Product image', gallery, Number.isNaN(index) ? 0 : index);
		});
	});

	prevBtn.addEventListener('click', function () {
		moveLightbox(-1);
	});

	nextBtn.addEventListener('click', function () {
		moveLightbox(1);
	});

	closeBtn.addEventListener('click', closeLightbox);
	lightbox.addEventListener('click', function (event) {
		if (event.target === lightbox) {
			closeLightbox();
		}
	});

	document.addEventListener('keydown', function (event) {
		if (event.key === 'Escape' && lightbox.classList.contains('open')) {
			closeLightbox();
			return;
		}

		if (!lightbox.classList.contains('open')) {
			return;
		}

		if (event.key === 'ArrowLeft') {
			moveLightbox(-1);
		}

		if (event.key === 'ArrowRight') {
			moveLightbox(1);
		}
	});
})();

(function () {
	const deleteForm = document.getElementById('delete_image_form');
	const imageIdInput = document.getElementById('delete_image_image_id');
	const productIdInput = document.getElementById('delete_image_product_id');
	const deleteButtons = document.querySelectorAll('.js-delete-image');

	if (!deleteForm || !imageIdInput || !productIdInput || deleteButtons.length === 0) {
		return;
	}

	deleteButtons.forEach(function (button) {
		button.addEventListener('click', function () {
			const imageId = button.getAttribute('data-image-id') || '';
			const productId = button.getAttribute('data-product-id') || '';
			if (!imageId || !productId) {
				return;
			}

			if (!window.confirm('Delete this image?')) {
				return;
			}

			imageIdInput.value = imageId;
			productIdInput.value = productId;
			deleteForm.submit();
		});
	});
})();

(function () {
	const textInputs = document.querySelectorAll('input:not([type="hidden"]):not([type="checkbox"]):not([type="radio"]):not([type="file"]), textarea');
	const fileInputs = document.querySelectorAll('input[type="file"]');
	const selects = document.querySelectorAll('select');

	textInputs.forEach(function (el) {
		el.classList.add('form-control');
	});

	fileInputs.forEach(function (el) {
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