<?php
require_once 'config.php';
verifierConnexion($db);

$user_id = $_SESSION['user_id'];
$message = '';

// Photos de profil : sur le VOLUME persistant, catégorie « divers » (comme les icônes de
// module et l'habillage vidéo). Avant, elles vivaient dans public/uploads : elles étaient
// PERDUES à chaque redéploiement Railway et ne comptaient dans aucun total de stockage.
$storeBase = defined('FAMI_STORAGE_BASE') ? rtrim(FAMI_STORAGE_BASE, '/') : (__DIR__ . '/uploads');
$uploadDir = $storeBase . '/divers/profils/';
if (!is_dir($uploadDir)) {
    @mkdir($uploadDir, 0775, true);
}

// Traitement de l'upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['photo_profil'])) {
    requireValidCSRF();

    $file = $_FILES['photo_profil'];
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $maxSize = 5 * 1024 * 1024; // 5 Mo

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $message = "<div class='alert error'>❌ Erreur lors de l'upload.</div>";
    } elseif ($file['size'] > $maxSize) {
        $message = "<div class='alert error'>❌ Image trop grande (max 5 Mo).</div>";
    } elseif (!in_array($file['type'], $allowedTypes, true)) {
        $message = "<div class='alert error'>❌ Format non autorisé (JPEG, PNG, GIF, WebP uniquement).</div>";
    } else {
        // Vérification que c'est bien une image (sécurité supplémentaire)
        $imageInfo = @getimagesize($file['tmp_name']);
        if (!$imageInfo) {
            $message = "<div class='alert error'>❌ Fichier invalide.</div>";
        } else {
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $safeName = 'user_' . $user_id . '_' . time() . '.' . $ext;
            $destPath = $uploadDir . $safeName;

            // Supprimer l'ancienne photo si elle existe (volume OU ancien dossier public).
            $stmtOld = $db->prepare("SELECT photo_profil FROM utilisateurs WHERE id = ?");
            $stmtOld->execute([$user_id]);
            $oldPhoto = (string) $stmtOld->fetchColumn();
            if ($oldPhoto !== '') {
                $oldAbs = (strpos($oldPhoto, 'uploads/') === 0)
                    ? __DIR__ . '/' . $oldPhoto
                    : $storeBase . '/' . $oldPhoto;
                if (is_file($oldAbs)) { @unlink($oldAbs); }
            }

            if (move_uploaded_file($file['tmp_name'], $destPath)) {
                require_once 'includes/compress.php';
                famiCompressImageFile($destPath, 600);
                $relativePath = 'divers/profils/' . $safeName;
                $stmt = $db->prepare("UPDATE utilisateurs SET photo_profil = ? WHERE id = ?");
                $stmt->execute([$relativePath, $user_id]);
                $_SESSION['photo_profil'] = $relativePath;
                $message = "<div class='alert success'>✅ Photo mise à jour avec succès.</div>";
            } else {
                $message = "<div class='alert error'>❌ Impossible de sauvegarder la photo.</div>";
            }
        }
    }
}

// Suppression de la photo
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['supprimer_photo'])) {
    requireValidCSRF();
    $stmtOld = $db->prepare("SELECT photo_profil FROM utilisateurs WHERE id = ?");
    $stmtOld->execute([$user_id]);
    $oldPhoto = $stmtOld->fetchColumn();
    if ($oldPhoto) {
        $oldAbs = (strpos($oldPhoto, 'uploads/') === 0) ? __DIR__ . '/' . $oldPhoto : $storeBase . '/' . $oldPhoto;
        if (is_file($oldAbs)) { @unlink($oldAbs); }
    }
    $stmt = $db->prepare("UPDATE utilisateurs SET photo_profil = NULL WHERE id = ?");
    $stmt->execute([$user_id]);
    $_SESSION['photo_profil'] = null;
    $message = "<div class='alert success'>✅ Photo supprimée.</div>";
}

// Récupérer les infos actuelles
$stmtUser = $db->prepare("SELECT nom, prenom, photo_profil FROM utilisateurs WHERE id = ?");
$stmtUser->execute([$user_id]);
$currentUser = $stmtUser->fetch();
$currentPhoto = $currentUser['photo_profil'] ?? null;
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= t('Mon profil', 'Mijn profiel') ?> — FamiFormation</title>
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Open Sans', sans-serif; background: url('background.jpg') no-repeat center center fixed; background-size: cover; margin: 0; display: flex; flex-direction: column; align-items: center; min-height: 100vh; }
        .top-nav { width: 100%; display: flex; justify-content: space-between; align-items: center; padding: 10px 20px; box-sizing: border-box; }
        .btn-back { background: rgba(255,255,255,0.9); color: #2d5a37; text-decoration: none; padding: 10px 20px; border-radius: 30px; font-weight: bold; font-size: 0.9rem; box-shadow: 0 4px 10px rgba(0,0,0,0.1); transition: all 0.3s; }
        .btn-back:hover { transform: scale(1.05); background: #fff; }
        .card { background: rgba(255,255,255,0.97); border-radius: 20px; padding: 40px; width: 90%; max-width: 500px; box-shadow: 0 10px 30px rgba(0,0,0,0.15); margin-top: 20px; text-align: center; }
        h1 { color: #2d5a37; font-size: 1.8rem; margin-bottom: 10px; }
        .photo-preview { width: 130px; height: 130px; border-radius: 50%; object-fit: cover; border: 4px solid #2d5a37; margin: 15px auto; display: block; }
        .photo-placeholder { width: 130px; height: 130px; border-radius: 50%; background: #e8f5e9; border: 4px solid #2d5a37; margin: 15px auto; display: flex; align-items: center; justify-content: center; font-size: 3rem; }
        .nom-display { font-size: 1.3rem; font-weight: 700; color: #333; margin-bottom: 20px; }
        .form-group { margin: 20px 0; text-align: left; }
        label { font-weight: 600; color: #555; display: block; margin-bottom: 6px; }
        input[type="file"] { width: 100%; padding: 10px; border: 2px dashed #2d5a37; border-radius: 10px; background: #f9f9f9; cursor: pointer; box-sizing: border-box; }
        .btn-submit { background: #2d5a37; color: white; border: none; padding: 12px 30px; border-radius: 30px; font-weight: bold; font-size: 1rem; cursor: pointer; transition: all 0.3s; width: 100%; margin-top: 10px; }
        .btn-submit:hover { background: #1e3d25; transform: scale(1.02); }
        .btn-delete { background: #d93025; color: white; border: none; padding: 10px 25px; border-radius: 30px; font-weight: bold; font-size: 0.9rem; cursor: pointer; margin-top: 10px; transition: all 0.3s; }
        .btn-delete:hover { background: #a52019; }
        .alert { padding: 12px 18px; border-radius: 10px; margin-bottom: 20px; font-weight: 600; }
        .alert.success { background: #d4edda; color: #155724; }
        .alert.error { background: #f8d7da; color: #721c24; }
        .hint { font-size: 0.8rem; color: #888; margin-top: 6px; }
    </style>
</head>
<body>
<?php
    require_once __DIR__ . '/includes/topbar.php';
    famiTopbar($db, ['back' => 'index.php', 'title' => t('Mon profil', 'Mijn profiel')]);
?>
<div class="top-nav">
</div>
<div class="card">
    <h1>Mon profil</h1>

    <?= $message ?>

    <?php if ($currentPhoto && famiStoredFileExists($currentPhoto)): ?>
        <img src="<?= htmlspecialchars(moduleFileUrl($currentPhoto)) ?>&v=<?= time() ?>" alt="<?= t('Photo de profil', 'Profielfoto') ?>" class="photo-preview">
    <?php else: ?>
        <div class="photo-placeholder">👤</div>
    <?php endif; ?>

    <div class="nom-display">
        <?= htmlspecialchars(trim(($currentUser['prenom'] ?? '') . ' ' . ($currentUser['nom'] ?? ''))) ?>
    </div>

    <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
        <div class="form-group">
            <label for="photo_profil"><?= t('Choisir une nouvelle photo', 'Een nieuwe foto kiezen') ?></label>
            <input type="file" id="photo_profil" name="photo_profil" accept="image/jpeg,image/png,image/gif,image/webp" required>
            <p class="hint"><?= t('JPEG, PNG, GIF ou WebP — max 5 Mo', 'JPEG, PNG, GIF of WebP — max. 5 MB') ?></p>
        </div>
        <button type="submit" class="btn-submit">📷 <?= t('Mettre à jour la photo', 'Foto bijwerken') ?></button>
    </form>

    <?php if ($currentPhoto): ?>
    <form method="POST" onsubmit="return confirm('<?= t('Supprimer la photo de profil ?', 'Profielfoto verwijderen?') ?>');">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
        <input type="hidden" name="supprimer_photo" value="1">
        <button type="submit" class="btn-delete">🗑️ <?= t('Supprimer la photo', 'Foto verwijderen') ?></button>
    </form>
    <?php endif; ?>
</div>
</body>
</html>
