<?php
require __DIR__ . '/includes/bootstrap.php';

$flash = get_flash();
$cartTotal = 0.0;
$databaseError = null;

$categoryDetails = [
    'fumigenes-bengales' => ['icon' => '💨', 'fr' => 'Effets colorés', 'nl' => 'Kleurige effecten'],
    'batteries-feux' => ['icon' => '🎇', 'fr' => 'Spectacles complets', 'nl' => 'Complete vuurwerkshows'],
    'batteries-silencieuses' => ['icon' => '✨', 'fr' => 'Sans détonation', 'nl' => 'Zonder knallen'],
    'fontaines' => ['icon' => '🌟', 'fr' => 'Jets élégants', 'nl' => 'Sierlijke fonteinen'],
    'fusees' => ['icon' => '🚀', 'fr' => 'Effets aériens', 'nl' => 'Luchteffecten'],
    'pochettes-multipacks' => ['icon' => '🧺', 'fr' => 'Divers packs', 'nl' => 'Diverse pakketten'],
    'petards' => ['icon' => '🧨', 'fr' => 'Classiques', 'nl' => 'Klassiek knalvuurwerk'],
    'promotions' => ['icon' => '🏷️', 'fr' => 'Derniers stocks', 'nl' => 'Laatste stuks'],
    'baby-shower' => ['icon' => '🍼', 'fr' => 'Ambiances douces', 'nl' => 'Zachte sfeer'],
    'anniversaire-gateau' => ['icon' => '🎂', 'fr' => 'Moment festif', 'nl' => 'Feestelijke momenten'],
];

try {
    $pdo = get_pdo($config);
    $cartTotal = cart_total($pdo);
} catch (Throwable $exception) {
    $databaseError = 'La base MySQL n\'est pas encore configurée. Importez database.sql puis adaptez les accès dans includes/config.php.';
}

$assetVersion = (string) filemtime(__DIR__ . '/assets/css/style.css');
$logoVersion = (string) filemtime(__DIR__ . '/assets/logo.png');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Famipyro</title>
    <link rel="stylesheet" href="assets/css/style.css?v=<?= urlencode($assetVersion); ?>">
</head>
<body class="home-screen">
<div class="page-shell">
    <div class="panel kiosk-panel">
        <div class="kiosk-topbar">
            <div class="kiosk-badge total-badge">🧺 <?= money($cartTotal); ?></div>
            <div class="kiosk-actions">
                <a class="kiosk-badge print" href="cart.php">🛒 Panier • <?= cart_count(); ?></a>
                <a class="kiosk-badge secondary" href="admin/index.php">🔒 Admin</a>
            </div>
        </div>

        <div class="brand kiosk-brand home-brand">
            <img class="main-logo" src="assets/logo.png?v=<?= urlencode($logoVersion); ?>" alt="Famipyro">
            <p>Les feux d'artifice pour toute la famille</p>
            <p class="small">Vuurwerk voor het hele gezin</p>
        </div>

        <?php if ($flash): ?>
            <div class="notice <?= e($flash['type']); ?>"><?= e($flash['message']); ?></div>
        <?php endif; ?>

        <?php if ($databaseError): ?>
            <div class="notice error"><?= e($databaseError); ?></div>
        <?php endif; ?>

        <div class="category-grid kiosk-grid">
            <?php foreach (PRODUCT_CATEGORIES as $key => $label): ?>
                <a class="category-card" href="category.php?category=<?= urlencode($key); ?>">
                    <div class="category-icon"><?= e($categoryDetails[$key]['icon'] ?? '🎆'); ?></div>
                    <strong><?= e($label); ?></strong>
                    <span class="sub"><?= e($categoryDetails[$key]['fr'] ?? ''); ?></span>
                    <span class="sub-alt"><?= e($categoryDetails[$key]['nl'] ?? ''); ?></span>
                </a>
            <?php endforeach; ?>
        </div>

        <div class="home-actions">
            <a class="floating-action" href="cart.php">🛒 Voir le panier</a>
        </div>
    </div>
</div>
</body>
</html>
