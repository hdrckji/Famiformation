<?php
// Affiche toutes les erreurs PHP
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'config.php';
verifierConnexion($db);
if (!function_exists('isAdmin')) {
    echo "<div class='alert error'>Erreur : fonction isAdmin() non définie.</div>";
    exit;
}
if (!isAdmin()) {
    echo "<div class='alert error'>Accès refusé : vous n'êtes pas administrateur.";
    header('Location: index.php');
    exit();
}
$user_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($user_id <= 0) {
    echo "<div class='alert error'>Utilisateur non trouvé (ID manquant ou invalide).";
    exit;
}
if (!isset($db) || !$db) {
    echo "<div class='alert error'>Erreur de connexion à la base de données.</div>";
    exit;
}
ensureUserProfileColumns($db);
// Infos utilisateur
try {
    $stmt = $db->prepare("SELECT * FROM utilisateurs WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();
} catch (Exception $e) {
    echo "<div class='alert error'>Erreur SQL : ".htmlspecialchars($e->getMessage())."</div>";
    exit;
}
if (!$user) {
    echo "<div class='alert error'>Utilisateur non trouvé (ID: $user_id).</div>";
    exit;
}

$pageMessage = '';
$departmentSyncNote = '';

// Enregistrement ville + date d'anniversaire (tous rôles)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_profile_info'])) {
    requireValidCSRF();
    $ville = trim((string) ($_POST['ville'] ?? ''));
    $dateNaissance = trim((string) ($_POST['date_naissance'] ?? ''));
    $dateVal = (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateNaissance) === 1) ? $dateNaissance : null;
    try {
        $db->prepare("UPDATE utilisateurs SET ville = ?, date_naissance = ? WHERE id = ?")
           ->execute([$ville !== '' ? $ville : null, $dateVal, $user_id]);
        $stmt = $db->prepare("SELECT * FROM utilisateurs WHERE id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch();
        $pageMessage = "<div class='alert' style='background:#dff3e3;color:#1d6a39;padding:10px 14px;border-radius:8px;font-weight:700;'>Ville et date d'anniversaire enregistrées.</div>";
    } catch (Exception $e) {
        $pageMessage = "<div class='alert error'>Enregistrement impossible : " . htmlspecialchars($e->getMessage()) . "</div>";
    }
}

if (($user['role'] ?? '') === 'etudiant') {
    ensureDepartmentsTable($db);
    ensureStudentDepartmentLinksTable($db);

    $planningDepartmentNames = [];
    $usePlanningDepartmentCatalog = false;
    $planningDbConfigured = hasPlanningDbConfig();

    try {
        $planningDepartmentNames = syncDepartmentsFromPlanningDb($db);
        $usePlanningDepartmentCatalog = !empty($planningDepartmentNames);
    } catch (Exception $e) {
        $departmentSyncNote = "<div class='alert error'>La base des départements configurée dans le .env n'a pas pu être lue. La liste locale reste utilisée.</div>";
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        requireValidCSRF();

        if (isset($_POST['add_department_link'])) {
            $departmentName = trim((string) ($_POST['department_name'] ?? ''));
            $priorityRank = max(1, (int) ($_POST['priority_rank'] ?? 1));

            if ($departmentName === '') {
                $pageMessage = "<div class='alert error'>❌ Le département est obligatoire.</div>";
            } else {
                $normalizedDepartmentName = substr($departmentName, 0, 120);
                $departmentCatalog = [];
                $excludedDepartmentNames = getExcludedDepartmentNames();
                $excludedIndex = [];
                foreach ($excludedDepartmentNames as $excludedDepartmentName) {
                    $excludedIndex[function_exists('mb_strtolower') ? mb_strtolower($excludedDepartmentName, 'UTF-8') : strtolower($excludedDepartmentName)] = true;
                }

                $normalizedInputKey = function_exists('mb_strtolower')
                    ? mb_strtolower($normalizedDepartmentName, 'UTF-8')
                    : strtolower($normalizedDepartmentName);

                if (isset($excludedIndex[$normalizedInputKey])) {
                    $pageMessage = "<div class='alert error'>❌ Ce département a été supprimé définitivement et ne peut plus être utilisé.</div>";
                }

                if ($pageMessage === '' && $usePlanningDepartmentCatalog) {
                    foreach ($planningDepartmentNames as $planningDepartmentName) {
                        $catalogKey = function_exists('mb_strtolower')
                            ? mb_strtolower($planningDepartmentName, 'UTF-8')
                            : strtolower($planningDepartmentName);
                        $departmentCatalog[$catalogKey] = $planningDepartmentName;
                    }

                    $inputKey = function_exists('mb_strtolower')
                        ? mb_strtolower($normalizedDepartmentName, 'UTF-8')
                        : strtolower($normalizedDepartmentName);

                    if (!isset($departmentCatalog[$inputKey])) {
                        $pageMessage = "<div class='alert error'>❌ Ce département n'existe pas dans la base planning. Sélectionne un département existant.</div>";
                    } else {
                        $normalizedDepartmentName = $departmentCatalog[$inputKey];
                    }
                }

                if ($pageMessage === '') {
                    $departmentStmt = $db->prepare(
                        "INSERT INTO departments (department_name, is_active)
                         VALUES (?, 1)
                         ON DUPLICATE KEY UPDATE department_name = VALUES(department_name), is_active = 1, updated_at = CURRENT_TIMESTAMP"
                    );
                    $departmentStmt->execute([$normalizedDepartmentName]);

                    $departmentIdStmt = $db->prepare("SELECT id FROM departments WHERE department_name = ? LIMIT 1");
                    $departmentIdStmt->execute([$normalizedDepartmentName]);
                    $departmentId = (int) $departmentIdStmt->fetchColumn();

                    if ($departmentId > 0) {
                        $stmt = $db->prepare(
                            "INSERT INTO student_department_links (student_id, department_id, priority_rank)
                             VALUES (?, ?, ?)
                             ON DUPLICATE KEY UPDATE priority_rank = VALUES(priority_rank), updated_at = CURRENT_TIMESTAMP"
                        );
                        $stmt->execute([$user_id, $departmentId, $priorityRank]);
                        $pageMessage = "<div class='alert success'>✅ Département lié à l'étudiant.</div>";
                    } else {
                        $pageMessage = "<div class='alert error'>❌ Impossible d'enregistrer ce département.</div>";
                    }
                }
            }
        }

        if (isset($_POST['update_department_link'])) {
            $linkId = (int) ($_POST['link_id'] ?? 0);
            $priorityRank = max(1, (int) ($_POST['priority_rank'] ?? 1));
            $stmt = $db->prepare("UPDATE student_department_links SET priority_rank = ? WHERE id = ? AND student_id = ?");
            $stmt->execute([$priorityRank, $linkId, $user_id]);
            $pageMessage = "<div class='alert success'>✅ Priorité mise à jour.</div>";
        }

        if (isset($_POST['delete_department_link'])) {
            $linkId = (int) ($_POST['link_id'] ?? 0);
            $stmt = $db->prepare("DELETE FROM student_department_links WHERE id = ? AND student_id = ?");
            $stmt->execute([$linkId, $user_id]);
            $pageMessage = "<div class='alert success'>✅ Département supprimé.</div>";
        }

        if (isset($_POST['unlock_onboarding'])) {
            $db->prepare("UPDATE utilisateurs SET onboarding_unlocked = 1, statut = NULL WHERE id = ?")->execute([$user_id]);
            $stmt = $db->prepare("SELECT * FROM utilisateurs WHERE id = ?");
            $stmt->execute([$user_id]);
            $user = $stmt->fetch();
            $pageMessage = "<div class='alert success'>✅ Onboarding débloqué et compte réactivé.</div>";
        }

        if (isset($_POST['lock_onboarding'])) {
            $db->prepare("UPDATE utilisateurs SET onboarding_unlocked = 0 WHERE id = ?")->execute([$user_id]);
            $stmt = $db->prepare("SELECT * FROM utilisateurs WHERE id = ?");
            $stmt->execute([$user_id]);
            $user = $stmt->fetch();
            $pageMessage = "<div class='alert success'>✅ Onboarding verrouillé.</div>";
        }

        if (isset($_POST['force_activate'])) {
            $db->prepare("UPDATE utilisateurs SET statut = NULL WHERE id = ?")->execute([$user_id]);
            $stmt = $db->prepare("SELECT * FROM utilisateurs WHERE id = ?");
            $stmt->execute([$user_id]);
            $user = $stmt->fetch();
            $pageMessage = "<div class='alert success'>✅ Compte réactivé.</div>";
        }

        if (isset($_POST['force_quiz_caisse'])) {
            foreach (['quiz_caisse', 'quiz_pdf'] as $quizPage) {
                $db->prepare("INSERT INTO statistiques (utilisateur_id, nom_page, score, date_tentative) VALUES (?, ?, 10, NOW())")->execute([$user_id, $quizPage]);
            }
            $pageMessage = "<div class='alert success'>✅ Quiz caisse validés manuellement (10/10).</div>";
        }

        if (isset($_POST['force_quiz_onboarding'])) {
            foreach (['quiz_onboarding_video', 'quiz_onboarding_pdf'] as $quizPage) {
                $db->prepare("INSERT INTO statistiques (utilisateur_id, nom_page, score, date_tentative) VALUES (?, ?, 10, NOW())")->execute([$user_id, $quizPage]);
            }
            $pageMessage = "<div class='alert success'>✅ Quiz onboarding validés manuellement (10/10).</div>";
        }

        if (isset($_POST['validate_evaluation'])) {
            // Migration colonne
            try {
                $colEvalDone = $db->query("SHOW COLUMNS FROM utilisateurs LIKE 'evaluation_done'");
                if (!$colEvalDone->fetch()) {
                    $db->exec("ALTER TABLE utilisateurs ADD COLUMN evaluation_done TINYINT(1) NOT NULL DEFAULT 0 AFTER onboarding_unlocked");
                }
            } catch (Exception $e) {}

            $db->prepare("UPDATE utilisateurs SET onboarding_unlocked = 1, statut = NULL, evaluation_done = 1 WHERE id = ?")
               ->execute([$user_id]);

            // Écriture dans evaluations_stock.php
            $evalFile = __DIR__ . '/evaluations_stock.php';
            $evals = (file_exists($evalFile) && ($tmp = include($evalFile)) && is_array($tmp)) ? $tmp : [];
            $evals[] = [
                'nom_evalue'  => $user['nom'],
                'prenom_evalue' => $user['prenom'],
                'nom'         => $user['nom'],
                'prenom'      => $user['prenom'],
                'date_eval'   => date('Y-m-d'),
                'score_chariot_test' => 20,
                'score'       => 20,
                'commentaire' => 'Validé manuellement par l\'administrateur',
                'encart1' => '', 'encart2' => '',
                'remarques1' => '', 'remarques2' => '', 'remarques3' => '',
                'attitude1' => [], 'travail1' => [],
            ];
            file_put_contents($evalFile, '<?php return ' . var_export($evals, true) . ';', LOCK_EX);

            try {
                $db->exec(
                    "CREATE TABLE IF NOT EXISTS evaluations (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        utilisateur_id INT NULL,
                        nom_evalue VARCHAR(100) NOT NULL,
                        prenom_evalue VARCHAR(100) NOT NULL,
                        date_eval DATE NULL,
                        score_global INT NULL,
                        score_chariot_test INT NULL,
                        commentaire TEXT NULL,
                        encart1 TEXT NULL,
                        encart2 TEXT NULL,
                        remarques1 TEXT NULL,
                        remarques2 TEXT NULL,
                        remarques3 TEXT NULL,
                        attitude1_json JSON NULL,
                        attitude2_json JSON NULL,
                        attitude3_json JSON NULL,
                        travail1_json JSON NULL,
                        travail2_json JSON NULL,
                        travail3_json JSON NULL,
                        payload_json JSON NULL,
                        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        INDEX idx_evaluations_nom_prenom (nom_evalue, prenom_evalue),
                        INDEX idx_evaluations_user_id (utilisateur_id),
                        INDEX idx_evaluations_date (date_eval)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
                );

                $manualPayload = [
                    'nom_evalue' => $user['nom'],
                    'prenom_evalue' => $user['prenom'],
                    'date_eval' => date('Y-m-d'),
                    'score_chariot_test' => 20,
                    'score' => 20,
                    'commentaire' => 'Validé manuellement par l\'administrateur',
                    'encart1' => '',
                    'encart2' => '',
                    'remarques1' => '',
                    'remarques2' => '',
                    'remarques3' => '',
                    'attitude1' => [],
                    'travail1' => [],
                ];
                $payloadJson = json_encode($manualPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if ($payloadJson === false) {
                    $payloadJson = null;
                }

                $insertEvalDb = $db->prepare(
                    "INSERT INTO evaluations (
                        utilisateur_id,
                        nom_evalue,
                        prenom_evalue,
                        date_eval,
                        score_global,
                        score_chariot_test,
                        commentaire,
                        encart1,
                        encart2,
                        remarques1,
                        remarques2,
                        remarques3,
                        attitude1_json,
                        attitude2_json,
                        attitude3_json,
                        travail1_json,
                        travail2_json,
                        travail3_json,
                        payload_json
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                );
                $insertEvalDb->execute([
                    $user_id,
                    $user['nom'],
                    $user['prenom'],
                    date('Y-m-d'),
                    20,
                    20,
                    'Validé manuellement par l\'administrateur',
                    '',
                    '',
                    '',
                    '',
                    '',
                    json_encode([], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    null,
                    null,
                    json_encode([], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    null,
                    null,
                    $payloadJson,
                ]);
            } catch (Exception $e) {
                error_log('[FamiFormation] Manual evaluation DB insert failed: ' . $e->getMessage());
            }

            // Rechargement user
            $stmt = $db->prepare("SELECT * FROM utilisateurs WHERE id = ?");
            $stmt->execute([$user_id]);
            $user = $stmt->fetch();

            // Email étudiant
            $emailSent = false;
            if (!empty($user['email'])) {
                $emailSent = sendStudentEvaluationSuccessEmail($user['email'], $user['prenom'] ?? '');
            }
            $agencyMailSent = sendInterimAgencyEvaluationOutcomeEmail($db, $user_id, true);

            $pageMessage = "<div class='alert success'>✅ Évaluation caisse validée manuellement. Onboarding débloqué."
                . ($emailSent ? "<br>📨 Email de félicitations envoyé à l'étudiant." : '')
                . ($agencyMailSent ? "<br>📨 L'agence intérim a été notifiée." : '')
                . "</div>";
        }
    }
}
// Quiz réalisés
$quiz = $db->prepare("SELECT nom_page, score, date_tentative FROM statistiques WHERE utilisateur_id = ? AND score IS NOT NULL ORDER BY date_tentative DESC, id DESC");
$quiz->execute([$user_id]);
$quizz = $quiz->fetchAll();
// Formations présentielles suivies
$form = $db->prepare("SELECT fs.titre, fc.date_heure FROM formations_inscriptions fi JOIN formations_sessions fs ON fi.formation_id = fs.id JOIN formations_creneaux fc ON fi.creneau_id = fc.id WHERE fi.utilisateur_id = ? AND fi.creneau_id IS NOT NULL ORDER BY fc.date_heure DESC");
$form->execute([$user_id]);
$formations = $form->fetchAll();
// Formations où inscrit (présentiel à venir)
$insc = $db->prepare("SELECT fs.titre, fc.date_heure FROM formations_inscriptions fi JOIN formations_sessions fs ON fi.formation_id = fs.id JOIN formations_creneaux fc ON fi.creneau_id = fc.id WHERE fi.utilisateur_id = ? AND fc.date_heure >= NOW() ORDER BY fc.date_heure ASC");
$insc->execute([$user_id]);
$inscriptions = $insc->fetchAll();

$linkedDepartments = [];
$availableDepartments = [];
if (($user['role'] ?? '') === 'etudiant') {
    $planningDepartmentNames = $planningDepartmentNames ?? [];
    $usePlanningDepartmentCatalog = $usePlanningDepartmentCatalog ?? false;

    $linksStmt = $db->prepare(
        "SELECT sdl.id, sdl.priority_rank, d.id AS department_id, d.department_name
         FROM student_department_links sdl
         JOIN departments d ON d.id = sdl.department_id
         WHERE sdl.student_id = ?
         ORDER BY sdl.priority_rank ASC, d.department_name ASC"
    );
    $linksStmt->execute([$user_id]);
    $linkedDepartments = $linksStmt->fetchAll(PDO::FETCH_ASSOC);

    if ($usePlanningDepartmentCatalog) {
        $activePlanningStmt = $db->prepare(
            "SELECT id, department_name
             FROM departments
             WHERE is_active = 1
             ORDER BY department_name ASC"
        );
        $activePlanningStmt->execute();
        $activePlanningDepartments = $activePlanningStmt->fetchAll(PDO::FETCH_ASSOC);

        $planningNameIndex = [];
        foreach ($planningDepartmentNames as $departmentName) {
            $planningNameIndex[$departmentName] = true;
        }

        foreach ($activePlanningDepartments as $departmentRow) {
            if (isset($planningNameIndex[$departmentRow['department_name']])) {
                $availableDepartments[] = $departmentRow;
            }
        }

        $departmentSyncNote = "<div class='alert success'>Liste des départements synchronisée depuis la base planning.</div>";
    } else {
        $availableStmt = $db->prepare(
            "SELECT id, department_name
             FROM departments
             WHERE is_active = 1
             ORDER BY department_name ASC"
        );
        $availableStmt->execute();
        $availableDepartments = $availableStmt->fetchAll(PDO::FETCH_ASSOC);

        if ($departmentSyncNote === '') {
            if (!$planningDbConfigured) {
                $departmentSyncNote = "<div class='alert success'>Base secondaire des départements non configurée dans le .env. La liste locale des départements est utilisée pour le moment.</div>";
            } else {
                $departmentSyncNote = "<div class='alert error'>Aucun département exploitable n'a été trouvé dans la base secondaire configurée. La liste locale des départements est utilisée.</div>";
            }
        }
    }
}

// Scores progression pour les étudiants
$progressionScores = [];
if (($user['role'] ?? '') === 'etudiant') {
    $qScores = $db->prepare(
        "SELECT nom_page, MAX(score) AS best
         FROM statistiques
         WHERE utilisateur_id = ?
           AND nom_page IN ('quiz_caisse','quiz_pdf','quiz_onboarding_video','quiz_onboarding_pdf')
           AND score IS NOT NULL
         GROUP BY nom_page"
    );
    $qScores->execute([$user_id]);
    foreach ($qScores->fetchAll() as $row) {
        $progressionScores[$row['nom_page']] = (int) $row['best'];
    }
}

$evaluationDone = false;
if (($user['role'] ?? '') === 'etudiant') {
    try {
        $colCheck = $db->query("SHOW COLUMNS FROM utilisateurs LIKE 'evaluation_done'");
        if (!$colCheck->fetch()) {
            $db->exec("ALTER TABLE utilisateurs ADD COLUMN evaluation_done TINYINT(1) NOT NULL DEFAULT 0 AFTER onboarding_unlocked");
        }

        $evalDoneStmt = $db->prepare("SELECT evaluation_done FROM utilisateurs WHERE id = ?");
        $evalDoneStmt->execute([$user_id]);
        $evaluationDone = (bool) $evalDoneStmt->fetchColumn();
    } catch (Exception $e) {}

    if (!$evaluationDone && file_exists(__DIR__ . '/evaluations_stock.php')) {
        $storedEvaluations = include(__DIR__ . '/evaluations_stock.php');
        if (is_array($storedEvaluations)) {
            foreach (array_reverse($storedEvaluations) as $storedEvaluation) {
                if (!is_array($storedEvaluation)) {
                    continue;
                }

                $evalNom = trim((string) ($storedEvaluation['nom'] ?? $storedEvaluation['nom_evalue'] ?? ''));
                $evalPrenom = trim((string) ($storedEvaluation['prenom'] ?? $storedEvaluation['prenom_evalue'] ?? ''));
                $evalScore = isset($storedEvaluation['score']) ? (int) $storedEvaluation['score'] : null;

                if ($evalNom !== '' && $evalPrenom !== ''
                    && strcasecmp($evalNom, (string) ($user['nom'] ?? '')) === 0
                    && strcasecmp($evalPrenom, (string) ($user['prenom'] ?? '')) === 0
                    && $evalScore !== null) {
                    $evaluationDone = true;

                    try {
                        $db->prepare("UPDATE utilisateurs SET evaluation_done = 1, onboarding_unlocked = IF(? >= 12, 1, onboarding_unlocked) WHERE id = ?")
                           ->execute([$evalScore, $user_id]);
                        $user['evaluation_done'] = 1;
                        if ($evalScore >= 12) {
                            $user['onboarding_unlocked'] = 1;
                        }
                    } catch (Exception $e) {}

                    break;
                }
            }
        }
    }
}
?><!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Fiche Collaborateur - FamiFormation</title>
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Open Sans', sans-serif; background: #f4f7f6; margin: 0; padding: 20px; }
        .container { max-width: 900px; margin: 0 auto; background: #fff; border-radius: 18px; box-shadow: 0 8px 32px rgba(45,90,55,0.10); padding: 36px; }
        h1 { color: #2d5a37; margin-bottom: 18px; }
        .section { margin-bottom: 32px; }
        .section-title { font-size: 1.2em; font-weight: bold; color: #1d6f42; margin-bottom: 10px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { padding: 10px 12px; border-bottom: 1px solid #eee; font-size: 0.97em; }
        th { background: #c8e6c9; color: #1d6f42; text-align: left; }
        .score-ok { color: #2d5a37; font-weight: bold; }
        .score-mid { color: #d9a406; font-weight: bold; }
        .score-ko { color: #a83232; font-weight: bold; }
        .badge-date { background: #e8f5e9; color: #2d5a37; border-radius: 8px; padding: 2px 8px; font-size: 0.85em; margin-left: 8px; }
        .back-link { display: inline-block; margin-bottom: 18px; color: #2d5a37; text-decoration: none; font-weight: bold; }
        .back-link:hover { text-decoration: underline; }
        .alert { padding: 14px 16px; border-radius: 12px; margin-bottom: 18px; font-weight: 700; }
        .alert.success { background: #dff3e3; color: #1d6a39; }
        .alert.error { background: #fce8e6; color: #9d2f2a; }
        .link-form { display: grid; grid-template-columns: minmax(0, 1fr) 120px 140px; gap: 12px; align-items: end; margin-top: 12px; }
        .input-control { width: 100%; box-sizing: border-box; padding: 10px 12px; border: 1px solid #d5ddd6; border-radius: 10px; font: inherit; }
        .btn-primary { border: none; background: #2d5a37; color: #fff; font-weight: 700; padding: 10px 14px; border-radius: 10px; cursor: pointer; }
        .btn-danger { border: none; background: #c94a42; color: #fff; font-weight: 700; padding: 10px 14px; border-radius: 10px; cursor: pointer; }
        .linked-student-card { border: 1px solid #dbe7dd; border-radius: 14px; padding: 14px; background: #fbfdfb; margin-top: 12px; }
        .linked-student-head { display: flex; justify-content: space-between; align-items: center; gap: 16px; margin-bottom: 10px; }
        .linked-student-name { font-weight: 700; color: #214b35; }
        .linked-student-meta { color: #607063; font-size: 0.92rem; }
        .linked-actions { display: flex; gap: 10px; align-items: end; }
    </style>
</head>
<body>
<div class="container">
    <a href="admin.php" class="back-link">⬅ Retour à la liste</a>
    <h1>Fiche collaborateur</h1>
    <?php echo $pageMessage; ?>
    <div class="section">
        <div class="section-title">Informations générales</div>
        <?php
            $photoProfilPath = $user['photo_profil'] ?? null;
            $photoAbsPath = $photoProfilPath ? __DIR__ . '/' . $photoProfilPath : null;
        ?>
        <?php if ($photoProfilPath && $photoAbsPath && is_file($photoAbsPath)): ?>
            <div style="margin-bottom:16px;">
                <img src="<?= htmlspecialchars($photoProfilPath) ?>?v=<?= filemtime($photoAbsPath) ?>" alt="Photo de profil" style="width:100px;height:100px;border-radius:50%;object-fit:cover;border:3px solid #2d5a37;display:block;">
            </div>
        <?php else: ?>
            <div style="width:100px;height:100px;border-radius:50%;background:#e8f5e9;border:3px solid #2d5a37;display:flex;align-items:center;justify-content:center;font-size:2.5rem;margin-bottom:16px;">👤</div>
        <?php endif; ?>
        <strong>Nom :</strong> <?php echo ucfirst(strtolower(htmlspecialchars($user['nom'] ?? ''))); ?><br>
        <strong>Prénom :</strong> <?php echo ucfirst(strtolower(htmlspecialchars($user['prenom'] ?? ''))); ?><br>
        <strong>Identifiant :</strong> <?php echo htmlspecialchars($user['identifiant'] ?? ''); ?><br>
        <strong>Email :</strong> <?php echo htmlspecialchars($user['email'] ?? ''); ?><br>
        <strong>Agence intérim :</strong> <?php echo htmlspecialchars($user['interim'] ?? ''); ?><br>
        <?php
            $interimVal = trim((string) ($user['interim'] ?? ''));
            $isInterimaire = ($user['role'] ?? '') !== 'etudiant'
                && $interimVal !== ''
                && strtolower($interimVal) !== 'famiflora';
        ?>
        <strong>Rôle :</strong> <?php echo htmlspecialchars($user['role'] ?? ''); ?>
        <?php if ($isInterimaire): ?>
            <span style="display:inline-block;margin-left:8px;background:#fff3e0;color:#b35c00;border:1px solid #ffcc80;border-radius:8px;padding:2px 10px;font-size:0.85em;font-weight:700;">Intérimaire</span>
        <?php endif; ?>
        <br>
        <strong>Onboarding débloqué :</strong> <?php echo !empty($user['onboarding_unlocked'] ?? 0) ? 'Oui' : 'Non'; ?><br>

        <hr style="border:none;border-top:1px solid #e3ece5;margin:16px 0;">
        <?php
            $villeVal = (string) ($user['ville'] ?? '');
            $naissanceVal = (string) ($user['date_naissance'] ?? '');
            $langDefaut = function_exists('villeLangueDefaut') ? villeLangueDefaut($villeVal) : 'fr';
        ?>
        <form method="POST" style="display:flex;flex-wrap:wrap;gap:16px;align-items:flex-end;">
            <?php echo csrfField(); ?>
            <input type="hidden" name="save_profile_info" value="1">
            <div>
                <label style="font-weight:700;color:#1d6f42;display:block;margin-bottom:6px;">Ville de résidence</label>
                <input type="text" name="ville" value="<?php echo htmlspecialchars($villeVal); ?>" placeholder="Ex : Anvers, Bruxelles, Gand..." class="input-control" style="min-width:230px;">
                <div style="font-size:0.8em;color:#7a8e82;margin-top:4px;">Langue par défaut du site : <strong><?php echo $langDefaut === 'nl' ? 'Néerlandais 🇳🇱' : 'Français 🇫🇷'; ?></strong></div>
            </div>
            <div>
                <label style="font-weight:700;color:#1d6f42;display:block;margin-bottom:6px;">Date d'anniversaire 🎂</label>
                <input type="date" name="date_naissance" value="<?php echo htmlspecialchars($naissanceVal); ?>" class="input-control">
            </div>
            <button type="submit" class="btn-primary">Enregistrer</button>
        </form>
    </div>
    <?php if (($user['role'] ?? '') === 'etudiant'):
        $caisseOk   = ($progressionScores['quiz_caisse'] ?? 0) >= 8;
        $caissePdfOk = ($progressionScores['quiz_pdf'] ?? 0) >= 8;
        $onboardingUnlocked = !empty($user['onboarding_unlocked'] ?? 0);
        $onbVideoOk = ($progressionScores['quiz_onboarding_video'] ?? 0) >= 8;
        $onbPdfOk   = ($progressionScores['quiz_onboarding_pdf'] ?? 0) >= 8;
        $isInactif  = ($user['statut'] ?? '') === 'inactif';
    ?>
    <div class="section">
        <div class="section-title">Progression &amp; Déblocage</div>
        <p style="color:#607063;line-height:1.6;margin-top:0;">Forcer manuellement les étapes du parcours étudiant. Chaque action est irréversible sauf indication.</p>
        <div style="display:flex;flex-direction:column;gap:14px;">

            <?php
            $steps = [
                ['label'=>'Quiz Caisse (vidéo)',  'key'=>'quiz_caisse',            'ok'=>$caisseOk,    'score'=>$progressionScores['quiz_caisse'] ?? null],
                ['label'=>'Quiz Caisse (PDF)',     'key'=>'quiz_pdf',               'ok'=>$caissePdfOk, 'score'=>$progressionScores['quiz_pdf'] ?? null],
            ];
            foreach ($steps as $step):
                $scoreDisplay = $step['score'] !== null ? $step['score'].'/10' : 'Jamais passé';
            ?>
            <div style="display:flex;align-items:center;justify-content:space-between;padding:12px 16px;border-radius:12px;border:1px solid <?php echo $step['ok'] ? '#a5d6a7' : '#f8bbd0'; ?>;background:<?php echo $step['ok'] ? '#f1faf2' : '#fff8f8'; ?>;">
                <div>
                    <span style="font-weight:700;color:#214b35;"><?php echo $step['label']; ?></span>
                    <span style="margin-left:10px;font-size:0.9em;color:#607063;"><?php echo $scoreDisplay; ?></span>
                    <span style="margin-left:8px;font-size:1.1em;"><?php echo $step['ok'] ? '✅' : '❌'; ?></span>
                </div>
                <?php if (!$step['ok']): ?>
                <form method="POST" onsubmit="return confirm('Forcer la validation des deux quiz caisse (10/10) pour cet étudiant ?');">
                    <?php echo csrfField(); ?>
                    <button type="submit" name="force_quiz_caisse" class="btn-primary" style="font-size:0.9em;padding:8px 14px;">Forcer validation caisse</button>
                </form>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>

            <div style="display:flex;align-items:center;justify-content:space-between;padding:12px 16px;border-radius:12px;border:1px solid <?php echo $evaluationDone ? '#a5d6a7' : '#fff3cd'; ?>;background:<?php echo $evaluationDone ? '#f1faf2' : '#fffdf5'; ?>;">
                <div>
                    <span style="font-weight:700;color:#214b35;">Évaluation caisse validée</span>
                    <span style="margin-left:8px;font-size:1.1em;"><?php echo $evaluationDone ? '✅ Oui' : '⏳ Non'; ?></span>
                    <div style="font-size:0.82em;color:#607063;margin-top:2px;">Cette étape est normalement validée via evaluation.php (compte Accueil).</div>
                </div>
                <div style="display:flex;gap:8px;">
                    <?php if (!$evaluationDone): ?>
                    <form method="POST" onsubmit="return confirm('Valider manuellement l\'évaluation caisse pour cet étudiant ?');">
                        <?php echo csrfField(); ?>
                        <button type="submit" name="validate_evaluation" class="btn-primary" style="font-size:0.9em;padding:8px 14px;background:#1565c0;">Valider l'évaluation caisse</button>
                    </form>
                    <?php else: ?>
                    <span style="font-size:0.88em;color:#2d6a4f;font-weight:700;padding:8px 14px;background:#d8f3dc;border-radius:8px;">Évaluation enregistrée</span>
                    <?php endif; ?>
                    <?php if ($isInactif): ?>
                    <form method="POST" onsubmit="return confirm('Réactiver le compte de cet étudiant ?');">
                        <?php echo csrfField(); ?>
                        <button type="submit" name="force_activate" class="btn-primary" style="font-size:0.9em;padding:8px 14px;background:#1976d2;">Réactiver le compte</button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>

            <?php
            $steps2 = [
                ['label'=>'Quiz Onboarding (vidéo)', 'ok'=>$onbVideoOk, 'score'=>$progressionScores['quiz_onboarding_video'] ?? null],
                ['label'=>'Quiz Onboarding (PDF)',   'ok'=>$onbPdfOk,  'score'=>$progressionScores['quiz_onboarding_pdf'] ?? null],
            ];
            foreach ($steps2 as $step):
                $scoreDisplay = $step['score'] !== null ? $step['score'].'/10' : 'Jamais passé';
            ?>
            <div style="display:flex;align-items:center;justify-content:space-between;padding:12px 16px;border-radius:12px;border:1px solid <?php echo $step['ok'] ? '#a5d6a7' : '#f8bbd0'; ?>;background:<?php echo $step['ok'] ? '#f1faf2' : '#fff8f8'; ?>;">
                <div>
                    <span style="font-weight:700;color:#214b35;"><?php echo $step['label']; ?></span>
                    <span style="margin-left:10px;font-size:0.9em;color:#607063;"><?php echo $scoreDisplay; ?></span>
                    <span style="margin-left:8px;font-size:1.1em;"><?php echo $step['ok'] ? '✅' : '❌'; ?></span>
                </div>
                <?php if (!$step['ok']): ?>
                <form method="POST" onsubmit="return confirm('Forcer la validation des deux quiz onboarding (10/10) pour cet étudiant ?');">
                    <?php echo csrfField(); ?>
                    <button type="submit" name="force_quiz_onboarding" class="btn-primary" style="font-size:0.9em;padding:8px 14px;">Forcer validation onboarding</button>
                </form>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>

        </div>
    </div>
    <?php endif; ?>
    <?php if (($user['role'] ?? '') === 'etudiant'): ?>
    <div class="section">
        <div class="section-title">Départements liés et priorités</div>
        <p style="color:#607063; line-height:1.6; margin-top:0;">Rattache ici cet étudiant à un ou plusieurs départements et définis leur priorité. La liste proposée est synchronisée avec la base planning quand elle est disponible.</p>
        <?php echo $departmentSyncNote; ?>

        <form method="POST" class="link-form">
            <?php echo csrfField(); ?>
            <div>
                <label for="department_name" style="font-weight:700; color:#1d6f42; display:block; margin-bottom:6px;">Département</label>
                <input type="text" name="department_name" id="department_name" class="input-control" list="department_suggestions" placeholder="Exemple : Caisse, Garden, Animalerie..." required>
                <datalist id="department_suggestions">
                    <?php foreach ($availableDepartments as $departmentOption): ?>
                        <option value="<?php echo htmlspecialchars($departmentOption['department_name']); ?>"></option>
                    <?php endforeach; ?>
                </datalist>
            </div>
            <div>
                <label for="priority_rank" style="font-weight:700; color:#1d6f42; display:block; margin-bottom:6px;">Priorité</label>
                <input type="number" name="priority_rank" id="priority_rank" min="1" value="1" class="input-control" required>
            </div>
            <div>
                <button type="submit" name="add_department_link" class="btn-primary" style="width:100%;">Ajouter le département</button>
            </div>
        </form>

        <?php if (!empty($linkedDepartments)): ?>
            <?php foreach ($linkedDepartments as $linkedDepartment): ?>
                <div class="linked-student-card">
                    <div class="linked-student-head">
                        <div>
                            <div class="linked-student-name"><?php echo htmlspecialchars($linkedDepartment['department_name']); ?></div>
                            <div class="linked-student-meta">Département priorisé pour cet étudiant</div>
                        </div>
                    </div>
                    <div class="linked-actions">
                        <form method="POST" style="display:flex; gap:10px; align-items:end; flex:1;">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="link_id" value="<?php echo (int) $linkedDepartment['id']; ?>">
                            <div>
                                <label style="font-weight:700; color:#1d6f42; display:block; margin-bottom:6px;">Priorité</label>
                                <input type="number" name="priority_rank" min="1" value="<?php echo (int) $linkedDepartment['priority_rank']; ?>" class="input-control" style="width:120px;">
                            </div>
                            <button type="submit" name="update_department_link" class="btn-primary">Mettre à jour</button>
                        </form>
                        <form method="POST" onsubmit="return confirm('Supprimer ce département lié ?');">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="link_id" value="<?php echo (int) $linkedDepartment['id']; ?>">
                            <button type="submit" name="delete_department_link" class="btn-danger">Supprimer</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <em>Aucun département lié pour le moment.</em>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <div class="section">
        <div class="section-title">Quiz réalisés</div>
        <table>
            <thead><tr><th>Quiz</th><th>Score</th><th>Date</th></tr></thead>
            <tbody>
            <?php foreach ($quizz as $q): ?>
                <tr>
                    <td><?php echo htmlspecialchars($q['nom_page']); ?></td>
                    <td class="<?php echo ($q['score']>=8?'score-ok':($q['score']>=5?'score-mid':'score-ko')); ?>"><?php echo $q['score']; ?> / 10</td>
                    <td><?php echo date('d/m/Y', strtotime($q['date_tentative'])); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="section">
        <div class="section-title">Évaluations</div>
        <?php 
        $evals = [];
        $nom = $user['nom'];
        $prenom = $user['prenom'];
        $found = false;

        try {
            $evalDbStmt = $db->prepare(
                "SELECT *
                 FROM evaluations
                 WHERE LOWER(nom_evalue) = LOWER(?)
                   AND LOWER(prenom_evalue) = LOWER(?)
                 ORDER BY date_eval DESC, id DESC"
            );
            $evalDbStmt->execute([$nom, $prenom]);
            $evals = $evalDbStmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $evals = [];
        }

        if (empty($evals)) {
            $evals = include('evaluations_stock.php');
        }

        $decodeGrid = static function ($value) {
            if (is_array($value)) {
                return $value;
            }
            if (is_string($value) && $value !== '') {
                $decoded = json_decode($value, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
            return [];
        };

        $formatGridNote = static function ($value) {
            $key = strtolower(trim((string) $value));
            $map = [
                'red' => '😡',
                'yellow' => '😐',
                'lightgreen' => '🙂',
                'green' => '😀',
            ];
            return $map[$key] ?? htmlspecialchars((string) $value);
        };

        foreach ($evals as $e) {
            if (!is_array($e)) {
                continue;
            }

            $evalNom = trim((string) ($e['nom'] ?? $e['nom_evalue'] ?? ''));
            $evalPrenom = trim((string) ($e['prenom'] ?? $e['prenom_evalue'] ?? ''));

            if ($evalNom !== '' && $evalPrenom !== '' && strcasecmp($evalNom, $nom) === 0 && strcasecmp($evalPrenom, $prenom) === 0) {
                $found = true;
                echo '<div style="border:1px solid #c8e6c9; border-radius:8px; padding:12px; margin-bottom:12px; background:#f8f9fa;">';
                echo '<strong>Date :</strong> ' . htmlspecialchars((string) ($e['date'] ?? $e['date_eval'] ?? '')) . '<br>';
                echo '<strong>Score chariot test :</strong> ' . htmlspecialchars((string) ($e['score_chariot_test'] ?? '')) . ' / 20<br>';
                echo '<strong>Score :</strong> ' . htmlspecialchars((string) ($e['score_global'] ?? $e['score'] ?? '')) . ' / 20<br>';
                echo '<strong>Commentaire :</strong> ' . nl2br(htmlspecialchars((string) ($e['commentaire'] ?? ''))) . '<br>';
                echo '<strong>Encart 1 :</strong> ' . nl2br(htmlspecialchars((string) ($e['encart1'] ?? ''))) . '<br>';
                echo '<strong>Encart 2 :</strong> ' . nl2br(htmlspecialchars((string) ($e['encart2'] ?? ''))) . '<br>';
                for ($i = 1; $i <= 3; $i++) {
                    $rem = $e['remarques'.$i] ?? '';
                    $att = $decodeGrid($e['attitude'.$i] ?? ($e['attitude'.$i.'_json'] ?? []));
                    $trav = $decodeGrid($e['travail'.$i] ?? ($e['travail'.$i.'_json'] ?? []));
                    if (!empty($rem) || !empty($att) || !empty($trav)) {
                        echo '<div style="margin:18px 0 10px 0; padding:10px; border:1px solid #e0e0e0; border-radius:8px; background:#f6f8f6;">';
                        echo '<strong>Examinateur '.$i.'</strong><br>';
                        if (!empty($att)) {
                            echo '<u>Grille Attitude</u><br><table style="width:100%;margin-bottom:10px;border-collapse:collapse;font-size:0.97em;">';
                            echo '<tr style="background:#e8f5e9;"><th>Critère</th><th>Note</th></tr>';
                            $attitude = ["Présentation soignée","Agréable et souriant avec les clients","Politesse","Acceptation et prise en compte des remarques","Intérêt pour le poste","Niveau de confort","Motivation"];
                            foreach ($attitude as $crit) {
                                $val = $att[$crit] ?? '';
                                echo '<tr><td>'.htmlspecialchars($crit).'</td><td>'.$formatGridNote($val).'</td></tr>';
                            }
                            echo '</table>';
                        }
                        if (!empty($trav)) {
                            echo '<u>Grille Travail</u><br><table style="width:100%;margin-bottom:10px;border-collapse:collapse;font-size:0.97em;">';
                            echo '<tr style="background:#e8f5e9;"><th>Critère</th><th>Note</th></tr>';
                            $travail = ["Regarde l'écran de caisse","Demande de la carte de fidélité, code postal","Demande de déposer les articles sur le tapis","Vérifier les codes rentrer manuellement","Attention au caddies et bas des poussettes","Comptage des articles","Travail en cash"];
                            foreach ($travail as $crit) {
                                $val = $trav[$crit] ?? '';
                                echo '<tr><td>'.htmlspecialchars($crit).'</td><td>'.$formatGridNote($val).'</td></tr>';
                            }
                            echo '</table>';
                        }
                        if (!empty($rem)) {
                            echo '<strong>Remarques :</strong> ' . nl2br(htmlspecialchars($rem)) . '<br>';
                        }
                        echo '</div>';
                    }
                }
                echo '</div>';
            }
        }
        if (!$found) echo '<em>Aucune évaluation enregistrée pour ce collaborateur.</em>';
        ?>
    </div>
    <div class="section">
        <div class="section-title">Formations présentielles suivies</div>
        <table>
            <thead><tr><th>Formation</th><th>Date</th></tr></thead>
            <tbody>
            <?php foreach ($formations as $f): ?>
                <tr>
                    <td><?php echo htmlspecialchars($f['titre']); ?></td>
                    <td><?php echo date('d/m/Y H:i', strtotime($f['date_heure'])); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="section">
        <div class="section-title">Formations à venir (inscriptions)</div>
        <table>
            <thead><tr><th>Formation</th><th>Date</th></tr></thead>
            <tbody>
            <?php foreach ($inscriptions as $f): ?>
                <tr>
                    <td><?php echo htmlspecialchars($f['titre']); ?></td>
                    <td><?php echo date('d/m/Y H:i', strtotime($f['date_heure'])); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
</body>
</html>
