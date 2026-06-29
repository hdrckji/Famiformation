<?php
require __DIR__ . '/includes/bootstrap.php';

$orderId = (int) ($_GET['id'] ?? 0);
$order = null;
$items = [];

try {
    $pdo = get_pdo($config);
    $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = ? LIMIT 1');
    $stmt->execute([$orderId]);
    $order = $stmt->fetch();

    if ($order) {
        $itemStmt = $pdo->prepare('SELECT * FROM order_items WHERE order_id = ? ORDER BY product_name');
        $itemStmt->execute([$orderId]);
        $items = $itemStmt->fetchAll();
    }
} catch (Throwable $exception) {
    $order = null;
}
$assetVersion = (string) filemtime(__DIR__ . '/assets/css/style.css');
$logoVersion = (string) filemtime(__DIR__ . '/assets/logo.png');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Impression commande</title>
    <link rel="stylesheet" href="assets/css/style.css?v=<?= urlencode($assetVersion); ?>">
</head>
<body>
<div class="print-sheet">
    <?php if (!$order): ?>
        <div class="notice error">Commande introuvable.</div>
        <a class="button secondary" href="index.php">Retour à la boutique</a>
    <?php else: ?>
        <img src="assets/logo.png?v=<?= urlencode($logoVersion); ?>" alt="Famipyro" style="width:110px; display:block; margin:0 auto 8px;">
        <h1 style="text-align:center; margin-bottom:4px;">Préparation de commande</h1>
        <p class="small" style="text-align:center; margin-top:0;">Commande n° <?= e(format_order_number((int) $order['id'])); ?></p>

        <div class="print-actions">
            <button onclick="window.print()">Réimprimer</button>
            <a class="button secondary" href="index.php">Nouvelle commande</a>
        </div>

        <div class="print-summary">
            <div class="print-meta-card">
                <div class="print-meta-grid">
                    <div class="meta-block">
                        <span class="small">Client</span>
                        <strong><?= e($order['customer_name']); ?></strong>
                    </div>
                    <div class="meta-block">
                        <span class="small">Numéro client</span>
                        <strong><?= e(normalize_barcode_value((string) ($order['customer_phone'] ?: '-'))); ?></strong>
                    </div>
                    <div class="meta-block">
                        <span class="small">Commande</span>
                        <strong><?= e(format_order_number((int) $order['id'])); ?></strong>
                    </div>
                    <div class="meta-block">
                        <span class="small">Date</span>
                        <strong><?= e($order['created_at']); ?></strong>
                    </div>
                </div>
            </div>

            <?php if (!empty($order['customer_phone'])): ?>
                <div class="barcode-panel">
                    <div class="small">Code barre client</div>
                    <?= render_code128_barcode($order['customer_phone']); ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Article (code barre + référence)</th>
                    <th>Produit</th>
                    <th>Quantité</th>
                    <th>Promo</th>
                    <th>Prix unitaire</th>
                    <th>Sous-total</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($items as $item): ?>
                    <?php
                    $quantity = (int) $item['quantity'];
                    $paidQuantity = (int) ($item['paid_quantity'] ?? $quantity);
                    $freeQuantity = (int) ($item['free_quantity'] ?? max(0, $quantity - $paidQuantity));
                    $lineTotal = isset($item['line_total']) ? (float) $item['line_total'] : ((float) $item['unit_price'] * max(0, $paidQuantity));
                    ?>
                    <tr>
                        <td class="print-item-barcode"><?= render_code128_barcode((string) $item['article_number']); ?></td>
                        <td><?= e($item['product_name']); ?></td>
                        <td><?= (int) $item['quantity']; ?></td>
                        <td>
                            <?php if ($freeQuantity > 0): ?>
                                <?= $paidQuantity; ?> + <?= $freeQuantity; ?> gratuit
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>
                        <td><?= money((float) $item['unit_price']); ?></td>
                        <td><?= money($lineTotal); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <p class="cart-total">Total : <?= money((float) $order['total_amount']); ?></p>

        <?php if (($_GET['autoprint'] ?? '0') === '1'): ?>
            <script>
                window.addEventListener('load', function () {
                    setTimeout(function () {
                        window.print();
                    }, 250);
                });

                window.addEventListener('afterprint', function () {
                    window.location.href = 'index.php';
                });
            </script>
        <?php endif; ?>
    <?php endif; ?>
</div>
</body>
</html>
