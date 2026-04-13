<?php
require_once 'discount_system.php';

$originalPrice = 500.00;

$calculator = new PriceCalculator(new NoDiscount());

$scenarios = [
    [
        'label' => 'No Discount',
        'strategy' => new NoDiscount(),
    ],
    [
        'label' => '10% Store-wide Discount',
        'strategy' => new PercentageDiscount(10),
    ],
    [
        'label' => 'Flat RM 50 Discount',
        'strategy' => new FlatDiscount(50),
    ],
];

$results = [];
foreach ($scenarios as $scenario) {
    $calculator->setDiscount($scenario['strategy']);
    $results[] = [
        'label' => $scenario['label'],
        'finalPrice' => $calculator->calculateFinalPrice($originalPrice),
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Discount Strategy Demo</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 24px;
            background: #f5f6f8;
            color: #1f2937;
        }

        .card {
            max-width: 700px;
            background: #ffffff;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.08);
        }

        h1 {
            margin-top: 0;
            font-size: 1.5rem;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 12px;
        }

        th,
        td {
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
            padding: 10px 8px;
        }

        th {
            background: #f9fafb;
        }

        .note {
            margin-top: 12px;
            font-size: 0.92rem;
            color: #4b5563;
        }
    </style>
</head>
<body>
    <div class="card">
        <h1>Multi-Strategy Discount System</h1>
        <p>Original Price: <strong>RM <?php echo number_format($originalPrice, 2); ?></strong></p>

        <table>
            <thead>
                <tr>
                    <th>Discount Strategy</th>
                    <th>Final Price</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($results as $row): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($row['label']); ?></td>
                        <td>RM <?php echo number_format((float) $row['finalPrice'], 2); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <p class="note">
            The same <strong>PriceCalculator</strong> object is reused, and only its injected
            <strong>DiscountInterface</strong> strategy is swapped for each calculation.
        </p>
    </div>
</body>
</html>
