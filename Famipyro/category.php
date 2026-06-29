<?php
require __DIR__ . '/includes/bootstrap.php';

$flash = get_flash();
$selectedCategory = $_GET['category'] ?? '';
$products = [];
$cartItems = [];
$cartTotal = 0.0;
$errorMessage = null;

if (!isset(PRODUCT_CATEGORIES[$selectedCategory])) {
    header('Location: index.php');
    exit;
}

try {
    $pdo = get_pdo($config);
    $products = fetch_products($pdo, $selectedCategory);
    $cartItems = cart_items($pdo);
    $cartTotal = cart_total($pdo);
} catch (Throwable $exception) {
    $errorMessage = 'Impossible de charger cette catégorie pour le moment.';
}

$assetVersion = (string) filemtime(__DIR__ . '/assets/css/style.css');
$logoVersion = (string) filemtime(__DIR__ . '/assets/logo.png');
$categoryNoticeImagePath = 'assets/category-first-image.png';
$hasCategoryNoticeImage = is_file(__DIR__ . '/' . $categoryNoticeImagePath);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e(PRODUCT_CATEGORIES[$selectedCategory]); ?> - Famipyro</title>
    <link rel="stylesheet" href="assets/css/style.css?v=<?= urlencode($assetVersion); ?>">
</head>
<body>
<div class="page-shell">
    <div class="panel kiosk-panel">
        <div class="kiosk-topbar">
            <a class="kiosk-badge secondary" href="index.php">← Retour à l'accueil</a>
            <div class="kiosk-actions">
                <a class="kiosk-badge secondary" href="cart.php">Panier • <?= cart_count(); ?></a>
                <div class="kiosk-badge total-badge"><?= money($cartTotal); ?></div>
            </div>
        </div>

        <div class="brand kiosk-brand">
            <img class="main-logo small-logo" src="assets/logo.png?v=<?= urlencode($logoVersion); ?>" alt="Famipyro">
            <p><?= e(PRODUCT_CATEGORIES[$selectedCategory]); ?></p>
        </div>

        <?php if ($flash): ?>
            <div class="notice <?= e($flash['type']); ?>"><?= e($flash['message']); ?></div>
        <?php endif; ?>

        <?php if ($errorMessage): ?>
            <div class="notice error"><?= e($errorMessage); ?></div>
        <?php endif; ?>

        <div class="kiosk-sections">
            <section class="section-card">
                <h2 style="margin-top:0;">Produits disponibles</h2>
                <div class="products-grid compact-products">
                    <?php if ($products === []): ?>
                        <div class="empty-state">Aucune référence disponible dans cette catégorie.</div>
                    <?php endif; ?>

                    <?php foreach ($products as $product): ?>
                        <article class="product-card kiosk-product-card">
                            <div class="product-image">
                                <?php $imageUrl = product_image_url($product); ?>
                                <?php if ($imageUrl !== null): ?>
                                    <img src="<?= e($imageUrl); ?>" alt="<?= e($product['name']); ?>">
                                <?php else: ?>
                                    <div class="placeholder-icon">🎆</div>
                                <?php endif; ?>
                            </div>
                            <div class="product-body">
                                <strong><?= e($product['name']); ?></strong>
                                <div class="product-meta">Réf. <?= e($product['article_number']); ?></div>
                                <div><?= e($product['description']); ?></div>
                                <div class="product-meta">Stock : <?= (int) $product['stock']; ?></div>
                                <div class="price-row">
                                    <div class="price"><?= money((float) $product['price']); ?></div>
                                </div>
                                <form action="add_to_cart.php" method="post" class="qty-form">
                                    <input type="hidden" name="product_id" value="<?= (int) $product['id']; ?>">
                                    <input type="hidden" name="quantity" value="1" class="qty-value">
                                    <div class="qty-stepper">
                                        <button type="button" class="qty-btn qty-minus" <?= (int) $product['stock'] < 1 ? 'disabled' : ''; ?>>−</button>
                                        <span class="qty-display">1</span>
                                        <button type="button" class="qty-btn qty-plus" data-max="<?= (int) $product['stock']; ?>" <?= (int) $product['stock'] < 1 ? 'disabled' : ''; ?>>+</button>
                                        <button type="submit" <?= (int) $product['stock'] < 1 ? 'disabled' : ''; ?>>Ajouter</button>
                                    </div>
                                </form>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>

            <aside class="section-card cart-panel">
                <?php if ($hasCategoryNoticeImage): ?>
                    <div style="margin-bottom:12px;">
                        <img src="<?= e($categoryNoticeImagePath); ?>" alt="Rappel réglementaire" style="width:100%; height:auto; border-radius:10px; border:1px solid var(--line); background:#fff;">
                    </div>
                <?php endif; ?>
                <h3 style="margin-top:0;">Panier</h3>
                <?php if ($cartItems === []): ?>
                    <div class="empty-state">Aucun article pour le moment.</div>
                    <a class="button secondary" href="cart.php" style="display:inline-block; margin-top:10px;">Ouvrir le panier</a>
                <?php else: ?>
                    <?php foreach ($cartItems as $item): ?>
                        <div class="cart-item">
                            <div>
                                <strong><?= e($item['name']); ?></strong><br>
                                <span class="small">Qté : <?= (int) $item['cart_quantity']; ?></span>
                            </div>
                            <div style="text-align:right;">
                                <div><?= money((float) $item['subtotal']); ?></div>
                                <form action="update_cart.php" method="post" style="margin-top:6px;">
                                    <input type="hidden" name="product_id" value="<?= (int) $item['id']; ?>">
                                    <input type="hidden" name="action" value="remove">
                                    <button class="secondary" type="submit">Supprimer</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <div class="cart-total">Total : <?= money($cartTotal); ?></div>
                    <div style="display:flex; gap:10px; flex-wrap:wrap;">
                        <a class="button" href="cart.php">Commander</a>
                    </div>
                <?php endif; ?>
            </aside>
        </div>
    </div>
</div>
<script>
document.querySelectorAll('.qty-form').forEach(function (form) {
    var minusBtn = form.querySelector('.qty-minus');
    var plusBtn = form.querySelector('.qty-plus');
    var display = form.querySelector('.qty-display');
    var input = form.querySelector('.qty-value');
    var max = parseInt(plusBtn.dataset.max, 10) || 1;

    minusBtn.addEventListener('click', function () {
        var val = parseInt(input.value, 10);
        if (val > 1) {
            val--;
            input.value = val;
            display.textContent = val;
        }
        minusBtn.disabled = val <= 1;
        plusBtn.disabled = false;
    });

    plusBtn.addEventListener('click', function () {
        var val = parseInt(input.value, 10);
        if (val < max) {
            val++;
            input.value = val;
            display.textContent = val;
        }
        plusBtn.disabled = val >= max;
        minusBtn.disabled = false;
    });
});
</script>
</body>
</html>
