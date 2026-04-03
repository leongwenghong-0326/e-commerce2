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

        var navCssExisting = document.querySelector('link[href*="asset/css/member-nav.css"]');
        if (!navCssExisting) {
            var navCss = document.createElement('link');
            navCss.rel = 'stylesheet';
            navCss.href = 'asset/css/member-nav.css';
            document.head.appendChild(navCss);
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

<nav class="navbar navbar-expand-lg navbar-light site-nav">
    <div class="container-fluid">
        <a class="navbar-brand" href="index.php">
            <span class="brand-icon"><i class="bi bi-bag"></i></span>
            <span>ShopSphere</span>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarContent" aria-controls="navbarContent" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="navbarContent">
            <div class="nav-search-shell my-3 my-lg-0">
                <form class="d-flex" action="products.php" method="get" role="search">
                    <span class="search-icon" aria-hidden="true"><i class="bi bi-search"></i></span>
                    <input
                        class="form-control me-2 search-input"
                        type="search"
                        name="q"
                        placeholder="Search products"
                        aria-label="Search"
                    >
                    <button class="btn btn-outline-success" type="submit">Search</button>
                </form>
            </div>

            <ul class="navbar-nav ms-lg-auto mb-2 mb-lg-0 align-items-lg-center">
                <?php if (isset($_SESSION['user_id'])): ?>
                    <li class="nav-item">
                        <a class="nav-link d-flex align-items-center gap-1" href="cart.php">
                            <svg class="nav-icon cart-icon" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">
                                <path d="M0 1.5A.5.5 0 0 1 .5 1H2a.5.5 0 0 1 .49.402L2.89 3H14.5a.5.5 0 0 1 .49.598l-1.5 7A.5.5 0 0 1 13 11H4a.5.5 0 0 1-.49-.402L1.61 2H.5A.5.5 0 0 1 0 1.5zM4.41 10h8.18l1.286-6H3.124L4.41 10zM5.5 13a1 1 0 1 1-2 0 1 1 0 0 1 2 0zm6 0a1 1 0 1 1-2 0 1 1 0 0 1 2 0z"/>
                            </svg>
                            <?php if ($cartCount > 0): ?>
                                <span class="badge text-bg-success ms-1"><?php echo $cartCount; ?></span>
                            <?php endif; ?>
                        </a>
                    </li>
                <?php endif; ?>
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
                    <li class="nav-item d-flex align-items-center">
                        <a class="nav-link px-2" href="member_login.php">Login</a>
                        <span class="text-muted">|</span>
                        <a class="nav-link px-2" href="member_register.php">Register</a>
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
