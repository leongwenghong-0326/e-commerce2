<?php

interface DiscountInterface
{
    public function applyDiscount($originalPrice);
}

class PercentageDiscount implements DiscountInterface
{
    private float $percentage;

    public function __construct(float $percentage)
    {
        $this->percentage = max(0, min(100, $percentage));
    }

    public function applyDiscount($originalPrice)
    {
        $price = max(0, (float) $originalPrice);
        $discountValue = $price * ($this->percentage / 100);

        return max(0, $price - $discountValue);
    }
}

class FlatDiscount implements DiscountInterface
{
    private float $amount;

    public function __construct(float $amount)
    {
        $this->amount = max(0, $amount);
    }

    public function applyDiscount($originalPrice)
    {
        $price = max(0, (float) $originalPrice);

        return max(0, $price - $this->amount);
    }
}

class NoDiscount implements DiscountInterface
{
    public function applyDiscount($originalPrice)
    {
        return max(0, (float) $originalPrice);
    }
}

class PriceCalculator
{
    private DiscountInterface $discount;

    public function __construct(DiscountInterface $discount)
    {
        $this->discount = $discount;
    }

    public function setDiscount(DiscountInterface $discount): void
    {
        $this->discount = $discount;
    }

    public function calculateFinalPrice(float $originalPrice): float
    {
        return round($this->discount->applyDiscount($originalPrice), 2);
    }
}

function getDiscountOptions(): array
{
    return [
        'none' => [
            'label' => 'No Discount',
            'type' => 'none',
            'value' => 0,
        ],
        'percentage_10' => [
            'label' => '10% Store-wide Discount',
            'type' => 'percentage',
            'value' => 10,
        ],
        'flat_50' => [
            'label' => 'Flat RM 50 Discount',
            'type' => 'flat',
            'value' => 50,
        ],
    ];
}

function getDefaultDiscountPriority(string $discountKey): int
{
    if ($discountKey === 'percentage_10') {
        return 10;
    }
    if ($discountKey === 'flat_50') {
        return 20;
    }

    return 999;
}

function getDefaultDiscountMinSubtotal(string $discountKey): float
{
    if ($discountKey === 'flat_50') {
        return 200.0;
    }

    return 0.0;
}

function getDiscountContextByKey(string $discountKey): array
{
    $options = getDiscountOptions();
    $safeKey = array_key_exists($discountKey, $options) ? $discountKey : 'none';
    $option = $options[$safeKey];

    if ($safeKey === 'percentage_10') {
        $strategy = new PercentageDiscount((float) $option['value']);
    } elseif ($safeKey === 'flat_50') {
        $strategy = new FlatDiscount((float) $option['value']);
    } else {
        $strategy = new NoDiscount();
    }

    return [
        'key' => $safeKey,
        'label' => (string) $option['label'],
        'type' => (string) $option['type'],
        'value' => (float) $option['value'],
        'strategy' => $strategy,
    ];
}

function ensureCampaignDiscountTable(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS CampaignDiscounts (
            CampaignId CHAR(36) NOT NULL,
            DiscountKey VARCHAR(50) NOT NULL,
            IsActive BOOLEAN NOT NULL DEFAULT FALSE,
            UpdatedDate DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (CampaignId),
            UNIQUE KEY uq_discount_key (DiscountKey),
            KEY idx_discount_key (DiscountKey)
        )"
    );

    // Safeguard for older drafts that may have created an invalid unique index on IsActive.
    $indexStmt = $pdo->prepare(
        "SELECT 1
         FROM INFORMATION_SCHEMA.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'CampaignDiscounts'
           AND INDEX_NAME = 'uq_active_discount'
         LIMIT 1"
    );
    $indexStmt->execute();
    if ((bool) $indexStmt->fetchColumn()) {
        $pdo->exec('ALTER TABLE CampaignDiscounts DROP INDEX uq_active_discount');
    }

    $priorityColumnStmt = $pdo->prepare(
        "SELECT 1
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'CampaignDiscounts'
           AND COLUMN_NAME = 'Priority'
         LIMIT 1"
    );
    $priorityColumnStmt->execute();
    if (!(bool) $priorityColumnStmt->fetchColumn()) {
        $pdo->exec('ALTER TABLE CampaignDiscounts ADD COLUMN Priority INT NOT NULL DEFAULT 100 AFTER IsActive');
    }

    $minSubtotalColumnStmt = $pdo->prepare(
        "SELECT 1
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'CampaignDiscounts'
           AND COLUMN_NAME = 'MinSubtotal'
         LIMIT 1"
    );
    $minSubtotalColumnStmt->execute();
    if (!(bool) $minSubtotalColumnStmt->fetchColumn()) {
        $pdo->exec('ALTER TABLE CampaignDiscounts ADD COLUMN MinSubtotal DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER Priority');
    }

    $options = getDiscountOptions();
    $seedStmt = $pdo->prepare(
           'INSERT INTO CampaignDiscounts (CampaignId, DiscountKey, IsActive, Priority, MinSubtotal, UpdatedDate)
            VALUES (UUID(), :discount_key, 0, :priority, :min_subtotal, NOW())
            ON DUPLICATE KEY UPDATE
               DiscountKey = VALUES(DiscountKey),
               Priority = COALESCE(Priority, VALUES(Priority)),
               MinSubtotal = COALESCE(MinSubtotal, VALUES(MinSubtotal))'
    );

    foreach (array_keys($options) as $discountKey) {
        $seedStmt->execute([
            ':discount_key' => $discountKey,
            ':priority' => getDefaultDiscountPriority($discountKey),
            ':min_subtotal' => number_format(getDefaultDiscountMinSubtotal($discountKey), 2, '.', ''),
        ]);
    }

    $priorityBackfillStmt = $pdo->prepare(
        'UPDATE CampaignDiscounts
         SET Priority = :priority
         WHERE DiscountKey = :discount_key AND (Priority IS NULL OR Priority <= 0)'
    );
    foreach (array_keys($options) as $discountKey) {
        $priorityBackfillStmt->execute([
            ':priority' => getDefaultDiscountPriority($discountKey),
            ':discount_key' => $discountKey,
        ]);
    }

    $minSubtotalBackfillStmt = $pdo->prepare(
        'UPDATE CampaignDiscounts
         SET MinSubtotal = :min_subtotal
         WHERE DiscountKey = :discount_key AND MinSubtotal < 0'
    );
    foreach (array_keys($options) as $discountKey) {
        $minSubtotalBackfillStmt->execute([
            ':min_subtotal' => number_format(getDefaultDiscountMinSubtotal($discountKey), 2, '.', ''),
            ':discount_key' => $discountKey,
        ]);
    }
}

function setDiscountKeyActiveState(PDO $pdo, string $discountKey, bool $isActive): void
{
    ensureCampaignDiscountTable($pdo);

    $context = getDiscountContextByKey($discountKey);
    $upsert = $pdo->prepare(
        'INSERT INTO CampaignDiscounts (CampaignId, DiscountKey, IsActive, Priority, MinSubtotal, UpdatedDate)
         VALUES (UUID(), :discount_key, :is_active, :priority, :min_subtotal, NOW())
         ON DUPLICATE KEY UPDATE
             IsActive = VALUES(IsActive),
             UpdatedDate = VALUES(UpdatedDate),
             Priority = COALESCE(Priority, VALUES(Priority)),
             MinSubtotal = COALESCE(MinSubtotal, VALUES(MinSubtotal))'
    );
    $upsert->execute([
        ':discount_key' => $context['key'],
        ':is_active' => $isActive ? 1 : 0,
        ':priority' => getDefaultDiscountPriority($context['key']),
        ':min_subtotal' => number_format(getDefaultDiscountMinSubtotal($context['key']), 2, '.', ''),
    ]);
}

function setDiscountPriority(PDO $pdo, string $discountKey, int $priority): void
{
    ensureCampaignDiscountTable($pdo);

    $context = getDiscountContextByKey($discountKey);
    $safePriority = max(1, min(9999, $priority));

    $stmt = $pdo->prepare(
        'UPDATE CampaignDiscounts
         SET Priority = :priority, UpdatedDate = NOW()
         WHERE DiscountKey = :discount_key
         LIMIT 1'
    );
    $stmt->execute([
        ':priority' => $safePriority,
        ':discount_key' => $context['key'],
    ]);
}

function setDiscountMinSubtotal(PDO $pdo, string $discountKey, float $minSubtotal): void
{
    ensureCampaignDiscountTable($pdo);

    $context = getDiscountContextByKey($discountKey);
    $safeMinSubtotal = max(0, round($minSubtotal, 2));

    $stmt = $pdo->prepare(
        'UPDATE CampaignDiscounts
         SET MinSubtotal = :min_subtotal, UpdatedDate = NOW()
         WHERE DiscountKey = :discount_key
         LIMIT 1'
    );
    $stmt->execute([
        ':min_subtotal' => number_format($safeMinSubtotal, 2, '.', ''),
        ':discount_key' => $context['key'],
    ]);
}

function getActiveDiscountContext(PDO $pdo): array
{
    $contexts = getActiveDiscountContexts($pdo);
    if (empty($contexts)) {
        return getDiscountContextByKey('none');
    }

    return $contexts[0];
}

function getActiveDiscountContexts(PDO $pdo): array
{
    ensureCampaignDiscountTable($pdo);

    $stmt = $pdo->query(
            'SELECT DiscountKey, Priority, MinSubtotal
         FROM CampaignDiscounts
         WHERE IsActive = 1
            ORDER BY Priority ASC, UpdatedDate DESC
         LIMIT 10'
    );
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $contexts = [];
    foreach ($rows as $row) {
        $discountKey = (string) ($row['DiscountKey'] ?? 'none');
        $context = getDiscountContextByKey($discountKey);
        $context['priority'] = (int) ($row['Priority'] ?? getDefaultDiscountPriority($discountKey));
        $context['min_subtotal'] = (float) ($row['MinSubtotal'] ?? getDefaultDiscountMinSubtotal($discountKey));
        $contexts[] = $context;
    }

    return $contexts;
}

function getEligibleDiscountContexts(float $subtotal, array $discountContexts): array
{
    $safeSubtotal = max(0, $subtotal);
    $eligible = [];

    foreach ($discountContexts as $context) {
        $minSubtotal = max(0, (float) ($context['min_subtotal'] ?? 0));
        if ($safeSubtotal >= $minSubtotal) {
            $eligible[] = $context;
        }
    }

    return $eligible;
}

function applyDiscountContexts(float $originalPrice, array $discountContexts): float
{
    $price = max(0, $originalPrice);
    $calculator = new PriceCalculator(new NoDiscount());

    foreach ($discountContexts as $context) {
        if (!isset($context['strategy']) || !($context['strategy'] instanceof DiscountInterface)) {
            continue;
        }
        $calculator->setDiscount($context['strategy']);
        $price = $calculator->calculateFinalPrice($price);
    }

    return round($price, 2);
}

function setActiveDiscountKey(PDO $pdo, string $discountKey): void
{
    ensureCampaignDiscountTable($pdo);

    $context = getDiscountContextByKey($discountKey);

    $pdo->beginTransaction();
    try {
        $pdo->exec('UPDATE CampaignDiscounts SET IsActive = 0');

        $upsert = $pdo->prepare(
            'INSERT INTO CampaignDiscounts (CampaignId, DiscountKey, IsActive, UpdatedDate)
             VALUES (UUID(), :discount_key, 1, NOW())
             ON DUPLICATE KEY UPDATE IsActive = VALUES(IsActive), UpdatedDate = VALUES(UpdatedDate)'
        );
        $upsert->execute([':discount_key' => $context['key']]);

        $activate = $pdo->prepare(
            'UPDATE CampaignDiscounts
             SET IsActive = 1, UpdatedDate = NOW()
             WHERE DiscountKey = :discount_key
             LIMIT 1'
        );
        $activate->execute([':discount_key' => $context['key']]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
