<?php
require __DIR__ . '/../includes/bootstrap.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: promotions.php');
    exit;
}

try {
    $pdo = get_pdo($config);
    ensure_promotions_table($pdo);

    $id = (int) ($_POST['id'] ?? 0);

    if (isset($_POST['delete_promotion'])) {
        if ($id > 0) {
            $deleteStmt = $pdo->prepare('DELETE FROM promotions WHERE id = ?');
            $deleteStmt->execute([$id]);
            flash('success', 'Promotion supprimée.');
        }

        header('Location: promotions.php');
        exit;
    }

    $articleNumber = normalize_barcode_value($_POST['article_number'] ?? '');
    $buyQuantity = max(0, (int) ($_POST['buy_quantity'] ?? 0));
    $freeQuantity = max(0, (int) ($_POST['free_quantity'] ?? 0));
    $isActive = isset($_POST['is_active']) ? 1 : 0;

    if ($articleNumber === '') {
        throw new RuntimeException('Le numéro d\'article est obligatoire.');
    }

    if ($buyQuantity <= 0 || $freeQuantity <= 0) {
        throw new RuntimeException('Les quantités doivent être supérieures à 0.');
    }

    $productStmt = $pdo->prepare('SELECT id FROM products WHERE article_number COLLATE utf8mb4_general_ci = ? COLLATE utf8mb4_general_ci LIMIT 1');
    $productStmt->execute([$articleNumber]);
    if (!$productStmt->fetch()) {
        throw new RuntimeException('Le numéro d\'article ne correspond à aucun produit du catalogue.');
    }

    if ($id > 0) {
        $stmt = $pdo->prepare('UPDATE promotions SET article_number = ?, buy_quantity = ?, free_quantity = ?, is_active = ? WHERE id = ?');
        $stmt->execute([$articleNumber, $buyQuantity, $freeQuantity, $isActive, $id]);
        flash('success', 'Promotion mise à jour.');
    } else {
        $stmt = $pdo->prepare('INSERT INTO promotions (article_number, buy_quantity, free_quantity, is_active) VALUES (?, ?, ?, ?)');
        $stmt->execute([$articleNumber, $buyQuantity, $freeQuantity, $isActive]);
        flash('success', 'Promotion ajoutée.');
    }
} catch (Throwable $exception) {
    $message = $exception->getMessage();
    if (str_contains(strtolower($message), 'duplicate')) {
        $message = 'Une promotion existe déjà pour ce numéro d\'article. Ouvrez-la puis modifiez-la.';
    }

    flash('error', $message);
}

header('Location: promotions.php');
exit;
