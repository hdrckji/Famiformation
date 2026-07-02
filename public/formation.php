<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'config.php';
verifierConnexion($db);
require_once 'includes/quizz_status.php';
$user_id = getCurrentUserId();
$role = getCurrentRole();
$message = "";

$caisseQuizStatus = null;
if ($role === 'etudiant') {
    $caisseQuizStatus = getCaisseQuizValidationStatus($user_id);
}


// Vue courante : landing (2 modules), présentiel, ou en ligne
$vue = $_GET['vue'] ?? '';
if (!in_array($vue, ['presentiel', 'enligne'], true)) {
    $vue = '';
}

// Restriction accès planning pour étudiant sans quizz validés (uniquement en présentiel)
// Flag pour le pop-up
$hasPlanningAccess = ($role !== 'etudiant' || hasCompletedCaisseQuizzes($user_id));
$showQuizzPopup = ($vue === 'presentiel') && !$hasPlanningAccess;

// 1. Inscription à un créneau
if (isset($_POST['inscrire'])) {
    // Validation CSRF
    requireValidCSRF();

    if (!$hasPlanningAccess) {
        $message = "<div class='alert error'>⛔ Vous devez d'abord valider les deux quiz de la formation caisse avant d'accéder au planning.</div>";
    } else {
    
    $creneau_id = $_POST['creneau_id'] ?? '';
    // Récupérer l'ID de formation du créneau
    $formIdStmt = $db->prepare("SELECT formation_id FROM formations_creneaux WHERE id = ?");
    $formIdStmt->execute([$creneau_id]);
    $formId = $formIdStmt->fetchColumn();
    // Vérifier si déjà inscrit à un créneau de cette formation
    $check = $db->prepare("SELECT id FROM formations_inscriptions WHERE formation_id = ? AND utilisateur_id = ? AND creneau_id IS NOT NULL");
    $check->execute([$formId, $user_id]);
    if ($check->fetch()) {
        $message = "<div class='alert error'>⚠️ Déjà inscrit à un créneau de cette formation.</div>";
    } else {
        // Vérifier places disponibles
        $st_places = $db->prepare("SELECT places_max, (SELECT COUNT(*) FROM formations_inscriptions WHERE creneau_id = ?) as inscrits FROM formations_creneaux WHERE id = ?");
        $st_places->execute([$creneau_id, $creneau_id]);
        $row = $st_places->fetch();
        $dispo = $row ? ($row['places_max'] - $row['inscrits']) : 0;
        if ($dispo <= 0) {
            $message = "<div class='alert error'>⛔ Ce créneau est déjà complet. Impossible de vous inscrire.</div>";
        } else {
            $firstEnrollmentStmt = $db->prepare("SELECT COUNT(*) FROM formations_inscriptions WHERE utilisateur_id = ? AND creneau_id IS NOT NULL");
            $firstEnrollmentStmt->execute([$user_id]);
            $isFirstPhysicalEnrollment = ((int) $firstEnrollmentStmt->fetchColumn() === 0);

            $ins = $db->prepare("INSERT INTO formations_inscriptions (formation_id, creneau_id, utilisateur_id) VALUES (?, ?, ?)");
            $ins->execute([$formId, $creneau_id, $user_id]);
            $emailSent = false;
            $agencyMailSent = false;
            $accueilMailSent = false;
            if ($formId) {
                if ($role === 'etudiant') {
                    $emailSent = sendStudentTrainingEnrollmentEmail($db, $user_id, $formId, $creneau_id);
                    $accueilMailSent = sendAccueilTrainingEnrollmentEmail($db, $user_id, $formId, $creneau_id);
                    if ($isFirstPhysicalEnrollment) {
                        $agencyMailSent = sendInterimAgencyEnrollmentEmail($db, $user_id, $formId, $creneau_id);
                    }
                }
            }
            if ($role === 'etudiant') {
                if ($emailSent) {
                    $message = "<div class='alert success'>✅ Inscription confirmée ! Un email de confirmation a été envoyé.";
                    if ($agencyMailSent) {
                        $message .= "<br>📨 L'agence intérim a également été notifiée de ce premier rendez-vous.";
                    }
                    if ($accueilMailSent) {
                        $message .= "<br>📨 L'accueil a été informé de la date et de l'heure du rendez-vous.";
                    }
                    $message .= "</div>";
                } else {
                    $message = "<div class='alert success'>✅ Inscription confirmée ! <strong>Le mail de notification n'a pas pu être envoyé.</strong>";
                    if ($agencyMailSent) {
                        $message .= "<br>📨 L'agence intérim a tout de même été notifiée de ce premier rendez-vous.";
                    }
                    if ($accueilMailSent) {
                        $message .= "<br>📨 L'accueil a été informé de la date et de l'heure du rendez-vous.";
                    }
                    $message .= "</div>";
                }
            } else {
                $message = "<div class='alert success'>✅ Inscription confirmée !</div>";
            }
        }
    }
    }
}

// 2. Manifester son intérêt (Sans date)
if (isset($_POST['interet'])) {
    // Validation CSRF
    requireValidCSRF();

    if (!$hasPlanningAccess) {
        $message = "<div class='alert error'>⛔ Vous devez d'abord valider les deux quiz de la formation caisse avant d'accéder au planning.</div>";
    } else {
    
    $f_id = $_POST['f_id'] ?? '';
    $check = $db->prepare("SELECT id FROM formations_inscriptions WHERE formation_id = ? AND utilisateur_id = ? AND creneau_id IS NULL");
    $check->execute([$f_id, $user_id]);
    if ($check->fetch()) {
        $message = "<div class='alert success'>✅ Votre intérêt est déjà enregistré.</div>";
    } else {
        $ins = $db->prepare("INSERT INTO formations_inscriptions (formation_id, creneau_id, utilisateur_id) VALUES (?, NULL, ?)");
        $ins->execute([$f_id, $user_id]);
        
        $emailSent = false;
        if ($role === 'etudiant') {
            $emailSent = sendEnrollmentEmail($db, $user_id, $f_id, null);
        }

        if ($role === 'etudiant' && $emailSent) {
            $message = "<div class='alert success'>✅ Merci ! Votre intérêt pour cette formation a été enregistré. Un email de confirmation a été envoyé.</div>";
        } elseif ($role === 'etudiant') {
            $message = "<div class='alert success'>✅ Merci ! Votre intérêt pour cette formation a été enregistré. <strong>Le mail n'a pas pu être envoyé.</strong></div>";
        } else {
            $message = "<div class='alert success'>✅ Merci ! Votre intérêt pour cette formation a été enregistré.</div>";
        }
    }
    }
}

// Suggestions de formations
$db->exec("CREATE TABLE IF NOT EXISTS formation_suggestions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    utilisateur_id INT NOT NULL,
    sujet VARCHAR(255) NOT NULL,
    description TEXT NULL,
    date_soumission DATETIME DEFAULT CURRENT_TIMESTAMP
) DEFAULT CHARSET=utf8mb4");

if (isset($_POST['suggest_formation'])) {
    requireValidCSRF();
    $sujet = trim($_POST['sujet'] ?? '');
    $descSug = trim($_POST['description_suggestion'] ?? '');
    if ($sujet !== '') {
        $stmtSug = $db->prepare("INSERT INTO formation_suggestions (utilisateur_id, sujet, description) VALUES (?, ?, ?)");
        $stmtSug->execute([$user_id, $sujet, $descSug !== '' ? $descSug : null]);
        $message = "<div class='alert success'>💡 Merci ! Votre idée a bien été transmise.</div>";
    }
}

// Filtrage par profil (public_vise) - supporte plusieurs valeurs séparées par virgule
$stmt = $db->prepare("SELECT * FROM formations_sessions 
    WHERE public_vise = 'tous' 
       OR FIND_IN_SET(?, public_vise) 
    ORDER BY id DESC");
$stmt->execute([$role]);
$formations = $stmt->fetchAll();

// Formations en ligne = contenu du magasin coché "à évaluer"
require_once 'includes/modules.php';
ensureModulesTable($db);
$formationsEnLigne = [];
try {
    $rowsEnLigne = $db->query("SELECT * FROM modules WHERE a_evaluer = 1 AND is_active = 1 AND is_container = 0 ORDER BY nom ASC")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rowsEnLigne as $rEnLigne) {
        if (userCanSeeModule($rEnLigne, $role)) {
            $formationsEnLigne[] = $rEnLigne;
        }
    }
} catch (Exception $e) {
    $formationsEnLigne = [];
}

// Migration colonne is_evaluation_target sur formations_sessions
try {
    $colCheck = $db->query("SHOW COLUMNS FROM formations_sessions LIKE 'is_evaluation_target'");
    if (!$colCheck->fetch()) {
        $db->exec("ALTER TABLE formations_sessions ADD COLUMN is_evaluation_target TINYINT(1) NOT NULL DEFAULT 0");
    }
} catch (Exception $e) {}

// Récupérer evaluation_done pour l'étudiant courant
$studentEvaluationDone = false;
if ($role === 'etudiant') {
    try {
        $colEval = $db->query("SHOW COLUMNS FROM utilisateurs LIKE 'evaluation_done'");
        if ($colEval->fetch()) {
            $evalDoneStmt = $db->prepare("SELECT evaluation_done FROM utilisateurs WHERE id = ?");
            $evalDoneStmt->execute([$user_id]);
            $studentEvaluationDone = (bool) $evalDoneStmt->fetchColumn();
        }
    } catch (Exception $e) {}
}

$publicLabels = [
    'tous'               => '🌍 Tous',
    'employe_magasin'    => '👔 Magasin',
    'employe_logistique' => '🚚 Logistique',
    'etudiant'           => '🎓 Étudiant',
    'admin'              => '🛠 Admin',
    'teamcoach'          => '🧑‍🏫 TeamCoach',
    'mentor'             => '🧑‍🎓 Mentor',
];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Formation - Famiflora</title>
    <link rel="shortcut icon" type="image/x-icon" href="favicon.ico">
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Open Sans', sans-serif; background: url('background.jpg') no-repeat center center fixed; background-size: cover; margin: 0; display: flex; flex-direction: column; align-items: center; min-height: 100vh; }
        .container { background: rgba(255, 255, 255, 0.95); max-width: 1200px; width: 92%; padding: 40px; border-radius: 20px; box-shadow: 0 15px 35px rgba(0,0,0,0.2); text-align: center; margin-top: 50px; position: relative; }
        h1 { color: #2d5a37; margin-bottom: 20px; }
        .alert { padding: 15px; border-radius: 10px; margin-bottom: 20px; text-align: center; font-weight: bold; }
        .success { background: #d4edda; color: #155724; }
        .error { background: #f8d7da; color: #721c24; }
        .formations-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 20px; align-items: start; }
        .formation-card { background: white; border: 1px solid #eee; padding: 25px; border-radius: 15px; margin-bottom: 25px; text-align: left; position: relative; transition: transform 0.3s; }
        .formation-card:hover { transform: translateY(-5px); box-shadow: 0 5px 15px rgba(0,0,0,0.1); }
        .formation-title { font-size: 1.4rem; color: #2d5a37; font-weight: 700; margin-bottom: 10px; }
        .formation-desc { color: #666; font-size: 1rem; margin-bottom: 20px; line-height: 1.5; }
        .btn-admin-top { position: absolute; top: 20px; right: 20px; background: #2d5a37; color: white; padding: 10px 20px; border-radius: 50px; text-decoration: none; font-weight: bold; font-size: 0.9rem; transition: background 0.3s; }
        .btn-admin-top:hover { background: #1e3d25; }
        .select-date { width: 100%; padding: 12px; border-radius: 10px; border: 1px solid #ddd; font-family: inherit; font-size: 1rem; margin-bottom: 15px; outline: none; }
        .btn { border: none; padding: 12px 25px; border-radius: 50px; cursor: pointer; font-weight: bold; font-size: 1rem; transition: 0.3s; width: 100%; }
        .btn-ins { background: #d9a406; color: white; }
        .btn-ins:hover { background: #b88a05; }
        .btn-int { background: #2d5a37; color: white; }
        .btn-int:hover { background: #1e3d25; }
        .badge-inscrit { display: inline-block; background: #e8f5e9; color: #2d5a37; padding: 10px 20px; border-radius: 50px; font-weight: bold; margin-top: 10px; border: 1px solid #2d5a37; }
        .btn-back-home { display: block; text-align: center; background: #2d5a37; color: white; padding: 12px 30px; border-radius: 50px; font-weight: bold; text-decoration: none; margin-top: 20px; width: fit-content; margin-left: auto; margin-right: auto; transition: 0.3s; }
        .btn-back-home:hover { background: #1e3d25; transform: scale(1.05); }
        .quiz-status-box { margin-top: 18px; text-align: left; background: #f8fbf8; border: 1px solid #dde7df; border-radius: 16px; padding: 18px; }
        .quiz-status-title { margin: 0 0 10px; color: #2d5a37; font-weight: 700; }
        .quiz-status-row { display: flex; justify-content: space-between; align-items: center; gap: 16px; padding: 10px 0; border-top: 1px solid #e7eee8; }
        .quiz-status-row:first-of-type { border-top: none; padding-top: 0; }
        .quiz-status-badge { display: inline-block; padding: 6px 12px; border-radius: 999px; font-size: 0.85rem; font-weight: 700; }
        .quiz-status-badge.valid { background: #e7f6ea; color: #1f7a3c; }
        .quiz-status-badge.locked { background: #fff1ef; color: #b34434; }
        /* Badges profil */
        .badge-public { display: inline-block; background: #e8f0fe; color: #1a56a0; padding: 2px 10px; border-radius: 999px; font-size: 0.78rem; font-weight: 600; margin-right: 4px; margin-bottom: 6px; }
        /* Liste de créneaux */
        .creneaux-list { display: flex; flex-direction: column; gap: 10px; margin-top: 5px; }
        .creneau-row { display: flex; align-items: center; justify-content: space-between; padding: 12px 16px; border-radius: 12px; background: #f8fdf9; border: 1px solid #d8eddc; gap: 12px; flex-wrap: wrap; }
        .creneau-row.creneau-full { background: #fdf8f8; border-color: #eedddd; }
        .creneau-info { display: flex; align-items: center; flex-wrap: wrap; gap: 12px; flex: 1; }
        .creneau-date { font-weight: 600; color: #2d5a37; }
        .creneau-duree { color: #888; font-size: 0.9rem; }
        .creneau-places { font-size: 0.9rem; font-weight: 600; }
        .places-dispo { color: #1f7a3c; }
        .places-full { color: #b34434; }
        .btn-creneau { background: #d9a406; color: white; border: none; padding: 8px 20px; border-radius: 999px; cursor: pointer; font-weight: bold; font-size: 0.95rem; transition: background 0.2s; white-space: nowrap; }
        .btn-creneau:hover { background: #b88a05; }
        .no-date-msg { color: #666; font-style: italic; font-size: 0.9rem; margin-bottom: 15px; }
        /* Encart suggestion */
        .suggestion-box { background: #f6fdf7; border: 1px solid #c8e6cc; padding: 28px; border-radius: 18px; margin-top: 30px; text-align: left; }
        .suggestion-box h2 { color: #2d5a37; font-size: 1.1rem; margin: 0 0 6px; }
        .suggestion-box p { color: #666; font-size: 0.95rem; margin: 0 0 16px; }
        .suggestion-input { width: 100%; padding: 12px; border-radius: 10px; border: 1px solid #ddd; font-family: inherit; font-size: 1rem; box-sizing: border-box; margin-bottom: 10px; }
        .btn-suggest { background: #2d5a37; color: white; border: none; padding: 11px 28px; border-radius: 999px; cursor: pointer; font-weight: bold; font-size: 1rem; }
        .btn-suggest:hover { background: #1e3d25; }
        @media (max-width: 1200px) {
            .formations-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        @media (max-width: 980px) {
            .container { width: 94%; padding: 26px; }
            .formations-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="container">
        <?php if ($vue === ''): ?>
        <h1>🎓 Formation</h1>
        <p style="color:#666; margin-bottom:30px;">Choisissez un type de formation.</p>
        <div class="formations-grid" style="grid-template-columns:repeat(2,minmax(0,1fr));">
            <a href="formation.php?vue=enligne" class="formation-card" style="text-decoration:none; color:inherit; text-align:center;">
                <div style="font-size:3rem;">💻</div>
                <div class="formation-title" style="text-align:center;">En ligne</div>
                <div class="formation-desc" style="text-align:center;">Formations à faire directement sur le site.</div>
            </a>
            <a href="formation.php?vue=presentiel" class="formation-card" style="text-decoration:none; color:inherit; text-align:center;">
                <div style="font-size:3rem;">📅</div>
                <div class="formation-title" style="text-align:center;">Présentiel</div>
                <div class="formation-desc" style="text-align:center;">Sessions de formation pratique en magasin.</div>
            </a>
        </div>

        <?php elseif ($vue === 'enligne'): ?>
        <a href="formation.php" style="display:inline-block; color:#2d5a37; font-weight:bold; text-decoration:none; margin-bottom:10px;">⬅ Retour</a>
        <h1>💻 Formations en ligne</h1>
        <p style="color: #666; margin-bottom: 30px;">Formations à réaliser directement sur le site. Consultez le contenu, vous serez ensuite évalué.</p>
        <?php if (!empty($formationsEnLigne)): ?>
        <div class="formations-grid">
            <?php foreach ($formationsEnLigne as $fl): ?>
            <div class="formation-card">
                <div class="formation-title"><?= moduleIconHtml($fl, '1.4rem') ?> <?= htmlspecialchars($fl['nom']) ?></div>
                <?php if (!empty($fl['description'])): ?>
                    <div class="formation-desc"><?= htmlspecialchars($fl['description']) ?></div>
                <?php endif; ?>
                <a href="module.php?id=<?= (int) $fl['id'] ?>" class="btn btn-int" style="display:block; text-align:center; text-decoration:none; box-sizing:border-box;">Accéder à la formation</a>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="formation-card" style="text-align:center;">Aucune formation en ligne pour l'instant.</div>
        <?php endif; ?>

        <?php else: ?>
        <a href="formation.php" style="display:inline-block; color:#2d5a37; font-weight:bold; text-decoration:none; margin-bottom:10px;">⬅ Retour</a>
        <?php if ($role === 'admin' || $role === 'employe_magasin' || $role === 'teamcoach' || $role === 'mentor'): ?>
              <?php if (isset($_SESSION['role']) && $_SESSION['role'] !== 'teamcoach') : ?>
                 <a href="admin_formations.php" class="btn-admin-top">⚙️ Gérer les sessions</a>
              <?php endif; ?>
        <?php endif; ?>

        <h1>📅 Formations Présentielles</h1>
        <p style="color: #666; margin-bottom: 30px;">Inscrivez-vous aux prochaines sessions de formation pratique en magasin.</p>
        
        <?php echo $message; ?>

        <?php if ($hasPlanningAccess): ?>
        <div class="formations-grid">
        <?php foreach ($formations as $f): ?>
            <div class="formation-card">
                <?php if (!empty($f['public_vise'])): $pubTags = array_filter(array_map('trim', explode(',', $f['public_vise']))); ?>
                    <div style="margin-bottom:10px;">
                        <?php foreach($pubTags as $pt): ?>
                            <span class="badge-public"><?php echo htmlspecialchars($publicLabels[$pt] ?? $pt); ?></span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <div class="formation-title"><?php echo htmlspecialchars($f['titre']); ?></div>
                <div class="formation-desc"><?php echo htmlspecialchars($f['description']); ?></div>

                <?php 
                $check_ins = $db->prepare("SELECT c.date_heure FROM formations_inscriptions i JOIN formations_creneaux c ON i.creneau_id = c.id WHERE i.formation_id = ? AND i.utilisateur_id = ?");
                $check_ins->execute([$f['id'], $user_id]);
                $already = $check_ins->fetch();

                if ($already): ?>
                    <div class="badge-inscrit">✅ Vous êtes inscrit pour le <?php echo date('d/m/Y à H:i', strtotime($already['date_heure'])); ?></div>
                                <?php elseif ($role === 'etudiant' && !empty($f['is_evaluation_target']) && $studentEvaluationDone): ?>
                                    <div class="badge-inscrit" style="background:#e8f5e9;color:#1b5e20;border-color:#388e3c;">
                                        ✅ Formation et évaluation caisse déjà réalisées
                                    </div>
                <?php else: 
                    $st_c = $db->prepare("SELECT *, (SELECT COUNT(*) FROM formations_inscriptions WHERE creneau_id = formations_creneaux.id) as inscrits FROM formations_creneaux WHERE formation_id = ? AND date_heure > NOW() ORDER BY date_heure ASC");
                    $st_c->execute([$f['id']]);
                    $creneaux = $st_c->fetchAll();

                    if (count($creneaux) > 0): ?>
                        <div class="creneaux-list">
                            <?php foreach ($creneaux as $c):
                                $dispo = $c['places_max'] - $c['inscrits']; ?>
                                <div class="creneau-row <?php echo $dispo <= 0 ? 'creneau-full' : ''; ?>">
                                    <div class="creneau-info">
                                        <span class="creneau-date">📅 <?php echo date('d/m/Y à H:i', strtotime($c['date_heure'])); ?></span>
                                        <?php if (!empty($c['duree'])): ?>
                                            <span class="creneau-duree">⏱ <?php echo htmlspecialchars($c['duree']); ?></span>
                                        <?php endif; ?>
                                        <span class="creneau-places <?php echo $dispo <= 0 ? 'places-full' : 'places-dispo'; ?>">
                                            <?php echo $dispo <= 0 ? '🔴 Complet' : ('🟢 ' . $dispo . ' place' . ($dispo > 1 ? 's' : '')); ?>
                                        </span>
                                    </div>
                                    <?php if ($dispo > 0): ?>
                                    <form method="POST" style="display:inline;">
                                        <?php echo csrfField(); ?>
                                        <input type="hidden" name="creneau_id" value="<?php echo $c['id']; ?>">
                                        <button type="submit" name="inscrire" class="btn-creneau">S'inscrire</button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <form method="POST">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="f_id" value="<?php echo $f['id']; ?>">
                            <p class="no-date-msg">⏳ Aucune date n'est encore planifiée pour cette session.</p>
                            <button type="submit" name="interet" class="btn btn-int">Cette formation m'intéresse !</button>
                        </form>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        </div>
        <?php else: ?>
            <div class="formation-card" style="text-align:center;">
                <div class="formation-title">Accès au planning verrouillé</div>
                <div class="formation-desc">Vous devez valider les deux quiz liés à la formation caisse avec au moins <?php echo (int) ($caisseQuizStatus['required_score'] ?? 8); ?>/10 chacun avant de pouvoir consulter les sessions de formation.</div>
                <?php if ($caisseQuizStatus !== null): ?>
                    <div class="quiz-status-box">
                        <div class="quiz-status-title">État actuel de vos quiz caisse</div>
                        <div class="quiz-status-row">
                            <span>Quiz vidéo caisse</span>
                            <span class="quiz-status-badge <?php echo !empty($caisseQuizStatus['video_valid']) ? 'valid' : 'locked'; ?>">
                                <?php echo $caisseQuizStatus['video_best_score'] !== null ? $caisseQuizStatus['video_best_score'] . '/10' : 'Non passé'; ?>
                            </span>
                        </div>
                        <div class="quiz-status-row">
                            <span>Quiz PDF caisse</span>
                            <span class="quiz-status-badge <?php echo !empty($caisseQuizStatus['pdf_valid']) ? 'valid' : 'locked'; ?>">
                                <?php echo $caisseQuizStatus['pdf_best_score'] !== null ? $caisseQuizStatus['pdf_best_score'] . '/10' : 'Non passé'; ?>
                            </span>
                        </div>
                    </div>
                <?php endif; ?>
                <a href="formation-caisse.php" class="btn btn-ins" style="display:inline-block;width:auto;text-decoration:none;">Aller à la formation caisse</a>
            </div>
        <?php endif; ?>

        <div class="suggestion-box">
            <h2>💡 Une idée de formation ?</h2>
            <p>Partagez vos suggestions, nous en tiendrons compte pour les prochaines sessions.</p>
            <form method="POST">
                <?php echo csrfField(); ?>
                <input type="text" name="sujet" class="suggestion-input" placeholder="Sujet de la formation souhaitée *" required maxlength="255">
                <textarea name="description_suggestion" class="suggestion-input" rows="3" placeholder="Décrivez brièvement votre idée (optionnel)" style="resize:vertical;"></textarea>
                <button type="submit" name="suggest_formation" class="btn-suggest">Envoyer ma suggestion</button>
            </form>
        </div>
        <?php endif; ?>

        <a href="index.php" class="btn-back-home">⬅ Retour au menu principal</a>
    </div>

<?php if (!empty($showQuizzPopup)): ?>
<script>
document.addEventListener("DOMContentLoaded", function() {
    var popup = document.createElement("div");
    popup.style.position = "fixed";
    popup.style.top = "0";
    popup.style.left = "0";
    popup.style.width = "100vw";
    popup.style.height = "100vh";
    popup.style.background = "rgba(0,0,0,0.35)";
    popup.style.display = "flex";
    popup.style.alignItems = "center";
    popup.style.justifyContent = "center";
    popup.style.zIndex = "9999";
    popup.innerHTML = `
        <div style="background:#fffbe6;border:2px solid #d9a406;padding:40px 30px;border-radius:20px;box-shadow:0 8px 32px rgba(0,0,0,0.18);color:#a83232;font-size:1.2em;max-width:400px;text-align:center;">
            <span style="font-size:2.5em;">⛔</span><br>
            <strong>Accès au planning bloqué</strong><br><br>
            Vous devez valider les deux quiz caisse avec au moins <?php echo (int) ($caisseQuizStatus['required_score'] ?? 8); ?>/10 chacun pour accéder au planning des formations.<br><br>
            <button onclick="window.location.href='index.php'" style="background:#d9a406;color:#fff;padding:10px 30px;border:none;border-radius:15px;font-size:1em;cursor:pointer;">Retour à l'accueil</button>
        </div>
    `;
    document.body.appendChild(popup);
});
</script>
<?php endif; ?>
</body>
</html>