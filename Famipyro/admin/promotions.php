<?php
require __DIR__ . '/../includes/bootstrap.php';
require_admin();

$flash = get_flash();
$errorMessage = null;
$promotions = [];
$editPromotion = null;
$editId = (int) ($_GET['id'] ?? 0);

try {
    $pdo = get_pdo($config);
    ensure_promotions_table($pdo);

    $stmt = $pdo->query(
        'SELECT p.*, pr.name AS product_name
         FROM promotions p
            LEFT JOIN products pr ON pr.article_number COLLATE utf8mb4_general_ci = p.article_number COLLATE utf8mb4_general_ci
         ORDER BY p.created_at DESC, p.id DESC'
    );
    $promotions = $stmt->fetchAll();

    if ($editId > 0) {
        $editStmt = $pdo->prepare('SELECT * FROM promotions WHERE id = ? LIMIT 1');
        $editStmt->execute([$editId]);
        $editPromotion = $editStmt->fetch() ?: null;
    }
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
    <title>Promotions - Famipyro</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= urlencode($assetVersion); ?>">
</head>
<body>
<div class="page-shell">
    <div class="panel">
        <div class="topbar">
            <a class="topbar-action" href="index.php">← Retour admin</a>
            <div style="display:flex; gap:10px; flex-wrap:wrap;">
                <div class="topbar-badge">Promotions</div>
                <a class="topbar-action" href="orders.php">Commandes</a>
                <a class="topbar-action" href="../index.php">Voir la boutique</a>
            </div>
        </div>

        <div class="brand" style="margin-bottom:10px;">
            <img class="main-logo small-logo" src="../assets/logo.png?v=<?= urlencode($logoVersion); ?>" alt="Famipyro">
            <p style="font-size:1.4rem; font-weight:700; color:#1f5a36; margin-top:8px;">Gestion des promotions</p>
        </div>

        <?php if ($flash): ?>
            <div class="notice <?= e($flash['type']); ?>"><?= e($flash['message']); ?></div>
        <?php endif; ?>

        <?php if ($errorMessage): ?>
            <div class="notice error"><?= e($errorMessage); ?></div>
        <?php endif; ?>

        <div class="sidebar-card">
            <h2 style="margin-top:0;"><?= $editPromotion ? 'Modifier une promotion' : 'Encoder une promotion'; ?></h2>
            <form action="save_promotion.php" method="post" class="form-grid">
                <input type="hidden" name="id" value="<?= (int) ($editPromotion['id'] ?? 0); ?>">

                <div class="form-group">
                    <label>Numéro d'article</label>
                    <input type="text" name="article_number" required value="<?= e((string) ($editPromotion['article_number'] ?? '')); ?>" data-barcode-normalize="1" autocomplete="off" autocapitalize="characters" spellcheck="false" style="text-transform: uppercase;">
                    <div class="small">Le numéro doit correspondre à une référence existante du catalogue.</div>
                </div>

                <div class="form-group">
                    <label>Quantité à acheter</label>
                    <input type="number" name="buy_quantity" min="1" required value="<?= (int) ($editPromotion['buy_quantity'] ?? 2); ?>">
                </div>

                <div class="form-group">
                    <label>Quantité gratuite</label>
                    <input type="number" name="free_quantity" min="1" required value="<?= (int) ($editPromotion['free_quantity'] ?? 1); ?>">
                </div>

                <div class="form-group" style="align-self:end;">
                    <label><input type="checkbox" name="is_active" style="width:auto;" <?= isset($editPromotion['is_active']) ? ((int) $editPromotion['is_active'] === 1 ? 'checked' : '') : 'checked'; ?>> Promotion active</label>
                </div>

                <div class="form-group full" style="display:flex; gap:10px; flex-direction:row; flex-wrap:wrap;">
                    <button type="submit"><?= $editPromotion ? 'Enregistrer la promotion' : 'Ajouter la promotion'; ?></button>
                    <a class="button secondary" href="promotions.php">Réinitialiser</a>
                </div>
            </form>
        </div>

        <div class="sidebar-card">
            <h2 style="margin-top:0;">Promotions enregistrées</h2>
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>Réf.</th>
                        <th>Produit</th>
                        <th>Offre</th>
                        <th>Statut</th>
                        <th>Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if ($promotions === []): ?>
                        <tr>
                            <td colspan="5" class="empty-state">Aucune promotion enregistrée pour le moment.</td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($promotions as $promotion): ?>
                        <tr>
                            <td><?= e($promotion['article_number']); ?></td>
                            <td><?= e($promotion['product_name'] ?: 'Référence introuvable'); ?></td>
                            <td><?= (int) $promotion['buy_quantity']; ?> + <?= (int) $promotion['free_quantity']; ?> gratuit</td>
                            <td><?= (int) $promotion['is_active'] === 1 ? 'Active' : 'Inactive'; ?></td>
                            <td>
                                <div style="display:flex; flex-direction:column; gap:8px;">
                                    <a class="button secondary" href="promotions.php?id=<?= (int) $promotion['id']; ?>">Modifier</a>
                                    <form action="save_promotion.php" method="post" onsubmit="return confirm('Supprimer cette promotion ?');">
                                        <input type="hidden" name="id" value="<?= (int) $promotion['id']; ?>">
                                        <input type="hidden" name="delete_promotion" value="1">
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
<?= barcode_input_script(); ?>
</body>
</html>
