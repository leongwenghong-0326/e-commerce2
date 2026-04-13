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
	<link rel="stylesheet" href="../asset/css/admin-base.css">
	<link rel="stylesheet" href="../asset/css/admin-layout-responsive.css">
    <link rel="stylesheet" href="../asset/css/admin-member-management.css">
	
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
			<a href="category_management.php"><i class="bi bi-tags"></i> Category Management</a>
			<a href="member_management.php" class="active"><i class="bi bi-people"></i> Member Management</a>
			<a href="order_management.php"><i class="bi bi-cart"></i> Order Management</a>
			<a href="discount_management.php"><i class="bi bi-percent"></i> Discount Campaign</a>
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
				<span class="muted"><?php echo $totalSearchResults; ?> member(s)</span>
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
						<span class="pagination-ellipsis">...</span>
					<?php endif;

					for ($page = $startPage; $page <= $endPage; $page++):
						$isCurrentPage = $page === $currentPage;
						?>
						<a href="<?php echo htmlspecialchars($buildListUrl(['page' => $page])); ?>" class="pagination-link <?php echo $isCurrentPage ? 'active' : ''; ?>">
							<?php echo $page; ?>
						</a>
					<?php endfor;

					if ($endPage < $totalPages): ?>
						<span class="pagination-ellipsis">...</span>
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
			: '<p class="no-addresses-msg">No addresses on file</p>';

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