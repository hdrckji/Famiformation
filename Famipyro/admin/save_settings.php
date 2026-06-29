<?php
require __DIR__ . '/../includes/bootstrap.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    save_shop_settings([
        'client_mode' => $_POST['client_mode'] ?? 'card_or_name',
    ]);

    flash('success', 'Mode client mis à jour.');
}

header('Location: index.php');
exit;
