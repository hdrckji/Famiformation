<?php
require __DIR__ . '/../includes/bootstrap.php';
require_admin();

$flash = get_flash();
$products = [];
$editCategories = [];
$errorMessage = null;
$filterCategory = $_GET['category'] ?? '';

$settings = get_shop_settings();

try {
    $pdo = get_pdo($config);
    $products = $pdo->query('SELECT * FROM products ORDER BY created_at DESC, id DESC')->fetchAll();

    if (isset(PRODUCT_CATEGORIES[$filterCategory])) {
        $products = array_values(array_filter($products, static fn (array $product): bool => product_in_category($product, $filterCategory)));
    } else {
        $filterCategory = '';
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
    <title>Admin Famipyro</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= urlencode($assetVersion); ?>">
</head>
<body>
<div class="page-shell">
    <div class="panel">
        <div class="topbar">
            <a class="topbar-action" href="../index.php">← Retour boutique</a>
            <div style="display:flex; gap:10px; flex-wrap:wrap;">
                <div class="topbar-badge">Gestion des références</div>
                <a class="topbar-action" href="promotions.php">Promotions</a>
                <a class="topbar-action" href="orders.php">Commandes</a>
                <a class="topbar-action" href="logout.php">Déconnexion</a>
            </div>
        </div>

        <div class="brand" style="margin-bottom:10px;">
            <img class="main-logo small-logo" src="../assets/logo.png?v=<?= urlencode($logoVersion); ?>" alt="Famipyro">
            <p style="font-size:1.4rem; font-weight:700; color:#1f5a36; margin-top:8px;">Administration</p>
        </div>

        <?php if ($flash): ?>
            <div class="notice <?= e($flash['type']); ?>"><?= e($flash['message']); ?></div>
        <?php endif; ?>

        <?php if ($errorMessage): ?>
            <div class="notice error"><?= e($errorMessage); ?></div>
        <?php endif; ?>

        <div class="sidebar-card">
            <h2 style="margin-top:0;">Mode d'identification client</h2>
            <form action="save_settings.php" method="post" class="form-grid">
                <div class="form-group full">
                    <label>Scénario utilisé sur la page infos client</label>
                    <select name="client_mode">
                        <option value="account_only" <?= ($settings['client_mode'] ?? '') === 'account_only' ? 'selected' : ''; ?>>Compte client uniquement</option>
                        <option value="card_or_name" <?= ($settings['client_mode'] ?? '') === 'card_or_name' ? 'selected' : ''; ?>>Client sans carte accepté</option>
                    </select>
                    <div class="small">Mode actif : <?= e(client_mode_label($settings['client_mode'] ?? 'card_or_name')); ?></div>
                </div>
                <div class="form-group full">
                    <button type="submit">Enregistrer le scénario</button>
                </div>
            </form>
        </div>

        <div class="sidebar-card">
            <h2 style="margin-top:0;">Ajouter une référence</h2>
            <form action="save_product.php" method="post" enctype="multipart/form-data" class="form-grid">
                <input type="hidden" name="id" value="0">
                <input type="hidden" name="existing_image" value="">

                <div class="form-group full">
                    <label>Catégories</label>
                    <div class="checkbox-grid">
                        <?php foreach (PRODUCT_CATEGORIES as $key => $label): ?>
                            <label><input type="checkbox" name="category[]" value="<?= e($key); ?>" style="width:auto;"> <?= e($label); ?></label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="form-group">
                    <label>Numéro d'article</label>
                    <input type="text" name="article_number" required value="" data-barcode-normalize="1" autocomplete="off" autocapitalize="characters" spellcheck="false" style="text-transform: uppercase;">
                </div>

                <div class="form-group full">
                    <label>Nom commercial</label>
                    <input type="text" name="name" required value="">
                </div>

                <div class="form-group full">
                    <label>Description</label>
                    <textarea name="description" rows="4" required></textarea>
                </div>

                <div class="form-group">
                    <label>Prix</label>
                    <input type="number" step="0.01" name="price" required value="0.00">
                </div>

                <div class="form-group">
                    <label>Stock</label>
                    <input type="number" name="stock" min="0" required value="0">
                </div>

                <div class="form-group full">
                    <label>Photo produit</label>
                    <input type="file" name="image" accept=".jpg,.jpeg,.png,.webp,.svg">
                    <div class="small">Les images gardent maintenant leurs proportions, sans étirement. Une zone blanche est ajoutée si nécessaire.</div>
                </div>

                <div class="form-group full" style="flex-direction:row; align-items:center;">
                    <input type="checkbox" name="is_active" id="is_active" style="width:auto;" checked>
                    <label for="is_active">Référence active</label>
                </div>

                <div class="form-group full" style="display:flex; gap:10px; flex-direction:row;">
                    <button type="submit">Ajouter la référence</button>
                    <a class="button secondary" href="index.php">Réinitialiser</a>
                </div>
            </form>
        </div>

        <div class="sidebar-card">
            <div class="filter-bar">
                <h2 style="margin:0;">Catalogue actuel</h2>
                <form method="get" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                    <select name="category">
                        <option value="">Toutes les catégories</option>
                        <?php foreach (PRODUCT_CATEGORIES as $key => $label): ?>
                            <option value="<?= e($key); ?>" <?= $filterCategory === $key ? 'selected' : ''; ?>><?= e($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button class="secondary" type="submit">Filtrer</button>
                    <a class="button secondary" href="index.php">Réinitialiser</a>
                </form>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>Réf.</th>
                        <th>Produit</th>
                        <th>Catégories</th>
                        <th>Prix</th>
                        <th>Stock</th>
                        <th>Statut</th>
                        <th>Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($products as $product): ?>
                        <tr>
                            <td><?= e($product['article_number']); ?></td>
                            <td>
                                <a href="product.php?id=<?= (int) $product['id']; ?>"><strong><?= e($product['name']); ?></strong></a><br>
                                <span class="small"><?= e($product['description']); ?></span>
                            </td>
                            <td><?= e(product_category_labels($product)); ?></td>
                            <td><?= money((float) $product['price']); ?></td>
                            <td><?= (int) $product['stock']; ?></td>
                            <td><?= (int) $product['is_active'] === 1 ? 'Actif' : 'Inactif'; ?></td>
                            <td>
                                <div style="display:flex; flex-direction:column; gap:8px;">
                                    <a class="button secondary" href="product.php?id=<?= (int) $product['id']; ?>">Ouvrir la fiche</a>
                                    <form action="save_product.php" method="post" onsubmit="return confirm('Supprimer ce produit ?');">
                                        <input type="hidden" name="id" value="<?= (int) $product['id']; ?>">
                                        <input type="hidden" name="delete_product" value="1">
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
