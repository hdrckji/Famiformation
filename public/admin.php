<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
require_once 'config.php';
verifierConnexion($db);

ensureUserAccountAccessColumns($db);

if (!isAdminOrTeamcoach()) {
    header('Location: index.php');
    exit();
}

$hasInterimColumn = false;
try {
    $columnStmt = $db->query("SHOW COLUMNS FROM utilisateurs LIKE 'interim'");
    $hasInterimColumn = (bool) $columnStmt->fetch();
    if (!$hasInterimColumn) {
        $db->exec("ALTER TABLE utilisateurs ADD COLUMN interim VARCHAR(255) NULL AFTER email");
        $columnStmt = $db->query("SHOW COLUMNS FROM utilisateurs LIKE 'interim'");
        $hasInterimColumn = (bool) $columnStmt->fetch();
    }
} catch (Exception $e) {
    $hasInterimColumn = false;
}

$agencesInterim = [];
try {
    $agenceTableStmt = $db->query("SHOW TABLES LIKE 'interim_agences'");
    if ($agenceTableStmt->fetch()) {
        $agencesInterim = $db->query("SELECT nom_agence FROM interim_agences ORDER BY nom_agence ASC")->fetchAll(PDO::FETCH_COLUMN);
    }
} catch (Exception $e) {
    $agencesInterim = [];
}

$message = "";

// Suppression d'utilisateur (GET)
if (isset($_GET['delete'])) {
    requireValidCSRF();
    $uid = $_GET['delete'];
    // Supprimer d'abord les statistiques liées
    $db->prepare("DELETE FROM statistiques WHERE utilisateur_id = ?")->execute([$uid]);
    // On ne supprime pas l'admin principal
    $stmt = $db->prepare("DELETE FROM utilisateurs WHERE id = ? AND identifiant != 'admin'");
    $stmt->execute([$uid]);
    $deleted = $stmt->rowCount();
    if ($deleted > 0) {
        $message = "<div class='alert success'>✅ Utilisateur supprimé avec succès.</div>";
    } else {
        $message = "<div class='alert error'>❌ Échec de la suppression (utilisateur introuvable ou admin principal protégé).</div>";
    }
}

// Création de l'utilisateur spécial "Accueil" avec le rôle 'evaluateur' si non existant
try {
    $stmt = $db->prepare("SELECT id FROM utilisateurs WHERE identifiant = :id");
    $stmt->execute(['id' => 'Accueil']);
    if (!$stmt->fetch()) {
        $stmt = $db->prepare("INSERT INTO utilisateurs (identifiant, mot_de_passe, role) VALUES (:id, :pass, :role)");
        $stmt->execute([
            'id' => 'Accueil',
            'pass' => password_hash('1234', PASSWORD_DEFAULT),
            'role' => 'evaluateur'
        ]);
    } else {
        // Mise à jour du rôle si besoin
        $stmt = $db->prepare("UPDATE utilisateurs SET role = 'evaluateur' WHERE identifiant = :id");
        $stmt->execute(['id' => 'Accueil']);
    }
} catch (Exception $e) { /* déjà existant ou erreur */ }

// 1. GESTION DES ACTIONS
if (isset($_POST['creer_user'])) {
    // Validation CSRF
    requireValidCSRF();
    
    $id = trim($_POST['new_username'] ?? '');
    $nom = trim($_POST['new_nom'] ?? '');
    $prenom = trim($_POST['new_prenom'] ?? '');
    $email = trim($_POST['new_email'] ?? '');
    $interim = trim($_POST['new_interim'] ?? '');
    $mdp = $_POST['new_password'] ?? '';
    $role = $_POST['new_role'] ?? '';

    if ($role === 'etudiant' && $interim === '') {
        $message = "<div class='alert error'>❌ Le champ Intérim est obligatoire pour un profil étudiant.</div>";
    } elseif (!$hasInterimColumn) {
        $message = "<div class='alert error'>❌ La colonne Intérim n'est pas disponible en base de données.</div>";
    } elseif (empty($id)) {
        $message = "<div class='alert error'>❌ L'identifiant est obligatoire.</div>";
    } elseif ($mdp === '' && $email === '') {
        $message = "<div class='alert error'>❌ Si le mot de passe est vide, une adresse email est obligatoire pour envoyer le lien d'activation.</div>";
    } else {
        $hash = $mdp !== ''
            ? password_hash($mdp, PASSWORD_DEFAULT)
            : password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
        try {
            $stmt = $db->prepare("INSERT INTO utilisateurs (identifiant, nom, prenom, email, interim, mot_de_passe, role, account_activation_pending) VALUES (:id, :nom, :prenom, :email, :interim, :pass, :role, :activation_pending)");
            $stmt->execute([
                'id' => $id,
                'nom' => $nom,
                'prenom' => $prenom,
                'email' => $email !== '' ? $email : null,
                'interim' => $interim !== '' ? $interim : null,
                'pass' => $hash,
                'role' => $role,
                'activation_pending' => ($mdp === '' && $email !== '') ? 1 : 0,
            ]);
            $userId = (int) $db->lastInsertId();
            $message = "<div class='alert success'>✅ Collaborateur créé avec succès.</div>";

            if ($role === 'etudiant' && $email !== '') {
                $passwordSetupUrl = null;
                if ($mdp === '') {
                    $activationToken = issueUserAccountAccessToken($db, $userId, 'activation', 72);
                    $passwordSetupUrl = famiBuildAppUrl('set_password.php', ['token' => $activationToken]);
                }

                $welcomeMailSent = sendStudentWelcomeEmail($email, $id, $passwordSetupUrl);
                if ($welcomeMailSent) {
                    $message = $mdp === ''
                        ? "<div class='alert success'>✅ Collaborateur créé avec succès.<br>📨 Le mail de bienvenue étudiant avec lien de création du mot de passe a été envoyé.</div>"
                        : "<div class='alert success'>✅ Collaborateur créé avec succès.<br>📨 Le mail de bienvenue étudiant a été envoyé.</div>";
                } else {
                    $mailError = trim((string) getLastMailError());
                    $details = $mailError !== '' ? '<br><small>Détail SMTP : ' . e($mailError) . '</small>' : '';
                    $message = $mdp === ''
                        ? "<div class='alert warning'>⚠️ Collaborateur créé, mais <strong>en attente d'activation</strong> : le mail avec le lien de création du mot de passe n'a pas pu être envoyé. Le compte ne pourra pas se connecter tant que la procédure n'est pas terminée.{$details}</div>"
                        : "<div class='alert warning'>⚠️ Collaborateur créé, mais <strong>le mail de bienvenue étudiant n'a pas pu être envoyé.</strong>{$details}</div>";
                }
            } elseif ($mdp === '' && $email !== '') {
                $activationMailSent = sendAccountActivationEmail($db, $userId);
                if ($activationMailSent) {
                    $message = "<div class='alert success'>✅ Collaborateur créé avec succès.<br>📨 Un mail d'activation a été envoyé.</div>";
                } else {
                    $mailError = trim((string) getLastMailError());
                    $details = $mailError !== '' ? '<br><small>Détail SMTP : ' . e($mailError) . '</small>' : '';
                    $message = "<div class='alert warning'>⚠️ Collaborateur créé, mais <strong>en attente d'activation</strong> : le mail d'activation n'a pas pu être envoyé. Le compte ne pourra pas se connecter tant que la procédure n'est pas terminée.{$details}</div>";
                }
            }
        } catch (Exception $e) { $message = "<div class='alert error'>❌ Erreur : Identifiant déjà utilisé.</div>"; }
    }
}

if (isset($_POST['update_user'])) {
    requireValidCSRF();
    $uid = $_POST['user_id'] ?? '';
    $nouveau_role = $_POST['nouveau_role'] ?? '';
    $nouveau_mdp = $_POST['nouveau_mdp'] ?? '';
    $nouveau_interim = trim($_POST['nouveau_interim'] ?? '');
    if ($nouveau_role === 'etudiant' && $nouveau_interim === '') {
        $message = "<div class='alert error'>❌ Le champ Intérim est obligatoire pour un profil étudiant.</div>";
    } elseif (!$hasInterimColumn) {
        $message = "<div class='alert error'>❌ La colonne Intérim n'est pas disponible en base de données.</div>";
    } elseif (!empty($nouveau_mdp)) {
        $hash = password_hash($nouveau_mdp, PASSWORD_DEFAULT);
        $stmt = $db->prepare("UPDATE utilisateurs SET role = ?, interim = ?, mot_de_passe = ?, account_activation_pending = 0, account_access_token_hash = NULL, account_access_expires_at = NULL, account_access_type = NULL WHERE id = ?");
        $stmt->execute([$nouveau_role, $nouveau_interim !== '' ? $nouveau_interim : null, $hash, $uid]);
    } else {
        $stmt = $db->prepare("UPDATE utilisateurs SET role = ?, interim = ? WHERE id = ?");
        $stmt->execute([$nouveau_role, $nouveau_interim !== '' ? $nouveau_interim : null, $uid]);
    }

    if ($message === "") {
        $message = "<div class='alert success'>✅ Modifications enregistrées.</div>";
    }
}

if (isset($_POST['update_email']) && isset($_POST['user_id'])) {
    requireValidCSRF();
    $uid = $_POST['user_id'];
    $nouveau_email = trim($_POST['nouveau_email'] ?? '');
    $stmt = $db->prepare("UPDATE utilisateurs SET email = ? WHERE id = ?");
    $stmt->execute([$nouveau_email, $uid]);
    $message = "<div class='alert success'>✅ Email modifié.</div>";
}

if (isset($_POST['set_inactif']) && isset($_POST['user_id'])) {
    requireValidCSRF();
    $uid = intval($_POST['user_id']);
    $stmt = $db->prepare("UPDATE utilisateurs SET statut = 'inactif' WHERE id = ?");
    $stmt->execute([$uid]);
    $message = "<div class='alert success'>✅ Utilisateur passé en inactif.</div>";
}
if (isset($_POST['set_actif']) && isset($_POST['user_id'])) {
    requireValidCSRF();
    $uid = intval($_POST['user_id']);
    $stmt = $db->prepare("UPDATE utilisateurs SET statut = NULL WHERE id = ?");
    $stmt->execute([$uid]);
    $message = "<div class='alert success'>✅ Utilisateur réactivé.</div>";
}

// 2. RÉCUPÉRATION DES DONNÉES AVEC FILTRE
$role_filter = isset($_GET['filter_role']) ? $_GET['filter_role'] : '';
$search_nom = trim($_GET['search_nom'] ?? '');

// Récupérer dynamiquement tous les thèmes de quiz
$themes_stmt = $db->query("SELECT DISTINCT theme FROM quiz_questions ORDER BY theme ASC");
$all_quiz_themes = $themes_stmt->fetchAll(PDO::FETCH_COLUMN);

// Liste dynamique des thèmes quiz
$liste_quiz_array = array_map('addslashes', $all_quiz_themes);
$liste_quiz = "'" . implode("','", $liste_quiz_array) . "'";
$total_quiz = count($liste_quiz_array);

// Requête principale avec calcul dynamique du nombre de quiz réalisés
$query_str = "SELECT u.*, 
    (SELECT COUNT(DISTINCT nom_page) 
     FROM statistiques 
     WHERE utilisateur_id = u.id 
     AND nom_page IN ($liste_quiz) 
     AND score IS NOT NULL) as nb_tests
    FROM utilisateurs u";

$show_inactif = isset($_GET['show_inactif']) ? ($_GET['show_inactif'] === '1') : false;

$query_str = "SELECT u.*, 
      (SELECT COUNT(DISTINCT nom_page) 
       FROM statistiques 
       WHERE utilisateur_id = u.id 
       AND nom_page IN ($liste_quiz) 
       AND score IS NOT NULL) as nb_tests
      FROM utilisateurs u";

$where = [];
if ($role_filter != '') {
    $where[] = "u.role = " . $db->quote($role_filter);
}
if ($search_nom !== '') {
    $search = '%' . $search_nom . '%';
    $where[] = "(u.nom LIKE " . $db->quote($search) . " OR u.prenom LIKE " . $db->quote($search) . ")";
}
if ($show_inactif) {
    $where[] = "u.statut = 'inactif'";
} else {
    $where[] = "(u.statut IS NULL OR u.statut != 'inactif')";
}
$where[] = "u.role != 'agence_interim'";
if (count($where) > 0) {
    $query_str .= " WHERE " . implode(' AND ', $where);
}
$query_str .= " ORDER BY u.nom ASC";
$users = $db->query($query_str)->fetchAll();
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Administration - FamiFormation</title>
    <link rel="shortcut icon" type="image/x-icon" href="favicon.ico">
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Open Sans', sans-serif; background: #f4f7f6; margin: 0; padding: 20px; }
        .container { max-width: 1520px; margin: 0 auto; }
        h1 { color: #2d5a37; text-align: center; }
        .alert { padding: 15px; margin-bottom: 20px; border-radius: 8px; font-weight: bold; text-align: center; }
        .success { background: #d4edda; color: #155724; }
        .error { background: #f8d7da; color: #721c24; }
        .warning { background: #fff3cd; color: #856404; }
        .card { background: white; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); margin-bottom: 30px; overflow: hidden; }
        .card-header { background: #2d5a37; color: white; padding: 15px 25px; font-weight: bold; font-size: 1.1rem; display: flex; justify-content: space-between; align-items: center; }
        .card-body { padding: 25px; }
        table { width: 100%; border-collapse: separate; border-spacing: 0; margin-top: 10px; table-layout: fixed; }
        th, td { padding: 14px 12px; text-align: left; border-bottom: 1px solid #eee; vertical-align: top; }
        th { background: #f8f9fa; color: #666; text-transform: uppercase; font-size: 0.8rem; position: sticky; top: 0; z-index: 1; }
        .input-mini { padding: 6px; border-radius: 4px; border: 1px solid #ccc; font-size: 0.9rem; }
        .btn-save { background: #2d5a37; color: white; border: none; padding: 6px 12px; border-radius: 4px; cursor: pointer; }
        .btn-export { background: #1d6f42; color: white; text-decoration: none; padding: 10px 20px; border-radius: 8px; font-weight: bold; display: inline-block; margin-bottom: 20px; }
        .filter-select { padding: 5px; border-radius: 4px; border: none; font-size: 0.85rem; cursor: pointer; }
        .hint { color: #666; font-size: 0.85rem; margin-top: 10px; }
        .table-wrap { overflow-x: auto; padding: 0 0 8px 0; }
        .col-name { width: 17%; }
        .col-login { width: 10%; }
        .col-email { width: 16%; }
        .col-interim { width: 15%; }
        .col-quiz { width: 9%; text-align: center; }
        .col-role { width: 18%; }
        .col-status { width: 7%; }
        .col-actions { width: 14%; }
        .name-link { color: #1d6f42; text-decoration: none; font-weight: 700; }
        .name-link:hover { text-decoration: underline; }
        .muted-code { display: inline-block; background: #f4f7f6; border-radius: 6px; padding: 4px 8px; font-size: 0.85rem; }
        .stack-form { display: flex; align-items: center; gap: 8px; }
        .role-form { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 92px) 42px; gap: 8px; align-items: center; }
        .cell-note { font-size: 0.78rem; color: #777; margin-top: 6px; }
        .quiz-badge { display: inline-flex; align-items: center; justify-content: center; min-width: 88px; padding: 8px 10px; border-radius: 999px; background: #f0f7f1; color: #2d5a37; font-size: 0.85rem; font-weight: 700; }
        .status-cell { text-align: center; }
        .status-badge { display: inline-flex; align-items: center; justify-content: center; min-width: 92px; padding: 8px 10px; border-radius: 999px; font-size: 0.82rem; font-weight: 700; }
        .status-active { background: #e8f5e9; color: #2d5a37; }
        .status-inactive { background: #f9e1e1; color: #a83232; }
        .status-pending { background: #fff3cd; color: #856404; }
        .action-stack { display: flex; flex-direction: column; align-items: stretch; gap: 8px; min-width: 130px; }
        .action-stack form,
        .action-stack a { margin: 0; }
        .btn-delete-link { display: inline-flex; align-items: center; justify-content: center; background: #f9e1e1; color: #a83232; text-decoration: none; padding: 7px 10px; border-radius: 8px; font-size: 0.82rem; font-weight: 700; }
        .btn-delete-link:hover { background: #f4caca; }
        .sticky-name { position: sticky; left: 0; background: #fff; z-index: 2; box-shadow: 8px 0 14px rgba(0,0,0,0.03); }
        th.sticky-name { background: #f8f9fa; z-index: 3; }
        .role-form .input-mini,
        .stack-form .input-mini,
        .stack-form select { width: 100% !important; min-width: 0; }
        .role-form .btn-save { width: 42px; padding: 6px 0; }
    </style>
</head>
<body>

<div class="container">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <a href="index.php" style="text-decoration:none; color:#2d5a37; font-weight:bold;">⬅ Retour Accueil</a>
        <h1>Espace Administration</h1>
        <div style="display:flex; align-items:center; gap:10px;">
            <a href="admin_agences_interim.php" class="btn-export" style="background:#2d5a37;">🏢 Agences Intérim</a>
            <a href="export_excel.php" class="btn-export">📊 Export Excel Complet</a>
        </div>
    </div>

    <?php echo $message; ?>

    <div class="card">
        <div class="card-header" style="font-size:1.35rem;">Ajouter un nouveau collaborateur</div>
        <div class="card-body">
            <form method="POST" id="createUserForm" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px;">
                <?php echo csrfField(); ?>
                <input type="text" name="new_username" placeholder="Identifiant" required class="input-mini">
                <input type="text" name="new_nom" placeholder="Nom" required class="input-mini">
                <input type="text" name="new_prenom" placeholder="Prénom" required class="input-mini">
                <input type="email" name="new_email" placeholder="Email (optionnel)" class="input-mini">
                <select name="new_interim" id="new_interim" class="input-mini">
                    <option value="">Sélectionner une agence intérim</option>
                    <?php foreach ($agencesInterim as $agenceNom): ?>
                        <option value="<?php echo htmlspecialchars($agenceNom); ?>"><?php echo htmlspecialchars($agenceNom); ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="password" name="new_password" placeholder="Mot de passe (laisser vide pour activer par email)" class="input-mini">
                <select name="new_role" id="new_role" class="input-mini">
                    <option value="etudiant">Étudiant</option>
                    <option value="employe_magasin">Magasin</option>
                    <option value="teamcoach">Teamcoach</option>
                    <option value="mentor">Mentor</option>
                    <option value="employe_logistique">Logistique</option>
                    <option value="admin">Admin</option>
                    <option value="evaluateur">Évaluateur</option>
                </select>
                <button type="submit" name="creer_user" class="btn-save" style="height: 40px;">Créer le compte</button>
            </form>
            <div class="hint">Le champ Intérim est obligatoire uniquement pour les profils Étudiant. Si le mot de passe est laissé vide et qu'un email est renseigné, un mail d'activation part automatiquement. La liste des agences vient de la page Agences Intérim.</div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <span>Collaborateurs</span>
            <div style="background:#2d5a37; padding:4px 8px; border-radius:8px; margin-bottom:14px; display:flex; align-items:center; gap:0; height:28px; min-height:28px; max-height:28px;">
                <form method="GET" id="filterForm" style="display:flex; flex:1; align-items:center; gap:0; width:100%;">
                    <div style="flex:2; display:flex; align-items:center;">
                        <input id="search_nom" type="text" name="search_nom" value="<?php echo htmlspecialchars($_GET['search_nom'] ?? ''); ?>" placeholder="Recherche nom ou prénom..." class="input-mini" style="height:36px; width:220px; margin-right:18px; border:1px solid #ccc; font-size:1em;" />
                        <button type="submit" class="btn-save" style="height:32px; min-width:32px; font-size:1.1em; padding:0 8px; margin-right:18px;">🔍</button>
                    </div>
                    <div style="flex:1; display:flex; align-items:center; justify-content:center;">
                        <select id="filter_role" name="filter_role" class="filter-select" style="height:32px; min-width:120px; font-size:1em; margin-right:18px;" onchange="document.getElementById('filterForm').submit()">
                            <option value="">Tous les rôles</option>
                            <option value="etudiant" <?php if($role_filter == 'etudiant') echo 'selected'; ?>>Étudiant</option>
                            <option value="employe_magasin" <?php if($role_filter == 'employe_magasin') echo 'selected'; ?>>Magasin</option>
                            <option value="teamcoach" <?php if($role_filter == 'teamcoach') echo 'selected'; ?>>Teamcoach</option>
                            <option value="mentor" <?php if($role_filter == 'mentor') echo 'selected'; ?>>Mentor</option>
                            <option value="employe_logistique" <?php if($role_filter == 'employe_logistique') echo 'selected'; ?>>Logistique</option>
                            <option value="admin" <?php if($role_filter == 'admin') echo 'selected'; ?>>Admin</option>
                            <option value="evaluateur" <?php if($role_filter == 'evaluateur') echo 'selected'; ?>>Évaluateur</option>
                        </select>
                    </div>
                    <div style="flex:1; display:flex; align-items:center; justify-content:flex-end;">
                        <input id="show_inactif" type="checkbox" name="show_inactif" value="1" <?php if($show_inactif) echo 'checked'; ?> style="height:22px; width:22px; margin-right:8px;" onchange="document.getElementById('filterForm').submit()">
                        <label for="show_inactif" style="color:white; font-size:1em; margin-right:8px;">Inactif</label>
                    </div>
                </form>
            </div>
        </div>
        <div class="card-body" style="padding:0;">
            <div style="padding:16px 25px 0 25px;">
                <a href="admin_evaluations_orphelines.php" style="color:#b00;font-weight:bold;text-decoration:none;">Évaluations orphelines</a>
            </div>
            <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th class="col-name sticky-name">Collaborateur</th>
                        <th class="col-login">Identifiant</th>
                        <th class="col-email">Email</th>
                        <th class="col-interim">Intérim</th>
                        <th class="col-quiz">Quiz</th>
                        <th class="col-role">Profil</th>
                        <th class="col-status">Statut</th>
                        <th class="col-actions">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $u): ?>
                    <tr>
                        <td class="sticky-name">
                            <a href="admin_user.php?id=<?php echo $u['id']; ?>" class="name-link"><?php echo htmlspecialchars($u['nom'] . " " . $u['prenom']); ?></a>
                            <div class="cell-note"><?php echo htmlspecialchars($u['role']); ?></div>
                        </td>
                        <td><span class="muted-code"><?php echo htmlspecialchars($u['identifiant']); ?></span></td>
                        <td>
                            <form method="POST" class="stack-form">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                <input type="email" name="nouveau_email" value="<?php echo htmlspecialchars($u['email'] ?? ''); ?>" placeholder="Email" class="input-mini" style="width:140px;">
                                <button type="submit" name="update_email" class="btn-save" style="padding:2px 8px; font-size:0.9em;">✉️</button>
                            </form>
                        </td>
                        <td>
                            <form method="POST" class="stack-form">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                <select name="nouveau_interim" class="input-mini" style="width:180px;">
                                    <option value="">Aucune agence</option>
                                    <?php if (!empty($u['interim']) && !in_array($u['interim'], $agencesInterim, true)): ?>
                                        <option value="<?php echo htmlspecialchars($u['interim']); ?>" selected><?php echo htmlspecialchars($u['interim']); ?></option>
                                    <?php endif; ?>
                                    <?php foreach ($agencesInterim as $agenceNom): ?>
                                        <option value="<?php echo htmlspecialchars($agenceNom); ?>" <?php if (($u['interim'] ?? '') === $agenceNom) echo 'selected'; ?>><?php echo htmlspecialchars($agenceNom); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="hidden" name="nouveau_role" value="<?php echo htmlspecialchars($u['role']); ?>">
                                <button type="submit" name="update_user" class="btn-save" style="padding:2px 8px; font-size:0.9em;">🏢</button>
                            </form>
                        </td>
                        <td class="col-quiz">
                            <span class="quiz-badge">
                                <?php echo $u['nb_tests']; ?> / <?php echo $total_quiz; ?> quiz
                            </span>
                        </td>
                        <td>
                            <form method="POST" class="role-form">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                <input type="hidden" name="nouveau_interim" value="<?php echo htmlspecialchars($u['interim'] ?? ''); ?>">
                                <select name="nouveau_role" class="input-mini">
                                    <option value="etudiant" <?php if($u['role']=='etudiant') echo 'selected'; ?>>Étudiant</option>
                                    <option value="employe_magasin" <?php if($u['role']=='employe_magasin') echo 'selected'; ?>>Magasin</option>
                                    <option value="teamcoach" <?php if($u['role']=='teamcoach') echo 'selected'; ?>>Teamcoach</option>
                                    <option value="mentor" <?php if($u['role']=='mentor') echo 'selected'; ?>>Mentor</option>
                                    <option value="employe_logistique" <?php if($u['role']=='employe_logistique') echo 'selected'; ?>>Logistique</option>
                                    <option value="admin" <?php if($u['role']=='admin') echo 'selected'; ?>>Admin</option>
                                    <option value="evaluateur" <?php if($u['role']=='evaluateur') echo 'selected'; ?>>Évaluateur</option>
                                </select>
                                <input type="password" name="nouveau_mdp" placeholder="Nouv. Pass" class="input-mini" style="width:80px;">
                                <button type="submit" name="update_user" class="btn-save">💾</button>
                            </form>
                            <div class="cell-note">Le mot de passe peut rester vide si tu ne veux pas le changer.</div>
                        </td>
                        <td class="status-cell">
                            <?php if (($u['statut'] ?? '') === 'inactif'): ?>
                                <span class="status-badge status-inactive">Inactif</span>
                            <?php elseif (!empty($u['account_activation_pending'])): ?>
                                <span class="status-badge status-pending">En attente</span>
                            <?php else: ?>
                                <span class="status-badge status-active">Actif</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($u['identifiant'] !== 'admin'): ?>
                                <div class="action-stack">
                                    <?php if (($u['statut'] ?? '') === 'inactif'): ?>
                                        <form method="POST">
                                            <?php echo csrfField(); ?>
                                            <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                            <input type="hidden" name="set_actif" value="1">
                                            <button type="submit" class="btn-save" style="width:100%;background:#2d5a37;color:white;">✔ Activer</button>
                                        </form>
                                    <?php else: ?>
                                        <form method="POST">
                                            <?php echo csrfField(); ?>
                                            <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                            <input type="hidden" name="set_inactif" value="1">
                                            <button type="submit" class="btn-save" style="width:100%;background:#a83232;color:white;">✖ Désactiver</button>
                                        </form>
                                    <?php endif; ?>
                                    <a href="?delete=<?php echo $u['id']; ?>&csrf=<?php echo getCSRFToken(); ?>" class="btn-delete-link" onclick="return confirm('Supprimer définitivement cet utilisateur ?');">🗑 Supprimer</a>
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
    </div>
</div>
<script>
const roleField = document.getElementById('new_role');
const interimField = document.getElementById('new_interim');

function syncInterimRequirement() {
    if (!roleField || !interimField) {
        return;
    }

    const isStudent = roleField.value === 'etudiant';
    interimField.required = isStudent;
}

syncInterimRequirement();
if (roleField) {
    roleField.addEventListener('change', syncInterimRequirement);
}
</script>
</body>
</html>