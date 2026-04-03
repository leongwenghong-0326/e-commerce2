<?php
require_once 'config/config.php';

$categoryCards = [];
try {
    $categorySql = "SELECT
                        c.CategoryId,
                        c.CategoryName,
                        c.CategoryIcon,
                        COALESCE(COUNT(p.ProductId), 0) AS TotalProducts
                    FROM category c
                    LEFT JOIN Products p ON p.CategoryId = c.CategoryId
                    GROUP BY c.CategoryId, c.CategoryName, c.CategoryIcon
                    ORDER BY c.CategoryName ASC";
    $categoryStmt = $pdo->query($categorySql);
    $categoryCards = $categoryStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $categoryCards = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>E-commerce | Home</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&family=Space+Grotesk:wght@600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="asset/css/member-theme.css">
    <link rel="stylesheet" href="asset/css/member-index.css">
    
</head>
<body>
<?php include 'layout/nav.php'; ?>

<!-- HERO -->
<section class="hero">
    <div class="container">
        <div class="row align-items-center g-5">
            <div class="col-lg-6">
                <span class="hero-pill">New arrivals every week</span>
                <h1>Shop the things you <span>love,</span> delivered fast.</h1>
                <p class="lead mt-3 mb-4">Discover thousands of products at unbeatable prices. Free shipping on orders over RM 50.</p>
            </div>
            <div class="col-lg-6 d-none d-lg-block">
                <div class="hero-img-wrap text-center">
                    <div class="hero-emoji"><i class="bi bi-bag-heart-fill" aria-hidden="true"></i></div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- INTRODUCTION -->
<section class="intro-section">
    <div class="container">
        <div class="row g-3 g-lg-4 align-items-stretch">
            <div class="col-lg-8">
                <div class="intro-panel">
                    <span class="section-label mb-2">Why Shop With Us</span>
                    <h2 class="intro-title">A modern and dependable online shopping experience, built for everyday convenience.</h2>
                    <p class="intro-text">
                        E-commerce is designed to deliver a reliable digital marketplace with carefully selected products,
                        transparent pricing, and a smooth purchase journey. From product discovery to secure checkout,
                        every step is optimized to help you shop with confidence.
                    </p>
                    <p class="intro-text mt-3">
                        Our team focuses on fast fulfillment, accurate stock visibility, and responsive customer support,
                        so you can enjoy a consistent experience every time you place an order.
                    </p>
                    <ul class="intro-points">
                        <li><i class="bi bi-patch-check"></i>Trusted product quality</li>
                        <li><i class="bi bi-truck"></i>Fast and reliable delivery</li>
                        <li><i class="bi bi-shield-lock"></i>Safe and secure checkout</li>
                    </ul>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="intro-panel">
                    <div class="intro-metrics">
                        <div class="intro-metric">
                            <span class="intro-metric-value">10K+</span>
                            <span class="intro-metric-label">Curated products available</span>
                        </div>
                        <div class="intro-metric">
                            <span class="intro-metric-value">Live</span>
                            <span class="intro-metric-label">Real-time stock visibility</span>
                        </div>
                        <div class="intro-metric">
                            <span class="intro-metric-value">Reliable</span>
                            <span class="intro-metric-label">Fulfillment and delivery flow</span>
                        </div>
                        <div class="intro-metric">
                            <span class="intro-metric-value">Protected</span>
                            <span class="intro-metric-label">Secure checkout standards</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- CATEGORIES -->
<section class="category-showcase" id="categories">
    <div class="container">
        <div class="d-flex justify-content-between align-items-end mb-3 flex-wrap gap-2 category-showcase-head">
            <div>
                <span class="section-label">Browse by Category</span>
                <h2 class="section-title mb-0">Shop Categories</h2>
            </div>
            <a href="products.php" class="btn btn-outline-custom">
                <i class="bi bi-arrow-right"></i> View Products
            </a>
        </div>

        <?php if (empty($categoryCards)): ?>
            <div class="alert alert-light border" role="alert">No categories available yet.</div>
        <?php else: ?>
            <div class="row g-3">
                <?php foreach ($categoryCards as $cardIndex => $categoryCard): ?>
                    <?php
                    $iconPath = trim((string) ($categoryCard['CategoryIcon'] ?? ''));
                    $totalProducts = (int) ($categoryCard['TotalProducts'] ?? 0);
                    ?>
                    <div class="col-12 col-sm-6 col-lg-4 col-xl-3">
                        <a class="card category-card variant-<?php echo (int) ($cardIndex % 6); ?> text-decoration-none text-reset" data-card-order="<?php echo (int) $cardIndex; ?>" href="products.php?category_filter=<?php echo urlencode((string) ($categoryCard['CategoryName'] ?? '')); ?>">
                            <div class="card-body d-flex gap-3 align-items-center">
                                <?php if ($iconPath !== ''): ?>
                                    <img src="<?php echo htmlspecialchars($iconPath); ?>" alt="Category icon" class="category-icon">
                                <?php else: ?>
                                    <span class="category-icon"><i class="bi bi-tags"></i></span>
                                <?php endif; ?>
                                <div>
                                    <h5 class="card-title mb-1"><?php echo htmlspecialchars((string) ($categoryCard['CategoryName'] ?? 'Unnamed')); ?></h5>
                                    <p class="card-text text-muted mb-0"><?php echo $totalProducts; ?> related product<?php echo $totalProducts === 1 ? '' : 's'; ?></p>
                                </div>
                            </div>
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>

<!-- FOOTER -->
<footer class="site-footer">
    <div class="container">
        <div class="row g-4 g-xl-5">
            <div class="col-6 col-md-3 col-xl-2">
                <div class="footer-heading">Shop</div>
                <ul class="footer-links">
                    <li><a href="index.php#">Drinks</a></li>
                    <li><a href="index.php#">Gift Cards</a></li>
                    <li><a href="index.php#">Store Locator</a></li>
                    <li><a href="index.php#">Refer a Friend</a></li>
                </ul>
            </div>
            <div class="col-6 col-md-3 col-xl-2">
                <div class="footer-heading">Help</div>
                <ul class="footer-links">
                    <li><a href="index.php#">Contact Us</a></li>
                    <li><a href="index.php#">FAQ</a></li>
                    <li><a href="index.php#">Accessibility</a></li>
                </ul>
            </div>
            <div class="col-12 col-md-3 col-xl-2">
                <div class="footer-heading">About</div>
                <ul class="footer-links">
                    <li><a href="index.php#">Our Story</a></li>
                    <li><a href="index.php#">Digest</a></li>
                    <li><a href="index.php#">Ingredients</a></li>
                    <li><a href="index.php#">Wholesale</a></li>
                    <li><a href="index.php#">Careers</a></li>
                </ul>
            </div>
            <div class="col-12 col-md-9 col-xl-6 ms-xl-auto">
                <div class="footer-subscribe-copy">Sign up to get 10% off your first order</div>
                <form class="footer-subscribe" action="#" onsubmit="return false;">
                    <input type="email" class="form-control footer-input" placeholder="Your Email Address" aria-label="Your Email Address">
                    <button class="btn btn-footer-subscribe" type="button">Subscribe</button>
                </form>
                <div class="footer-social" aria-label="Social links">
                    <a href="index.php#" aria-label="Instagram"><i class="fab fa-instagram"></i></a>
                    <a href="index.php#" aria-label="Facebook"><i class="fab fa-facebook-f"></i></a>
                    <a href="index.php#" aria-label="Twitter"><i class="fab fa-twitter"></i></a>
                    <a href="index.php#" aria-label="LinkedIn"><i class="fab fa-linkedin-in"></i></a>
                </div>
            </div>
        </div>
        <div class="footer-bottom d-flex flex-wrap justify-content-between align-items-center gap-2">
            <span>&copy; <?php echo date('Y'); ?> ShopSphere. All rights reserved.</span>
            <div class="footer-legal">
                <a href="index.php#">Terms of Service</a>
                <a href="index.php#">Privacy Policy</a>
                <a href="index.php#">Do Not Sell My Information</a>
            </div>
        </div>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    document.querySelectorAll('.category-card[data-card-order]').forEach(function (card) {
        var order = parseInt(card.getAttribute('data-card-order'), 10);
        if (!Number.isNaN(order)) {
            card.style.setProperty('--i', String(order));
        }
    });
</script>
</body>
</html>
