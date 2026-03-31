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
    <style>
        :root {
            --bg: #f6f2e8;
            --ink: #1f1a15;
            --paper: rgba(255, 255, 255, 0.75);
            --accent: #da5a1b;
            --accent-strong: #b74009;
            --line: rgba(31, 26, 21, 0.16);
            --card-blue: #2f66b3;
            --card-green: #1f7a46;
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

        .dashboard-shell {
            width: 100%;
            min-width: 0;
            margin: 0;
            display: grid;
            grid-template-columns: 280px minmax(0, 1fr);
            border: none;
            border-radius: 0;
            overflow: hidden;
            box-shadow: none;
            background: var(--paper);
            backdrop-filter: blur(8px);
            animation: settle 480ms ease-out;
        }

        .dashboard-scroll {
            width: 100%;
            overflow-x: hidden;
            overflow-y: visible;
            padding-bottom: 0;
        }

        @keyframes settle {
            from {
                transform: translateY(20px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
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

        .cards {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 16px;
        }

        .stat-card {
            color: #fff;
            border-radius: 16px;
            padding: 22px;
            box-shadow: 0 14px 30px rgba(49, 36, 20, 0.18);
        }

        .stat-card h3 {
            margin: 0;
            font-size: 1rem;
            font-weight: 600;
        }

        .stat-value {
            margin: 10px 0 0;
            font-family: 'Syne', sans-serif;
            font-size: clamp(2rem, 4vw, 2.8rem);
            line-height: 1;
        }

        .stat-card.members {
            background: linear-gradient(135deg, #3f7ed7, var(--card-blue));
        }

        .stat-card.products {
            background: linear-gradient(135deg, #29995a, var(--card-green));
        }

        .stat-card.orders {
            background: linear-gradient(135deg, #c2661c, #d97e2a);
        }

        .stat-card.revenue {
            background: linear-gradient(135deg, #7c3e8f, #a55ecc);
        }

        .stat-card.pending {
            background: linear-gradient(135deg, #d64d4d, #e66666);
        }

        .stat-card.active {
            background: linear-gradient(135deg, #2d8f6e, #3ba88a);
        }

        @media (max-width: 1199px) {
            .dashboard-shell {
                grid-template-columns: 1fr;
            }

            .sidebar {
                min-height: auto;
                border-right: none;
                border-bottom: 1px solid var(--line);
            }

            .nav-links {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 10px;
            }

            .main {
                padding: 22px;
            }
        }

        @media (max-width: 960px) {
            .cards {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .main {
                padding: 20px;
            }

            .sidebar {
                padding: 22px 18px;
            }

            .nav-links {
                grid-template-columns: 1fr;
            }

            .topbar h1 {
                font-size: clamp(1.4rem, 4.8vw, 1.9rem);
            }
        }

        @media (max-width: 640px) {
            .main {
                padding: 14px;
            }

            .topbar {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }

            .admin-badge {
                width: 100%;
                text-align: center;
            }

            .cards {
                grid-template-columns: 1fr;
            }

            .stat-card {
                padding: 16px;
            }

            .stat-value {
                font-size: clamp(1.8rem, 10vw, 2.3rem);
            }

            .brand-tag {
                font-size: 11px;
                padding: 6px 10px;
            }

            .sidebar h2 {
                font-size: 1.35rem;
            }
        }

        @media (max-width: 420px) {
            .main {
                padding: 12px;
            }

            .stat-card {
                border-radius: 12px;
                padding: 14px;
            }

            .stat-card h3 {
                font-size: 0.92rem;
            }
        }
    </style>
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