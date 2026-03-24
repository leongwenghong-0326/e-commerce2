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
<style>
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
<nav class="navbar navbar-expand-lg navbar-light bg-white shadow-sm">
    <div class="container-fluid">
        <a class="navbar-brand" href="index.php">E-commerce</a>
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
                        <i class="bi bi-shop"></i>
                        <span>Shop</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link d-flex align-items-center gap-1" href="cart.php">
                        <i class="bi bi-cart3"></i>
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