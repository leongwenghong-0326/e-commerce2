<?php
require_once 'config/config.php';

$perPage = 12;

function hasProductColumn(PDO $pdo, string $columnName): bool
{
    static $columnCache = [];
    if (array_key_exists($columnName, $columnCache)) return $columnCache[$columnName];
    $stmt = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'Products' AND COLUMN_NAME = ? LIMIT 1");
    $stmt->execute([$columnName]);
    $columnCache[$columnName] = (bool) $stmt->fetchColumn();
    return $columnCache[$columnName];
}

$hasCategoryColumn = hasProductColumn($pdo, 'Category');

$categoryExpr = $hasCategoryColumn
    ? "COALESCE(NULLIF(TRIM(p.Category), ''), 'Uncategorized')"
    : "CASE
        WHEN LOWER(p.ProductName) REGEXP 'phone|laptop|computer|headset' THEN 'Electronics'
        WHEN LOWER(p.ProductName) REGEXP 'shirt|shoe|dress|fashion' THEN 'Fashion'
        WHEN LOWER(p.ProductName) REGEXP 'beauty|cream|makeup' THEN 'Beauty'
        WHEN LOWER(p.ProductName) REGEXP 'home|kitchen|garden' THEN 'Home & Garden'
        WHEN LOWER(p.ProductName) REGEXP 'sport|fitness|yoga' THEN 'Sports'
        WHEN LOWER(p.ProductName) REGEXP 'book|magazine' THEN 'Books'
        ELSE 'Uncategorized'
       END";

$sql = "SELECT p.ProductId, p.ProductName, p.Description, p.Price, p.StockQuantity, p.CreateDate, $categoryExpr AS CategoryName, (SELECT ImageUrl FROM ProductImages pi WHERE pi.ProductId = p.ProductId ORDER BY pi.IsPrimary DESC, pi.ImageId DESC LIMIT 1) AS PrimaryImage FROM Products p ORDER BY p.CreateDate DESC LIMIT :limit";

$stmt = $pdo->prepare($sql);
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->execute();
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

$productsByCategory = [];
foreach ($products as $product) {
    $category = trim((string) ($product['CategoryName'] ?? 'Uncategorized'));
    if ($category === '') $category = 'Uncategorized';
    if (!isset($productsByCategory[$category])) $productsByCategory[$category] = [];
    $productsByCategory[$category][] = $product;
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

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: 'Outfit', sans-serif;
            color: var(--ink);
            background:
                radial-gradient(circle at 10% 15%, rgba(15, 143, 111, 0.22), transparent 40%),
                radial-gradient(circle at 90% 80%, rgba(39, 124, 198, 0.18), transparent 35%),
                linear-gradient(135deg, var(--bg-start), var(--bg-end));
        }

        .hero {
            padding: 84px 0 70px;
        }
        .hero-pill {
            display: inline-block;
            padding: 7px 12px;
            border-radius: 999px;
            font-size: 12px;
            letter-spacing: .08em;
            text-transform: uppercase;
            font-weight: 600;
            border: 1px solid var(--line);
            background: rgba(255, 255, 255, 0.6);
        }
        .hero h1 {
            margin: 14px 0 10px;
            font-family: 'Space Grotesk', sans-serif;
            font-size: clamp(2.15rem, 5vw, 3.35rem);
            font-weight: 700;
            line-height: 1.06;
            letter-spacing: -.02em;
            max-width: 13ch;
        }
        .hero h1 span { color: var(--accent); }
        .hero p.lead {
            max-width: 42ch;
            opacity: .88;
            font-size: 1.03rem;
            line-height: 1.6;
            margin-bottom: 26px;
        }

        .btn-primary-custom {
            border: none;
            border-radius: 12px;
            background: linear-gradient(135deg, var(--accent), var(--accent-strong));
            color: #fff;
            font-weight: 700;
            padding: 12px 24px;
            font-size: 15px;
            transition: transform 160ms ease, box-shadow 160ms ease;
        }
        .btn-primary-custom:hover {
            transform: translateY(-1px);
            box-shadow: 0 8px 16px rgba(11, 111, 86, 0.35);
            color: #fff;
        }
        .btn-outline-custom {
            border: 1px solid var(--line);
            border-radius: 12px;
            color: var(--ink);
            font-weight: 600;
            padding: 12px 24px;
            font-size: 15px;
            background: rgba(255, 255, 255, 0.65);
            transition: border-color 160ms ease, background 160ms ease;
        }
        .btn-outline-custom:hover {
            border-color: rgba(15, 143, 111, 0.75);
            background: rgba(15, 143, 111, 0.08);
            color: var(--accent-strong);
        }

        .hero-img-wrap {
            border-radius: 22px;
            border: 1px solid var(--line);
            background:
                linear-gradient(170deg, rgba(255, 255, 255, 0.38), rgba(255, 255, 255, 0.1)),
                repeating-linear-gradient(140deg, transparent, transparent 16px, rgba(27, 37, 48, 0.03) 16px, rgba(27, 37, 48, 0.03) 32px);
            box-shadow: 0 22px 48px rgba(10, 36, 60, 0.14);
            backdrop-filter: blur(10px);
            padding: 18px;
        }

        .section-label {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 999px;
            font-size: 12px;
            letter-spacing: .08em;
            text-transform: uppercase;
            font-weight: 700;
            border: 1px solid var(--line);
            background: rgba(255, 255, 255, 0.68);
            color: var(--accent-strong);
        }
        .section-title {
            margin-top: 8px;
            font-family: 'Space Grotesk', sans-serif;
            font-size: clamp(1.6rem, 3vw, 2.15rem);
            font-weight: 700;
            letter-spacing: -.018em;
            line-height: 1.1;
        }

        #categories {
            background: transparent !important;
            padding-top: 28px !important;
        }
        .browse-controls,
        .category-block {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 18px;
            backdrop-filter: blur(8px);
            box-shadow: 0 12px 32px rgba(10, 36, 60, 0.08);
        }
        .browse-controls {
            padding: 14px;
            margin-bottom: 16px;
        }
        .browse-controls .form-label {
            font-size: 12px;
            font-weight: 700;
            letter-spacing: .05em;
            text-transform: uppercase;
            color: #445564;
            margin-bottom: 5px;
        }
        .form-control,
        .form-select {
            border: 1px solid rgba(27, 37, 48, 0.2);
            border-radius: 12px;
            background: rgba(255, 255, 255, 0.9);
            min-height: 44px;
            font-size: 14px;
        }
        .form-control:focus,
        .form-select:focus {
            border-color: rgba(15, 143, 111, 0.75);
            box-shadow: 0 0 0 4px rgba(15, 143, 111, 0.15);
        }

        .category-chips {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 14px;
            position: sticky;
            top: 10px;
            z-index: 10;
            background: rgba(255,255,255,.94);
            border: 1px solid var(--line);
            border-radius: 14px;
            padding: 8px;
            backdrop-filter: blur(4px);
        }
        .category-chip {
            display: inline-flex;
            align-items: center;
            text-decoration: none;
            border: 1px solid var(--line);
            background: rgba(255,255,255,.88);
            color: #1f3845;
            border-radius: 999px;
            padding: 6px 12px;
            font-size: 12px;
            font-weight: 600;
            transition: all .15s ease;
        }
        .category-chip:hover {
            border-color: var(--accent);
            color: var(--accent-strong);
            background: rgba(15,143,111,.08);
        }
        .category-chip.active {
            border-color: var(--accent);
            background: linear-gradient(135deg, var(--accent), var(--accent-strong));
            color: #fff;
            box-shadow: 0 6px 16px rgba(11,111,86,.25);
        }

        .category-block {
            padding: 16px;
            margin-bottom: 16px;
        }
        .category-heading {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 1.2rem;
            font-weight: 700;
            margin: 0;
        }
        .category-meta {
            font-size: 12px;
            color: #5d6f7f;
        }
        .prod-card {
            background: rgba(255, 255, 255, 0.92);
            border: 1px solid var(--line);
            border-radius: 14px;
            overflow: hidden;
            height: 100%;
            transition: transform .18s ease, box-shadow .18s ease;
        }
        .prod-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 14px 24px rgba(10,36,60,.12);
        }
        .prod-img {
            width: 100%;
            aspect-ratio: 4 / 3;
            object-fit: cover;
            background: #eaf3ef;
            border-bottom: 1px solid var(--line);
        }
        .prod-body { padding: 12px; }
        .prod-name {
            font-size: .95rem;
            font-weight: 700;
            margin-bottom: 6px;
        }
        .prod-desc {
            font-size: 12.5px;
            color: #607182;
            min-height: 38px;
            margin-bottom: 8px;
        }
        .prod-price {
            font-weight: 700;
            margin-bottom: 8px;
            color: var(--accent-strong);
        }
        .stock-chip {
            display: inline-block;
            font-size: 11px;
            font-weight: 700;
            border-radius: 999px;
            padding: 4px 8px;
        }
        .stock-chip.ok { color: #1c6c41; background: #def4e7; }
        .stock-chip.low { color: #8a5a00; background: #fff2d6; }
        .stock-chip.out { color: #9c1f1f; background: #fce2e2; }

        .btn-success {
            border: none;
            background: linear-gradient(135deg, var(--accent), var(--accent-strong));
            border-radius: 12px;
            font-weight: 700;
        }
        .btn-success:hover {
            box-shadow: 0 8px 16px rgba(11,111,86,.3);
        }

        .pagination .page-link {
            border-radius: 10px;
            margin: 0 3px;
            border: 1px solid var(--line);
            color: #2a3f4f;
            background: rgba(255,255,255,.86);
        }
        .pagination .page-item.active .page-link {
            background: linear-gradient(135deg, var(--accent), var(--accent-strong));
            border-color: var(--accent);
            color: #fff;
        }

        .cta-banner {
            background: linear-gradient(135deg, #0f8f6f, #0b6f56);
            border-radius: 24px;
            padding: 52px 48px;
            color: #fff;
            position: relative;
            overflow: hidden;
            box-shadow: 0 18px 36px rgba(11, 111, 86, 0.28);
        }
        .cta-banner::before {
            content: '';
            position: absolute;
            width: 280px;
            height: 280px;
            border-radius: 50%;
            background: rgba(255,255,255,.06);
            top: -80px;
            right: -60px;
        }
        .cta-banner::after {
            content: '';
            position: absolute;
            width: 180px;
            height: 180px;
            border-radius: 50%;
            background: rgba(255,255,255,.05);
            bottom: -60px;
            left: 30px;
        }
        .cta-banner h2 {
            font-family:'Space Grotesk',sans-serif;
            font-weight: 800;
            font-size: clamp(1.6rem,3vw,2.2rem);
            letter-spacing: -.02em;
            margin-bottom: 10px;
        }
        .cta-banner p { opacity:.88; font-size:15px; max-width:44ch; margin-bottom:0; }
        .btn-cta-white {
            background: #fff;
            color: var(--accent-strong);
            border: none;
            border-radius: 12px;
            font-weight: 700;
            padding: 13px 28px;
            font-size: 15px;
            transition: transform .15s ease, box-shadow .15s ease;
            white-space: nowrap;
        }
        .btn-cta-white:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 18px rgba(0,0,0,.18);
            color: var(--accent-strong);
        }

        footer {
            background: #1b2530;
            color: rgba(255,255,255,.72);
            font-size: 14px;
            border-top: 1px solid rgba(255,255,255,.08);
        }
        footer a {
            color: rgba(255,255,255,.62);
            text-decoration: none;
        }
        footer a:hover { color: #fff; }
        .footer-brand {
            font-family: 'Space Grotesk',sans-serif;
            font-weight: 700;
            font-size: 1.2rem;
            color: #fff;
        }
        .footer-divider { border-color: rgba(255,255,255,.12); }

        @media (max-width: 992px) {
            .hero {
                padding: 64px 0 54px;
            }
        }
        @media (max-width: 768px) {
            .category-chips {
                top: 6px;
                margin-bottom: 12px;
            }
            .cta-banner {
                padding: 36px 24px;
            }
        }
    </style>
</head>
<body>
<?php include 'layout/nav.php'; ?>

<!-- ════════════════════ HERO ════════════════════ -->
<section class="hero">
    <div class="container">
        <div class="row align-items-center g-5">
            <div class="col-lg-6">
                <span class="hero-pill">✦ New arrivals every week</span>
                <h1>Shop the things you <span>love,</span> delivered fast.</h1>
                <p class="lead mt-3 mb-4">Discover thousands of products at unbeatable prices. Free shipping on orders over RM 50.</p>
                <div class="d-flex flex-wrap gap-3">
                    <a href="products.php" class="btn btn-primary-custom">
                        <i class="fas fa-shopping-bag me-2"></i>Shop Now
                    </a>
                    <a href="products.php" class="btn btn-outline-custom">Browse Products</a>
                </div>
            </div>
            <div class="col-lg-6 d-none d-lg-block">
                <div class="hero-img-wrap text-center">
                    <div style="font-size:11rem;line-height:1;filter:drop-shadow(0 18px 32px rgba(11,111,86,.20));">🛍️</div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ════════════════════ BROWSE PRODUCTS ════════════════════ -->
<section class="py-5" id="categories">
    <div class="container">
        <div class="d-flex justify-content-between align-items-end mb-3 flex-wrap gap-2">
            <div>
                <span class="section-label">New Arrivals</span>
                <h2 class="section-title mb-0">Featured Products</h2>
            </div>
            <a href="products.php" class="btn btn-outline-custom">
                <i class="bi bi-arrow-right"></i> View All
            </a>
        </div>

        <?php foreach ($productsByCategory as $categoryName => $categoryProducts): ?>
            <div class="category-block">
                <h3 class="category-heading"><?php echo htmlspecialchars($categoryName); ?></h3>
                <div class="row g-3">
                    <?php foreach ($categoryProducts as $product): ?>
                        <?php
                        $stock = (int) ($product['StockQuantity'] ?? 0);
                        $stockClass = $stock > 10 ? 'ok' : ($stock > 0 ? 'low' : 'out');
                        $stockText = $stock > 10 ? 'In Stock' : ($stock > 0 ? 'Limited Stock' : 'Out of Stock');
                        $image = trim((string) ($product['PrimaryImage'] ?? ''));
                        $imageSrc = $image !== '' ? $image : 'asset/image/default_avatar.png';
                        ?>
                        <div class="col-12 col-sm-6 col-lg-4 col-xl-3">
                            <div class="prod-card">
                                <img src="<?php echo htmlspecialchars($imageSrc); ?>" alt="<?php echo htmlspecialchars($product['ProductName']); ?>" class="prod-img">
                                <div class="prod-body">
                                    <h3 class="prod-name"><?php echo htmlspecialchars($product['ProductName']); ?></h3>
                                    <p class="prod-desc"><?php echo htmlspecialchars(substr($product['Description'] ?? '', 0, 70)); ?></p>
                                    <div class="prod-price">RM <?php echo number_format((float) $product['Price'], 2); ?></div>
                                    <span class="stock-chip <?php echo $stockClass; ?>"><?php echo htmlspecialchars($stockText); ?></span>
                                    <div class="mt-2">
                                        <a href="product_detail.php?id=<?php echo (int) $product['ProductId']; ?>" class="btn btn-success btn-sm w-100">
                                            <i class="bi bi-eye me-1"></i>View
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>

        <?php if (empty($productsByCategory)): ?>
            <div class="browse-controls text-center">
                <p class="text-muted">No products available yet. <a href="products.php">Browse all products</a></p>
            </div>
        <?php endif; ?>
    </div>
</section>

<!-- ════════════════════ CTA BANNER ════════════════════ -->
<section class="py-5">
    <div class="container py-2">
        <div class="cta-banner d-flex flex-column flex-md-row align-items-center justify-content-between gap-4">
            <div style="position:relative;z-index:1;">
                <h2 class="mb-2">Ready to start shopping?</h2>
                <p>Create a free account today and unlock exclusive member deals.</p>
            </div>
            <div class="d-flex gap-3 flex-shrink-0" style="position:relative;z-index:1;">
                <?php if (!isset($_SESSION['user_id'])): ?>
                    <a href="member_register.php" class="btn btn-cta-white">
                        Create Account <i class="fas fa-arrow-right ms-1"></i>
                    </a>
                <?php else: ?>
                    <a href="products.php" class="btn btn-cta-white">
                        Browse Products <i class="fas fa-arrow-right ms-1"></i>
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

<!-- ════════════════════ FOOTER ════════════════════ -->
<footer class="py-5 mt-3">
    <div class="container">
        <div class="row g-4 mb-4">
            <div class="col-md-4">
                <div class="footer-brand mb-2">🛍 E-commerce</div>
                <p style="font-size:13.5px;max-width:32ch;line-height:1.6;">Your one-stop shop for quality products at great prices, delivered right to your door.</p>
            </div>
            <div class="col-6 col-md-2">
                <div class="fw-semibold text-white mb-3" style="font-size:13px;letter-spacing:.04em;text-transform:uppercase;">Shop</div>
                <ul class="list-unstyled" style="font-size:13.5px;line-height:2;">
                    <li><a href="products.php">All Products</a></li>
                    <li><a href="products.php">Products</a></li>
                    <li><a href="cart.php">Cart</a></li>
                </ul>
            </div>
            <div class="col-6 col-md-2">
                <div class="fw-semibold text-white mb-3" style="font-size:13px;letter-spacing:.04em;text-transform:uppercase;">Account</div>
                <ul class="list-unstyled" style="font-size:13.5px;line-height:2;">
                    <li><a href="member_login.php">Login</a></li>
                    <li><a href="member_register.php">Register</a></li>
                    <li><a href="userProfile.php">My Profile</a></li>
                </ul>
            </div>
            <div class="col-md-4">
                <div class="fw-semibold text-white mb-3" style="font-size:13px;letter-spacing:.04em;text-transform:uppercase;">Stay Updated</div>
                <p style="font-size:13.5px;">Subscribe for deals and new arrivals.</p>
                <form class="d-flex gap-2" action="#" onsubmit="return false;">
                    <input type="email" class="form-control form-control-sm" placeholder="you@example.com" style="background:rgba(255,255,255,.08);border-color:rgba(255,255,255,.15);color:#fff;">
                    <button class="btn btn-sm btn-primary-custom flex-shrink-0" type="button">Subscribe</button>
                </form>
            </div>
        </div>
        <hr class="footer-divider">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2" style="font-size:12.5px;">
            <span>&copy; <?php echo date('Y'); ?> E-commerce. All rights reserved.</span>
        </div>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>