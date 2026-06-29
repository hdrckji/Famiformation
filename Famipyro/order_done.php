<?php
require __DIR__ . '/includes/bootstrap.php';

$orderId = (int) ($_GET['id'] ?? 0);
$order = null;

try {
    $pdo = get_pdo($config);
    $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = ? LIMIT 1');
    $stmt->execute([$orderId]);
    $order = $stmt->fetch();
} catch (Throwable $exception) {
    $order = null;
}

if (!$order) {
    header('Location: index.php');
    exit;
}

$assetVersion = (string) filemtime(__DIR__ . '/assets/css/style.css');
$logoVersion  = (string) filemtime(__DIR__ . '/assets/logo.png');
$pdfFilename  = 'commande-' . format_order_number((int) $order['id']) . '.pdf';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Commande enregistrée</title>
    <link rel="stylesheet" href="assets/css/style.css?v=<?= urlencode($assetVersion); ?>">
    <style>
        .done-screen { display:flex; flex-direction:column; align-items:center; justify-content:center; min-height:60vh; gap:16px; text-align:center; }
        .done-icon { font-size:3.5rem; }
        .done-title { font-size:1.6rem; font-weight:700; color:var(--green-dark); }
        .done-sub { color:var(--muted); font-size:1rem; }
    </style>
</head>
<body>
<div class="page-shell">
    <div class="panel">
        <div class="brand" style="margin-bottom:0;">
            <img class="main-logo small-logo" src="assets/logo.png?v=<?= urlencode($logoVersion); ?>" alt="Famipyro">
        </div>
        <div class="done-screen">
            <div class="done-icon">✅</div>
            <div class="done-title">Commande enregistrée</div>
            <div class="done-sub" id="done-sub">N° <?= e(format_order_number((int) $order['id'])); ?> — téléchargement en cours…</div>
            <a class="button secondary" id="pdf-btn" href="generate_pdf.php?id=<?= (int) $order['id']; ?>" style="display:none;">Télécharger le PDF manuellement</a>
            <a class="button" id="home-btn" href="index.php" style="display:none;">Nouvelle commande</a>
        </div>
    </div>
</div>
<script>
window.addEventListener('load', function () {
    fetch('generate_pdf.php?id=<?= (int) $order['id']; ?>')
        .then(function (response) {
            if (!response.ok) { throw new Error('HTTP ' + response.status); }
            return response.blob();
        })
        .then(function (blob) {
            var url = URL.createObjectURL(blob);
            var a = document.createElement('a');
            a.href = url;
            a.download = '<?= e($pdfFilename); ?>';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
            document.getElementById('done-sub').textContent = 'Téléchargement lancé — retour à l\'accueil dans un instant…';
            setTimeout(function () { window.location.href = 'index.php'; }, 1500);
        })
        .catch(function () {
            document.getElementById('done-sub').textContent = 'Le téléchargement automatique a échoué.';
            document.getElementById('pdf-btn').style.display = 'inline-block';
            document.getElementById('home-btn').style.display = 'inline-block';
        });
});
</script>
</body>
</html>
