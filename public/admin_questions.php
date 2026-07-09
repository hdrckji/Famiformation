<?php
// 1. Diagnostic d'erreurs
ini_set('display_errors', 1);
error_reporting(E_ALL);

// 2. Inclusion de la configuration
require_once 'config.php';
require_once 'includes/modules.php'; // mymemoryTranslateFrToNl()
require_once 'includes/widget.php';  // ensureWidgetTables() -> colonnes NL

// 3. Vérification de la session Admin
requireAdminOrTeamcoach();
ensureWidgetTables($db); // garantit les colonnes *_nl sur quiz_questions

$message = "";

$all_possible_themes = [
    'quiz_gerbeur' => 'Gerbeur',
    'quiz_rongeur' => 'Rongeur',
    'quiz_logistique_reception' => 'Logistique : Réception',
    'quiz_logistique_transfert' => 'Logistique : Transfert',
    'quiz_barbecue_weber' => 'Barbecue Weber',
    'quiz_barbecue2' => 'Barbecue Barbecook et Napoleon',
    'quiz_spa' => 'Déco : Spa',
    'quiz_marketing' => 'Déco : Marketing',
    'quiz_onboarding_video' => 'Onboarding Vidéo',
    'quiz_onboarding_pdf' => 'Onboarding PDF',
    'quiz_caisse' => 'Caisse Vidéo',
    'quiz_pdf' => 'Caisse PDF',
    'quiz_becosoft' => 'Becosoft Recherche',
    'quiz_gazon' => 'Becosoft Gazon',
    'quiz_bon_vente' => 'Becosoft Bon de Vente',
    'quiz_vente_flash' => 'Becosoft Vente Flash',
    'quiz_plantes_hiver' => 'Plantes Intérieur Hiver',
    'quiz_garden' => 'Garden',
    'quiz_feedback' => 'Management : Donner du feedback',
    'quiz_entretien' => 'Management : Entretiens de collaboration',
    'quiz_judo' => 'Management : Judo verbal',
    'quiz_mentor' => 'Management : Parrain/Marraine',
    'quiz_leadership' => 'Management : Leadership',
    'quiz_presence' => 'Management : Gestion de la présence',
    'quiz_mix' => 'Mix (Présentation équipe mix)',
    'quiz_mix_video' => 'Formation Mix Vidéo',
    'quiz_fleurs_artificielles' => 'Déco : Fleurs artificielles (Video)',
    'quiz_fleurs_artificielles_pdf' => 'Déco : Fleurs artificielles (PDF)',
    'quiz_engin' => 'Piscine : Quizz Engin',
    'quiz_changement_saison' => 'Déco : Changement de saison',
];

// 4. Traitement de l'ajout d'une question
if (isset($_POST['add_question'])) {
    requireValidCSRF();
    try {
        $qText = trim($_POST['question_text'] ?? '');
        $rA = trim($_POST['r1'] ?? '');
        $rB = trim($_POST['r2'] ?? '');
        $rC = trim($_POST['r3'] ?? '');
        $qNl = $qText !== '' ? mymemoryTranslateFrToNl($qText) : '';
        $aNl = $rA !== '' ? mymemoryTranslateFrToNl($rA) : '';
        $bNl = $rB !== '' ? mymemoryTranslateFrToNl($rB) : '';
        $cNl = $rC !== '' ? mymemoryTranslateFrToNl($rC) : '';
        $stmt = $db->prepare("INSERT INTO quiz_questions (theme, question_text, option_a, option_b, option_c, reponse_correcte, question_text_nl, option_a_nl, option_b_nl, option_c_nl) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $_POST['theme'] ?? '',
            $qText,
            $rA,
            $rB,
            $rC,
            $_POST['correct_answer'] ?? '',
            $qNl !== '' ? $qNl : null,
            $aNl !== '' ? $aNl : null,
            $bNl !== '' ? $bNl : null,
            $cNl !== '' ? $cNl : null,
        ]);
        $message = "<div class='alert success'>✅ Question enregistrée avec succès !</div>";
    } catch (Exception $e) {
        $message = "<div class='alert error'>❌ Erreur : " . e($e->getMessage()) . "</div>";
    }
}

// 4b. Traitement de la modification d'une question
if (isset($_POST['update_question'])) {
    requireValidCSRF();
    try {
        $qText = trim($_POST['edit_question_text'] ?? '');
        $rA = trim($_POST['edit_r1'] ?? '');
        $rB = trim($_POST['edit_r2'] ?? '');
        $rC = trim($_POST['edit_r3'] ?? '');
        $qNl = $qText !== '' ? mymemoryTranslateFrToNl($qText) : '';
        $aNl = $rA !== '' ? mymemoryTranslateFrToNl($rA) : '';
        $bNl = $rB !== '' ? mymemoryTranslateFrToNl($rB) : '';
        $cNl = $rC !== '' ? mymemoryTranslateFrToNl($rC) : '';
        $stmt = $db->prepare("UPDATE quiz_questions SET theme = ?, question_text = ?, option_a = ?, option_b = ?, option_c = ?, reponse_correcte = ?, question_text_nl = ?, option_a_nl = ?, option_b_nl = ?, option_c_nl = ? WHERE id = ?");
        $stmt->execute([
            trim($_POST['edit_theme'] ?? ''),
            $qText,
            $rA,
            $rB,
            $rC,
            trim($_POST['edit_correct_answer'] ?? 'A'),
            $qNl !== '' ? $qNl : null,
            $aNl !== '' ? $aNl : null,
            $bNl !== '' ? $bNl : null,
            $cNl !== '' ? $cNl : null,
            $_POST['question_id'] ?? ''
        ]);
        $message = "<div class='alert success'>✅ Question mise à jour !</div>";
    } catch (Exception $e) {
        $message = "<div class='alert error'>❌ Erreur : " . e($e->getMessage()) . "</div>";
    }
}

// 5. Suppression d'une question
if (isset($_GET['delete'])) {
    requireValidCSRF();
    $stmt = $db->prepare("DELETE FROM quiz_questions WHERE id = ?");
    $stmt->execute([$_GET['delete']]);
    header('Location: admin_questions.php');
    exit();
}

// 6. Gestion du filtre par thème
$theme_filter = isset($_GET['filter_theme']) ? $_GET['filter_theme'] : '';
$sql = "SELECT * FROM quiz_questions";
$params = [];
if ($theme_filter != '') {
    $sql .= " WHERE theme = ?";
    $params[] = $theme_filter;
}
$sql .= " ORDER BY theme ASC, id DESC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$questions = $stmt->fetchAll();

// Récupérer la liste des thèmes pour le filtre
$themes_stmt = $db->query("SELECT DISTINCT theme FROM quiz_questions ORDER BY theme ASC");
$all_themes = $themes_stmt->fetchAll(PDO::FETCH_COLUMN);
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Gestion des Questions - FamiFormation</title>
    <link rel="shortcut icon" type="image/x-icon" href="favicon.ico">
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Open Sans', sans-serif; background: #f4f7f6; margin: 0; padding: 20px; color: #333; }
        .container { max-width: 1200px; margin: 0 auto; }
        h1 { color: #2d5a37; text-align: center; }
        .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; text-align: center; font-weight: bold; }
        .success { background: #d4edda; color: #155724; }
        .error { background: #f8d7da; color: #721c24; }
        .card { background: white; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); padding: 25px; margin-bottom: 30px; }
        .form-group { margin-bottom: 15px; }
        label { display: block; font-weight: bold; margin-bottom: 5px; color: #666; }
        input[type="text"], select { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px; box-sizing: border-box; }
        .options-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px; margin-top: 10px; }
        .btn-submit { background: #2d5a37; color: white; border: none; padding: 12px 25px; border-radius: 6px; cursor: pointer; font-weight: bold; width: 100%; font-size: 1rem; margin-top: 10px; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; font-size: 0.9rem; }
        th, td { padding: 10px; text-align: left; border-bottom: 1px solid #eee; vertical-align: middle; }
        th { background: #f8f9fa; color: #666; font-size: 0.75rem; text-transform: uppercase; }
        .badge-theme { background: #e8f5e9; color: #2d5a37; padding: 4px 8px; border-radius: 4px; font-size: 0.75rem; font-weight: bold; display: block; margin-bottom: 5px; }
        .input-inline { padding: 5px; font-size: 0.85rem; margin-bottom: 2px; }
        .btn-save { background: #2d5a37; color: white; border: none; padding: 5px 10px; border-radius: 4px; cursor: pointer; }
    </style>
</head>
<body>

<div class="container">
    <a href="index.php" style="text-decoration:none; color:#2d5a37; font-weight:bold;">⬅ Retour Accueil</a>
    <h1>Gestion des Questions Quiz</h1>

    <?php echo $message; ?>

    <div class="card">
        <h2>Ajouter une question</h2>
        <form method="POST">
            <?php echo csrfField(); ?>
            <div class="form-group">
                <label>Thème de la formation</label>
                <select name="theme" required>
                    <?php foreach ($all_possible_themes as $slug => $label): ?>
                        <option value="<?php echo $slug; ?>"><?php echo htmlspecialchars($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label>Texte de la question</label>
                <input type="text" name="question_text" placeholder="Ex: Quelle est la règle d'or pour..." required>
            </div>

            <div class="options-grid">
                <div class="form-group">
                    <label style="color: #2d5a37;">Option A (CORRECTE)</label>
                    <input type="text" name="r1" required placeholder="Réponse vraie">
                </div>
                <div class="form-group">
                    <label>Option B (Fausse)</label>
                    <input type="text" name="r2" required placeholder="Réponse fausse">
                </div>
                <div class="form-group">
                    <label>Option C (Fausse - Optionnel)</label>
                    <input type="text" name="r3" placeholder="Autre réponse fausse">
                </div>
            </div>

            <input type="hidden" name="correct_answer" value="A">
            <button type="submit" name="add_question" class="btn-submit">💾 Enregistrer la question</button>
        </form>
    </div>

    <div class="card">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h2>Questions en base (<?php echo count($questions); ?>)</h2>
            
            <form method="GET" style="display: flex; gap: 10px; align-items: center;">
                <label style="margin:0;">Filtrer par thème :</label>
                <select name="filter_theme" onchange="this.form.submit()" style="width: 200px;">
                    <option value="">Tous les thèmes</option>
                    <?php
                    foreach($all_possible_themes as $slug => $label): ?>
                        <option value="<?php echo $slug; ?>" <?php echo ($theme_filter == $slug) ? 'selected' : ''; ?> >
                            <?php echo $label; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>

        <table>
            <thead>
                <tr>
                    <th width="150">Thème</th>
                    <th>Question et Réponses</th>
                    <th width="100" style="text-align:right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($questions as $q): ?>
                <tr>
                    <td>
                        <span class="badge-theme"><?php echo htmlspecialchars($all_possible_themes[$q['theme']] ?? str_replace('quiz_', '', $q['theme'])); ?></span>
                    </td>
                    <td>
                        <form method="POST" style="margin:0;">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="question_id" value="<?php echo $q['id']; ?>">
                            <select name="edit_theme" class="input-inline" style="margin-bottom:6px;">
                                <?php foreach ($all_possible_themes as $slug => $label): ?>
                                    <option value="<?php echo $slug; ?>" <?php echo ($q['theme'] === $slug) ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input type="text" name="edit_question_text" class="input-inline" style="font-weight:bold; border-color:#2d5a37;" value="<?php echo htmlspecialchars($q['question_text']); ?>">
                            <div class="options-grid" style="margin-top:5px;">
                                <input type="text" name="edit_r1" class="input-inline" title="Réponse Correcte" style="background:#e8f5e9;" value="<?php echo htmlspecialchars($q['option_a']); ?>">
                                <input type="text" name="edit_r2" class="input-inline" value="<?php echo htmlspecialchars($q['option_b']); ?>">
                                <input type="text" name="edit_r3" class="input-inline" value="<?php echo htmlspecialchars($q['option_c']); ?>">
                            </div>
                            <div style="margin-top:8px; display:flex; align-items:center; gap:8px;">
                                <label style="margin:0; font-size:0.85rem; color:#2d5a37;">Bonne réponse</label>
                                <select name="edit_correct_answer" class="input-inline" style="width:90px;">
                                    <option value="A" <?php echo (($q['reponse_correcte'] ?? 'A') === 'A') ? 'selected' : ''; ?>>A</option>
                                    <option value="B" <?php echo (($q['reponse_correcte'] ?? 'A') === 'B') ? 'selected' : ''; ?>>B</option>
                                    <option value="C" <?php echo (($q['reponse_correcte'] ?? 'A') === 'C') ? 'selected' : ''; ?>>C</option>
                                </select>
                            </div>
                    </td>
                    <td style="text-align:right;">
                            <button type="submit" name="update_question" class="btn-save" title="Sauvegarder">💾</button>
                        </form>
                        <a href="?delete=<?php echo $q['id']; ?>&csrf=<?php echo getCSRFToken(); ?>" onclick="return confirm('Supprimer cette question ?')" style="text-decoration:none; margin-left:10px;">🗑️</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

</body>
</html>