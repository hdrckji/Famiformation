<?php
require __DIR__ . '/../includes/bootstrap.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

try {
    $pdo = get_pdo($config);
    $id = (int) ($_POST['id'] ?? 0);

    if (isset($_POST['delete_product'])) {
        $stmt = $pdo->prepare('SELECT image_path FROM products WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $product = $stmt->fetch();

        $deleteStmt = $pdo->prepare('DELETE FROM products WHERE id = ?');
        $deleteStmt->execute([$id]);

        if (!empty($product['image_path'])) {
            $absoluteImage = __DIR__ . '/../' . ltrim((string) $product['image_path'], '/');
            if (is_file($absoluteImage)) {
                @unlink($absoluteImage);
            }
        }

        flash('success', 'Produit supprimé.');
        header('Location: index.php');
        exit;
    }

    if (isset($_POST['quick_update'])) {
        $stock = max(0, (int) ($_POST['stock'] ?? 0));
        $price = (float) ($_POST['price'] ?? 0);
        $selectedCategories = normalize_product_categories($_POST['category'] ?? []);
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        if ($selectedCategories === []) {
            throw new RuntimeException('Veuillez choisir au moins une catégorie.');
        }

        if ($price <= 0) {
            throw new RuntimeException('Le prix doit être supérieur à 0.');
        }

        $category = implode(',', $selectedCategories);
        $stmt = $pdo->prepare('UPDATE products SET category = ?, price = ?, stock = ?, is_active = ? WHERE id = ?');
        $stmt->execute([$category, $price, $stock, $isActive, $id]);
        flash('success', 'Prix, catégories et stock mis à jour.');
        header('Location: product.php?id=' . $id);
        exit;
    }

    $selectedCategories = normalize_product_categories($_POST['category'] ?? []);
    $category = implode(',', $selectedCategories);
    $articleNumber = normalize_barcode_value($_POST['article_number'] ?? '');
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $price = (float) ($_POST['price'] ?? 0);
    $stock = max(0, (int) ($_POST['stock'] ?? 0));
    $isActive = isset($_POST['is_active']) ? 1 : 0;
    $imagePath = trim($_POST['existing_image'] ?? '');

    if ($selectedCategories === []) {
        throw new RuntimeException('Veuillez choisir au moins une catégorie.');
    }

    if ($articleNumber === '' || $name === '' || $description === '') {
        throw new RuntimeException('Veuillez remplir tous les champs obligatoires.');
    }

    if ($price <= 0) {
        throw new RuntimeException('Le prix doit être supérieur à 0.');
    }

    if (!empty($_FILES['image']['name'])) {
        $uploadDir = __DIR__ . '/../uploads';
        $oldImagePath = $imagePath;
        $imagePath = process_product_upload($_FILES['image'], $uploadDir);

        if ($oldImagePath !== '' && $oldImagePath !== $imagePath) {
            $absoluteImage = __DIR__ . '/../' . ltrim($oldImagePath, '/');
            if (is_file($absoluteImage)) {
                @unlink($absoluteImage);
            }
        }
    }

    if ($id > 0) {
        $stmt = $pdo->prepare('UPDATE products SET category = ?, article_number = ?, name = ?, description = ?, price = ?, stock = ?, is_active = ?, image_path = ? WHERE id = ?');
        $stmt->execute([$category, $articleNumber, $name, $description, $price, $stock, $isActive, $imagePath ?: null, $id]);
        flash('success', 'Référence mise à jour.');
    } else {
        $stmt = $pdo->prepare('INSERT INTO products (category, article_number, name, description, price, stock, is_active, image_path) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$category, $articleNumber, $name, $description, $price, $stock, $isActive, $imagePath ?: null]);
        $id = (int) $pdo->lastInsertId();
        flash('success', 'Nouvelle référence ajoutée.');
    }
} catch (Throwable $exception) {
    flash('error', $exception->getMessage());
}

if ($id > 0) {
    header('Location: product.php?id=' . $id);
    exit;
}

header('Location: index.php');
exit;
