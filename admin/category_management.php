<?php
include_once '../config/config.php';
include_once '../config/auth.php';

if (empty($_SESSION['admin_csrf'])) {
	$_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
}

function ensureCategoryTable(PDO $pdo): void
{
	$pdo->exec(
		"CREATE TABLE IF NOT EXISTS category (
			CategoryId CHAR(36) NOT NULL,
			CategoryName VARCHAR(100) NOT NULL,
			CreateDate DATETIME DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (CategoryId),
			UNIQUE KEY uq_category_name (CategoryName)
		)"
	);
}

function hasProductsCategoryIdColumn(PDO $pdo): bool
{
	$stmt = $pdo->prepare(
		"SELECT 1
		 FROM INFORMATION_SCHEMA.COLUMNS
		 WHERE TABLE_SCHEMA = DATABASE()
		   AND TABLE_NAME = 'Products'
		   AND COLUMN_NAME = 'CategoryId'
		 LIMIT 1"
	);
	$stmt->execute();

	return (bool) $stmt->fetchColumn();
}

function hasCategoryIconColumn(PDO $pdo): bool
{
	$stmt = $pdo->prepare(
		"SELECT 1
		 FROM INFORMATION_SCHEMA.COLUMNS
		 WHERE TABLE_SCHEMA = DATABASE()
		   AND TABLE_NAME = 'category'
		   AND COLUMN_NAME = 'CategoryIcon'
		 LIMIT 1"
	);
	$stmt->execute();

	return (bool) $stmt->fetchColumn();
}

function uploadCategoryIcon(array $file, string $uploadDirAbs, string $uploadDirRel): string
{
	if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
		return '';
	}

	if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
		throw new RuntimeException('Category icon upload failed. Please try again.');
	}

	$maxBytes = 2 * 1024 * 1024;
	if (($file['size'] ?? 0) > $maxBytes) {
		throw new RuntimeException('Category icon is too large. Maximum allowed size is 2MB.');
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
		'image/svg+xml' => 'svg',
	];

	if (!isset($allowedMime[$mime])) {
		throw new RuntimeException('Only JPG, PNG, WEBP, GIF, or SVG icons are allowed.');
	}

	if (!is_dir($uploadDirAbs) && !mkdir($uploadDirAbs, 0775, true) && !is_dir($uploadDirAbs)) {
		throw new RuntimeException('Unable to create category icon directory.');
	}

	$filename = 'cat_' . bin2hex(random_bytes(8)) . '.' . $allowedMime[$mime];
	$relativePath = $uploadDirRel . '/' . $filename;
	if (mb_strlen($relativePath) > 60) {
		throw new RuntimeException('Category icon filename is too long for database column.');
	}

	$targetAbs = $uploadDirAbs . DIRECTORY_SEPARATOR . $filename;
	if (!move_uploaded_file($file['tmp_name'], $targetAbs)) {
		throw new RuntimeException('Unable to save uploaded category icon.');
	}

	return $relativePath;
}

function deleteCategoryIconFile(string $relativePath, string $projectRoot, string $uploadDirRel): void
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

ensureCategoryTable($pdo);
$hasCategoryIdInProducts = hasProductsCategoryIdColumn($pdo);
$hasCategoryIconInCategory = hasCategoryIconColumn($pdo);

$projectRoot = dirname(__DIR__);
$categoryIconDirRel = 'uploads/category_icons';
$categoryIconDirAbs = $projectRoot . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'category_icons';

$statusMessage = '';
$statusType = '';

if (isset($_GET['status'], $_GET['message'])) {
	$statusType = $_GET['status'] === 'ok' ? 'success' : 'danger';
	$statusMessage = trim((string) $_GET['message']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$action = trim((string) ($_POST['action'] ?? ''));
	$csrf = trim((string) ($_POST['csrf'] ?? ''));

	if (!hash_equals($_SESSION['admin_csrf'], $csrf)) {
		$statusType = 'danger';
		$statusMessage = 'Invalid security token. Please refresh and try again.';
	} else {
		try {
			if ($action === 'add_category') {
				$categoryName = trim((string) ($_POST['category_name'] ?? ''));
				$categoryIconPath = '';
				if ($categoryName === '') {
					throw new RuntimeException('Category name is required.');
				}
				if (mb_strlen($categoryName) > 100) {
					throw new RuntimeException('Category name must be 100 characters or less.');
				}
				if ($hasCategoryIconInCategory) {
					$categoryIconPath = uploadCategoryIcon($_FILES['category_icon'] ?? [], $categoryIconDirAbs, $categoryIconDirRel);
				}

				if ($hasCategoryIconInCategory) {
					$insertStmt = $pdo->prepare(
						'INSERT INTO category (CategoryId, CategoryName, CategoryIcon, CreateDate) VALUES (UUID(), :name, :icon, NOW())'
					);
					$insertStmt->execute([
						':name' => $categoryName,
						':icon' => $categoryIconPath !== '' ? $categoryIconPath : null,
					]);
				} else {
					$insertStmt = $pdo->prepare(
						'INSERT INTO category (CategoryId, CategoryName, CreateDate) VALUES (UUID(), :name, NOW())'
					);
					$insertStmt->execute([':name' => $categoryName]);
				}

				header('Location: category_management.php?status=ok&message=' . urlencode('Category added successfully.'));
				exit;
			}

			if ($action === 'update_category') {
				$categoryId = trim((string) ($_POST['category_id'] ?? ''));
				$categoryName = trim((string) ($_POST['category_name'] ?? ''));
				$removeIcon = isset($_POST['remove_icon']) && $_POST['remove_icon'] === '1';
				$newIconPath = '';
				$oldIconPath = '';

				if ($categoryId === '' || $categoryName === '') {
					throw new RuntimeException('Category ID and name are required.');
				}
				if (mb_strlen($categoryName) > 100) {
					throw new RuntimeException('Category name must be 100 characters or less.');
				}

				if ($hasCategoryIconInCategory) {
					$iconStmt = $pdo->prepare('SELECT CategoryIcon FROM category WHERE CategoryId = :id LIMIT 1');
					$iconStmt->execute([':id' => $categoryId]);
					$oldIconPath = (string) ($iconStmt->fetchColumn() ?: '');
					$newIconPath = uploadCategoryIcon($_FILES['category_icon'] ?? [], $categoryIconDirAbs, $categoryIconDirRel);

					if ($newIconPath !== '') {
						$updateStmt = $pdo->prepare('UPDATE category SET CategoryName = :name, CategoryIcon = :icon WHERE CategoryId = :id');
						$updateStmt->execute([
							':id' => $categoryId,
							':name' => $categoryName,
							':icon' => $newIconPath,
						]);
					} elseif ($removeIcon) {
						$updateStmt = $pdo->prepare('UPDATE category SET CategoryName = :name, CategoryIcon = NULL WHERE CategoryId = :id');
						$updateStmt->execute([
							':id' => $categoryId,
							':name' => $categoryName,
						]);
					} else {
						$updateStmt = $pdo->prepare('UPDATE category SET CategoryName = :name WHERE CategoryId = :id');
						$updateStmt->execute([
							':id' => $categoryId,
							':name' => $categoryName,
						]);
					}
				} else {
					$updateStmt = $pdo->prepare('UPDATE category SET CategoryName = :name WHERE CategoryId = :id');
					$updateStmt->execute([
						':id' => $categoryId,
						':name' => $categoryName,
					]);
				}

				if ($updateStmt->rowCount() === 0) {
					$existsStmt = $pdo->prepare('SELECT 1 FROM category WHERE CategoryId = :id LIMIT 1');
					$existsStmt->execute([':id' => $categoryId]);
					if (!$existsStmt->fetchColumn()) {
						throw new RuntimeException('Category not found.');
					}
				}

				if ($hasCategoryIconInCategory) {
					if ($newIconPath !== '' && $oldIconPath !== '') {
						deleteCategoryIconFile($oldIconPath, $projectRoot, $categoryIconDirRel);
					}
					if ($removeIcon && $newIconPath === '' && $oldIconPath !== '') {
						deleteCategoryIconFile($oldIconPath, $projectRoot, $categoryIconDirRel);
					}
				}

				header('Location: category_management.php?status=ok&message=' . urlencode('Category updated successfully.'));
				exit;
			}

			if ($action === 'delete_category') {
				$categoryId = trim((string) ($_POST['category_id'] ?? ''));
				$iconPath = '';
				if ($categoryId === '') {
					throw new RuntimeException('Category ID is required.');
				}

				if ($hasCategoryIdInProducts) {
					$inUseStmt = $pdo->prepare('SELECT COUNT(*) FROM Products WHERE CategoryId = :id');
					$inUseStmt->execute([':id' => $categoryId]);
					$inUseCount = (int) $inUseStmt->fetchColumn();

					if ($inUseCount > 0) {
						throw new RuntimeException('Cannot delete category because it is linked to products.');
					}
				}

				if ($hasCategoryIconInCategory) {
					$iconStmt = $pdo->prepare('SELECT CategoryIcon FROM category WHERE CategoryId = :id LIMIT 1');
					$iconStmt->execute([':id' => $categoryId]);
					$iconPath = (string) ($iconStmt->fetchColumn() ?: '');
				}

				$deleteStmt = $pdo->prepare('DELETE FROM category WHERE CategoryId = :id');
				$deleteStmt->execute([':id' => $categoryId]);

				if ($deleteStmt->rowCount() === 0) {
					throw new RuntimeException('Category not found or already deleted.');
				}

				if ($iconPath !== '') {
					deleteCategoryIconFile($iconPath, $projectRoot, $categoryIconDirRel);
				}

				header('Location: category_management.php?status=ok&message=' . urlencode('Category deleted successfully.'));
				exit;
			}
		} catch (PDOException $e) {
			if ((int) $e->getCode() === 23000) {
				$statusType = 'danger';
				$statusMessage = 'Category name already exists.';
			} else {
				$statusType = 'danger';
				$statusMessage = 'Database error: ' . $e->getMessage();
			}
		} catch (Throwable $e) {
			$statusType = 'danger';
			$statusMessage = $e->getMessage();
		}
	}
}

$search = trim((string) ($_GET['q'] ?? ''));
$editId = trim((string) ($_GET['edit'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 12;

$whereSql = '';
$params = [];
if ($search !== '') {
	$whereSql = ' WHERE c.CategoryName LIKE :keyword ';
	$params[':keyword'] = '%' . $search . '%';
}

$countStmt = $pdo->prepare('SELECT COUNT(*) FROM category c ' . $whereSql);
$countStmt->execute($params);
$totalRows = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalRows / $perPage));
if ($page > $totalPages) {
	$page = $totalPages;
}
$offset = ($page - 1) * $perPage;

$listSql = 'SELECT c.CategoryId, c.CategoryName, c.CreateDate';
if ($hasCategoryIconInCategory) {
	$listSql .= ', c.CategoryIcon';
}
if ($hasCategoryIdInProducts) {
	$listSql .= ', COALESCE(pcnt.ProductCount, 0) AS ProductCount';
}
$listSql .= ' FROM category c ';
if ($hasCategoryIdInProducts) {
	$listSql .= ' LEFT JOIN (
		SELECT CategoryId, COUNT(*) AS ProductCount
		FROM Products
		WHERE CategoryId IS NOT NULL
		GROUP BY CategoryId
	) pcnt ON pcnt.CategoryId = c.CategoryId ';
}
$listSql .= $whereSql . ' ORDER BY c.CategoryName ASC LIMIT :limit OFFSET :offset';

$listStmt = $pdo->prepare($listSql);
foreach ($params as $key => $value) {
	$listStmt->bindValue($key, $value, PDO::PARAM_STR);
}
$listStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$listStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$listStmt->execute();
$categories = $listStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$editCategory = null;
if ($editId !== '') {
	$editSql = 'SELECT CategoryId, CategoryName';
	if ($hasCategoryIconInCategory) {
		$editSql .= ', CategoryIcon';
	}
	$editSql .= ' FROM category WHERE CategoryId = :id LIMIT 1';
	$editStmt = $pdo->prepare($editSql);
	$editStmt->execute([':id' => $editId]);
	$editCategory = $editStmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

$buildUrl = function (array $overrides = []) use ($search, $editId, $page): string {
	$query = [
		'q' => $search,
		'edit' => $editId,
		'page' => $page,
	];

	foreach ($overrides as $key => $value) {
		if ($value === null || $value === '') {
			unset($query[$key]);
			continue;
		}
		$query[$key] = $value;
	}

	if (($query['q'] ?? '') === '') {
		unset($query['q']);
	}
	if (($query['edit'] ?? '') === '') {
		unset($query['edit']);
	}
	if (($query['page'] ?? 1) <= 1) {
		unset($query['page']);
	}

	return 'category_management.php' . (!empty($query) ? '?' . http_build_query($query) : '');
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Category Management || E-Commerce</title>
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link href="https://fonts.googleapis.com/css2?family=Syne:wght@500;700;800&family=IBM+Plex+Sans:wght@400;500;600&display=swap" rel="stylesheet">
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
	<link rel="stylesheet" href="../asset/css/admin-base.css">
	<link rel="stylesheet" href="../asset/css/admin-layout-responsive.css">
	<style>
		:root {
			--bg: #f6f2e8;
			--ink: #1f1a15;
			--paper: rgba(255, 255, 255, 0.75);
			--accent: #da5a1b;
			--accent-strong: #b74009;
			--line: rgba(31, 26, 21, 0.16);
			--card: rgba(255, 255, 255, 0.82);
			--ok: #1f7a46;
			--error: #a31515;
		}

		* { box-sizing: border-box; }

		body {
			margin: 0;
			min-height: 100vh;
			font-family: 'IBM Plex Sans', sans-serif;
			color: var(--ink);
			background:
				radial-gradient(circle at 15% 20%, rgba(218, 90, 27, 0.2), transparent 42%),
				radial-gradient(circle at 85% 82%, rgba(184, 64, 9, 0.22), transparent 35%),
				linear-gradient(145deg, #f7f0df 0%, #f4ede4 48%, #efe5d2 100%);
		}

		.shell {
			width: 100%;
			min-width: 1120px;
			display: grid;
			grid-template-columns: 280px minmax(0, 1fr);
			background: var(--paper);
			backdrop-filter: blur(8px);
		}

		.shell-scroll {
			width: 100%;
			overflow-x: auto;
			overflow-y: visible;
		}

		.sidebar {
			min-height: 100vh;
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
			line-height: 1.1;
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

		.badge-admin {
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

		.notice.error,
		.notice.danger {
			border: 1px solid rgba(163, 21, 21, 0.35);
			background: rgba(163, 21, 21, 0.1);
			color: var(--error);
		}

		.panel {
			background: rgba(255, 255, 255, 0.7);
			border: 1px solid var(--line);
			border-radius: 16px;
			overflow: hidden;
			margin-bottom: 16px;
		}

		.panel .panel-head {
			padding: 14px;
			border-bottom: 1px solid var(--line);
		}

		.panel .panel-body {
			padding: 14px;
		}

		.form-label {
			font-size: 13px;
			font-weight: 600;
		}

		.form-control,
		.form-select,
		input[type="file"] {
			border: 1px solid rgba(31, 26, 21, 0.22);
			border-radius: 12px;
			padding: 10px 12px;
			font-size: 14px;
			font-family: inherit;
			background: rgba(255, 255, 255, 0.9);
			outline: none;
		}

		.form-control:focus,
		.form-select:focus,
		input[type="file"]:focus {
			border-color: rgba(218, 90, 27, 0.7);
			box-shadow: 0 0 0 4px rgba(218, 90, 27, 0.15);
		}

		.btn {
			border-radius: 12px;
			padding: 10px 14px;
			font-size: 14px;
			font-weight: 700;
			letter-spacing: 0.01em;
		}

		.btn-dark {
			border: none;
			color: #fff;
			background: linear-gradient(135deg, var(--accent), var(--accent-strong));
		}

		.btn-outline-dark,
		.btn-outline-secondary,
		.btn-outline-primary,
		.btn-outline-danger {
			color: var(--ink);
			border: 1px solid var(--line);
			background: rgba(255, 255, 255, 0.85);
		}

		.btn-outline-primary:hover,
		.btn-outline-secondary:hover,
		.btn-outline-dark:hover,
		.btn-outline-danger:hover {
			border-color: rgba(218, 90, 27, 0.55);
			color: var(--accent);
			background: rgba(255, 255, 255, 0.92);
		}

		.table {
			margin-bottom: 0;
		}

		table {
			width: 100%;
			border-collapse: collapse;
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
			vertical-align: middle;
		}

		tbody tr:hover {
			background: rgba(255, 255, 255, 0.55);
		}

		.table > :not(caption) > * > * {
			background: transparent;
			border-bottom-color: rgba(31, 26, 21, 0.1);
		}

		@media (max-width: 1199px) {
			.shell {
				min-width: 0;
				grid-template-columns: 1fr;
			}

			.shell-scroll {
				overflow-x: auto;
				-webkit-overflow-scrolling: touch;
			}

			.sidebar {
				min-height: auto;
				border-right: none;
				border-bottom: 1px solid var(--line);
				padding: 22px 18px;
			}

			.nav-links {
				grid-template-columns: repeat(2, minmax(0, 1fr));
				gap: 10px;
			}

			.main {
				padding: 22px;
			}
		}

		@media (max-width: 991px) {
			.main {
				padding: 16px;
			}

			.topbar {
				flex-wrap: wrap;
				align-items: flex-start;
				gap: 10px;
			}

			.badge-admin {
				width: 100%;
				text-align: center;
			}

			.nav-links {
				grid-template-columns: 1fr;
			}

			.table-wrap,
			.table-responsive {
				overflow-x: auto;
				-webkit-overflow-scrolling: touch;
				max-width: 100%;
			}

			.table-responsive table {
				min-width: 760px;
			}
		}

		@media (max-width: 767px) {
			.main {
				padding: 12px;
			}

			.panel .panel-head,
			.panel .panel-body {
				padding: 12px;
			}

			.topbar h1 {
				font-size: clamp(1.35rem, 6vw, 1.8rem);
			}

			.d-flex.gap-2 {
				flex-wrap: wrap;
			}

			.d-flex.gap-2 .btn,
			.d-flex.gap-2 form {
				width: 100%;
			}

			.d-flex.gap-2 form .btn {
				width: 100%;
			}

			tbody td {
				padding: 10px 12px;
			}
		}

		@media (max-width: 575px) {
			.btn {
				width: 100%;
			}

			.col-md-3.d-grid.gap-2 {
				grid-template-columns: 1fr;
			}

			.table-responsive table {
				min-width: 640px;
			}

			thead th[style],
			tbody td[style] {
				width: auto !important;
			}
		}

	</style>
</head>
<body>
<div class="shell-scroll">
	<div class="shell">
		<aside class="sidebar">
			<span class="brand-tag">Admin Portal</span>
			<h2>Category Management</h2>
			<nav class="nav-links">
				<a href="admin_dashboard.php"><i class="bi bi-speedometer2"></i> Control Panel</a>
				<a href="product_management.php"><i class="bi bi-box"></i> Product Management</a>
				<a class="active" href="category_management.php"><i class="bi bi-tags"></i> Category Management</a>
				<a href="member_management.php"><i class="bi bi-people"></i> Member Management</a>
				<a href="order_management.php"><i class="bi bi-cart"></i> Order Management</a>
				<a href="admin_logout.php"><i class="bi bi-gear"></i> Logout</a>
			</nav>
		</aside>

		<main class="main">
			<div class="topbar">
				<h1>Categories</h1>
				<span class="badge-admin">Admin</span>
			</div>

			<?php if ($statusMessage !== ''): ?>
				<div class="notice <?php echo htmlspecialchars($statusType === 'success' ? 'success' : 'error'); ?>" role="alert">
					<?php echo htmlspecialchars($statusMessage); ?>
				</div>
			<?php endif; ?>

			<div class="row g-3 mb-3">
				<div class="col-lg-5">
					<div class="panel h-100">
						<div class="panel-head">
							<h5 class="mb-0"><?php echo $editCategory ? 'Edit Category' : 'Add Category'; ?></h5>
							<small class="text-muted">Manage names and icons used by products and filters.</small>
						</div>
						<div class="panel-body">
							<form method="post" enctype="multipart/form-data" action="category_management.php<?php echo $editCategory ? '?edit=' . urlencode((string) $editCategory['CategoryId']) : ''; ?>">
								<input type="hidden" name="csrf" value="<?php echo htmlspecialchars($_SESSION['admin_csrf']); ?>">
								<input type="hidden" name="action" value="<?php echo $editCategory ? 'update_category' : 'add_category'; ?>">
								<?php if ($editCategory): ?>
									<input type="hidden" name="category_id" value="<?php echo htmlspecialchars((string) $editCategory['CategoryId']); ?>">
								<?php endif; ?>

								<div class="mb-3">
									<label class="form-label">Category Name</label>
									<input type="text" name="category_name" class="form-control" maxlength="100" required
										   value="<?php echo htmlspecialchars((string) ($editCategory['CategoryName'] ?? '')); ?>"
										   placeholder="Example: Electronics">
								</div>

								<?php if ($hasCategoryIconInCategory): ?>
									<div class="mb-3">
										<label class="form-label">Category Icon</label>
										<input type="file" name="category_icon" class="form-control" accept="image/jpeg,image/png,image/webp,image/gif,image/svg+xml">
										<div class="form-text">Optional. JPG, PNG, WEBP, GIF, SVG. Max 2MB.</div>
									</div>

									<?php if (!empty($editCategory['CategoryIcon'])): ?>
										<div class="mb-3 d-flex align-items-center gap-2">
											<img src="../<?php echo htmlspecialchars((string) $editCategory['CategoryIcon']); ?>" alt="Category icon" style="width:40px;height:40px;object-fit:cover;border-radius:8px;border:1px solid rgba(31,26,21,0.12);">
											<div class="form-check m-0">
												<input class="form-check-input" type="checkbox" name="remove_icon" id="remove_icon" value="1">
												<label class="form-check-label" for="remove_icon">Remove current icon</label>
											</div>
										</div>
									<?php endif; ?>
								<?php endif; ?>

								<div class="d-flex gap-2">
									<button type="submit" class="btn btn-dark">
										<?php echo $editCategory ? 'Update Category' : 'Add Category'; ?>
									</button>
									<?php if ($editCategory): ?>
										<a class="btn btn-outline-secondary" href="<?php echo htmlspecialchars($buildUrl(['edit' => null])); ?>">Cancel Edit</a>
									<?php endif; ?>
								</div>
							</form>
						</div>
					</div>
				</div>

				<div class="col-lg-7">
					<div class="panel h-100">
						<div class="panel-head">
							<h5 class="mb-0">Search Categories</h5>
						</div>
						<div class="panel-body">
							<form method="get" class="row g-2 align-items-end">
								<div class="col-md-9">
									<label class="form-label">Keyword</label>
									<input type="text" class="form-control" name="q" value="<?php echo htmlspecialchars($search); ?>" placeholder="Type category name">
								</div>
								<div class="col-md-3 d-grid gap-2">
									<button type="submit" class="btn btn-outline-dark">Search</button>
									<a href="category_management.php" class="btn btn-outline-secondary">Reset</a>
								</div>
							</form>
						</div>
					</div>
				</div>
			</div>

			<div class="panel">
				<div class="panel-head d-flex justify-content-between align-items-center">
					<h5 class="mb-0">Category List</h5>
					<small class="text-muted"><?php echo (int) $totalRows; ?> total</small>
				</div>
				<div class="panel-body">
					<div class="table-responsive">
						<table class="table align-middle mb-0">
							<thead>
								<tr>
									<?php if ($hasCategoryIconInCategory): ?>
										<th style="width: 90px;">Icon</th>
									<?php endif; ?>
									<th>Name</th>
									<?php if ($hasCategoryIdInProducts): ?>
										<th style="width: 120px;">Products</th>
									<?php endif; ?>
									<th style="width: 190px;">Created</th>
									<th style="width: 220px;">Actions</th>
								</tr>
							</thead>
							<tbody>
								<?php if (empty($categories)): ?>
									<tr>
										<td colspan="<?php echo ($hasCategoryIconInCategory ? 1 : 0) + ($hasCategoryIdInProducts ? 1 : 0) + 3; ?>" class="text-center text-muted py-4">No categories found.</td>
									</tr>
								<?php else: ?>
									<?php foreach ($categories as $category): ?>
										<tr>
											<?php if ($hasCategoryIconInCategory): ?>
												<td>
													<?php if (!empty($category['CategoryIcon'])): ?>
														<img src="../<?php echo htmlspecialchars((string) $category['CategoryIcon']); ?>" alt="Category icon" style="width:34px;height:34px;object-fit:cover;border-radius:8px;border:1px solid rgba(31,26,21,0.12);">
													<?php else: ?>
														<span class="text-muted small">-</span>
													<?php endif; ?>
												</td>
											<?php endif; ?>
											<td><?php echo htmlspecialchars((string) $category['CategoryName']); ?></td>
											<?php if ($hasCategoryIdInProducts): ?>
												<td><?php echo (int) ($category['ProductCount'] ?? 0); ?></td>
											<?php endif; ?>
											<td><?php echo htmlspecialchars((string) ($category['CreateDate'] ?? '')); ?></td>
											<td>
												<div class="d-flex gap-2">
													<a class="btn btn-sm btn-outline-primary" href="<?php echo htmlspecialchars($buildUrl(['edit' => (string) $category['CategoryId']])); ?>">Edit</a>
													<form method="post" onsubmit="return confirm('Delete this category?');">
														<input type="hidden" name="csrf" value="<?php echo htmlspecialchars($_SESSION['admin_csrf']); ?>">
														<input type="hidden" name="action" value="delete_category">
														<input type="hidden" name="category_id" value="<?php echo htmlspecialchars((string) $category['CategoryId']); ?>">
														<button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
													</form>
												</div>
											</td>
										</tr>
									<?php endforeach; ?>
								<?php endif; ?>
							</tbody>
						</table>
					</div>

					<?php if ($totalPages > 1): ?>
						<nav class="mt-3">
							<ul class="pagination mb-0">
								<?php for ($p = 1; $p <= $totalPages; $p++): ?>
									<li class="page-item <?php echo $p === $page ? 'active' : ''; ?>">
										<a class="page-link" href="<?php echo htmlspecialchars($buildUrl(['page' => $p])); ?>"><?php echo $p; ?></a>
									</li>
								<?php endfor; ?>
							</ul>
						</nav>
					<?php endif; ?>
				</div>
			</div>
		</main>
	</div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
