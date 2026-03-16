<?php
require_once 'config/config.php';

// Default avatar if user has none
$defaultAvatar = 'assets/default-avatar.png';

// Initialize variables
$userAvatar = $defaultAvatar;
$fullName = '';

// If user is logged in, fetch their full name and profile photo from UserProfile
if(isset($_SESSION['user_id'])) {
    $userId = $_SESSION['user_id'];

    $stmt = $pdo->prepare("
        SELECT up.FirstName, up.LastName, up.ProfilePhotoUrl 
        FROM Users u
        LEFT JOIN UserProfile up ON u.UserId = up.UserId
        WHERE u.UserId = :userId
        LIMIT 1
    ");
    $stmt->bindParam(':userId', $userId);
    $stmt->execute();
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if($user) {
        $fullName = trim(($user['FirstName'] ?? '') . ' ' . ($user['LastName'] ?? ''));
        if(empty($fullName)) $fullName = "User"; // fallback
        $userAvatar = !empty($user['ProfilePhotoUrl']) ? $user['ProfilePhotoUrl'] : $defaultAvatar;
    } else {
        $fullName = "User";
    }
}
?>
<nav class="navbar navbar-expand-lg navbar-light bg-white shadow-sm">
    <div class="container-fluid">
        <!-- Brand -->
        <a class="navbar-brand" href="index.php">E-commerce</a>

        <!-- Toggler for mobile -->
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarContent" 
                aria-controls="navbarContent" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>

        <!-- Navbar content -->
        <div class="collapse navbar-collapse" id="navbarContent">
            <!-- Centered search container -->
            <div class="d-flex flex-column flex-lg-row w-100 align-items-center justify-content-center mb-2 mb-lg-0">
                <form class="d-flex w-100 w-lg-auto" action="index.php" method="get" role="search">
                    <div class="input-group search-container" style="min-width: 250px;">
                        <input 
                            type="search" 
                            name="q" 
                            class="form-control rounded-pill ps-3 shadow-sm search-input" 
                            placeholder="Search" 
                            aria-label="Search"
                        >
                        <button class="btn btn-success rounded-pill shadow-sm search-btn" type="submit">
                            Search
                        </button>
                    </div>
                </form>
            </div>

            <!-- User menu -->
            <ul class="navbar-nav ms-auto mb-2 mb-lg-0 align-items-center">
                <?php if(isset($_SESSION['user_id'])): ?>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle d-flex align-items-center" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown">
                            <img src="<?php echo htmlspecialchars($userAvatar); ?>" class="rounded-circle me-2" width="32" height="32" alt="User Avatar">
                            <span><?php echo htmlspecialchars($fullName); ?></span>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                            <li><a class="dropdown-item" href="profile.php">Profile</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="logout.php">Logout</a></li>
                        </ul>
                    </li>
                <?php else: ?>
                    <li class="nav-item">
                        <a class="nav-link" href="member_login.php">Login</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="member_register.php">Register</a>
                    </li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</nav>

<style>
/* Smooth expand on focus for mobile */
@media (max-width: 991px) {
    .search-container {
        width: 100%;
        transition: width 0.4s ease;
    }
    .search-input:focus {
        width: 100%;
        transition: width 0.4s ease;
        box-shadow: 0 0 8px rgba(0,0,0,0.2);
    }
    .search-btn {
        flex-shrink: 0;
    }
}
</style>