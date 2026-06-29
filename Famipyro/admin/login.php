<?php
require __DIR__ . '/../includes/bootstrap.php';

if (is_admin()) {
    header('Location: index.php');
    exit;
}

$errorMessage = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (admin_credentials_are_valid($config, $username, $password)) {
        $_SESSION['admin_logged_in'] = true;
        header('Location: index.php');
        exit;
    }

    $errorMessage = 'Identifiants invalides.';
}
$assetVersion = (string) filemtime(__DIR__ . '/../assets/css/style.css');
$logoVersion = (string) filemtime(__DIR__ . '/../assets/logo.png');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Connexion admin</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= urlencode($assetVersion); ?>">
</head>
<body>
<div class="page-shell">
    <div class="panel auth-card">
        <div class="brand">
            <img class="main-logo small-logo" src="../assets/logo.png?v=<?= urlencode($logoVersion); ?>" alt="Famipyro">
            <p style="font-size:1.3rem; font-weight:700; color:#1f5a36; margin-top:8px;">Administration</p>
        </div>

        <?php if ($errorMessage): ?>
            <div class="notice error"><?= e($errorMessage); ?></div>
        <?php endif; ?>

        <form method="post" class="form-grid">
            <div class="form-group full">
                <label>Utilisateur</label>
                <input type="text" name="username" required>
            </div>
            <div class="form-group full">
                <label>Mot de passe</label>
                <input type="password" name="password" required>
            </div>
            <div class="form-group full">
                <button type="submit">Se connecter</button>
            </div>
        </form>
    </div>
</div>
</body>
</html>
