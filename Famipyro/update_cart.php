<?php
require __DIR__ . '/includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $productId = (int) ($_POST['product_id'] ?? 0);
    $action = $_POST['action'] ?? '';

    if ($action === 'clear') {
        clear_cart();
        flash('success', 'Tous les articles du panier ont été supprimés.');
    } elseif ($action === 'remove') {
        remove_from_cart($productId);
        flash('success', 'Article supprimé du panier.');
    } else {
        $quantity = (int) ($_POST['quantity'] ?? 0);
        update_cart_quantity($productId, $quantity);
        flash('success', 'Panier mis à jour.');
    }
}

header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'cart.php'));
exit;
