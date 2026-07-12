<?php
// ============================================================
// quiz.php — page dédiée pour PASSER le quiz (séparée du guide/vidéo).
//   Le quiz n'apparaît jamais dans le guide ; on y arrive par un bouton.
// ============================================================
require_once 'config.php';
verifierConnexion($db);
require_once 'includes/modules.php';
require_once 'includes/quiz_view.php';

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$module = $id > 0 ? getModuleById($db, $id) : null;
if (!$module || empty($module['quiz_json'])) { header('Location: index.php'); exit(); }

$isAdmin = ((($_SESSION['role'] ?? '') === 'admin') && (!function_exists('isApercuActif') || !isApercuActif()));
if (!$isAdmin && function_exists('userCanSeeModule') && !userCanSeeModule($module, currentDisplayRole())) {
    header('Location: index.php'); exit();
}

$quiz = json_decode((string) $module['quiz_json'], true);
$backId = !empty($module['parent_id']) ? (int) $module['parent_id'] : (int) $id;
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Quiz — <?= htmlspecialchars(moduleNom($module)) ?></title>
<link rel="shortcut icon" type="image/x-icon" href="favicon.ico">
<link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700&display=swap" rel="stylesheet">
<style>
    body { font-family:'Open Sans',sans-serif; background:url('background.jpg') no-repeat center center fixed; background-size:cover; margin:0; display:flex; flex-direction:column; align-items:center; min-height:100vh; }
    .topbar { width:100%; box-sizing:border-box; padding:16px; }
    .back-link { color:#2d5a37; text-decoration:none; font-weight:bold; background:rgba(255,255,255,0.9); padding:10px 18px; border-radius:20px; }
    .qhead { text-align:center; padding:10px 20px 0; }
    .qhead h1 { color:#2d5a37; background:rgba(255,255,255,0.92); padding:12px 30px; border-radius:30px; display:inline-block; }
</style>
</head>
<body>
    <div class="topbar"><a href="module.php?id=<?= (int) $backId ?>" class="back-link">⬅ <?= t('Retour', 'Terug') ?></a></div>
    <div class="qhead"><h1>📝 <?= htmlspecialchars(moduleNom($module)) ?></h1></div>
    <?php renderQuizForm($quiz, (int) $id); ?>
</body>
</html>
