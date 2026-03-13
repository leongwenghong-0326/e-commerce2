<?php

require_once 'config/config.php';

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>E-commerce || Home</title>
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary shadow-sm">
        <div class="container">
            <!-- Brand -->
            <a class="navbar-brand fw-bold" href="index.php">My E-commerce</a>
            
            <!-- Toggler for mobile -->
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNavDropdown" 
                aria-controls="navbarNavDropdown" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>

            <!-- Navbar links -->
            <div class="collapse navbar-collapse" id="navbarNavDropdown">
                <!-- Left links -->
                <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                    <li class="nav-item">
                        <a class="nav-link active" href="index.php">Home</a>
                    </li>
                    <!-- Products dropdown -->
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="productsDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            Products
                        </a>
                        <ul class="dropdown-menu" aria-labelledby="productsDropdown">
                            <li><a class="dropdown-item" href="products.php?category=electronics">Electronics</a></li>
                            <li><a class="dropdown-item" href="products.php?category=fashion">Fashion</a></li>
                            <li><a class="dropdown-item" href="products.php?category=home">Home & Living</a></li>
                        </ul>
                    </li>
                </ul>

                <!-- Search bar -->
                <form class="d-flex me-3" role="search" action="products.php" method="GET">
                    <input class="form-control me-2" type="search" name="search" placeholder="Search products..." aria-label="Search">
                    <button class="btn btn-light" type="submit"><i class="bi bi-search"></i></button>
                </form>

                <!-- Right links (login/profile/cart) -->
                <ul class="navbar-nav mb-2 mb-lg-0">
                    <?php if(isset($_SESSION['UserId'])): ?>
                        <li class="nav-item">
                            <a class="nav-link" href="profile.php"><i class="bi bi-person-circle"></i> Profile</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="cart.php"><i class="bi bi-cart"></i> Cart</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="logout.php">Logout</a>
                        </li>
                    <?php else: ?>
                        <li class="nav-item">
                            <a class="nav-link" href="member_login.php">Login</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="register.php">Register</a>
                        </li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </nav>
</body>
</html>