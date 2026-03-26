<?php

require_once __DIR__ . '/../config/config.php';

// Set default values first
$userAvatar = 'asset/image/default_avatar.png';
$userName = 'User';
$cartCount = 0;

if(isset($_SESSION['user_id'])){
    $sql = "SELECT u.UserId, up.FirstName, up.ProfilePhotoUrl 
            FROM Users u 
            JOIN UserProfile up ON u.UserId = up.UserId 
            WHERE u.UserId = :user_id LIMIT 1";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(['user_id' => $_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // Check if fetch returned data before using it
    if($user){
        $userAvatar = $user['ProfilePhotoUrl'] ?: $userAvatar;
        $userName = $user['FirstName'] ?: $userName;
    }

    $cartStmt = $pdo->prepare('SELECT COALESCE(SUM(Quantity), 0) FROM Carts WHERE UserId = :user_id');
    $cartStmt->execute(['user_id' => $_SESSION['user_id']]);
    $cartCount = (int) $cartStmt->fetchColumn();
}

?>
<script>
    (function () {
        if (!document.head) {
            return;
        }

        var existing = document.querySelector('link[href*="bootstrap-icons"]');
        if (existing) {
            return;
        }

        var iconCss = document.createElement('link');
        iconCss.rel = 'stylesheet';
        iconCss.href = 'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css';
        document.head.appendChild(iconCss);
    })();
</script>
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

    .site-nav {
        background: var(--panel) !important;
        border-bottom: 1px solid var(--line);
        backdrop-filter: blur(8px);
        box-shadow: 0 8px 20px rgba(10, 36, 60, 0.08) !important;
        position: relative;
        z-index: 1040;
    }

    .site-nav .navbar-brand {
        font-family: 'Space Grotesk', sans-serif;
        font-weight: 700;
        letter-spacing: -0.01em;
        color: var(--ink);
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }

    .site-nav .brand-icon {
        width: 26px;
        height: 26px;
        border-radius: 8px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        color: #fff;
        background: linear-gradient(135deg, var(--accent), var(--accent-strong));
        box-shadow: 0 6px 12px rgba(11, 111, 86, 0.28);
        font-size: 0.9rem;
    }

    .site-nav .nav-link {
        color: var(--ink);
        font-weight: 600;
        border-radius: 10px;
        padding: 8px 10px !important;
    }

    .site-nav .nav-link:hover,
    .site-nav .nav-link:focus {
        color: var(--accent-strong);
        background: rgba(15, 143, 111, 0.08);
    }

    .site-nav .form-control {
        border-radius: 12px;
        border: 1px solid rgba(27, 37, 48, 0.2);
        background: rgba(255, 255, 255, 0.92);
    }

    .site-nav .form-control:focus {
        border-color: rgba(15, 143, 111, 0.75);
        box-shadow: 0 0 0 4px rgba(15, 143, 111, 0.15);
    }

    .site-nav .btn-outline-success {
        border-color: rgba(15, 143, 111, 0.45);
        color: var(--accent-strong);
        font-weight: 700;
        border-radius: 12px;
    }

    .site-nav .btn-outline-success:hover {
        background: linear-gradient(135deg, var(--accent), var(--accent-strong));
        border-color: transparent;
        color: #fff;
    }

    .site-nav .dropdown-menu {
        border: 1px solid var(--line);
        border-radius: 12px;
        box-shadow: 0 14px 30px rgba(10, 36, 60, 0.12);
        padding: 6px;
        z-index: 1060;
    }

    .site-nav .dropdown-item {
        border-radius: 8px;
        font-weight: 500;
        padding: 8px 10px;
    }

    .site-nav .dropdown-item:hover {
        background: rgba(15, 143, 111, 0.08);
        color: var(--accent-strong);
    }

    .site-nav .badge.text-bg-success {
        background: linear-gradient(135deg, var(--accent), var(--accent-strong)) !important;
    }

    .site-nav .nav-icon {
        width: 1rem;
        height: 1rem;
        flex-shrink: 0;
    }

    .nav-search-shell {
        width: 100%;
        max-width: 420px;
    }

    @media (min-width: 992px) {
        .nav-search-shell {
            margin: 0 auto;
        }
    }
</style>
<nav class="navbar navbar-expand-lg navbar-light site-nav">
    <div class="container-fluid">
        <a class="navbar-brand" href="index.php">
            <span class="brand-icon"><i class="bi bi-bag"></i></span>
            <span>E-commerce</span>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarContent" aria-controls="navbarContent" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="navbarContent">
            <div class="nav-search-shell my-3 my-lg-0">
                <form class="d-flex" action="products.php" method="get" role="search">
                    <input
                        class="form-control me-2"
                        type="search"
                        name="q"
                        placeholder="Search products"
                        aria-label="Search"
                    >
                    <button class="btn btn-outline-success" type="submit">Search</button>
                </form>
            </div>

            <ul class="navbar-nav ms-lg-auto mb-2 mb-lg-0 align-items-lg-center">
                <li class="nav-item">
                    <a class="nav-link d-flex align-items-center gap-1" href="products.php">
                        <svg class="nav-icon" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">
                            <path d="M2 3.5A1.5 1.5 0 0 1 3.5 2h9A1.5 1.5 0 0 1 14 3.5V5h.5A.5.5 0 0 1 15 5.5v1a2.5 2.5 0 0 1-1 2V13a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1V8.5a2.5 2.5 0 0 1-1-2v-1A.5.5 0 0 1 1.5 5H2V3.5zM3.5 3a.5.5 0 0 0-.5.5V5h10V3.5a.5.5 0 0 0-.5-.5h-9zM2 6v.5a1.5 1.5 0 1 0 3 0V6H2zm4 0v.5a1.5 1.5 0 1 0 3 0V6H6zm4 0v.5a1.5 1.5 0 1 0 3 0V6h-3zM3 8.95V13h10V8.95a2.48 2.48 0 0 1-2-.95 2.49 2.49 0 0 1-3 0 2.49 2.49 0 0 1-3 0 2.48 2.48 0 0 1-2 .95z"/>
                        </svg>
                        <span>Shop</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link d-flex align-items-center gap-1" href="cart.php">
                        <svg class="nav-icon" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">
                            <path d="M0 1.5A.5.5 0 0 1 .5 1H2a.5.5 0 0 1 .49.402L2.89 3H14.5a.5.5 0 0 1 .49.598l-1.5 7A.5.5 0 0 1 13 11H4a.5.5 0 0 1-.49-.402L1.61 2H.5A.5.5 0 0 1 0 1.5zM4.41 10h8.18l1.286-6H3.124L4.41 10zM5.5 13a1 1 0 1 1-2 0 1 1 0 0 1 2 0zm6 0a1 1 0 1 1-2 0 1 1 0 0 1 2 0z"/>
                        </svg>
                        <span>Cart</span>
                        <?php if ($cartCount > 0): ?>
                            <span class="badge text-bg-success ms-1"><?php echo $cartCount; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <?php if(isset($_SESSION['user_id'])): ?>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle d-flex align-items-center" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <img src="<?php echo htmlspecialchars($userAvatar); ?>" class="rounded-circle me-2" width="32" height="32" alt="User Avatar">
                            <span><?php echo htmlspecialchars($userName); ?></span>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                            <li><a class="dropdown-item" href="userProfile.php">Profile</a></li>
                            <li>
                                <a class="dropdown-item d-flex align-items-center gap-2" href="my_orders.php">
                                    <i class="bi bi-bag-check"></i>
                                    <span>My Orders</span>
                                </a>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="logout.php">Logout</a></li>
                        </ul>
                    </li>
                <?php else: ?>
                    <li class="nav-item">
                        <a class="nav-link d-flex align-items-center gap-1" href="member_login.php">
                            <i class="bi bi-box-arrow-in-right"></i>
                            <span>Login</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link d-flex align-items-center gap-1" href="member_register.php">
                            <i class="bi bi-person-plus"></i>
                            <span>Register</span>
                        </a>
                    </li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</nav>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const trigger = document.getElementById('userDropdown');

        if (!trigger) {
            return;
        }

        if (window.bootstrap && window.bootstrap.Dropdown) {
            window.bootstrap.Dropdown.getOrCreateInstance(trigger);
            return;
        }

        const menu = trigger.nextElementSibling;

        if (!menu) {
            return;
        }

        trigger.addEventListener('click', function (event) {
            event.preventDefault();
            const isOpen = menu.classList.toggle('show');
            trigger.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        });

        document.addEventListener('click', function (event) {
            if (trigger.contains(event.target) || menu.contains(event.target)) {
                return;
            }

            menu.classList.remove('show');
            trigger.setAttribute('aria-expanded', 'false');
        });
    });
</script>