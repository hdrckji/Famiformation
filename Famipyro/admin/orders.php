<?php
require __DIR__ . '/../includes/bootstrap.php';
require_admin();

$flash = get_flash();
$orders = [];
$errorMessage = null;

try {
    $pdo = get_pdo($config);

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_order'])) {
        $orderId = (int) ($_POST['order_id'] ?? 0);
        if ($orderId > 0) {
            delete_order_and_restore_stock($pdo, $orderId);
            flash('success', 'Commande supprimée et stock réintégré.');
            header('Location: orders.php');
            exit;
        }
    }

    $stmt = $pdo->query('SELECT o.*, COUNT(oi.id) AS item_count FROM orders o LEFT JOIN order_items oi ON oi.order_id = o.id GROUP BY o.id ORDER BY o.created_at DESC, o.id DESC');
    $orders = $stmt->fetchAll();
} catch (Throwable $exception) {
    $errorMessage = $exception->getMessage();
}

$assetVersion = (string) filemtime(__DIR__ . '/../assets/css/style.css');
$logoVersion = (string) filemtime(__DIR__ . '/../assets/logo.png');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Commandes - Famipyro</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= urlencode($assetVersion); ?>">
</head>
<body>
<div class="page-shell">
    <div class="panel">
        <div class="topbar">
            <a class="topbar-action" href="index.php">← Retour admin</a>
            <a class="topbar-action" href="promotions.php">Promotions</a>
            <a class="topbar-action" href="../index.php">Voir la boutique</a>
        </div>

        <div class="brand" style="margin-bottom:10px;">
            <img class="main-logo small-logo" src="../assets/logo.png?v=<?= urlencode($logoVersion); ?>" alt="Famipyro">
            <p style="font-size:1.4rem; font-weight:700; color:#1f5a36; margin-top:8px;">Toutes les commandes</p>
        </div>

        <?php if ($flash): ?>
            <div class="notice <?= e($flash['type']); ?>"><?= e($flash['message']); ?></div>
        <?php endif; ?>

        <?php if ($errorMessage): ?>
            <div class="notice error"><?= e($errorMessage); ?></div>
        <?php endif; ?>

        <div class="sidebar-card">
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>N° commande</th>
                        <th>Client</th>
                        <th>N° client</th>
                        <th>Articles</th>
                        <th>Total</th>
                        <th>Date</th>
                        <th>Action</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if ($orders === []): ?>
                        <tr>
                            <td colspan="7" class="empty-state">Aucune commande enregistrée.</td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($orders as $order): ?>
                        <tr>
                            <td><?= e(format_order_number((int) $order['id'])); ?></td>
                            <td><?= e($order['customer_name'] ?: 'Compte client'); ?></td>
                            <td><?= e($order['customer_phone'] ?: '-'); ?></td>
                            <td><?= (int) $order['item_count']; ?></td>
                            <td><?= money((float) $order['total_amount']); ?></td>
                            <td><?= e($order['created_at']); ?></td>
                            <td>
                                <div style="display:flex; flex-direction:column; gap:8px;">
                                    <a class="button secondary" href="../print_order.php?id=<?= (int) $order['id']; ?>">Voir / imprimer</a>
                                    <form method="post" onsubmit="return confirm('Supprimer cette commande et remettre les articles en stock ?');">
                                        <input type="hidden" name="order_id" value="<?= (int) $order['id']; ?>">
                                        <input type="hidden" name="delete_order" value="1">
                                        <button class="danger" type="submit">Supprimer</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
</body>
</html>
