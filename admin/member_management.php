<?php
include_once '../config/config.php';
include_once '../config/auth.php';

if (empty($_SESSION['admin_csrf'])) {
	$_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
}

$statusMessage = '';
$statusType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$action = $_POST['action'] ?? '';
	$userId = trim($_POST['user_id'] ?? '');
	$csrf = $_POST['csrf'] ?? '';

	if (!hash_equals($_SESSION['admin_csrf'], $csrf)) {
		$statusMessage = 'Invalid security token. Please refresh and try again.';
		$statusType = 'error';
	} elseif ($action === 'toggle_member_status' && $userId !== '') {
		try {
			$checkSql = "SELECT u.UserId, u.IsActive
						 FROM Users u
						 JOIN Roles r ON u.RoleId = r.RoleId
						 WHERE u.UserId = :user_id AND r.RoleName = 'Member'
						 LIMIT 1";
			$checkStmt = $pdo->prepare($checkSql);
			$checkStmt->execute([':user_id' => $userId]);
			$member = $checkStmt->fetch(PDO::FETCH_ASSOC);

			if (!$member) {
				$statusMessage = 'Member not found.';
				$statusType = 'error';
			} else {
				$nextStatus = ((int) $member['IsActive'] === 1) ? 0 : 1;
				$updateSql = "UPDATE Users SET IsActive = :next_status WHERE UserId = :user_id";
				$updateStmt = $pdo->prepare($updateSql);
				$updateStmt->execute([
					':next_status' => $nextStatus,
					':user_id' => $userId,
				]);

				$query = http_build_query([
					'status' => 'ok',
					'message' => $nextStatus === 1 ? 'Member activated successfully.' : 'Member deactivated successfully.',
				]);
				header('Location: member_management.php?' . $query);
				exit;
			}
		} catch (Exception $e) {
			$statusMessage = 'Failed to update member status: ' . $e->getMessage();
			$statusType = 'error';
		}
	}
}

if (isset($_GET['status'], $_GET['message'])) {
	$statusType = $_GET['status'] === 'ok' ? 'success' : 'error';
	$statusMessage = (string) $_GET['message'];
}

$search = trim($_GET['q'] ?? '');
$currentPage = max(1, (int) ($_GET['page'] ?? 1));
$itemsPerPage = 25;

$statusFilter = strtolower(trim($_GET['member_status'] ?? 'all'));
$allowedStatusFilters = ['all', 'active', 'inactive'];
if (!in_array($statusFilter, $allowedStatusFilters, true)) {
	$statusFilter = 'all';
}

$sortBy = strtolower(trim($_GET['sort_by'] ?? 'created_date'));
$allowedSortColumns = [
	'name' => "COALESCE(NULLIF(TRIM(CONCAT(up.FirstName, ' ', up.LastName)), ''), u.Email)",
	'email' => 'u.Email',
	'status' => 'u.IsActive',
	'created_date' => 'u.CreatedDate',
	'last_login' => 'u.LastLogin',
];
if (!isset($allowedSortColumns[$sortBy])) {
	$sortBy = 'created_date';
}

$sortDir = strtolower(trim($_GET['sort_dir'] ?? 'desc'));
if (!in_array($sortDir, ['asc', 'desc'], true)) {
	$sortDir = 'desc';
}

try {
	// Count total members (for stats and pagination)
	$countSql = "SELECT
					COUNT(*) AS total_members,
					SUM(CASE WHEN u.IsActive = 1 THEN 1 ELSE 0 END) AS active_members,
					SUM(CASE WHEN u.IsActive = 0 THEN 1 ELSE 0 END) AS inactive_members
				 FROM Users u
				 JOIN Roles r ON u.RoleId = r.RoleId
				 LEFT JOIN UserProfile up ON up.UserId = u.UserId
				 WHERE r.RoleName = 'Member'";
	$countParams = [];
	if ($search !== '') {
		$countSql .= " AND (
			u.Email LIKE :keyword_email
			OR up.FirstName LIKE :keyword_first_name
			OR up.LastName LIKE :keyword_last_name
		)";
		$keyword = '%' . $search . '%';
		$countParams[':keyword_email'] = $keyword;
		$countParams[':keyword_first_name'] = $keyword;
		$countParams[':keyword_last_name'] = $keyword;
	}
	if ($statusFilter === 'active') {
		$countSql .= ' AND u.IsActive = 1';
	} elseif ($statusFilter === 'inactive') {
		$countSql .= ' AND u.IsActive = 0';
	}
	$countStmt = $pdo->prepare($countSql);
	$countStmt->execute($countParams);
	$allCounts = $countStmt->fetch(PDO::FETCH_ASSOC) ?: [
		'total_members' => 0,
		'active_members' => 0,
		'inactive_members' => 0,
	];
	$counts = $allCounts;
	$totalSearchResults = (int) $counts['total_members'];
	$totalPages = max(1, (int) ceil($totalSearchResults / $itemsPerPage));
	$currentPage = min($currentPage, $totalPages);
	$offset = ($currentPage - 1) * $itemsPerPage;

	$listSql = "SELECT
					u.UserId,
					u.Email,
					u.IsActive,
					u.CreatedDate,
					u.LastLogin,
					up.FirstName,
					up.LastName,
					up.PhoneNumber,
					up.CreateDate as ProfileCreateDate,
					up.UpdateDate as ProfileUpdateDate
				FROM Users u
				JOIN Roles r ON u.RoleId = r.RoleId
				LEFT JOIN UserProfile up ON up.UserId = u.UserId
				WHERE r.RoleName = 'Member'";

	$params = [];
	if ($search !== '') {
		$listSql .= " AND (
			u.Email LIKE :keyword_email
			OR up.FirstName LIKE :keyword_first_name
			OR up.LastName LIKE :keyword_last_name
		)";
		$keyword = '%' . $search . '%';
		$params[':keyword_email'] = $keyword;
		$params[':keyword_first_name'] = $keyword;
		$params[':keyword_last_name'] = $keyword;
	}
	if ($statusFilter === 'active') {
		$listSql .= ' AND u.IsActive = 1';
	} elseif ($statusFilter === 'inactive') {
		$listSql .= ' AND u.IsActive = 0';
	}

	$listSql .= ' ORDER BY ' . $allowedSortColumns[$sortBy] . ' ' . strtoupper($sortDir) . ' LIMIT ' . $itemsPerPage . ' OFFSET ' . $offset;
	$listStmt = $pdo->prepare($listSql);
	$listStmt->execute($params);
	$members = $listStmt->fetchAll(PDO::FETCH_ASSOC);

	// Load addresses for each member
	$memberAddresses = [];
	if (!empty($members)) {
		$userIds = array_map(fn($m) => $m['UserId'], $members);
		$addrSql = "SELECT 
					AddressId,
					UserId,
					RecipientName,
					PhoneNumber,
					FullAddress,
					IsDefault
				FROM Addresses
				WHERE UserId IN (" . implode(',', array_fill(0, count($userIds), '?')) . ")
				ORDER BY IsDefault DESC, AddressId DESC";
		$addrStmt = $pdo->prepare($addrSql);
		$addrStmt->execute($userIds);
		$allAddresses = $addrStmt->fetchAll(PDO::FETCH_ASSOC);
		foreach ($allAddresses as $addr) {
			if (!isset($memberAddresses[$addr['UserId']])) {
				$memberAddresses[$addr['UserId']] = [];
			}
			$memberAddresses[$addr['UserId']][] = $addr;
		}
	}

	$queryBase = [
		'q' => $search,
		'member_status' => $statusFilter,
		'sort_by' => $sortBy,
		'sort_dir' => $sortDir,
	];
	$buildListUrl = function (array $overrides = []) use ($queryBase): string {
		$params = array_merge($queryBase, $overrides);

		if (($params['q'] ?? '') === '') {
			unset($params['q']);
		}
		if (($params['member_status'] ?? 'all') === 'all') {
			unset($params['member_status']);
		}
		if (($params['sort_by'] ?? 'created_date') === 'created_date') {
			unset($params['sort_by']);
		}
		if (($params['sort_dir'] ?? 'desc') === 'desc') {
			unset($params['sort_dir']);
		}
		if (($params['page'] ?? null) === 1) {
			unset($params['page']);
		}

		return 'member_management.php' . (!empty($params) ? '?' . http_build_query($params) : '');
	};
} catch (Exception $e) {
	die('Error fetching member data: ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Member Management || E-Commerce</title>
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
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
			padding: 0;
		}

		.shell {
			width: 100%;
			min-width: 1160px;
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

		.stat-card.active {
			background: linear-gradient(135deg, #29995a, #1f7a46);
		}

		.stat-card.inactive {
			background: linear-gradient(135deg, #c98517, #9a5b07);
		}

		.panel {
			border: 1px solid var(--line);
			border-radius: 16px;
			background: rgba(255, 255, 255, 0.7);
			overflow: hidden;
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

		.search-form {
			display: flex;
			gap: 8px;
			flex-wrap: wrap;
			width: 100%;
			max-width: 560px;
		}

		.search-form input {
			flex: 1;
			min-width: 200px;
			border: 1px solid rgba(31, 26, 21, 0.22);
			border-radius: 12px;
			padding: 10px 12px;
			font-size: 14px;
			font-family: inherit;
			background: rgba(255, 255, 255, 0.9);
			outline: none;
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

		.search-form input:focus {
			border-color: rgba(218, 90, 27, 0.7);
			box-shadow: 0 0 0 4px rgba(218, 90, 27, 0.15);
		}

		.search-form select:focus {
			border-color: rgba(218, 90, 27, 0.7);
			box-shadow: 0 0 0 4px rgba(218, 90, 27, 0.15);
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

		.btn-success {
			color: #fff;
			background: linear-gradient(135deg, #29995a, #1f7a46);
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

		.table-wrap {
			width: 100%;
			overflow-x: auto;
		}

		table {
			width: 100%;
			border-collapse: collapse;
			min-width: 900px;
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

		.status-pill {
			display: inline-block;
			padding: 5px 10px;
			border-radius: 999px;
			font-size: 12px;
			font-weight: 700;
			letter-spacing: 0.03em;
			text-transform: uppercase;
		}

		.status-pill.active {
			color: var(--ok);
			border: 1px solid rgba(31, 122, 70, 0.34);
			background: rgba(31, 122, 70, 0.12);
		}

		.status-pill.inactive {
			color: var(--warning);
			border: 1px solid rgba(154, 91, 7, 0.34);
			background: rgba(154, 91, 7, 0.12);
		}

		.action-form {
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

		.member-name-link {
			color: var(--accent);
			cursor: pointer;
			text-decoration: none;
			font-weight: 500;
			transition: color 160ms ease;
		}

		.member-name-link:hover {
			color: var(--accent-strong);
			text-decoration: underline;
		}

		.modal {
			display: none;
			position: fixed;
			top: 0;
			left: 0;
			width: 100%;
			height: 100%;
			background: rgba(0, 0, 0, 0.5);
			z-index: 1000;
			align-items: center;
			justify-content: center;
			padding: 16px;
		}

		.modal.active {
			display: flex;
		}

		.modal-content {
			background: var(--paper);
			border: 1px solid var(--line);
			border-radius: 16px;
			width: 100%;
			max-width: 600px;
			max-height: 85vh;
			overflow-y: auto;
			box-shadow: 0 20px 60px rgba(49, 36, 20, 0.3);
		}

		.modal-header {
			display: flex;
			justify-content: space-between;
			align-items: center;
			padding: 20px;
			border-bottom: 1px solid var(--line);
		}

		.modal-header h2 {
			margin: 0;
			font-family: 'Syne', sans-serif;
			font-size: 1.4rem;
		}

		.modal-close {
			background: none;
			border: none;
			font-size: 24px;
			cursor: pointer;
			color: rgba(31, 26, 21, 0.6);
			transition: color 160ms ease;
		}

		.modal-close:hover {
			color: var(--ink);
		}

		.modal-body {
			padding: 20px;
		}

		.profile-section {
			margin-bottom: 20px;
		}

		.profile-section h3 {
			margin: 0 0 12px;
			font-family: 'Syne', sans-serif;
			font-size: 1rem;
			color: var(--accent);
			text-transform: uppercase;
			letter-spacing: 0.05em;
		}

		.profile-row {
			display: flex;
			justify-content: space-between;
			align-items: flex-start;
			padding: 10px 0;
			border-bottom: 1px solid rgba(31, 26, 21, 0.08);
		}

		.profile-row:last-child {
			border-bottom: none;
		}

		.profile-label {
			font-weight: 600;
			color: rgba(31, 26, 21, 0.78);
			min-width: 140px;
		}

		.profile-value {
			text-align: right;
			flex: 1;
			color: var(--ink);
			word-break: break-word;
		}

		.address-item {
			padding: 12px;
			margin-bottom: 10px;
			border: 1px solid var(--line);
			border-radius: 10px;
			background: rgba(255, 255, 255, 0.5);
		}

		.address-item.default {
			border-color: var(--ok);
			background: rgba(31, 122, 70, 0.08);
		}

		.address-default-badge {
			display: inline-block;
			padding: 4px 8px;
			border-radius: 999px;
			font-size: 11px;
			font-weight: 700;
			letter-spacing: 0.03em;
			text-transform: uppercase;
			color: var(--ok);
			border: 1px solid var(--ok);
			background: rgba(31, 122, 70, 0.12);
			margin-bottom: 8px;
		}

		.pagination {
			display: flex;
			align-items: center;
			justify-content: center;
			gap: 6px;
			padding: 16px 14px;
			border-top: 1px solid var(--line);
			margin-top: 16px;
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

		.pagination-btn:disabled:hover {
			border-color: var(--line);
			background: rgba(255, 255, 255, 0.8);
			color: var(--ink);
		}

		@media (max-width: 960px) {
			.stats {
				grid-template-columns: 1fr;
			}
		}

		@media (max-width: 640px) {
			body {
				padding: 0;
			}

			.shell {
				min-width: 1080px;
			}
		}
	</style>
</head>
<body>
<div class="shell-scroll">
<div class="shell">
	<aside class="sidebar">
		<span class="brand-tag">Admin Portal</span>
		<h2>Member Management</h2>
		<nav class="nav-links">
			<a href="admin_dashboard.php"><i class="bi bi-speedometer2"></i> Control Panel</a>
			<a href="product_management.php"><i class="bi bi-box"></i> Product Management</a>
			<a href="member_management.php" class="active"><i class="bi bi-people"></i> Member Management</a>
			<a href="order_management.php"><i class="bi bi-cart"></i> Order Management</a>
			<a href="admin_logout.php"><i class="bi bi-gear"></i> Logout</a>
		</nav>
	</aside>

	<main class="main">
		<div class="topbar">
			<h1>Members</h1>
			<span class="admin-badge">Admin</span>
		</div>

		<?php if ($statusMessage !== ''): ?>
			<div class="notice <?php echo htmlspecialchars($statusType ?: 'error'); ?>">
				<?php echo htmlspecialchars($statusMessage); ?>
			</div>
		<?php endif; ?>

		<section class="stats">
			<article class="stat-card total">
				<h3>Total Members</h3>
				<p><?php echo (int) $counts['total_members']; ?></p>
			</article>
			<article class="stat-card active">
				<h3>Active Members</h3>
				<p><?php echo (int) $counts['active_members']; ?></p>
			</article>
			<article class="stat-card inactive">
				<h3>Inactive Members</h3>
				<p><?php echo (int) $counts['inactive_members']; ?></p>
			</article>
		</section>

		<section class="panel">
			<div class="panel-header">
				<form class="search-form" method="get" action="member_management.php">
					<input type="hidden" name="sort_by" value="<?php echo htmlspecialchars($sortBy); ?>">
					<input type="hidden" name="sort_dir" value="<?php echo htmlspecialchars($sortDir); ?>">
					<input
						type="text"
						name="q"
						value="<?php echo htmlspecialchars($search); ?>"
						placeholder="Search by email or name"
					>
					<select name="member_status">
						<option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>All Statuses</option>
						<option value="active" <?php echo $statusFilter === 'active' ? 'selected' : ''; ?>>Active</option>
						<option value="inactive" <?php echo $statusFilter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
					</select>
					<button class="btn btn-primary" type="submit"><i class="bi bi-search"></i> Search</button>
					<?php if ($search !== '' || $statusFilter !== 'all'): ?>
						<a class="btn btn-outline" href="member_management.php"><i class="bi bi-x-circle"></i> Clear</a>
					<?php endif; ?>
				</form>
				<span class="muted"><?php echo $totalSearchResults; ?> result(s)</span>
			</div>

			<?php if (empty($members)): ?>
				<div class="empty-state">
					<p>No members found for the current filter.</p>
				</div>
			<?php else: ?>
				<div class="table-wrap table-responsive">
					<table class="table table-hover align-middle mb-0">
						<thead>
							<tr>
								<th class="sortable"><a href="<?php echo htmlspecialchars($buildListUrl(['sort_by' => 'name', 'sort_dir' => ($sortBy === 'name' && $sortDir === 'asc') ? 'desc' : 'asc', 'page' => 1])); ?>">Name<?php if ($sortBy === 'name') echo $sortDir === 'asc' ? ' ▲' : ' ▼'; ?></a></th>
								<th class="sortable"><a href="<?php echo htmlspecialchars($buildListUrl(['sort_by' => 'email', 'sort_dir' => ($sortBy === 'email' && $sortDir === 'asc') ? 'desc' : 'asc', 'page' => 1])); ?>">Email<?php if ($sortBy === 'email') echo $sortDir === 'asc' ? ' ▲' : ' ▼'; ?></a></th>
								<th class="sortable"><a href="<?php echo htmlspecialchars($buildListUrl(['sort_by' => 'status', 'sort_dir' => ($sortBy === 'status' && $sortDir === 'asc') ? 'desc' : 'asc', 'page' => 1])); ?>">Status<?php if ($sortBy === 'status') echo $sortDir === 'asc' ? ' ▲' : ' ▼'; ?></a></th>
								<th class="sortable"><a href="<?php echo htmlspecialchars($buildListUrl(['sort_by' => 'created_date', 'sort_dir' => ($sortBy === 'created_date' && $sortDir === 'asc') ? 'desc' : 'asc', 'page' => 1])); ?>">Created<?php if ($sortBy === 'created_date') echo $sortDir === 'asc' ? ' ▲' : ' ▼'; ?></a></th>
								<th class="sortable"><a href="<?php echo htmlspecialchars($buildListUrl(['sort_by' => 'last_login', 'sort_dir' => ($sortBy === 'last_login' && $sortDir === 'asc') ? 'desc' : 'asc', 'page' => 1])); ?>">Last Login<?php if ($sortBy === 'last_login') echo $sortDir === 'asc' ? ' ▲' : ' ▼'; ?></a></th>
								<th>Action</th>
							</tr>
						</thead>
						<tbody>
						<?php foreach ($members as $member): ?>
							<?php
							$name = trim((string) (($member['FirstName'] ?? '') . ' ' . ($member['LastName'] ?? '')));
							if ($name === '') {
								$name = 'No profile name';
							}
							$isActive = (int) ($member['IsActive'] ?? 0) === 1;
							?>
							<tr>
							<td>
								<a href="javascript:void(0)" class="member-name-link" onclick="openMemberModal(<?php echo htmlspecialchars(json_encode($member)); ?>)">
									<?php echo htmlspecialchars($name); ?>
								</a>
							</td>
								<td><?php echo htmlspecialchars((string) $member['Email']); ?></td>
								<td>
									<span class="status-pill <?php echo $isActive ? 'active' : 'inactive'; ?>">
										<?php echo $isActive ? 'Active' : 'Inactive'; ?>
									</span>
								</td>
								<td><?php echo htmlspecialchars((string) ($member['CreatedDate'] ?? '-')); ?></td>
								<td><?php echo htmlspecialchars((string) ($member['LastLogin'] ?? '-')); ?></td>
								<td>
									<form class="action-form" method="post" action="member_management.php<?php echo $search !== '' ? '?q=' . urlencode($search) : ''; ?>">
										<input type="hidden" name="csrf" value="<?php echo htmlspecialchars($_SESSION['admin_csrf']); ?>">
										<input type="hidden" name="action" value="toggle_member_status">
										<input type="hidden" name="user_id" value="<?php echo htmlspecialchars((string) $member['UserId']); ?>">
										<button class="btn <?php echo $isActive ? 'btn-danger' : 'btn-success'; ?>" type="submit">
											<?php echo $isActive ? 'Deactivate' : 'Activate'; ?>
										</button>
									</form>
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
					(<?php echo $totalSearchResults; ?> total)
				</span>
				<div class="pagination-controls">
					<?php if ($currentPage > 1): ?>
						<a href="<?php echo htmlspecialchars($buildListUrl(['page' => 1])); ?>" class="pagination-link" title="First page">
							<i class="bi bi-chevron-double-left"></i>
						</a>
						<a href="<?php echo htmlspecialchars($buildListUrl(['page' => $currentPage - 1])); ?>" class="pagination-link" title="Previous page">
							<i class="bi bi-chevron-left"></i>
						</a>
					<?php else: ?>
						<button class="pagination-btn" disabled title="First page">
							<i class="bi bi-chevron-double-left"></i>
						</button>
						<button class="pagination-btn" disabled title="Previous page">
							<i class="bi bi-chevron-left"></i>
						</button>
					<?php endif; ?>

					<?php
					// Show page numbers (show current page and 2 neighbors)
					$startPage = max(1, $currentPage - 1);
					$endPage = min($totalPages, $currentPage + 1);

					// If at the beginning, show 1,2,3
					if ($currentPage <= 2) {
						$endPage = min($totalPages, 3);
					}
					// If at the end, show ...n-2, n-1, n
					if ($currentPage >= $totalPages - 1) {
						$startPage = max(1, $totalPages - 2);
					}

					if ($startPage > 1): ?>
						<span style="color: rgba(31, 26, 21, 0.5);">...</span>
					<?php endif;

					for ($page = $startPage; $page <= $endPage; $page++):
						$isCurrentPage = $page === $currentPage;
						?>
						<a href="<?php echo htmlspecialchars($buildListUrl(['page' => $page])); ?>" class="pagination-link <?php echo $isCurrentPage ? 'active' : ''; ?>">
							<?php echo $page; ?>
						</a>
					<?php endfor;

					if ($endPage < $totalPages): ?>
						<span style="color: rgba(31, 26, 21, 0.5);">...</span>
					<?php endif; ?>

					<?php if ($currentPage < $totalPages): ?>
						<a href="<?php echo htmlspecialchars($buildListUrl(['page' => $currentPage + 1])); ?>" class="pagination-link" title="Next page">
							<i class="bi bi-chevron-right"></i>
						</a>
						<a href="<?php echo htmlspecialchars($buildListUrl(['page' => $totalPages])); ?>" class="pagination-link" title="Last page">
							<i class="bi bi-chevron-double-right"></i>
						</a>
					<?php else: ?>
						<button class="pagination-btn" disabled title="Next page">
							<i class="bi bi-chevron-right"></i>
						</button>
						<button class="pagination-btn" disabled title="Last page">
							<i class="bi bi-chevron-double-right"></i>
						</button>
					<?php endif; ?>
				</div>
			</div>
			<?php endif; ?>
		</section>
	</main>
</div>
</div>

<!-- Member Details Modal -->
<div id="memberModal" class="modal">
	<div class="modal-content">
		<div class="modal-header">
			<h2 id="modalMemberName">Member Profile</h2>
			<button type="button" class="modal-close" onclick="closeMemberModal()">&times;</button>
		</div>
		<div class="modal-body" id="modalBody">
			<!-- Populated by JavaScript -->
		</div>
	</div>
</div>

<script>
	const memberAddresses = <?php echo json_encode($memberAddresses); ?>;

	function openMemberModal(member) {
		const modal = document.getElementById('memberModal');
		const modalBody = document.getElementById('modalBody');
		const modalName = document.getElementById('modalMemberName');

		const firstName = member.FirstName || '';
		const lastName = member.LastName || '';
		const fullName = (firstName + ' ' + lastName).trim() || 'No profile name';

		modalName.textContent = fullName;

		const profileDate = member.ProfileUpdateDate ? new Date(member.ProfileUpdateDate).toLocaleDateString() : 'N/A';
		const createdDate = member.CreatedDate ? new Date(member.CreatedDate).toLocaleDateString() : 'N/A';
		const lastLogin = member.LastLogin ? new Date(member.LastLogin).toLocaleDateString() : 'Never';

		const addresses = memberAddresses[member.UserId] || [];
		const addressesHtml = addresses.length > 0
			? addresses.map(addr => `
				<div class="address-item ${addr.IsDefault ? 'default' : ''}">
					${addr.IsDefault ? '<div class="address-default-badge">Default</div>' : ''}
					<div class="profile-row">
						<div class="profile-label">Recipient:</div>
						<div class="profile-value">${escapeHtml(addr.RecipientName)}</div>
					</div>
					<div class="profile-row">
						<div class="profile-label">Phone:</div>
						<div class="profile-value">${escapeHtml(addr.PhoneNumber)}</div>
					</div>
					<div class="profile-row">
						<div class="profile-label">Address:</div>
						<div class="profile-value">${escapeHtml(addr.FullAddress)}</div>
					</div>
				</div>
			`).join('')
			: '<p style="color: rgba(31, 26, 21, 0.6);">No addresses on file</p>';

		modalBody.innerHTML = `
			<div class="profile-section">
				<h3>Basic Information</h3>
				<div class="profile-row">
					<div class="profile-label">First Name:</div>
					<div class="profile-value">${escapeHtml(firstName || '-')}</div>
				</div>
				<div class="profile-row">
					<div class="profile-label">Last Name:</div>
					<div class="profile-value">${escapeHtml(lastName || '-')}</div>
				</div>
				<div class="profile-row">
					<div class="profile-label">Email:</div>
					<div class="profile-value">${escapeHtml(member.Email)}</div>
				</div>
				<div class="profile-row">
					<div class="profile-label">Phone:</div>
					<div class="profile-value">${escapeHtml(member.PhoneNumber || '-')}</div>
				</div>
				<div class="profile-row">
					<div class="profile-label">Status:</div>
					<div class="profile-value">
						<span class="status-pill ${member.IsActive ? 'active' : 'inactive'}">
							${member.IsActive ? 'Active' : 'Inactive'}
						</span>
					</div>
				</div>
			</div>

			<div class="profile-section">
				<h3>Account Details</h3>
				<div class="profile-row">
					<div class="profile-label">Account Created:</div>
					<div class="profile-value">${escapeHtml(createdDate)}</div>
				</div>
				<div class="profile-row">
					<div class="profile-label">Last Login:</div>
					<div class="profile-value">${escapeHtml(lastLogin)}</div>
				</div>
				<div class="profile-row">
					<div class="profile-label">Profile Updated:</div>
					<div class="profile-value">${escapeHtml(profileDate)}</div>
				</div>
			</div>

			<div class="profile-section">
				<h3>Addresses</h3>
				${addressesHtml}
			</div>
		`;

		modal.classList.add('active');
	}

	function closeMemberModal() {
		const modal = document.getElementById('memberModal');
		modal.classList.remove('active');
	}

	function escapeHtml(text) {
		if (!text) return '';
		const div = document.createElement('div');
		div.textContent = text;
		return div.innerHTML;
	}

	// Close modal when clicking outside the modal-content
	document.getElementById('memberModal')?.addEventListener('click', function(e) {
		if (e.target === this) {
			closeMemberModal();
		}
	});
</script>

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