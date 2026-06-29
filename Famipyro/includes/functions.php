<?php

declare(strict_types=1);

const PRODUCT_CATEGORIES = [
    'fumigenes-bengales' => 'Fumigènes et Bengales',
    'batteries-feux' => 'Batteries de feux d\'artifice',
    'batteries-silencieuses' => 'Batteries silencieuses',
    'fontaines' => 'Fontaines',
    'fusees' => 'Fusées',
    'pochettes-multipacks' => 'Pochettes & Multipacks',
    'petards' => 'Pétards',
    'promotions' => 'Promotions',
    'baby-shower' => 'Baby Shower',
    'anniversaire-gateau' => 'Anniversaire & Fontaines à gâteau',
];

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function money(float $value): string
{
    return number_format($value, 2, ',', ' ') . ' €';
}

function normalize_product_categories(array|string|null $categories): array
{
    if (is_string($categories)) {
        $categories = explode(',', $categories);
    }

    $normalized = [];

    foreach ((array) $categories as $category) {
        $key = trim((string) $category);
        if ($key !== '' && isset(PRODUCT_CATEGORIES[$key])) {
            $normalized[$key] = $key;
        }
    }

    return array_values($normalized);
}

function product_in_category(array $product, string $category): bool
{
    return in_array($category, normalize_product_categories($product['category'] ?? ''), true);
}

function product_category_labels(array $product): string
{
    $labels = [];
    foreach (normalize_product_categories($product['category'] ?? '') as $categoryKey) {
        $labels[] = PRODUCT_CATEGORIES[$categoryKey] ?? $categoryKey;
    }

    return implode(', ', $labels);
}

function ensure_shop_settings_table(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS shop_settings (
            setting_key VARCHAR(100) PRIMARY KEY,
            setting_value TEXT NOT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
}

function get_shop_settings(): array
{
    $defaults = [
        'client_mode' => 'card_or_name',
    ];

    try {
        global $config;
        $pdo = get_pdo($config);
        ensure_shop_settings_table($pdo);

        $stmt = $pdo->query('SELECT setting_key, setting_value FROM shop_settings');
        $settings = $defaults;

        foreach ($stmt->fetchAll() as $row) {
            $settings[(string) $row['setting_key']] = (string) $row['setting_value'];
        }

        return $settings;
    } catch (Throwable $exception) {
        return $defaults;
    }
}

function save_shop_settings(array $settings): void
{
    $allowedModes = ['account_only', 'card_or_name'];
    $clientMode = in_array((string) ($settings['client_mode'] ?? ''), $allowedModes, true)
        ? (string) $settings['client_mode']
        : 'card_or_name';

    global $config;
    $pdo = get_pdo($config);
    ensure_shop_settings_table($pdo);

    $stmt = $pdo->prepare('REPLACE INTO shop_settings (setting_key, setting_value) VALUES (?, ?)');
    $stmt->execute(['client_mode', $clientMode]);
}

function client_mode_label(string $mode): string
{
    return match ($mode) {
        'account_only' => 'Compte client uniquement',
        default => 'Client sans carte accepté',
    };
}

function ensure_promotions_table(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS promotions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            article_number VARCHAR(50) NOT NULL UNIQUE,
            buy_quantity INT NOT NULL,
            free_quantity INT NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
}

function ensure_order_items_promotion_columns(PDO $pdo): void
{
    $columns = [
        'paid_quantity' => 'INT NOT NULL DEFAULT 0',
        'free_quantity' => 'INT NOT NULL DEFAULT 0',
        'line_total' => 'DECIMAL(10,2) NOT NULL DEFAULT 0.00',
    ];

    foreach ($columns as $columnName => $definition) {
        $checkStmt = $pdo->prepare('SHOW COLUMNS FROM order_items LIKE ?');
        $checkStmt->execute([$columnName]);

        if (!$checkStmt->fetch()) {
            $pdo->exec('ALTER TABLE order_items ADD COLUMN ' . $columnName . ' ' . $definition);
        }
    }
}

function active_promotions_by_article(PDO $pdo, array $articleNumbers): array
{
    ensure_promotions_table($pdo);

    $normalized = [];
    foreach ($articleNumbers as $articleNumber) {
        $value = normalize_barcode_value((string) $articleNumber);
        if ($value !== '') {
            $normalized[$value] = $value;
        }
    }

    if ($normalized === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($normalized), '?'));
    $stmt = $pdo->prepare(
        "SELECT article_number, buy_quantity, free_quantity
         FROM promotions
         WHERE is_active = 1 AND article_number IN ($placeholders)"
    );
    $stmt->execute(array_values($normalized));

    $promotions = [];
    foreach ($stmt->fetchAll() as $row) {
        $article = normalize_barcode_value((string) $row['article_number']);
        $buyQuantity = max(0, (int) $row['buy_quantity']);
        $freeQuantity = max(0, (int) $row['free_quantity']);

        if ($article !== '' && $buyQuantity > 0 && $freeQuantity > 0) {
            $promotions[$article] = [
                'buy_quantity' => $buyQuantity,
                'free_quantity' => $freeQuantity,
            ];
        }
    }

    return $promotions;
}

function promotion_breakdown(int $quantity, float $unitPrice, ?array $promotion): array
{
    $quantity = max(0, $quantity);
    $unitPrice = max(0.0, $unitPrice);

    if ($quantity === 0 || !$promotion) {
        $lineTotal = $quantity * $unitPrice;

        return [
            'buy_quantity' => 0,
            'free_rule_quantity' => 0,
            'free_quantity' => 0,
            'paid_quantity' => $quantity,
            'line_total_without_promo' => $lineTotal,
            'line_total' => $lineTotal,
            'discount_amount' => 0.0,
        ];
    }

    $buyQuantity = max(0, (int) ($promotion['buy_quantity'] ?? 0));
    $freeRuleQuantity = max(0, (int) ($promotion['free_quantity'] ?? 0));
    $bundleSize = $buyQuantity + $freeRuleQuantity;

    if ($buyQuantity <= 0 || $freeRuleQuantity <= 0 || $bundleSize <= 0) {
        $lineTotal = $quantity * $unitPrice;

        return [
            'buy_quantity' => 0,
            'free_rule_quantity' => 0,
            'free_quantity' => 0,
            'paid_quantity' => $quantity,
            'line_total_without_promo' => $lineTotal,
            'line_total' => $lineTotal,
            'discount_amount' => 0.0,
        ];
    }

    $bundleCount = intdiv($quantity, $bundleSize);
    $freeQuantity = $bundleCount * $freeRuleQuantity;
    $paidQuantity = max(0, $quantity - $freeQuantity);
    $lineTotalWithoutPromo = $quantity * $unitPrice;
    $lineTotal = $paidQuantity * $unitPrice;

    return [
        'buy_quantity' => $buyQuantity,
        'free_rule_quantity' => $freeRuleQuantity,
        'free_quantity' => $freeQuantity,
        'paid_quantity' => $paidQuantity,
        'line_total_without_promo' => $lineTotalWithoutPromo,
        'line_total' => $lineTotal,
        'discount_amount' => max(0.0, $lineTotalWithoutPromo - $lineTotal),
    ];
}

function format_order_number(int $orderId): string
{
    return 'FP-' . date('Y') . '-' . str_pad((string) $orderId, 6, '0', STR_PAD_LEFT);
}

function normalize_barcode_value(?string $value): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }

    if (preg_match('/[&é"\'\(\-è_çà]/u', $value) === 1 && preg_match('/[A-Za-z]/', $value) !== 1) {
        $value = strtr($value, [
            '&' => '1',
            'é' => '2',
            '"' => '3',
            "'" => '4',
            '(' => '5',
            '-' => '6',
            'è' => '7',
            '_' => '8',
            'ç' => '9',
            'à' => '0',
        ]);
    }

    return strtoupper($value);
}

function barcode_input_script(): string
{
    return <<<'HTML'
<script>
function normalizeBarcodeInputValue(value) {
    const map = {
        '&': '1',
        'é': '2',
        '"': '3',
        "'": '4',
        '(': '5',
        '-': '6',
        'è': '7',
        '_': '8',
        'ç': '9',
        'à': '0'
    };

    if (!/[a-z]/i.test(value) && /[&é"'(\-è_çà]/i.test(value)) {
        value = value.split('').map(function (character) {
            return Object.prototype.hasOwnProperty.call(map, character) ? map[character] : character;
        }).join('');
    }

    return value.toUpperCase();
}

document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-barcode-normalize="1"]').forEach(function (input) {
        const applyNormalization = function () {
            input.value = normalizeBarcodeInputValue(input.value);
        };

        input.addEventListener('input', applyNormalization);
        input.addEventListener('change', applyNormalization);
        applyNormalization();
    });
});
</script>
HTML;
}

function render_code128_barcode(?string $value, bool $compact = false): string
{
    $value = normalize_barcode_value($value);
    if ($value === '') {
        return '';
    }

    $value = preg_replace('/[^\x20-\x7E]/', '', $value) ?: '';
    if ($value === '') {
        return '';
    }

    $patterns = [
        [2, 1, 2, 2, 2, 2], [2, 2, 2, 1, 2, 2], [2, 2, 2, 2, 2, 1], [1, 2, 1, 2, 2, 3],
        [1, 2, 1, 3, 2, 2], [1, 3, 1, 2, 2, 2], [1, 2, 2, 2, 1, 3], [1, 2, 2, 3, 1, 2],
        [1, 3, 2, 2, 1, 2], [2, 2, 1, 2, 1, 3], [2, 2, 1, 3, 1, 2], [2, 3, 1, 2, 1, 2],
        [1, 1, 2, 2, 3, 2], [1, 2, 2, 1, 3, 2], [1, 2, 2, 2, 3, 1], [1, 1, 3, 2, 2, 2],
        [1, 2, 3, 1, 2, 2], [1, 2, 3, 2, 2, 1], [2, 2, 3, 2, 1, 1], [2, 2, 1, 1, 3, 2],
        [2, 2, 1, 2, 3, 1], [2, 1, 3, 2, 1, 2], [2, 2, 3, 1, 1, 2], [3, 1, 2, 1, 3, 1],
        [3, 1, 1, 2, 2, 2], [3, 2, 1, 1, 2, 2], [3, 2, 1, 2, 2, 1], [3, 1, 2, 2, 1, 2],
        [3, 2, 2, 1, 1, 2], [3, 2, 2, 2, 1, 1], [2, 1, 2, 1, 2, 3], [2, 1, 2, 3, 2, 1],
        [2, 3, 2, 1, 2, 1], [1, 1, 1, 3, 2, 3], [1, 3, 1, 1, 2, 3], [1, 3, 1, 3, 2, 1],
        [1, 1, 2, 3, 1, 3], [1, 3, 2, 1, 1, 3], [1, 3, 2, 3, 1, 1], [2, 1, 1, 3, 1, 3],
        [2, 3, 1, 1, 1, 3], [2, 3, 1, 3, 1, 1], [1, 1, 2, 1, 3, 3], [1, 1, 2, 3, 3, 1],
        [1, 3, 2, 1, 3, 1], [1, 1, 3, 1, 2, 3], [1, 1, 3, 3, 2, 1], [1, 3, 3, 1, 2, 1],
        [3, 1, 3, 1, 2, 1], [2, 1, 1, 3, 3, 1], [2, 3, 1, 1, 3, 1], [2, 1, 3, 1, 1, 3],
        [2, 1, 3, 3, 1, 1], [2, 1, 3, 1, 3, 1], [3, 1, 1, 1, 2, 3], [3, 1, 1, 3, 2, 1],
        [3, 3, 1, 1, 2, 1], [3, 1, 2, 1, 1, 3], [3, 1, 2, 3, 1, 1], [3, 3, 2, 1, 1, 1],
        [3, 1, 4, 1, 1, 1], [2, 2, 1, 4, 1, 1], [4, 3, 1, 1, 1, 1], [1, 1, 1, 2, 2, 4],
        [1, 1, 1, 4, 2, 2], [1, 2, 1, 1, 2, 4], [1, 2, 1, 4, 2, 1], [1, 4, 1, 1, 2, 2],
        [1, 4, 1, 2, 2, 1], [1, 1, 2, 2, 1, 4], [1, 1, 2, 4, 1, 2], [1, 2, 2, 1, 1, 4],
        [1, 2, 2, 4, 1, 1], [1, 4, 2, 1, 1, 2], [1, 4, 2, 2, 1, 1], [2, 4, 1, 2, 1, 1],
        [2, 2, 1, 1, 1, 4], [4, 1, 3, 1, 1, 1], [2, 4, 1, 1, 1, 2], [1, 3, 4, 1, 1, 1],
        [1, 1, 1, 2, 4, 2], [1, 2, 1, 1, 4, 2], [1, 2, 1, 2, 4, 1], [1, 1, 4, 2, 1, 2],
        [1, 2, 4, 1, 1, 2], [1, 2, 4, 2, 1, 1], [4, 1, 1, 2, 1, 2], [4, 2, 1, 1, 1, 2],
        [4, 2, 1, 2, 1, 1], [2, 1, 2, 1, 4, 1], [2, 1, 4, 1, 2, 1], [4, 1, 2, 1, 2, 1],
        [1, 1, 1, 1, 4, 3], [1, 1, 1, 3, 4, 1], [1, 3, 1, 1, 4, 1], [1, 1, 4, 1, 1, 3],
        [1, 1, 4, 3, 1, 1], [4, 1, 1, 1, 1, 3], [4, 1, 1, 3, 1, 1], [1, 1, 3, 1, 4, 1],
        [1, 1, 4, 1, 3, 1], [3, 1, 1, 1, 4, 1], [4, 1, 1, 1, 3, 1], [2, 1, 1, 4, 1, 2],
        [2, 1, 1, 2, 1, 4], [2, 1, 1, 2, 3, 2], [2, 3, 3, 1, 1, 1, 2],
    ];

    $codes = [];
    $isNumeric = preg_match('/^\d+$/', $value) === 1;

    if ($isNumeric) {
        if (strlen($value) % 2 === 0) {
            $codes[] = 105;
            for ($i = 0, $length = strlen($value); $i < $length; $i += 2) {
                $codes[] = (int) substr($value, $i, 2);
            }
        } else {
            $codes[] = 104;
            $codes[] = ord($value[0]) - 32;
            if (strlen($value) > 1) {
                $codes[] = 99;
                for ($i = 1, $length = strlen($value); $i < $length; $i += 2) {
                    $codes[] = (int) substr($value, $i, 2);
                }
            }
        }
    } else {
        $codes[] = 104;
        for ($i = 0, $length = strlen($value); $i < $length; $i++) {
            $ascii = ord($value[$i]);
            if ($ascii >= 32 && $ascii <= 126) {
                $codes[] = $ascii - 32;
            }
        }
    }

    if (count($codes) < 2) {
        return '';
    }

    $checksum = $codes[0];
    foreach (array_slice($codes, 1) as $index => $code) {
        $checksum += $code * ($index + 1);
    }

    $codes[] = $checksum % 103;
    $codes[] = 106;

    $moduleWidth = 2;
    $height = 60;
    $quietZone = 20;
    $x = $quietZone;
    $bars = '';

    foreach ($codes as $code) {
        $pattern = $patterns[$code] ?? null;
        if ($pattern === null) {
            continue;
        }

        foreach ($pattern as $index => $widthUnit) {
            $lineWidth = $widthUnit * $moduleWidth;
            if ($index % 2 === 0) {
                $bars .= '<rect x="' . $x . '" y="0" width="' . $lineWidth . '" height="' . $height . '" fill="#111" />';
            }
            $x += $lineWidth;
        }
    }

    $width = $x + $quietZone;

    $className = $compact ? 'barcode-wrap compact' : 'barcode-wrap';
    $textHtml = $compact ? '' : '<div class="barcode-text">' . e($value) . '</div>';

    return '<div class="' . $className . '"><svg class="barcode-svg" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $width . ' ' . $height . '" role="img" aria-label="Code barre" preserveAspectRatio="xMidYMid meet" shape-rendering="crispEdges"><rect width="100%" height="100%" fill="#fff" />' . $bars . '</svg>' . $textHtml . '</div>';
}

function delete_order_and_restore_stock(PDO $pdo, int $orderId): void
{
    $pdo->beginTransaction();

    try {
        $itemStmt = $pdo->prepare('SELECT product_id, quantity FROM order_items WHERE order_id = ?');
        $itemStmt->execute([$orderId]);
        $items = $itemStmt->fetchAll();

        $updateStockStmt = $pdo->prepare('UPDATE products SET stock = stock + ? WHERE id = ?');
        foreach ($items as $item) {
            $updateStockStmt->execute([(int) $item['quantity'], (int) $item['product_id']]);
        }

        $deleteItemsStmt = $pdo->prepare('DELETE FROM order_items WHERE order_id = ?');
        $deleteItemsStmt->execute([$orderId]);

        $deleteOrderStmt = $pdo->prepare('DELETE FROM orders WHERE id = ?');
        $deleteOrderStmt->execute([$orderId]);

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $exception;
    }
}

function product_image_url(array|string|null $product): ?string
{
    $path = is_array($product) ? (string) ($product['image_path'] ?? '') : (string) $product;
    $articleNumber = is_array($product) ? strtolower(trim((string) ($product['article_number'] ?? ''))) : '';
    $name = is_array($product) ? strtolower(trim((string) ($product['name'] ?? ''))) : '';

    $path = trim($path);
    if ($path !== '' && preg_match('/^https?:\/\//i', $path)) {
        return $path;
    }

    if ($path !== '') {
        $normalized = ltrim(str_replace('\\', '/', $path), '/');
        $rootPath = dirname(__DIR__) . '/' . $normalized;

        if (is_file($rootPath)) {
            return $normalized;
        }

        $fallback = 'uploads/' . basename($normalized);
        if (is_file(dirname(__DIR__) . '/' . $fallback)) {
            return $fallback;
        }
    }

    $uploadDir = dirname(__DIR__) . '/uploads';
    if (!is_dir($uploadDir)) {
        return null;
    }

    $files = array_values(array_filter(scandir($uploadDir) ?: [], static fn (string $file): bool => $file !== '.' && $file !== '..'));
    $expectedBaseName = strtolower(pathinfo($path, PATHINFO_FILENAME));
    $normalizedName = preg_replace('/[^a-z0-9]+/', '', $name) ?: '';

    foreach ($files as $file) {
        $fileName = strtolower($file);
        $fileBaseName = strtolower(pathinfo($file, PATHINFO_FILENAME));
        $flatBaseName = preg_replace('/[^a-z0-9]+/', '', $fileBaseName) ?: '';

        if ($expectedBaseName !== '' && ($fileBaseName === $expectedBaseName || str_contains($fileBaseName, $expectedBaseName))) {
            return 'uploads/' . $file;
        }

        if ($articleNumber !== '' && str_contains($fileName, $articleNumber)) {
            return 'uploads/' . $file;
        }

        if ($normalizedName !== '' && $flatBaseName !== '' && (str_contains($flatBaseName, $normalizedName) || str_contains($normalizedName, $flatBaseName))) {
            return 'uploads/' . $file;
        }
    }

    return null;
}

function process_product_upload(array $file, string $uploadDir): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Impossible de téléverser l\'image.');
    }

    $tmpFile = (string) ($file['tmp_name'] ?? '');
    $originalName = (string) ($file['name'] ?? '');
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'webp', 'svg'];

    if (!in_array($extension, $allowed, true)) {
        throw new RuntimeException('Format d\'image non autorisé.');
    }

    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
        throw new RuntimeException('Impossible de créer le dossier de téléversement.');
    }

    if ($extension === 'svg') {
        $fileName = uniqid('product_', true) . '.svg';
        $target = $uploadDir . '/' . $fileName;

        if (!move_uploaded_file($tmpFile, $target)) {
            throw new RuntimeException('Impossible de téléverser l\'image SVG.');
        }

        return 'uploads/' . $fileName;
    }

    if (!function_exists('getimagesize')) {
        $fileName = uniqid('product_', true) . '.' . $extension;
        $target = $uploadDir . '/' . $fileName;

        if (!move_uploaded_file($tmpFile, $target)) {
            throw new RuntimeException('Impossible de téléverser l\'image.');
        }

        return 'uploads/' . $fileName;
    }

    $imageInfo = @getimagesize($tmpFile);
    if ($imageInfo === false) {
        throw new RuntimeException('Le fichier envoyé n\'est pas une image valide.');
    }

    [$sourceWidth, $sourceHeight] = $imageInfo;
    $mime = $imageInfo['mime'] ?? '';

    $sourceImage = match ($mime) {
        'image/jpeg' => function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($tmpFile) : false,
        'image/png' => function_exists('imagecreatefrompng') ? @imagecreatefrompng($tmpFile) : false,
        'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($tmpFile) : false,
        default => false,
    };

    if (!$sourceImage) {
        $fileName = uniqid('product_', true) . '.' . $extension;
        $target = $uploadDir . '/' . $fileName;

        if (!move_uploaded_file($tmpFile, $target)) {
            throw new RuntimeException('Impossible de téléverser l\'image.');
        }

        return 'uploads/' . $fileName;
    }

    $minX = $sourceWidth;
    $minY = $sourceHeight;
    $maxX = -1;
    $maxY = -1;

    for ($y = 0; $y < $sourceHeight; $y++) {
        for ($x = 0; $x < $sourceWidth; $x++) {
            $rgba = imagecolorat($sourceImage, $x, $y);
            $alpha = ($rgba >> 24) & 0x7F;
            $red = ($rgba >> 16) & 0xFF;
            $green = ($rgba >> 8) & 0xFF;
            $blue = $rgba & 0xFF;

            if ($alpha < 120 && ($red < 245 || $green < 245 || $blue < 245)) {
                $minX = min($minX, $x);
                $minY = min($minY, $y);
                $maxX = max($maxX, $x);
                $maxY = max($maxY, $y);
            }
        }
    }

    if ($maxX >= $minX && $maxY >= $minY) {
        $padding = 12;
        $cropX = max(0, $minX - $padding);
        $cropY = max(0, $minY - $padding);
        $cropWidth = min($sourceWidth - $cropX, ($maxX - $minX) + 1 + ($padding * 2));
        $cropHeight = min($sourceHeight - $cropY, ($maxY - $minY) + 1 + ($padding * 2));

        $croppedImage = imagecreatetruecolor($cropWidth, $cropHeight);
        $background = imagecolorallocate($croppedImage, 255, 255, 255);
        imagefill($croppedImage, 0, 0, $background);
        imagecopy($croppedImage, $sourceImage, 0, 0, $cropX, $cropY, $cropWidth, $cropHeight);
        imagedestroy($sourceImage);
        $sourceImage = $croppedImage;
        $sourceWidth = $cropWidth;
        $sourceHeight = $cropHeight;
    }

    $maxWidth = 1200;
    $maxHeight = 900;
    $scale = min($maxWidth / max(1, $sourceWidth), $maxHeight / max(1, $sourceHeight));
    $resizeWidth = (int) max(1, round($sourceWidth * $scale));
    $resizeHeight = (int) max(1, round($sourceHeight * $scale));

    $canvas = imagecreatetruecolor($resizeWidth, $resizeHeight);
    $background = imagecolorallocate($canvas, 255, 255, 255);
    imagefill($canvas, 0, 0, $background);

    imagecopyresampled(
        $canvas,
        $sourceImage,
        0,
        0,
        0,
        0,
        $resizeWidth,
        $resizeHeight,
        $sourceWidth,
        $sourceHeight
    );

    $fileName = uniqid('product_', true) . '.jpg';
    $target = $uploadDir . '/' . $fileName;

    imagejpeg($canvas, $target, 90);
    imagedestroy($sourceImage);
    imagedestroy($canvas);

    return 'uploads/' . $fileName;
}

function ensure_cart(): void
{
    if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
        $_SESSION['cart'] = [];
    }

    foreach ($_SESSION['cart'] as $productId => $quantity) {
        $quantity = (int) $quantity;
        if ($quantity <= 0) {
            unset($_SESSION['cart'][$productId]);
            continue;
        }

        $_SESSION['cart'][(int) $productId] = $quantity;
    }
}

function add_to_cart(int $productId, int $quantity = 1): void
{
    ensure_cart();
    $_SESSION['cart'][$productId] = ($_SESSION['cart'][$productId] ?? 0) + max(1, $quantity);
}

function update_cart_quantity(int $productId, int $quantity): void
{
    ensure_cart();

    if ($quantity <= 0) {
        unset($_SESSION['cart'][$productId]);
        return;
    }

    $_SESSION['cart'][$productId] = $quantity;
}

function remove_from_cart(int $productId): void
{
    ensure_cart();
    unset($_SESSION['cart'][$productId]);
}

function clear_cart(): void
{
    $_SESSION['cart'] = [];
}

function cart_count(): int
{
    ensure_cart();
    return array_sum($_SESSION['cart']);
}

function cart_items(PDO $pdo): array
{
    ensure_cart();

    if ($_SESSION['cart'] === []) {
        return [];
    }

    $ids = array_map('intval', array_keys($_SESSION['cart']));
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT * FROM products WHERE id IN ($placeholders) ORDER BY name");
    $stmt->execute($ids);
    $products = $stmt->fetchAll();
    $articleNumbers = array_map(static fn (array $product): string => (string) ($product['article_number'] ?? ''), $products);
    $promotionsByArticle = active_promotions_by_article($pdo, $articleNumbers);

    $validProducts = [];
    $foundIds = [];

    foreach ($products as $product) {
        $productId = (int) $product['id'];
        $foundIds[] = $productId;
        $quantity = (int) ($_SESSION['cart'][$productId] ?? 0);
        $cartQuantity = min($quantity, max(0, (int) $product['stock']));

        if ($cartQuantity <= 0 || (int) $product['is_active'] !== 1) {
            unset($_SESSION['cart'][$productId]);
            continue;
        }

        $articleNumber = normalize_barcode_value((string) ($product['article_number'] ?? ''));
        $promotion = $promotionsByArticle[$articleNumber] ?? null;
        $breakdown = promotion_breakdown($cartQuantity, (float) $product['price'], $promotion);

        $product['cart_quantity'] = $cartQuantity;
        $product['paid_quantity'] = $breakdown['paid_quantity'];
        $product['free_quantity'] = $breakdown['free_quantity'];
        $product['line_total_without_promo'] = $breakdown['line_total_without_promo'];
        $product['discount_amount'] = $breakdown['discount_amount'];
        $product['subtotal'] = $breakdown['line_total'];
        $product['promotion_buy_quantity'] = $breakdown['buy_quantity'];
        $product['promotion_free_quantity'] = $breakdown['free_rule_quantity'];
        $validProducts[] = $product;
    }

    foreach ($ids as $productId) {
        if (!in_array($productId, $foundIds, true)) {
            unset($_SESSION['cart'][$productId]);
        }
    }

    return $validProducts;
}

function cart_total(PDO $pdo): float
{
    $total = 0.0;
    foreach (cart_items($pdo) as $item) {
        $total += (float) $item['subtotal'];
    }
    return $total;
}

function fetch_products(PDO $pdo, ?string $category = null): array
{
    $stmt = $pdo->query('SELECT * FROM products WHERE is_active = 1 AND stock > 0 ORDER BY name');
    $products = $stmt->fetchAll();

    if ($category && isset(PRODUCT_CATEGORIES[$category])) {
        return array_values(array_filter($products, static fn (array $product): bool => product_in_category($product, $category)));
    }

    return $products;
}

function get_product(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM products WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $product = $stmt->fetch();

    return $product ?: null;
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function get_flash(): ?array
{
    $message = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $message;
}

function is_admin(): bool
{
    return !empty($_SESSION['admin_logged_in']);
}

function verify_stored_password(string $plainPassword, string $storedHash): bool
{
    $storedHash = trim($storedHash);
    if ($storedHash === '') {
        return false;
    }

    if (str_starts_with($storedHash, '$2y$') || str_starts_with($storedHash, '$argon2')) {
        return password_verify($plainPassword, $storedHash);
    }

    if (str_starts_with($storedHash, 'pbkdf2_sha256$')) {
        $parts = explode('$', $storedHash, 4);
        if (count($parts) !== 4) {
            return false;
        }

        [, $iterations, $saltHex, $expectedHex] = $parts;
        $salt = @hex2bin($saltHex);
        if ($salt === false) {
            return false;
        }

        $derived = hash_pbkdf2('sha256', $plainPassword, $salt, (int) $iterations, 32, true);
        return hash_equals(strtolower($expectedHex), bin2hex($derived));
    }

    return hash_equals($storedHash, $plainPassword);
}

function admin_credentials_are_valid(array $config, string $username, string $password): bool
{
    $expectedUser = getenv('ADMIN_USER') ?: ($config['admin_user'] ?? 'admin');
    $storedHash = getenv('ADMIN_PASS_HASH') ?: ($config['admin_pass_hash'] ?? ($config['admin_pass'] ?? ''));

    return hash_equals((string) $expectedUser, $username) && verify_stored_password($password, (string) $storedHash);
}

function require_admin(): void
{
    if (!is_admin()) {
        header('Location: login.php');
        exit;
    }
}

function save_order(PDO $pdo, array $customer): int
{
    $items = cart_items($pdo);

    if ($items === []) {
        throw new RuntimeException('Le panier est vide.');
    }

    $total = 0.0;
    foreach ($items as $item) {
        $total += (float) ($item['subtotal'] ?? 0.0);
    }

    ensure_order_items_promotion_columns($pdo);

    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare('INSERT INTO orders (customer_name, customer_phone, customer_notes, total_amount, created_at) VALUES (?, ?, ?, ?, NOW())');
        $stmt->execute([
            trim($customer['name'] ?? ''),
            normalize_barcode_value($customer['phone'] ?? ''),
            trim($customer['notes'] ?? ''),
            $total,
        ]);

        $orderId = (int) $pdo->lastInsertId();

        $itemStmt = $pdo->prepare('INSERT INTO order_items (order_id, product_id, article_number, product_name, quantity, paid_quantity, free_quantity, unit_price, line_total) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stockStmt = $pdo->prepare('UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?');

        foreach ($items as $item) {
            if ((int) $item['cart_quantity'] <= 0) {
                continue;
            }

            $itemStmt->execute([
                $orderId,
                $item['id'],
                normalize_barcode_value($item['article_number']),
                $item['name'],
                $item['cart_quantity'],
                (int) ($item['paid_quantity'] ?? $item['cart_quantity']),
                (int) ($item['free_quantity'] ?? 0),
                $item['price'],
                (float) ($item['subtotal'] ?? 0.0),
            ]);

            $stockStmt->execute([$item['cart_quantity'], $item['id'], $item['cart_quantity']]);
        }

        $pdo->commit();
        $_SESSION['cart'] = [];

        return $orderId;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}
