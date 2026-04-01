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

$sortBy = strtolower(trim((string) ($_GET['sort_by'] ?? 'desc')));
$sortDir = strtolower(trim((string) ($_GET['sort_dir'] ?? 'desc')));
if (!in_array($sortDir, ['asc', 'desc'], true)) {
    $sortDir = 'desc';
}

if ($sortBy === 'newest' || $sortBy === 'latest') {
    $sortBy = 'desc';
}
if ($sortBy === 'earliest') {
    $sortBy = 'asc';
}

if ($sortBy === 'desc') {
    $sortDir = 'desc';
} elseif ($sortBy === 'asc') {
    $sortDir = 'asc';
}

$allowedSortColumns = [
    'desc' => 'p.CreateDate',
    'asc' => 'p.CreateDate',
    'name' => 'p.ProductName',
    'price' => 'p.Price',
];
if ($supportsCategoryFeature) {
    $allowedSortColumns['category'] = 'CategoryName';
}
if (!isset($allowedSortColumns[$sortBy])) {
    $sortBy = 'desc';
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
    if (($query['sort_by'] ?? 'desc') === 'desc') {
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
        .page-shell {
            max-width: 1240px;
            margin: 24px auto 40px;
            padding: 0 12px;
        }

        .filter-card {
            border-radius: 14px;
            border: 1px solid rgba(27, 37, 48, 0.12);
            overflow: hidden;
            box-shadow: 0 10px 24px rgba(10, 36, 60, 0.08);
        }

        .filter-card .card-header {
            background: #fff;
            font-weight: 700;
        }

        .filter-form {
            display: grid;
            gap: 10px;
        }

        .title {
            margin: 0;
            font-family: 'Space Grotesk', sans-serif;
            font-weight: 700;
            font-size: clamp(1.2rem, 2.2vw, 1.6rem);
        }

        .product-card {
            border: 0;
            border-radius: 14px;
            overflow: hidden;
            box-shadow: 0 8px 18px rgba(10, 36, 60, 0.08);
            transition: transform 0.25s ease, box-shadow 0.25s ease;
        }

        .product-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 14px 28px rgba(10, 36, 60, 0.14);
        }

        .product-image {
            width: 100%;
            height: 210px;
            object-fit: cover;
            background: #f1f5f3;
        }

        .name {
            font-weight: 700;
            margin-bottom: 6px;
        }

        .price {
            font-weight: 700;
            color: #0b6f56;
            margin-bottom: 6px;
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
            border-radius: 14px;
            border: 1px dashed rgba(27, 37, 48, 0.2);
            background: rgba(255, 255, 255, 0.8);
            text-align: center;
            padding: 38px 16px;
        }
    </style>
</head>
<body>
<?php include 'layout/nav.php'; ?>

<div class="page-shell">
    <div class="row g-4">
        <aside class="col-12 col-lg-3">
            <div class="card filter-card">
                <div class="card-header">Filter</div>
                <div class="card-body">
                    <form method="get" action="products.php" class="filter-form">
                        <?php if ($supportsCategoryFeature): ?>
                            <select class="form-select" name="category_filter">
                                <option value="all" <?php echo $categoryFilter === 'all' ? 'selected' : ''; ?>>All Categories</option>
                                <?php foreach ($availableCategories as $availableCategory): ?>
                                    <option value="<?php echo htmlspecialchars((string) $availableCategory); ?>" <?php echo $categoryFilter === (string) $availableCategory ? 'selected' : ''; ?>><?php echo htmlspecialchars((string) $availableCategory); ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>

                        <div class="row g-2">
                            <div class="col-6">
                                <input type="number" class="form-control" step="0.01" min="0" name="min_price" value="<?php echo htmlspecialchars($minPriceRaw); ?>" placeholder="Min RM">
                            </div>
                            <div class="col-6">
                                <input type="number" class="form-control" step="0.01" min="0" name="max_price" value="<?php echo htmlspecialchars($maxPriceRaw); ?>" placeholder="Max RM">
                            </div>
                        </div>

                        <select class="form-select" name="sort_by">
                            <option value="desc" <?php echo $sortBy === 'desc' ? 'selected' : ''; ?>>Desc</option>
                            <option value="asc" <?php echo $sortBy === 'asc' ? 'selected' : ''; ?>>Asc</option>
                        </select>

                        <button class="btn btn-success w-100" type="submit">Apply</button>
                        <a class="btn btn-outline-secondary w-100" href="products.php">Reset</a>
                    </form>
                </div>
            </div>
        </aside>

        <main class="col-12 col-lg-9">
            <div class="d-flex justify-content-between align-items-center mb-3 gap-2 flex-wrap">
                <h1 class="title mb-0">All Products</h1>
                <span class="text-muted small"><?php echo $totalResults; ?> product(s)</span>
            </div>

            <?php if (empty($products)): ?>
                <div class="empty">
                    <i class="bi bi-search fs-1 text-muted"></i>
                    <h2 class="h5 mt-2 mb-1">No products found</h2>
                    <p class="text-muted mb-0">Try changing your search keywords or filters.</p>
                </div>
            <?php else: ?>
                <div class="row row-cols-1 row-cols-md-2 row-cols-xl-3 g-4">
                    <?php foreach ($products as $product): ?>
                        <?php
                        $image = trim((string) ($product['PrimaryImage'] ?? ''));
                        $imageSrc = $image !== '' ? $image : 'asset/image/default_avatar.png';
                        $stock = max(0, (int) ($product['StockQuantity'] ?? 0));

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
                        <div class="col">
                            <article class="card h-100 product-card">
                                <a href="product_detail.php?id=<?php echo urlencode((string) $product['ProductId']); ?>">
                                    <img class="product-image" src="<?php echo htmlspecialchars($imageSrc); ?>" alt="<?php echo htmlspecialchars((string) $product['ProductName']); ?>">
                                </a>
                                <div class="card-body">
                                    <div class="name text-truncate"><?php echo htmlspecialchars((string) $product['ProductName']); ?></div>
                                    <div class="small text-muted mb-1 d-flex align-items-center gap-2">
                                        <?php if (!empty($product['CategoryIcon'])): ?>
                                            <img src="<?php echo htmlspecialchars((string) $product['CategoryIcon']); ?>" alt="Category icon" style="width:18px;height:18px;object-fit:cover;border-radius:4px;border:1px solid rgba(27,37,48,0.15);">
                                        <?php endif; ?>
                                        <span><?php echo htmlspecialchars((string) ($product['CategoryName'] ?? 'Uncategorized')); ?></span>
                                    </div>
                                    <div class="price">RM <?php echo number_format((float) $product['Price'], 2); ?></div>
                                    <div class="d-flex justify-content-between align-items-center mb-3">
                                        <span class="text-muted small">Stock: <?php echo $stock; ?></span>
                                        <span class="stock-badge <?php echo $stockBadgeClass; ?>"><?php echo $stockLabel; ?></span>
                                    </div>
                                    <div class="d-grid gap-2">
                                        <a class="btn btn-outline-primary btn-sm" href="product_detail.php?id=<?php echo urlencode((string) $product['ProductId']); ?>">View Details</a>
                                        <a class="btn btn-success btn-sm <?php echo $stock <= 0 ? 'disabled' : ''; ?>" href="cart.php?add=<?php echo urlencode((string) $product['ProductId']); ?>&qty=1">Add to Cart</a>
                                        <a class="btn btn-primary btn-sm <?php echo $stock <= 0 ? 'disabled' : ''; ?>" href="cart.php?add=<?php echo urlencode((string) $product['ProductId']); ?>&qty=1&buy_now=1">Buy Now</a>
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
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
