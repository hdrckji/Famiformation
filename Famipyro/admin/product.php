<?php
require __DIR__ . '/../includes/bootstrap.php';
require_admin();

$flash = get_flash();
$errorMessage = null;
$product = null;
$productId = (int) ($_GET['id'] ?? 0);

try {
    $pdo = get_pdo($config);
    $product = get_product($pdo, $productId);

    if (!$product) {
        throw new RuntimeException('Article introuvable.');
    }

    $selectedCategories = normalize_product_categories($product['category'] ?? '');
} catch (Throwable $exception) {
    $errorMessage = $exception->getMessage();
    $selectedCategories = [];
}

$assetVersion = (string) filemtime(__DIR__ . '/../assets/css/style.css');
$logoVersion = (string) filemtime(__DIR__ . '/../assets/logo.png');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fiche article - Famipyro</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= urlencode($assetVersion); ?>">
</head>
<body>
<div class="page-shell">
    <div class="panel">
        <div class="topbar">
            <a class="topbar-action" href="index.php">← Retour admin</a>
            <a class="topbar-action" href="promotions.php">Promotions</a>
            <a class="topbar-action" href="orders.php">Voir les commandes</a>
            <a class="topbar-action" href="../index.php">Voir la boutique</a>
        </div>

        <div class="brand" style="margin-bottom:10px;">
            <img class="main-logo small-logo" src="../assets/logo.png?v=<?= urlencode($logoVersion); ?>" alt="Famipyro">
            <p style="font-size:1.4rem; font-weight:700; color:#1f5a36; margin-top:8px;">Fiche article</p>
        </div>

        <?php if ($flash): ?>
            <div class="notice <?= e($flash['type']); ?>"><?= e($flash['message']); ?></div>
        <?php endif; ?>

        <?php if ($errorMessage): ?>
            <div class="notice error"><?= e($errorMessage); ?></div>
        <?php endif; ?>

        <?php if ($product): ?>
            <div class="sidebar-card">
                <form action="save_product.php" method="post" enctype="multipart/form-data" class="form-grid">
                    <input type="hidden" name="id" value="<?= (int) $product['id']; ?>">
                    <input type="hidden" name="existing_image" value="<?= e($product['image_path'] ?? ''); ?>">

                    <div class="form-group full">
                        <label>Catégories</label>
                        <div class="checkbox-grid">
                            <?php foreach (PRODUCT_CATEGORIES as $key => $label): ?>
                                <label><input type="checkbox" name="category[]" value="<?= e($key); ?>" style="width:auto;" <?= in_array($key, $selectedCategories, true) ? 'checked' : ''; ?>> <?= e($label); ?></label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Numéro d'article</label>
                        <input type="text" name="article_number" required value="<?= e(normalize_barcode_value((string) $product['article_number'])); ?>" data-barcode-normalize="1" autocomplete="off" autocapitalize="characters" spellcheck="false" style="text-transform: uppercase;">
                    </div>

                    <div class="form-group">
                        <label>Prix</label>
                        <input type="number" step="0.01" name="price" required value="<?= e((string) $product['price']); ?>">
                    </div>

                    <div class="form-group full">
                        <label>Nom commercial</label>
                        <input type="text" name="name" required value="<?= e($product['name']); ?>">
                    </div>

                    <div class="form-group full">
                        <label>Description</label>
                        <textarea name="description" rows="5" required><?= e($product['description']); ?></textarea>
                    </div>

                    <div class="form-group">
                        <label>Stock</label>
                        <input type="number" name="stock" min="0" required value="<?= (int) $product['stock']; ?>">
                    </div>

                    <div class="form-group">
                        <label>État</label>
                        <label><input type="checkbox" name="is_active" style="width:auto;" <?= (int) $product['is_active'] === 1 ? 'checked' : ''; ?>> Référence active</label>
                    </div>

                    <div class="form-group full">
                        <label>Image actuelle</label>
                        <div class="image-preview-box">
                            <?php $imageUrl = product_image_url($product); ?>
                            <?php if ($imageUrl !== null): ?>
                                <img src="../<?= e($imageUrl); ?>" alt="<?= e($product['name']); ?>" class="admin-product-preview">
                            <?php else: ?>
                                <div class="small">Aucune image disponible actuellement pour cet article.</div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="form-group full">
                        <label>Changer la photo</label>
                        <input type="file" name="image" accept=".jpg,.jpeg,.png,.webp,.svg">
                        <div class="small">Toutes les informations connues de cet article peuvent être modifiées depuis cette fiche.</div>
                    </div>

                    <div class="form-group full" style="display:flex; gap:10px; flex-direction:row; flex-wrap:wrap;">
                        <button type="submit">Enregistrer les changements</button>
                        <a class="button secondary" href="index.php">Retour au catalogue</a>
                    </div>
                </form>
            </div>
        <?php endif; ?>
    </div>
</div>
<?= barcode_input_script(); ?>
</body>
</html>
