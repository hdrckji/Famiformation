<?php
require_once 'config.php';
verifierConnexion($db);

if (!function_exists('safeLoadPhpArrayFile')) {
    function safeLoadPhpArrayFile($filePath)
    {
        if (!is_string($filePath) || $filePath === '' || !file_exists($filePath)) {
            return [];
        }

        try {
            $data = include $filePath;
            return is_array($data) ? $data : [];
        } catch (Throwable $e) {
            error_log('[FamiFormation] Unable to load array file ' . $filePath . ': ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('ensureEvaluationsTable')) {
    function ensureEvaluationsTable(PDO $db)
    {
        static $tableReady = false;
        if ($tableReady) {
            return true;
        }

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

        $tableReady = true;
        return true;
    }
}

if (!function_exists('saveEvaluationToDatabase')) {
    function saveEvaluationToDatabase(PDO $db, array $evalData, $matchedUser = null)
    {
        try {
            ensureEvaluationsTable($db);

            $toJson = static function ($value) {
                if (!is_array($value)) {
                    return null;
                }
                $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                return $json === false ? null : $json;
            };

            $payloadJson = json_encode($evalData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($payloadJson === false) {
                $payloadJson = null;
            }

            $stmt = $db->prepare(
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

            $stmt->execute([
                is_array($matchedUser) && isset($matchedUser['id']) ? (int) $matchedUser['id'] : null,
                trim((string) ($evalData['nom_evalue'] ?? $evalData['nom'] ?? '')),
                trim((string) ($evalData['prenom_evalue'] ?? $evalData['prenom'] ?? '')),
                trim((string) ($evalData['date_eval'] ?? $evalData['date'] ?? '')),
                isset($evalData['score']) && $evalData['score'] !== '' ? (int) $evalData['score'] : null,
                isset($evalData['score_chariot_test']) && $evalData['score_chariot_test'] !== '' ? (int) $evalData['score_chariot_test'] : null,
                (string) ($evalData['commentaire'] ?? ''),
                (string) ($evalData['encart1'] ?? ''),
                (string) ($evalData['encart2'] ?? ''),
                (string) ($evalData['remarques1'] ?? ''),
                (string) ($evalData['remarques2'] ?? ''),
                (string) ($evalData['remarques3'] ?? ''),
                $toJson($evalData['attitude1'] ?? null),
                $toJson($evalData['attitude2'] ?? null),
                $toJson($evalData['attitude3'] ?? null),
                $toJson($evalData['travail1'] ?? null),
                $toJson($evalData['travail2'] ?? null),
                $toJson($evalData['travail3'] ?? null),
                $payloadJson,
            ]);

            return true;
        } catch (Throwable $e) {
            error_log('[FamiFormation] Unable to persist evaluation in DB: ' . $e->getMessage());
            return false;
        }
    }
}

// Vérifier si l'utilisateur connecté est 'Accueil'
if ($_SESSION['username'] !== 'Accueil') {
    header('Location: index.php');
    exit();
}

$message = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCSRF();
    // Traitement du formulaire
    $nom_evalue = ucfirst(strtolower(trim($_POST['nom_evalue'] ?? '')));
    $prenom_evalue = ucfirst(strtolower(trim($_POST['prenom_evalue'] ?? '')));
    $date_eval = trim($_POST['date_eval'] ?? '');
    $commentaire = trim($_POST['commentaire'] ?? '');
    $encart1 = trim($_POST['encart1'] ?? '');
    $encart2 = trim($_POST['encart2'] ?? '');
    $remarques1 = trim($_POST['remarques1'] ?? '');
    $remarques2 = trim($_POST['remarques2'] ?? '');
    $remarques3 = trim($_POST['remarques3'] ?? '');
    $score = isset($_POST['score']) ? intval($_POST['score']) : null;
    // Vérification si le nom/prénom existe
    $stmt = $db->prepare("SELECT id, role, prenom, email FROM utilisateurs WHERE LOWER(nom) = ? AND LOWER(prenom) = ? LIMIT 1");
    $stmt->execute([strtolower($nom_evalue), strtolower($prenom_evalue)]);
    $user_eval = $stmt->fetch();
    // On stocke tout le POST pour ne rien perdre (y compris les grilles)
    $eval_data = $_POST;
    saveEvaluationToDatabase($db, $eval_data, $user_eval ?: null);
    $evaluationsStockPath = __DIR__ . '/evaluations_stock.php';
    $evaluationsOrphelinesPath = __DIR__ . '/evaluations_orphelines.php';

    if ($user_eval) {
        $evals = safeLoadPhpArrayFile($evaluationsStockPath);
        $evals[] = $eval_data;
        $writeResult = file_put_contents($evaluationsStockPath, '<?php return ' . var_export($evals, true) . ';', LOCK_EX);
        if ($writeResult === false) {
            $message = '<div class="alert success">⚠️ Évaluation traitée mais l\'écriture dans le fichier de stockage a échoué.</div>';
        }
    } else {
        $evals_orphelines = safeLoadPhpArrayFile($evaluationsOrphelinesPath);
        if (!file_exists($evaluationsOrphelinesPath)) {
            file_put_contents($evaluationsOrphelinesPath, "<?php\nreturn [];\n", LOCK_EX);
        }
        $evals_orphelines[] = $eval_data;
        // Utilisation de serialize pour garantir la robustesse
        $writeResult = file_put_contents($evaluationsOrphelinesPath, '<?php return unserialize(' . var_export(serialize($evals_orphelines), true) . ');', LOCK_EX);
        if ($writeResult === false) {
            $message = '<div class="alert success">⚠️ Évaluation traitée mais l\'écriture dans le fichier orphelin a échoué.</div>';
        }
        clearstatcache(true, $evaluationsOrphelinesPath);
        file_put_contents('debug_eval_orphelines.log', date('c')." - Ajout orphelin: ".json_encode($eval_data)."\n", FILE_APPEND | LOCK_EX);
    }

    // Gestion du statut et du déblocage onboarding pour les étudiants
    if ($score !== null && $user_eval && $user_eval['role'] === 'etudiant') {
        try {
            $columnStmt = $db->query("SHOW COLUMNS FROM utilisateurs LIKE 'onboarding_unlocked'");
            if (!$columnStmt->fetch()) {
                $db->exec("ALTER TABLE utilisateurs ADD COLUMN onboarding_unlocked TINYINT(1) NOT NULL DEFAULT 0 AFTER statut");
            }
        } catch (Exception $e) {
            // Ignore la migration si elle échoue, l'enregistrement principal reste prioritaire.
        }

        try {
            $colEvalDone = $db->query("SHOW COLUMNS FROM utilisateurs LIKE 'evaluation_done'");
            if (!$colEvalDone->fetch()) {
                $db->exec("ALTER TABLE utilisateurs ADD COLUMN evaluation_done TINYINT(1) NOT NULL DEFAULT 0 AFTER onboarding_unlocked");
            }
        } catch (Exception $e) {
            // Ignore la migration si elle échoue, l'enregistrement principal reste prioritaire.
        }

        if ($score < 12) {
            $upd = $db->prepare("UPDATE utilisateurs SET statut = 'inactif', onboarding_unlocked = 0, evaluation_done = 1 WHERE id = ?");
            $upd->execute([$user_eval['id']]);
            $failureMailSent = false;
            if (!empty($user_eval['email'])) {
                $failureMailSent = sendStudentEvaluationFailureEmail($user_eval['email'], $user_eval['prenom'] ?? '');
            }
            $agencyFailureMailSent = sendInterimAgencyEvaluationOutcomeEmail($db, (int) $user_eval['id'], false);

            $message = '<div class="alert success">✅ Évaluation enregistrée !<br>L\'utilisateur a été passé en inactif (score < 12/20).';
            if (!empty($user_eval['email'])) {
                $message .= $failureMailSent
                    ? '<br>📨 Un mail a été envoyé à l\'étudiant pour l\'informer du résultat.'
                    : '<br><strong>Le mail de résultat à l\'étudiant n\'a pas pu être envoyé.</strong>';
            }
            $message .= $agencyFailureMailSent
                ? '<br>📨 L\'agence intérim a été informée que le test n\'a pas été concluant.'
                : '<br><strong>Le mail à l\'agence intérim n\'a pas pu être envoyé.</strong>';
            $message .= '</div>';
        } else {
            $upd = $db->prepare("UPDATE utilisateurs SET statut = NULL, onboarding_unlocked = 1, evaluation_done = 1 WHERE id = ?");
            $upd->execute([$user_eval['id']]);
            $successMailSent = false;
            if (!empty($user_eval['email'])) {
                $successMailSent = sendStudentEvaluationSuccessEmail($user_eval['email'], $user_eval['prenom'] ?? '');
            }
            $agencySuccessMailSent = sendInterimAgencyEvaluationOutcomeEmail($db, (int) $user_eval['id'], true);

            $message = '<div class="alert success">✅ Évaluation enregistrée !<br>L\'onboarding est maintenant débloqué pour cet utilisateur.';
            if (!empty($user_eval['email'])) {
                $message .= $successMailSent
                    ? '<br>📨 Un mail de félicitations a été envoyé à l\'utilisateur.'
                    : '<br><strong>Le mail de félicitations n\'a pas pu être envoyé.</strong>';
            }
            $message .= $agencySuccessMailSent
                ? '<br>📨 L\'agence intérim a été informée que le test est validé.'
                : '<br><strong>Le mail à l\'agence intérim n\'a pas pu être envoyé.</strong>';
            $message .= '</div>';
        }
    } else {
        $message = '<div class="alert success">✅ Évaluation enregistrée !</div>';
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Évaluation collaborateur</title>
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Open Sans', sans-serif; background: #f4f7f6; margin: 0; padding: 20px; }
        .container { max-width: 500px; margin: 40px auto; background: white; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.07); padding: 30px; }
        h1 { color: #2d5a37; text-align: center; margin-bottom: 30px; }
        .form-group { margin-bottom: 18px; }
        label { font-weight: bold; color: #2d5a37; margin-bottom: 6px; display: block; }
        input, textarea { width: 100%; padding: 10px; border-radius: 6px; border: 1px solid #ddd; font-size: 1rem; }
        textarea { min-height: 100px; resize: vertical; }
        .btn-submit { background: #2d5a37; color: white; border: none; padding: 12px 25px; border-radius: 6px; cursor: pointer; font-weight: bold; width: 100%; font-size: 1rem; margin-top: 10px; }
        .alert.success { background: #d4edda; color: #155724; padding: 12px; border-radius: 6px; margin-bottom: 18px; text-align: center; }
    </style>
</head>
<body>
<?php require_once __DIR__ . '/includes/modules.php'; echo apercuBanner($db ?? null); ?>
    <div class="container" style="max-width:1100px;">
        <h1>Nouvelle évaluation</h1>
        <?php echo $message; ?>
        <form method="POST" id="evalForm" onsubmit="return verifierNomPrenom();">
            <?php echo csrfField(); ?>
            <div class="form-group">
                <label for="nom_evalue">Nom de la personne évaluée</label>
                <input type="text" name="nom_evalue" id="nom_evalue" required>
            </div>
            <div class="form-group">
                <label for="prenom_evalue">Prénom de la personne évaluée</label>
                <input type="text" name="prenom_evalue" id="prenom_evalue" required>
            </div>
            <div class="form-group">
                <label for="date_eval">Date de l'évaluation</label>
                <input type="date" name="date_eval" id="date_eval" required>
            </div>
            <div class="form-group">
                <label for="score_chariot_test">Score du chariot test sur 20</label>
                <input type="number" name="score_chariot_test" id="score_chariot_test" min="0" max="20" step="1">
            </div>
            <div class="form-group">
                <label for="score">Note d'appréciation globale</label>
                <input type="number" name="score" id="score" min="0" max="20" step="1" required>
            </div>

            <hr style="margin:32px 0;">
            <div style="text-align:center; margin-bottom:24px;">
                <button type="button" class="exam-btn" onclick="showExaminateur(1)" id="btnExam1" style="background:#8fd34f; color:#222; font-weight:bold; border-radius:8px; border:none; padding:10px 28px; margin:0 10px; font-size:1.1em;">Examinateur 1</button>
                <button type="button" class="exam-btn" onclick="showExaminateur(2)" id="btnExam2" style="background:#e0e0e0; color:#222; font-weight:bold; border-radius:8px; border:none; padding:10px 28px; margin:0 10px; font-size:1.1em;">Examinateur 2</button>
                <button type="button" class="exam-btn" onclick="showExaminateur(3)" id="btnExam3" style="background:#e0e0e0; color:#222; font-weight:bold; border-radius:8px; border:none; padding:10px 28px; margin:0 10px; font-size:1.1em;">Examinateur 3</button>
            </div>

            <div id="exam1" class="examinateur-block">
                <?php $examinateurIndex = 1; ?>
                <?php include 'grille_eval.php'; ?>
                <div class="form-group">
                    <label for="remarques1">Remarques examinateur 1</label>
                    <textarea name="remarques1" id="remarques1" placeholder="Compléter ici..." style="width:100%;min-height:80px;"></textarea>
                </div>
            </div>
            <div id="exam2" class="examinateur-block" style="display:none;">
                <?php $examinateurIndex = 2; ?>
                <?php include 'grille_eval.php'; ?>
                <div class="form-group">
                    <label for="remarques2">Remarques examinateur 2</label>
                    <textarea name="remarques2" id="remarques2" placeholder="Compléter ici..." style="width:100%;min-height:80px;"></textarea>
                </div>
            </div>
            <div id="exam3" class="examinateur-block" style="display:none;">
                <?php $examinateurIndex = 3; ?>
                <?php include 'grille_eval.php'; ?>
                <div class="form-group">
                    <label for="remarques3">Remarques examinateur 3</label>
                    <textarea name="remarques3" id="remarques3" placeholder="Compléter ici..." style="width:100%;min-height:80px;"></textarea>
                </div>
            </div>
            <div style="display:flex; gap:18px; margin-top:24px;">
                <button type="button" class="btn-submit" style="background:#e0e0e0;color:#2d5a37;" onclick="sauvegarderDonnees()">Sauvegarder</button>
                <button type="submit" class="btn-submit" style="background:#2d5a37;">Valider la formation</button>
            </div>
            <div id="saveMessage" style="margin-top:18px; text-align:center; color:#2d5a37; font-weight:bold;"></div>
        </form>
        <script>
        function showExaminateur(num) {
            for (let i=1; i<=3; i++) {
                document.getElementById('exam'+i).style.display = (i===num)?'block':'none';
                document.getElementById('btnExam'+i).style.background = (i===num)?'#8fd34f':'#e0e0e0';
            }
        }

        function sauvegarderDonnees() {
            const form = document.getElementById('evalForm');
            const formData = new FormData(form);
            fetch('save_evaluation_temp.php', {
                method: 'POST',
                body: formData
            })
            .then(async (response) => {
                const text = await response.text();
                if (!response.ok || text.trim() !== 'OK') {
                    throw new Error(text || 'Erreur inconnue');
                }
                return text;
            })
            .then(() => {
                document.getElementById('saveMessage').innerText = 'Données sauvegardées temporairement.';
            })
            .catch((err) => {
                document.getElementById('saveMessage').innerText = 'Erreur lors de la sauvegarde : ' + (err.message || 'inconnue');
            });
        }

        // Liste des utilisateurs pour vérification
        const utilisateurs = <?php
            $us = $db->query("SELECT nom, prenom FROM utilisateurs");
            $arr = [];
            foreach ($us as $u) {
                $arr[] = [
                    'nom' => strtolower((string) $u['nom']),
                    'prenom' => strtolower((string) $u['prenom']),
                ];
            }
            echo json_encode($arr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        ?>;

        function verifierNomPrenom() {
            const nom = document.getElementById('nom_evalue').value.trim().toLowerCase();
            const prenom = document.getElementById('prenom_evalue').value.trim().toLowerCase();
            const trouve = utilisateurs.some(u => u.nom === nom && u.prenom === prenom);
            if (!trouve) {
                return confirm('⚠️ Attention : ce nom/prénom ne correspond à aucun collaborateur connu. Voulez-vous quand même enregistrer l\'évaluation ?');
            }
            return true;
        }
        </script>
    </div>
    <script>
        window.addEventListener('unload', function() {
            // Appel AJAX pour déconnecter
            navigator.sendBeacon('logout.php');
        });
    </script>
</body>
</html>
