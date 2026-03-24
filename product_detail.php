<?php
require_once 'config/config.php';

$productId = trim((string) ($_GET['id'] ?? ''));
if ($productId === '') {
    header('Location: products.php');
    exit();
}

$productStmt = $pdo->prepare(
    'SELECT ProductId, ProductName, Description, Price, StockQuantity, CreateDate
     FROM Products
     WHERE ProductId = :product_id
     LIMIT 1'
);
$productStmt->execute([':product_id' => $productId]);
$product = $productStmt->fetch(PDO::FETCH_ASSOC);

if (!$product) {
    header('Location: products.php');
    exit();
}

$imageStmt = $pdo->prepare(
    'SELECT ImageUrl, IsPrimary
     FROM ProductImages
     WHERE ProductId = :product_id
     ORDER BY IsPrimary DESC, ImageId DESC'
);
$imageStmt->execute([':product_id' => $productId]);
$images = $imageStmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($images)) {
    $images = [[
        'ImageUrl' => 'asset/image/default_avatar.png',
        'IsPrimary' => 1,
    ]];
}

$mainImage = (string) ($images[0]['ImageUrl'] ?? 'asset/image/default_avatar.png');
$stock = max(0, (int) ($product['StockQuantity'] ?? 0));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>E-commerce | <?php echo htmlspecialchars((string) $product['ProductName']); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
    <style>
        body {
            font-family: 'Outfit', sans-serif;
            background: #f8fbf9;
            color: #1b2530;
        }
        .page-shell {
            max-width: 1120px;
            margin: 24px auto 40px;
            padding: 0 14px;
        }
        .detail-card {
            background: #fff;
            border: 1px solid #e2ede9;
            border-radius: 16px;
            padding: 16px;
        }
        .main-image {
            width: 100%;
            border-radius: 14px;
            aspect-ratio: 4 / 3;
            object-fit: cover;
            border: 1px solid #e2ede9;
            background: #eef4f1;
        }
        .thumb-list {
            display: flex;
            gap: 8px;
            margin-top: 10px;
            flex-wrap: wrap;
        }
        .thumb {
            width: 70px;
            height: 70px;
            border-radius: 10px;
            border: 1px solid #d7e5de;
            object-fit: cover;
            cursor: pointer;
            background: #eef4f1;
        }
        .title {
            font-family: 'Space Grotesk', sans-serif;
            font-size: clamp(1.4rem, 3vw, 2rem);
            font-weight: 700;
            margin: 0 0 10px;
        }
        .price {
            font-size: 1.3rem;
            font-weight: 700;
            margin-bottom: 10px;
        }
        .desc {
            color: #5d6d7c;
            line-height: 1.6;
            margin-bottom: 14px;
            white-space: pre-line;
        }
    </style>
</head>
<body>
<?php include 'layout/nav.php'; ?>

<div class="page-shell">
    <div class="mb-3">
        <a href="products.php" class="btn btn-outline-success btn-sm"><i class="bi bi-arrow-left"></i> Back to Products</a>
    </div>

    <section class="detail-card">
        <div class="row g-4">
            <div class="col-12 col-lg-6">
                <img id="mainImage" class="main-image" src="<?php echo htmlspecialchars($mainImage); ?>" alt="<?php echo htmlspecialchars((string) $product['ProductName']); ?>">
                <?php if (count($images) > 1): ?>
                    <div class="thumb-list">
                        <?php foreach ($images as $img): ?>
                            <img class="thumb" src="<?php echo htmlspecialchars((string) $img['ImageUrl']); ?>" alt="Product thumbnail" onclick="document.getElementById('mainImage').src = this.src;">
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="col-12 col-lg-6">
                <h1 class="title"><?php echo htmlspecialchars((string) $product['ProductName']); ?></h1>
                <div class="price">RM <?php echo number_format((float) $product['Price'], 2); ?></div>
                <div class="mb-2 small text-muted">Stock: <?php echo $stock; ?></div>
                <p class="desc"><?php echo htmlspecialchars(trim((string) ($product['Description'] ?? '')) ?: 'No description available.'); ?></p>

                <form method="get" action="cart.php" class="row g-2 align-items-end">
                    <input type="hidden" name="add" value="<?php echo htmlspecialchars((string) $product['ProductId']); ?>">
                    <div class="col-6 col-md-4">
                        <label class="form-label" for="qty">Quantity</label>
                        <input id="qty" class="form-control" type="number" name="qty" min="1" max="<?php echo $stock; ?>" value="1" <?php echo $stock <= 0 ? 'disabled' : ''; ?>>
                    </div>
                    <div class="col-6 col-md-8 d-grid">
                        <button type="submit" class="btn btn-success" <?php echo $stock <= 0 ? 'disabled' : ''; ?>>
                            <i class="bi bi-cart-plus"></i> Add to Cart
                        </button>
                    </div>
                </form>

                <?php if ($stock <= 0): ?>
                    <div class="alert alert-warning mt-3 mb-0">This product is currently out of stock.</div>
                <?php endif; ?>
            </div>
        </div>
    </section>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
