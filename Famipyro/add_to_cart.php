<?php
require __DIR__ . '/includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $productId = (int) ($_POST['product_id'] ?? 0);

    try {
        $pdo = get_pdo($config);
        $product = get_product($pdo, $productId);

        $quantity = max(1, min((int) ($_POST['quantity'] ?? 1), (int) ($product['stock'] ?? 1)));

        if ($product && (int) $product['is_active'] === 1 && (int) $product['stock'] > 0) {
            add_to_cart($productId, $quantity);
            flash('success', 'Article ajouté au panier.');
        } else {
            flash('error', 'Cette référence n\'est plus disponible.');
        }
    } catch (Throwable $exception) {
        flash('error', 'Impossible d\'ajouter l\'article pour le moment.');
    }
}

header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'index.php'));
exit;
