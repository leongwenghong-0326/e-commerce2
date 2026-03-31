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

        .category-showcase {
            padding: 20px 0 10px;
        }

        .intro-section {
            padding: 10px 0 28px;
        }

        .intro-panel {
            border: 1px solid var(--line);
            border-radius: 20px;
            background: linear-gradient(160deg, rgba(255, 255, 255, 0.94), rgba(246, 253, 250, 0.9));
            box-shadow: 0 16px 30px rgba(10, 36, 60, 0.08);
            padding: 26px;
            height: 100%;
        }

        .intro-title {
            font-family: 'Space Grotesk', sans-serif;
            font-size: clamp(1.45rem, 2.5vw, 2rem);
            font-weight: 700;
            letter-spacing: -0.015em;
            line-height: 1.15;
            margin-bottom: 12px;
            color: #1f3845;
        }

        .intro-text {
            font-size: 0.97rem;
            color: #4e6578;
            line-height: 1.7;
            margin-bottom: 0;
            max-width: 58ch;
        }

        .intro-points {
            margin: 16px 0 0;
            padding: 0;
            list-style: none;
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 10px;
            max-width: 64ch;
        }

        .intro-points li {
            border: 1px solid rgba(15, 143, 111, 0.2);
            border-radius: 12px;
            background: rgba(255, 255, 255, 0.74);
            padding: 10px 12px;
            font-size: 0.88rem;
            font-weight: 600;
            color: #335061;
            display: flex;
            align-items: center;
            gap: 7px;
        }

        .intro-points i {
            color: var(--accent-strong);
            font-size: 0.95rem;
        }

        .intro-metrics {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
        }

        .intro-metric {
            border: 1px solid rgba(15, 143, 111, 0.2);
            border-radius: 14px;
            background: rgba(255, 255, 255, 0.75);
            padding: 14px;
        }

        .intro-metric-value {
            display: block;
            font-family: 'Space Grotesk', sans-serif;
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--accent-strong);
            line-height: 1.1;
        }

        .intro-metric-label {
            display: block;
            margin-top: 5px;
            font-size: 0.84rem;
            color: #627384;
        }

        .category-showcase-head {
            animation: fadeUp 520ms ease-out both;
        }

        .category-card {
            border: 1px solid var(--line);
            border-radius: 16px;
            background: rgba(255, 255, 255, 0.9);
            box-shadow: 0 12px 26px rgba(10, 36, 60, 0.08);
            height: 100%;
        }

        .category-icon {
            width: 56px;
            height: 56px;
            border-radius: 12px;
            border: 1px solid rgba(27, 37, 48, 0.14);
            object-fit: cover;
            background: #f3f8f5;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.3rem;
        }

        #categories {
            background: transparent !important;
            padding-top: 28px !important;
        }

        .category-showcase .section-title {
            margin-bottom: 0;
        }

        .category-card {
            border: 1px solid var(--line);
            border-radius: 16px;
            background: linear-gradient(180deg, rgba(255, 255, 255, 0.96), rgba(255, 255, 255, 0.88));
            box-shadow: 0 12px 26px rgba(10, 36, 60, 0.08);
            height: 100%;
            transition: transform 180ms ease, box-shadow 180ms ease, border-color 180ms ease;
            opacity: 0;
            transform: translateY(16px);
            animation: fadeUp 560ms ease-out both;
            animation-delay: calc(60ms * var(--i, 0));
        }

        .category-card.variant-0 {
            border-color: rgba(15, 143, 111, 0.35);
            background: linear-gradient(180deg, rgba(232, 250, 244, 0.92), rgba(255, 255, 255, 0.9));
        }

        .category-card.variant-1 {
            border-color: rgba(45, 118, 197, 0.35);
            background: linear-gradient(180deg, rgba(234, 243, 255, 0.92), rgba(255, 255, 255, 0.9));
        }

        .category-card.variant-2 {
            border-color: rgba(214, 112, 24, 0.35);
            background: linear-gradient(180deg, rgba(255, 245, 232, 0.92), rgba(255, 255, 255, 0.9));
        }

        .category-card.variant-3 {
            border-color: rgba(192, 82, 40, 0.35);
            background: linear-gradient(180deg, rgba(255, 239, 234, 0.92), rgba(255, 255, 255, 0.9));
        }

        .category-card.variant-4 {
            border-color: rgba(58, 138, 92, 0.35);
            background: linear-gradient(180deg, rgba(236, 248, 238, 0.92), rgba(255, 255, 255, 0.9));
        }

        .category-card.variant-5 {
            border-color: rgba(201, 146, 38, 0.35);
            background: linear-gradient(180deg, rgba(255, 249, 234, 0.92), rgba(255, 255, 255, 0.9));
        }

        .category-card:hover {
            transform: translateY(-3px);
            border-color: rgba(15, 143, 111, 0.45);
            box-shadow: 0 18px 30px rgba(10, 36, 60, 0.12);
        }

        .category-card .card-body {
            padding: 1rem;
        }

        .category-card .card-title {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 1rem;
            font-weight: 700;
            color: #1f3845;
        }

        .category-card .card-text {
            font-size: 0.86rem;
            color: #607182 !important;
        }

        @keyframes fadeUp {
            from {
                opacity: 0;
                transform: translateY(16px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
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

        .site-footer {
            position: relative;
            margin-top: 2.2rem;
            background:
                radial-gradient(circle at 85% 15%, rgba(39, 124, 198, 0.2), transparent 34%),
                linear-gradient(135deg, #102f2e 0%, #153b43 54%, #0b6f56 100%);
            color: rgba(255, 255, 255, 0.86);
            overflow: hidden;
        }
        .site-footer::before {
            content: '';
            position: absolute;
            top: -138px;
            left: -8%;
            width: 112%;
            height: 244px;
            background: var(--bg-start);
            border-bottom-left-radius: 70% 100%;
            border-bottom-right-radius: 38% 100%;
            transform: rotate(-2.1deg);
            z-index: 0;
        }
        .site-footer::after {
            content: '';
            position: absolute;
            top: -96px;
            left: 8%;
            width: 96%;
            height: 156px;
            background: var(--bg-start);
            border-bottom-left-radius: 36% 100%;
            border-bottom-right-radius: 64% 100%;
            transform: rotate(1.5deg);
            opacity: 0.95;
            z-index: 0;
        }
        .site-footer .container {
            position: relative;
            z-index: 1;
            padding-top: 7.4rem;
            padding-bottom: 1.45rem;
        }
        .footer-heading {
            color: #ffffff;
            font-size: 1.04rem;
            font-weight: 700;
            letter-spacing: 0.03em;
            margin-bottom: 0.75rem;
            text-transform: uppercase;
        }
        .footer-links {
            margin: 0;
            padding: 0;
            list-style: none;
        }
        .footer-links li {
            margin-bottom: 0.48rem;
        }
        .footer-links a {
            color: rgba(255, 255, 255, 0.84);
            text-decoration: none;
            font-size: 1.03rem;
            line-height: 1.45;
        }
        .footer-links a:hover {
            color: #ffffff;
            text-decoration: underline;
            text-decoration-color: rgba(255, 255, 255, 0.5);
            text-underline-offset: 0.18em;
        }
        .footer-subscribe-copy {
            font-family: 'Space Grotesk', sans-serif;
            font-size: clamp(1.35rem, 2.2vw, 1.8rem);
            font-weight: 700;
            line-height: 1.2;
            color: #ffffff;
            margin-bottom: 1rem;
        }
        .footer-subscribe {
            display: flex;
            gap: 0.7rem;
            align-items: center;
            flex-wrap: wrap;
        }
        .footer-input {
            min-height: 3.2rem;
            border: 0;
            border-radius: 999px;
            padding-inline: 1.25rem;
            min-width: min(100%, 415px);
            flex: 1 1 320px;
            font-size: 1.05rem;
            font-weight: 500;
            color: #173930;
            background: #f6fbf9;
        }
        .footer-input:focus {
            outline: none;
            box-shadow: 0 0 0 0.2rem rgba(15, 143, 111, 0.36);
        }
        .btn-footer-subscribe {
            border: 0;
            border-radius: 999px;
            min-height: 3.2rem;
            padding: 0.7rem 2rem;
            font-weight: 700;
            font-size: 1.06rem;
            color: #ffffff;
            background: linear-gradient(135deg, var(--accent), var(--accent-strong));
            white-space: nowrap;
        }
        .btn-footer-subscribe:hover {
            color: #ffffff;
            background: linear-gradient(135deg, #12a57f, #0f8f6f);
        }
        .footer-social {
            display: flex;
            gap: 0.85rem;
            margin-top: 1.1rem;
        }
        .footer-social a {
            width: 2.5rem;
            height: 2.5rem;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #ffffff;
            text-decoration: none;
            border: 1px solid rgba(255, 255, 255, 0.5);
            font-size: 1.14rem;
            transition: background 0.18s ease, transform 0.18s ease;
        }
        .footer-social a:hover {
            background: rgba(15, 143, 111, 0.55);
            transform: translateY(-1px);
        }
        .footer-bottom {
            margin-top: 2.4rem;
            border-top: 1px solid rgba(255, 255, 255, 0.2);
            padding-top: 1.1rem;
            color: rgba(255, 255, 255, 0.78);
            font-size: 0.96rem;
        }
        .footer-legal {
            display: flex;
            gap: 1.3rem;
            flex-wrap: wrap;
        }
        .footer-legal a {
            color: rgba(255, 255, 255, 0.7);
            text-decoration: none;
        }
        .footer-legal a:hover {
            color: #ffffff;
        }

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
            .intro-panel {
                padding: 20px;
            }
            .intro-metrics {
                grid-template-columns: 1fr;
            }
            .intro-points {
                grid-template-columns: 1fr;
            }
            .site-footer::before {
                top: -98px;
                left: -10%;
                width: 122%;
                height: 170px;
            }
            .site-footer::after {
                top: -68px;
                left: 4%;
                width: 104%;
                height: 108px;
            }
            .site-footer .container {
                padding-top: 5.8rem;
            }
            .footer-subscribe {
                align-items: stretch;
            }
            .footer-input,
            .btn-footer-subscribe {
                width: 100%;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            .category-showcase-head,
            .category-card {
                animation: none !important;
                opacity: 1;
                transform: none;
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
            </div>
            <div class="col-lg-6 d-none d-lg-block">
                <div class="hero-img-wrap text-center">
                    <div style="font-size:11rem;line-height:1;filter:drop-shadow(0 18px 32px rgba(11,111,86,.20));">🛍️</div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ════════════════════ INTRODUCTION ════════════════════ -->
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

<!-- ════════════════════ CATEGORIES ════════════════════ -->
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
                        <a class="card category-card variant-<?php echo (int) ($cardIndex % 6); ?> text-decoration-none text-reset" style="--i: <?php echo (int) $cardIndex; ?>;" href="products.php?category_filter=<?php echo urlencode((string) ($categoryCard['CategoryName'] ?? '')); ?>">
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

<!-- ════════════════════ FOOTER ════════════════════ -->
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
            <span>&copy; <?php echo date('Y'); ?> E-commerce. All rights reserved.</span>
            <div class="footer-legal">
                <a href="index.php#">Terms of Service</a>
                <a href="index.php#">Privacy Policy</a>
                <a href="index.php#">Do Not Sell My Information</a>
            </div>
        </div>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>