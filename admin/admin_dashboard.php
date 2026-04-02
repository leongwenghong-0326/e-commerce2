<?php
include_once '../config/config.php';
include_once '../config/auth.php';

try {
    // Total Users (Active Members)
    $sqlUser = "SELECT COUNT(*) 
                FROM Users u 
                JOIN Roles r ON u.RoleId = r.RoleId 
                WHERE r.RoleName = 'Member'
                AND u.IsActive = TRUE";
    $stmtUser = $pdo->query($sqlUser);
    $totalUsers = $stmtUser->fetchColumn();

    // Total Products
    $sqlProduct = "SELECT COUNT(*) FROM Products";
    $stmtProduct = $pdo->query($sqlProduct);
    $totalProducts = $stmtProduct->fetchColumn();

    // Total Orders
    $sqlOrders = "SELECT COUNT(*) FROM Orders";
    $stmtOrders = $pdo->query($sqlOrders);
    $totalOrders = $stmtOrders->fetchColumn();

    // Total Revenue
    $sqlRevenue = "SELECT COALESCE(SUM(TotalAmount), 0) FROM Orders";
    $stmtRevenue = $pdo->query($sqlRevenue);
    $totalRevenue = $stmtRevenue->fetchColumn();

    // Pending Orders
    $sqlPending = "SELECT COUNT(*) FROM Orders WHERE OrderStatus = 'Pending'";
    $stmtPending = $pdo->query($sqlPending);
    $pendingOrders = $stmtPending->fetchColumn();

    // Active Members
    $sqlActive = "SELECT COUNT(*) 
                  FROM Users u 
                  JOIN Roles r ON u.RoleId = r.RoleId 
                  WHERE r.RoleName = 'Member'
                  AND u.IsActive = TRUE";
    $stmtActive = $pdo->query($sqlActive);
    $activeMembers = $stmtActive->fetchColumn();

} catch (Exception $e) {
    die("Error fetching dashboard data: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard || E-Commerce</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@500;700;800&family=IBM+Plex+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="../asset/css/admin-base.css">
    <link rel="stylesheet" href="../asset/css/admin-layout-responsive.css">
    <link rel="stylesheet" href="../asset/css/admin-dashboard.css">
    
</head>
<body>
<div class="dashboard-scroll">
<div class="dashboard-shell">
    <aside class="sidebar">
        <span class="brand-tag">Admin Portal</span>
        <h2>Admin Dashboard</h2>
        <nav class="nav-links">
            <a href="admin_dashboard.php" class="active"><i class="bi bi-speedometer2"></i> Control Panel</a>
            <a href="product_management.php"><i class="bi bi-box"></i> Product Management</a>
            <a href="category_management.php"><i class="bi bi-tags"></i> Category Management</a>
            <a href="member_management.php"><i class="bi bi-people"></i> Member Management</a>
            <a href="order_management.php"><i class="bi bi-cart"></i> Order Management</a>
            <a href="admin_logout.php"><i class="bi bi-gear"></i> Logout</a>
        </nav>
    </aside>

    <main class="main">
        <div class="topbar">
            <h1>Control Panel</h1>
            <span class="admin-badge">Admin</span>
        </div>

        <section class="cards">
            <article class="stat-card members">
                <h3>Total Members</h3>
                <p class="stat-value"><?php echo $totalUsers; ?></p>
            </article>
            <article class="stat-card products">
                <h3>Total Products</h3>
                <p class="stat-value"><?php echo $totalProducts; ?></p>
            </article>
            <article class="stat-card orders">
                <h3>Total Orders</h3>
                <p class="stat-value"><?php echo $totalOrders; ?></p>
            </article>
            <article class="stat-card revenue">
                <h3>Total Revenue</h3>
                <p class="stat-value">RM <?php echo number_format($totalRevenue, 2); ?></p>
            </article>
            <article class="stat-card pending">
                <h3>Pending Orders</h3>
                <p class="stat-value"><?php echo $pendingOrders; ?></p>
            </article>
            <article class="stat-card active">
                <h3>Active Members</h3>
                <p class="stat-value"><?php echo $activeMembers; ?></p>
            </article>
        </section>
    </main>
</div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>