<?php
require __DIR__ . '/includes/bootstrap.php';

$flash = get_flash();
$items = [];
$total = 0.0;
$databaseError = null;

try {
    $pdo = get_pdo($config);
    $items = cart_items($pdo);
    $total = cart_total($pdo);
} catch (Throwable $exception) {
    $databaseError = 'Connexion à la base de données impossible pour le moment.';
}

$assetVersion = (string) filemtime(__DIR__ . '/assets/css/style.css');
$logoVersion = (string) filemtime(__DIR__ . '/assets/logo.png');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panier - Famipyro</title>
    <link rel="stylesheet" href="assets/css/style.css?v=<?= urlencode($assetVersion); ?>">
</head>
<body>
<div class="page-shell">
    <div class="panel">
        <div class="topbar">
            <a class="topbar-action" href="index.php">← Retour à la boutique</a>
            <div class="topbar-badge">Total panier : <?= money($total); ?></div>
        </div>

        <div class="brand" style="margin-bottom:10px;">
            <img class="main-logo small-logo" src="assets/logo.png?v=<?= urlencode($logoVersion); ?>" alt="Famipyro">
            <h1 style="font-size:2.3rem;">Votre panier</h1>
        </div>

        <?php if ($flash): ?>
            <div class="notice <?= e($flash['type']); ?>"><?= e($flash['message']); ?></div>
        <?php endif; ?>

        <?php if ($databaseError): ?>
            <div class="notice error"><?= e($databaseError); ?></div>
        <?php endif; ?>

        <div class="sidebar-card">
            <?php if ($items === []): ?>
                <div class="empty-state">Le panier est vide.</div>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead>
                        <tr>
                            <th>Article</th>
                            <th>Prix</th>
                            <th>Quantité</th>
                            <th>Promo</th>
                            <th>Sous-total</th>
                            <th>Action</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($items as $item): ?>
                            <tr>
                                <td>
                                    <strong><?= e($item['name']); ?></strong><br>
                                    <span class="small">Réf. <?= e($item['article_number']); ?></span>
                                </td>
                                <td><?= money((float) $item['price']); ?></td>
                                <td>
                                    <form action="update_cart.php" method="post" style="display:flex; gap:8px; align-items:center;">
                                        <input type="hidden" name="product_id" value="<?= (int) $item['id']; ?>">
                                        <input type="number" name="quantity" min="0" max="<?= (int) $item['stock']; ?>" value="<?= (int) $item['cart_quantity']; ?>" style="max-width:90px;">
                                        <button class="secondary" type="submit">Mettre à jour</button>
                                    </form>
                                </td>
                                <td>
                                    <?php if ((int) ($item['free_quantity'] ?? 0) > 0): ?>
                                        <strong><?= (int) $item['promotion_buy_quantity']; ?> + <?= (int) $item['promotion_free_quantity']; ?> gratuit</strong><br>
                                        <span class="small">Offerts: <?= (int) $item['free_quantity']; ?> • Remise: <?= money((float) ($item['discount_amount'] ?? 0.0)); ?></span>
                                    <?php else: ?>
                                        <span class="small">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= money((float) $item['subtotal']); ?>
                                    <?php if ((float) ($item['discount_amount'] ?? 0.0) > 0.0): ?>
                                        <br><span class="small">au lieu de <?= money((float) ($item['line_total_without_promo'] ?? $item['subtotal'])); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <form action="update_cart.php" method="post">
                                        <input type="hidden" name="product_id" value="<?= (int) $item['id']; ?>">
                                        <input type="hidden" name="action" value="remove">
                                        <button class="danger" type="submit">Supprimer</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="cart-total">Montant total : <?= money($total); ?></div>
                <div style="display:flex; gap:12px; flex-wrap:wrap; margin-top:16px;">
                    <form action="update_cart.php" method="post">
                        <input type="hidden" name="action" value="clear">
                        <button class="danger" type="submit">Supprimer tous les articles</button>
                    </form>
                    <a class="button" href="checkout.php">Continuer la commande</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>
