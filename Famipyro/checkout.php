<?php
require __DIR__ . '/includes/bootstrap.php';

$items = [];
$total = 0.0;
$errorMessage = null;

try {
    $pdo = get_pdo($config);
    $items = cart_items($pdo);
    $total = cart_total($pdo);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $name = trim($_POST['name'] ?? '');
        $legalConfirmation = isset($_POST['legal_confirmation']);

        if (!$legalConfirmation) {
            throw new RuntimeException('Le client doit confirmer sa majorité et le respect des règles de transport et d\'utilisation.');
        }

        if ($name === '') {
            throw new RuntimeException('Veuillez saisir le nom du client.');
        }

        $orderId = save_order($pdo, [
            'name' => $name,
            'phone' => '',
            'notes' => '',
        ]);

        header('Location: order_done.php?id=' . $orderId);
        exit;
    }
} catch (Throwable $exception) {
    $errorMessage = $exception->getMessage();
}

$assetVersion = (string) filemtime(__DIR__ . '/assets/css/style.css');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Finaliser - Famipyro</title>
    <link rel="stylesheet" href="assets/css/style.css?v=<?= urlencode($assetVersion); ?>">
</head>
<body>
<div class="page-shell">
    <div class="panel">
        <div class="topbar">
            <a class="topbar-action" href="cart.php">← Retour au panier</a>
        </div>

        <div class="layout">
            <div class="sidebar-card">
                <h2 style="margin-top:0;">Informations client</h2>

                <?php if ($errorMessage): ?>
                    <div class="notice error"><?= e($errorMessage); ?></div>
                <?php endif; ?>

                <form method="post" class="form-grid">
                    <div class="form-group full">
                        <label>Nom du client</label>
                        <input type="text" name="name" placeholder="Nom du client" value="<?= e($_POST['name'] ?? ''); ?>" required autofocus>
                    </div>

                    <div class="form-group full">
                        <label style="display:flex; gap:10px; align-items:flex-start; line-height:1.4;">
                            <input type="checkbox" name="legal_confirmation" value="1" style="width:auto; margin-top:3px;" <?= isset($_POST['legal_confirmation']) ? 'checked' : ''; ?> required>
                            <span>Je certifie être majeur et m'engage à respecter les règles de transport, de stockage et d'utilisation des feux d'artifice.</span>
                        </label>
                    </div>

                    <div class="form-group full">
                        <button type="submit">Valider</button>
                    </div>
                </form>
            </div>

            <aside>
                <div class="sidebar-card">
                    <h3 style="margin-top:0;">Récapitulatif</h3>
                    <?php foreach ($items as $item): ?>
                        <div class="cart-item">
                            <div>
                                <strong><?= e($item['name']); ?></strong><br>
                                <span class="small">Qté : <?= (int) $item['cart_quantity']; ?></span>
                                <?php if ((int) ($item['free_quantity'] ?? 0) > 0): ?>
                                    <br><span class="small">Promo : <?= (int) $item['promotion_buy_quantity']; ?> + <?= (int) $item['promotion_free_quantity']; ?> gratuit (offerts : <?= (int) $item['free_quantity']; ?>)</span>
                                <?php endif; ?>
                            </div>
                            <div>
                                <?= money((float) $item['subtotal']); ?>
                                <?php if ((float) ($item['discount_amount'] ?? 0.0) > 0.0): ?>
                                    <br><span class="small">-<?= money((float) $item['discount_amount']); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <div class="cart-total">Total : <?= money($total); ?></div>
                </div>
            </aside>
        </div>
    </div>
</div>
</body>
</html>
