<?php
require_once 'config/config.php';

function hasProductColumn(PDO $pdo, string $columnName): bool
{
    static $cache = [];
    if (array_key_exists($columnName, $cache)) {
        return $cache[$columnName];
    }

    $stmt = $pdo->prepare(
        "SELECT 1
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'Products'
           AND COLUMN_NAME = ?
         LIMIT 1"
    );
    $stmt->execute([$columnName]);
    $cache[$columnName] = (bool) $stmt->fetchColumn();

    return $cache[$columnName];
}

function hasTable(PDO $pdo, string $tableName): bool
{
    static $cache = [];
    if (array_key_exists($tableName, $cache)) {
        return $cache[$tableName];
    }

    $stmt = $pdo->prepare(
        "SELECT 1
         FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
         LIMIT 1"
    );
    $stmt->execute([$tableName]);
    $cache[$tableName] = (bool) $stmt->fetchColumn();

    return $cache[$tableName];
}

$search = trim((string) ($_GET['q'] ?? ''));
$currentPage = max(1, (int) ($_GET['page'] ?? 1));
$itemsPerPage = 12;
$hasCategoryColumn = hasProductColumn($pdo, 'Category');
$hasCategoryIdColumn = hasProductColumn($pdo, 'CategoryId');
$hasCategoryTable = hasTable($pdo, 'category');
$supportsCategoryFeature = ($hasCategoryIdColumn && $hasCategoryTable) || $hasCategoryColumn;
$useCategoryJoin = $hasCategoryIdColumn && $hasCategoryTable;

$categoryFilter = trim((string) ($_GET['category_filter'] ?? 'all'));
if (!$supportsCategoryFeature) {
    $categoryFilter = 'all';
}
if ($categoryFilter === 'uncategorized') {
    $categoryFilter = 'all';
}

$minPriceRaw = trim((string) ($_GET['min_price'] ?? ''));
$maxPriceRaw = trim((string) ($_GET['max_price'] ?? ''));
$minPrice = ($minPriceRaw !== '' && is_numeric($minPriceRaw) && (float) $minPriceRaw >= 0)
    ? (float) $minPriceRaw
    : null;
$maxPrice = ($maxPriceRaw !== '' && is_numeric($maxPriceRaw) && (float) $maxPriceRaw >= 0)
    ? (float) $maxPriceRaw
    : null;
if ($minPrice !== null && $maxPrice !== null && $minPrice > $maxPrice) {
    [$minPrice, $maxPrice] = [$maxPrice, $minPrice];
}

$sortBy = strtolower(trim((string) ($_GET['sort_by'] ?? 'newest')));
$sortDir = strtolower(trim((string) ($_GET['sort_dir'] ?? 'desc')));
if (!in_array($sortDir, ['asc', 'desc'], true)) {
    $sortDir = 'desc';
}

$allowedSortColumns = [
    'newest' => 'p.CreateDate',
    'name' => 'p.ProductName',
    'price' => 'p.Price',
];
if ($supportsCategoryFeature) {
    $allowedSortColumns['category'] = 'CategoryName';
}
if (!isset($allowedSortColumns[$sortBy])) {
    $sortBy = 'newest';
}

$availableCategories = [];
if ($supportsCategoryFeature) {
    $categoryMap = [];

    if ($useCategoryJoin) {
        $tableCategoryStmt = $pdo->query("SELECT CategoryName FROM category ORDER BY CategoryName ASC");
        $tableCategories = $tableCategoryStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        foreach ($tableCategories as $tableCategory) {
            $tableCategory = trim((string) $tableCategory);
            if ($tableCategory === '') {
                continue;
            }

            $categoryMap[strtolower($tableCategory)] = $tableCategory;
        }
    }

    if ($hasCategoryColumn) {
        $catStmt = $pdo->query("SELECT DISTINCT TRIM(Category) AS CategoryName FROM Products WHERE Category IS NOT NULL AND TRIM(Category) <> '' ORDER BY TRIM(Category) ASC");
        $rawCategories = $catStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        foreach ($rawCategories as $rawCategory) {
            $category = trim((string) $rawCategory);
            if ($category === '') {
                continue;
            }

            $key = strtolower($category);
            if ($key === 'electronic' || $key === 'electronics') {
                $category = 'Electronic';
                $key = 'electronic';
            }

            if (!isset($categoryMap[$key])) {
                $categoryMap[$key] = $category;
            }
        }
    }
    $availableCategories = array_values($categoryMap);
    natcasesort($availableCategories);
    $availableCategories = array_values($availableCategories);

    if ($categoryFilter !== 'all' && !in_array($categoryFilter, $availableCategories, true)) {
        $categoryFilter = 'all';
    }
}

$listSql = "SELECT
            p.ProductId,
            p.ProductName,
            p.Description,
            p.Price,
            p.StockQuantity,
            p.CreateDate,
                " . ($supportsCategoryFeature
                ? ($useCategoryJoin
                    ? "COALESCE(NULLIF(TRIM(c.CategoryName), ''), 'Uncategorized')"
                    : "CASE
                            WHEN p.Category IS NULL OR TRIM(p.Category) = '' THEN 'Uncategorized'
                            WHEN LOWER(TRIM(p.Category)) IN ('electronic', 'electronics') THEN 'Electronic'
                            ELSE TRIM(p.Category)
                       END")
                : "'Uncategorized'") . " AS CategoryName,
            " . ($useCategoryJoin ? "c.CategoryIcon" : "NULL") . " AS CategoryIcon,
            (
                SELECT ImageUrl
                FROM ProductImages pi
                WHERE pi.ProductId = p.ProductId
                ORDER BY pi.IsPrimary DESC, pi.ImageId DESC
                LIMIT 1
            ) AS PrimaryImage
            FROM Products p
            " . ($useCategoryJoin ? "LEFT JOIN category c ON c.CategoryId = p.CategoryId" : "") . "
            WHERE 1=1";

$countSql = "SELECT COUNT(*) FROM Products p
            " . ($useCategoryJoin ? "LEFT JOIN category c ON c.CategoryId = p.CategoryId" : "") . "
            WHERE 1=1";
$params = [];

if ($search !== '') {
    $listSql .= " AND (
        p.ProductName LIKE :kw_name
        OR p.Description LIKE :kw_desc
    )";
    $countSql .= " AND (
        p.ProductName LIKE :kw_name
        OR p.Description LIKE :kw_desc
    )";
    $keyword = '%' . $search . '%';
    $params[':kw_name'] = $keyword;
    $params[':kw_desc'] = $keyword;
}

if ($minPrice !== null) {
    $listSql .= ' AND p.Price >= :min_price';
    $countSql .= ' AND p.Price >= :min_price';
    $params[':min_price'] = number_format($minPrice, 2, '.', '');
}
if ($maxPrice !== null) {
    $listSql .= ' AND p.Price <= :max_price';
    $countSql .= ' AND p.Price <= :max_price';
    $params[':max_price'] = number_format($maxPrice, 2, '.', '');
}

if ($supportsCategoryFeature && $categoryFilter !== 'all') {
    if ($useCategoryJoin) {
        $listSql .= " AND COALESCE(NULLIF(TRIM(c.CategoryName), ''), 'Uncategorized') = :category_filter";
        $countSql .= " AND COALESCE(NULLIF(TRIM(c.CategoryName), ''), 'Uncategorized') = :category_filter";
        $params[':category_filter'] = $categoryFilter;
    } else {
        if ($categoryFilter === 'Electronic') {
            $listSql .= " AND LOWER(TRIM(p.Category)) IN ('electronic', 'electronics')";
            $countSql .= " AND LOWER(TRIM(p.Category)) IN ('electronic', 'electronics')";
        } else {
            $listSql .= ' AND p.Category = :category_filter';
            $countSql .= ' AND p.Category = :category_filter';
            $params[':category_filter'] = $categoryFilter;
        }
    }
}

$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalResults = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalResults / $itemsPerPage));
$currentPage = min($currentPage, $totalPages);
$offset = ($currentPage - 1) * $itemsPerPage;

$listSql .= ' ORDER BY ' . $allowedSortColumns[$sortBy] . ' ' . strtoupper($sortDir) . ' LIMIT ' . $itemsPerPage . ' OFFSET ' . $offset;
$listStmt = $pdo->prepare($listSql);
$listStmt->execute($params);
$products = $listStmt->fetchAll(PDO::FETCH_ASSOC);

$buildUrl = function (array $overrides = []) use ($search, $sortBy, $sortDir, $minPriceRaw, $maxPriceRaw, $categoryFilter): string {
    $query = [
        'q' => $search,
        'category_filter' => $categoryFilter,
        'sort_by' => $sortBy,
        'sort_dir' => $sortDir,
        'min_price' => $minPriceRaw,
        'max_price' => $maxPriceRaw,
    ];
    $query = array_merge($query, $overrides);

    if (($query['q'] ?? '') === '') {
        unset($query['q']);
    }
    if (($query['category_filter'] ?? 'all') === 'all') {
        unset($query['category_filter']);
    }
    if (($query['sort_by'] ?? 'newest') === 'newest') {
        unset($query['sort_by']);
    }
    if (($query['sort_dir'] ?? 'desc') === 'desc') {
        unset($query['sort_dir']);
    }
    if (($query['min_price'] ?? '') === '') {
        unset($query['min_price']);
    }
    if (($query['max_price'] ?? '') === '') {
        unset($query['max_price']);
    }
    if (($query['page'] ?? null) === 1) {
        unset($query['page']);
    }

    return 'products.php' . (!empty($query) ? '?' . http_build_query($query) : '');
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>E-commerce | Products</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet">
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

        .page-shell {
            max-width: 1200px;
            margin: 24px auto 40px;
            padding: 0 14px;
        }

        .top-panel,
        .empty {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 16px;
            backdrop-filter: blur(8px);
            box-shadow: 0 14px 30px rgba(10, 36, 60, 0.08);
        }

        .top-panel {
            padding: 16px;
            margin-bottom: 14px;
        }

        .title {
            margin: 0;
            font-family: 'Space Grotesk', sans-serif;
            font-weight: 700;
            font-size: clamp(1.35rem, 3vw, 2rem);
            letter-spacing: -0.012em;
        }

        .form-control,
        .form-select {
            border: 1px solid rgba(27, 37, 48, 0.2);
            border-radius: 12px;
            min-height: 44px;
            background: rgba(255, 255, 255, 0.92);
            font-size: 14px;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: rgba(15, 143, 111, 0.75);
            box-shadow: 0 0 0 4px rgba(15, 143, 111, 0.15);
        }

        .btn-success {
            border: none;
            border-radius: 12px;
            background: linear-gradient(135deg, var(--accent), var(--accent-strong));
            font-weight: 700;
        }

        .btn-success:hover {
            box-shadow: 0 8px 16px rgba(11, 111, 86, 0.3);
        }

        .product-card {
            background: rgba(255, 255, 255, 0.92);
            border: 1px solid var(--line);
            border-radius: 16px;
            overflow: hidden;
            height: 100%;
            transition: transform 0.15s ease, box-shadow 0.15s ease;
        }

        .product-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 16px 30px rgba(12, 46, 76, 0.12);
        }

        .product-image {
            width: 100%;
            aspect-ratio: 4 / 3;
            object-fit: cover;
            background: #eef4f1;
            border-bottom: 1px solid var(--line);
        }

        .product-body {
            padding: 14px;
        }

        .name {
            font-weight: 700;
            font-size: 1rem;
            margin-bottom: 6px;
        }

        .desc {
            font-size: 13px;
            color: #607080;
            min-height: 40px;
            margin-bottom: 10px;
        }

        .price {
            font-weight: 700;
            font-size: 1.08rem;
            color: var(--accent-strong);
            margin-bottom: 10px;
        }

        .stock-note {
            font-size: 12px;
            color: #667788;
        }

        .stock-badge {
            display: inline-block;
            font-size: 11px;
            font-weight: 700;
            border-radius: 999px;
            padding: 4px 10px;
        }

        .stock-ok {
            color: #14633b;
            background: #dff4e8;
        }

        .stock-low {
            color: #8a5a00;
            background: #fff2d6;
        }

        .stock-out {
            color: #9c1f1f;
            background: #fce2e2;
        }

        .empty {
            text-align: center;
            padding: 36px 16px;
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
    </style>
</head>
<body>
<?php include 'layout/nav.php'; ?>

<div class="page-shell">
    <section class="top-panel">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
            <h1 class="title">Shop Products</h1>
            <span class="text-muted small"><?php echo $totalResults; ?> result(s)</span>
        </div>

        <form method="get" action="products.php" class="row g-2">
            <div class="col-12 col-lg-3">
                <input type="text" class="form-control" name="q" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search by product name or description">
            </div>
            <?php if ($supportsCategoryFeature): ?>
                <div class="col-6 col-lg-2">
                    <select class="form-select" name="category_filter">
                        <option value="all" <?php echo $categoryFilter === 'all' ? 'selected' : ''; ?>>All Categories</option>
                        <?php foreach ($availableCategories as $availableCategory): ?>
                            <option value="<?php echo htmlspecialchars((string) $availableCategory); ?>" <?php echo $categoryFilter === (string) $availableCategory ? 'selected' : ''; ?>><?php echo htmlspecialchars((string) $availableCategory); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
            <div class="col-6 col-lg-2">
                <input type="number" class="form-control" step="0.01" min="0" name="min_price" value="<?php echo htmlspecialchars($minPriceRaw); ?>" placeholder="Min RM">
            </div>
            <div class="col-6 col-lg-2">
                <input type="number" class="form-control" step="0.01" min="0" name="max_price" value="<?php echo htmlspecialchars($maxPriceRaw); ?>" placeholder="Max RM">
            </div>
            <div class="col-6 col-lg-2">
                <select class="form-select" name="sort_by">
                    <option value="newest" <?php echo $sortBy === 'newest' ? 'selected' : ''; ?>>Newest</option>
                    <option value="name" <?php echo $sortBy === 'name' ? 'selected' : ''; ?>>Name</option>
                    <?php if ($supportsCategoryFeature): ?>
                        <option value="category" <?php echo $sortBy === 'category' ? 'selected' : ''; ?>>Category</option>
                    <?php endif; ?>
                    <option value="price" <?php echo $sortBy === 'price' ? 'selected' : ''; ?>>Price</option>
                </select>
            </div>
            <div class="col-6 col-lg-1">
                <select class="form-select" name="sort_dir">
                    <option value="desc" <?php echo $sortDir === 'desc' ? 'selected' : ''; ?>>Desc</option>
                    <option value="asc" <?php echo $sortDir === 'asc' ? 'selected' : ''; ?>>Asc</option>
                </select>
            </div>
            <div class="col-12 col-lg-1 d-grid">
                <button class="btn btn-success" type="submit">Go</button>
            </div>
        </form>
    </section>

    <?php if (empty($products)): ?>
        <div class="empty">
            <h2 class="h5 mb-2">No products found</h2>
            <p class="text-muted mb-0">Try changing your search keywords or filters.</p>
        </div>
    <?php else: ?>
        <div class="row g-3">
            <?php foreach ($products as $product): ?>
                <?php
                $image = trim((string) ($product['PrimaryImage'] ?? ''));
                $imageSrc = $image !== '' ? $image : 'asset/image/default_avatar.png';
                $stock = max(0, (int) ($product['StockQuantity'] ?? 0));
                $desc = trim((string) ($product['Description'] ?? ''));
                if ($desc === '') {
                    $desc = 'No description available.';
                }
                if (mb_strlen($desc) > 95) {
                    $desc = mb_substr($desc, 0, 92) . '...';
                }

                $stockBadgeClass = 'stock-ok';
                $stockLabel = 'In stock';
                if ($stock <= 0) {
                    $stockBadgeClass = 'stock-out';
                    $stockLabel = 'Out of stock';
                } elseif ($stock <= 5) {
                    $stockBadgeClass = 'stock-low';
                    $stockLabel = 'Low stock';
                }
                ?>
                <div class="col-12 col-sm-6 col-lg-3">
                    <article class="product-card">
                        <a href="product_detail.php?id=<?php echo urlencode((string) $product['ProductId']); ?>">
                            <img class="product-image" src="<?php echo htmlspecialchars($imageSrc); ?>" alt="<?php echo htmlspecialchars((string) $product['ProductName']); ?>">
                        </a>
                        <div class="product-body">
                            <div class="name"><?php echo htmlspecialchars((string) $product['ProductName']); ?></div>
                            <div class="stock-note mb-1 d-flex align-items-center gap-2">
                                <?php if (!empty($product['CategoryIcon'])): ?>
                                    <img src="<?php echo htmlspecialchars((string) $product['CategoryIcon']); ?>" alt="Category icon" style="width:18px;height:18px;object-fit:cover;border-radius:4px;border:1px solid rgba(27,37,48,0.15);">
                                <?php endif; ?>
                                <span>Category: <?php echo htmlspecialchars((string) ($product['CategoryName'] ?? 'Uncategorized')); ?></span>
                            </div>
                            <div class="desc"><?php echo htmlspecialchars($desc); ?></div>
                            <div class="price">RM <?php echo number_format((float) $product['Price'], 2); ?></div>
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <div class="stock-note">Stock: <?php echo $stock; ?></div>
                                <span class="stock-badge <?php echo $stockBadgeClass; ?>"><?php echo $stockLabel; ?></span>
                            </div>

                            <div class="d-grid gap-2">
                                <a class="btn btn-outline-primary btn-sm" href="product_detail.php?id=<?php echo urlencode((string) $product['ProductId']); ?>">View Details</a>
                                <a class="btn btn-success btn-sm <?php echo $stock <= 0 ? 'disabled' : ''; ?>" href="cart.php?add=<?php echo urlencode((string) $product['ProductId']); ?>&qty=1">Add to Cart</a>
                            </div>
                        </div>
                    </article>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if ($totalPages > 1): ?>
            <nav class="mt-4">
                <ul class="pagination justify-content-center mb-0">
                    <?php for ($page = 1; $page <= $totalPages; $page++): ?>
                        <li class="page-item <?php echo $page === $currentPage ? 'active' : ''; ?>">
                            <a class="page-link" href="<?php echo htmlspecialchars($buildUrl(['page' => $page])); ?>"><?php echo $page; ?></a>
                        </li>
                    <?php endfor; ?>
                </ul>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
