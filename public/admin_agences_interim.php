<?php
require_once 'config.php';
verifierConnexion($db);
requireAdminOrTeamcoach();

$message = '';

try {
    $db->exec(
        "CREATE TABLE IF NOT EXISTS interim_agences (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            nom_agence VARCHAR(255) NOT NULL,
            nom_contact VARCHAR(255) NOT NULL,
            email_1 VARCHAR(255) DEFAULT NULL,
            email_2 VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
} catch (Exception $e) {
    $message = "<div class='alert error'>❌ Impossible d'initialiser la table des agences intérim.</div>";
}

try {
    $db->exec(
        "CREATE TABLE IF NOT EXISTS interim_agence_users (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            agence_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_interim_agence_user_user (user_id),
            KEY idx_interim_agence_users_agence (agence_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
} catch (Exception $e) {
    if ($message === '') {
        $message = "<div class='alert error'>❌ Impossible d'initialiser les comptes des agences intérim.</div>";
    }
}

try {
    $columnStmt = $db->query("SHOW COLUMNS FROM utilisateurs LIKE 'interim'");
    if (!$columnStmt->fetch()) {
        $db->exec("ALTER TABLE utilisateurs ADD COLUMN interim VARCHAR(255) NULL AFTER email");
    }
} catch (Exception $e) {
    // La création de comptes reste possible même sans cette colonne.
}

if (isset($_POST['create_agence'])) {
    requireValidCSRF();

    $nomAgence = trim($_POST['nom_agence'] ?? '');
    $nomContact = trim($_POST['nom_contact'] ?? '');
    $email1 = trim($_POST['email_1'] ?? '');
    $email2 = trim($_POST['email_2'] ?? '');

    if ($nomAgence === '' || $nomContact === '') {
        $message = "<div class='alert error'>❌ Le nom de l'agence et le nom du contact sont obligatoires.</div>";
    } elseif ($email1 !== '' && !filter_var($email1, FILTER_VALIDATE_EMAIL)) {
        $message = "<div class='alert error'>❌ L'adresse email principale n'est pas valide.</div>";
    } elseif ($email2 !== '' && !filter_var($email2, FILTER_VALIDATE_EMAIL)) {
        $message = "<div class='alert error'>❌ La deuxième adresse email n'est pas valide.</div>";
    } else {
        $stmt = $db->prepare('INSERT INTO interim_agences (nom_agence, nom_contact, email_1, email_2) VALUES (?, ?, ?, ?)');
        $stmt->execute([
            $nomAgence,
            $nomContact,
            $email1 !== '' ? $email1 : null,
            $email2 !== '' ? $email2 : null,
        ]);
        $message = "<div class='alert success'>✅ Agence intérim ajoutée.</div>";
    }
}

if (isset($_POST['update_agence'])) {
    requireValidCSRF();

    $agenceId = (int) ($_POST['agence_id'] ?? 0);
    $nomAgence = trim($_POST['nom_agence'] ?? '');
    $nomContact = trim($_POST['nom_contact'] ?? '');
    $email1 = trim($_POST['email_1'] ?? '');
    $email2 = trim($_POST['email_2'] ?? '');

    if ($agenceId <= 0 || $nomAgence === '' || $nomContact === '') {
        $message = "<div class='alert error'>❌ Les informations de l'agence sont incomplètes.</div>";
    } elseif ($email1 !== '' && !filter_var($email1, FILTER_VALIDATE_EMAIL)) {
        $message = "<div class='alert error'>❌ L'adresse email principale n'est pas valide.</div>";
    } elseif ($email2 !== '' && !filter_var($email2, FILTER_VALIDATE_EMAIL)) {
        $message = "<div class='alert error'>❌ La deuxième adresse email n'est pas valide.</div>";
    } else {
        $stmt = $db->prepare('UPDATE interim_agences SET nom_agence = ?, nom_contact = ?, email_1 = ?, email_2 = ? WHERE id = ?');
        $stmt->execute([
            $nomAgence,
            $nomContact,
            $email1 !== '' ? $email1 : null,
            $email2 !== '' ? $email2 : null,
            $agenceId,
        ]);
        $message = "<div class='alert success'>✅ Agence intérim modifiée.</div>";
    }
}

if (isset($_POST['delete_agence'])) {
    requireValidCSRF();

    $agenceId = (int) ($_POST['agence_id'] ?? 0);
    if ($agenceId > 0) {
        $countLinksStmt = $db->prepare('SELECT COUNT(*) FROM interim_agence_users WHERE agence_id = ?');
        $countLinksStmt->execute([$agenceId]);
        $linkedUsersCount = (int) $countLinksStmt->fetchColumn();

        if ($linkedUsersCount > 0) {
            $message = "<div class='alert error'>❌ Cette agence possède {$linkedUsersCount} compte(s) utilisateur. Supprime d'abord les comptes agence liés.</div>";
        } else {
            $stmt = $db->prepare('DELETE FROM interim_agences WHERE id = ?');
            $stmt->execute([$agenceId]);
            $message = "<div class='alert success'>✅ Agence intérim supprimée.</div>";
        }
    }
}

if (isset($_POST['create_agence_user'])) {
    requireValidCSRF();

    $agenceId = (int) ($_POST['agence_id'] ?? 0);
    $username = trim($_POST['username'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    $email = trim($_POST['email'] ?? '');

    if ($agenceId <= 0 || $username === '' || $password === '' || $email === '') {
        $message = "<div class='alert error'>❌ L'agence, l'identifiant, le mot de passe et l'email sont obligatoires.</div>";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = "<div class='alert error'>❌ L'adresse email n'est pas valide.</div>";
    } else {
        try {
            $agenceStmt = $db->prepare('SELECT nom_agence FROM interim_agences WHERE id = ? LIMIT 1');
            $agenceStmt->execute([$agenceId]);
            $agenceNom = (string) $agenceStmt->fetchColumn();

            if ($agenceNom === '') {
                $message = "<div class='alert error'>❌ Agence introuvable.</div>";
            } else {
                $db->beginTransaction();

                $insertUser = $db->prepare(
                    "INSERT INTO utilisateurs (identifiant, email, interim, mot_de_passe, role)
                     VALUES (?, ?, ?, ?, 'agence_interim')"
                );
                $insertUser->execute([
                    $username,
                    $email,
                    $agenceNom,
                    password_hash($password, PASSWORD_DEFAULT),
                ]);

                $newUserId = (int) $db->lastInsertId();

                $linkStmt = $db->prepare('INSERT INTO interim_agence_users (agence_id, user_id) VALUES (?, ?)');
                $linkStmt->execute([$agenceId, $newUserId]);

                $db->commit();
                $message = "<div class='alert success'>✅ Compte agence créé avec succès.</div>";
            }
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $message = "<div class='alert error'>❌ Impossible de créer le compte (identifiant déjà utilisé ou erreur base de données).</div>";
        }
    }
}

if (isset($_POST['update_agence_user'])) {
    requireValidCSRF();

    $linkId = (int) ($_POST['link_id'] ?? 0);
    $email = trim($_POST['email'] ?? '');
    $newPassword = (string) ($_POST['new_password'] ?? '');

    if ($linkId <= 0 || $email === '') {
        $message = "<div class='alert error'>❌ L'email du compte agence est obligatoire.</div>";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = "<div class='alert error'>❌ L'adresse email du compte agence n'est pas valide.</div>";
    } else {
        $userIdStmt = $db->prepare(
            "SELECT u.id
             FROM interim_agence_users iau
             JOIN utilisateurs u ON u.id = iau.user_id
             WHERE iau.id = ? AND u.role = 'agence_interim'
             LIMIT 1"
        );
        $userIdStmt->execute([$linkId]);
        $userId = (int) $userIdStmt->fetchColumn();

        if ($userId <= 0) {
            $message = "<div class='alert error'>❌ Compte agence introuvable.</div>";
        } else {
            if ($newPassword !== '') {
                $stmt = $db->prepare('UPDATE utilisateurs SET email = ?, mot_de_passe = ? WHERE id = ?');
                $stmt->execute([$email, password_hash($newPassword, PASSWORD_DEFAULT), $userId]);
            } else {
                $stmt = $db->prepare('UPDATE utilisateurs SET email = ? WHERE id = ?');
                $stmt->execute([$email, $userId]);
            }

            $message = "<div class='alert success'>✅ Compte agence mis à jour.</div>";
        }
    }
}

if (isset($_POST['delete_agence_user'])) {
    requireValidCSRF();

    $linkId = (int) ($_POST['link_id'] ?? 0);
    if ($linkId > 0) {
        $userIdStmt = $db->prepare('SELECT user_id FROM interim_agence_users WHERE id = ? LIMIT 1');
        $userIdStmt->execute([$linkId]);
        $userId = (int) $userIdStmt->fetchColumn();

        if ($userId > 0) {
            $db->beginTransaction();
            try {
                $db->prepare('DELETE FROM statistiques WHERE utilisateur_id = ?')->execute([$userId]);
                $db->prepare('DELETE FROM interim_agence_users WHERE id = ?')->execute([$linkId]);
                $db->prepare("DELETE FROM utilisateurs WHERE id = ? AND role = 'agence_interim'")->execute([$userId]);
                $db->commit();
                $message = "<div class='alert success'>✅ Compte agence supprimé.</div>";
            } catch (Exception $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                $message = "<div class='alert error'>❌ Impossible de supprimer ce compte agence.</div>";
            }
        }
    }
}

$agences = [];
try {
    $stmt = $db->query('SELECT * FROM interim_agences ORDER BY nom_agence ASC, nom_contact ASC');
    $agences = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    if ($message === '') {
        $message = "<div class='alert error'>❌ Impossible de charger les agences intérim.</div>";
    }
}

$agenceUsers = [];
try {
    $stmt = $db->query(
        "SELECT iau.id AS link_id, iau.created_at AS linked_at, ia.id AS agence_id, ia.nom_agence,
                u.id AS user_id, u.identifiant, u.email, u.statut
         FROM interim_agence_users iau
         JOIN interim_agences ia ON ia.id = iau.agence_id
         JOIN utilisateurs u ON u.id = iau.user_id
         WHERE u.role = 'agence_interim'
         ORDER BY ia.nom_agence ASC, u.identifiant ASC"
    );
    $agenceUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    if ($message === '') {
        $message = "<div class='alert error'>❌ Impossible de charger les comptes agences.</div>";
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Agences Intérim - Administration</title>
    <link rel="shortcut icon" type="image/x-icon" href="favicon.ico">
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #f4f7f6;
            --card: #ffffff;
            --line: #e6ece8;
            --text: #244230;
            --muted: #65756d;
            --accent: #2d5a37;
            --accent-soft: #edf5ef;
            --warn: #a83232;
            --shadow: 0 12px 30px rgba(24, 48, 33, 0.08);
        }

        body {
            margin: 0;
            padding: 24px;
            background: var(--bg);
            font-family: 'Open Sans', sans-serif;
            color: var(--text);
        }

        .page {
            max-width: 1500px;
            margin: 0 auto;
        }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            margin-bottom: 22px;
        }

        .topbar-left {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .back-link,
        .top-action {
            text-decoration: none;
            border-radius: 999px;
            padding: 10px 16px;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .back-link {
            background: #fff;
            color: var(--accent);
            box-shadow: var(--shadow);
        }

        .top-action {
            background: var(--accent);
            color: #fff;
        }

        h1 {
            margin: 0;
            font-size: 2rem;
            color: var(--accent);
        }

        .subtitle {
            margin: 6px 0 0;
            color: var(--muted);
        }

        .alert {
            padding: 14px 16px;
            border-radius: 12px;
            font-weight: 700;
            margin-bottom: 20px;
        }

        .success {
            background: #dff1e4;
            color: #1f5e32;
        }

        .error {
            background: #f7dddd;
            color: #842828;
        }

        .layout {
            display: grid;
            grid-template-columns: 360px minmax(0, 1fr);
            gap: 24px;
            align-items: start;
        }

        .panel {
            background: var(--card);
            border-radius: 20px;
            box-shadow: var(--shadow);
            overflow: hidden;
        }

        .panel-head {
            padding: 18px 22px;
            background: var(--accent);
            color: #fff;
            font-weight: 700;
            font-size: 1.05rem;
        }

        .panel-body {
            padding: 22px;
        }

        .panel-note {
            margin-top: 12px;
            color: var(--muted);
            font-size: 0.88rem;
            line-height: 1.5;
        }

        .form-grid {
            display: grid;
            gap: 14px;
        }

        label {
            display: block;
            margin-bottom: 6px;
            font-size: 0.9rem;
            font-weight: 700;
            color: var(--text);
        }

        input,
        select {
            width: 100%;
            box-sizing: border-box;
            border: 1px solid #cfd9d3;
            border-radius: 12px;
            padding: 12px 14px;
            font-size: 0.95rem;
            background: #fff;
        }

        input:focus,
        select:focus {
            outline: none;
            border-color: #87aa93;
            box-shadow: 0 0 0 4px rgba(45, 90, 55, 0.12);
        }

        .separator {
            border: 0;
            border-top: 1px solid var(--line);
            margin: 22px 0;
        }

        .section-title {
            margin: 0 0 10px;
            font-size: 1rem;
            color: var(--accent);
        }

        .btn-primary,
        .btn-secondary,
        .btn-danger {
            border: none;
            border-radius: 12px;
            padding: 10px 14px;
            font-weight: 700;
            cursor: pointer;
            font-size: 0.92rem;
        }

        .btn-primary {
            background: var(--accent);
            color: #fff;
        }

        .btn-secondary {
            background: var(--accent-soft);
            color: var(--accent);
        }

        .btn-danger {
            background: #f9e1e1;
            color: var(--warn);
        }

        .table-toolbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            margin-bottom: 14px;
        }

        .table-meta {
            color: var(--muted);
            font-size: 0.9rem;
        }

        .table-wrap {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            min-width: 900px;
        }

        th,
        td {
            padding: 14px 12px;
            border-bottom: 1px solid var(--line);
            vertical-align: top;
        }

        th {
            position: sticky;
            top: 0;
            background: #f8fbf9;
            color: var(--muted);
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            z-index: 1;
        }

        .sticky-col {
            position: sticky;
            left: 0;
            background: #fff;
            z-index: 2;
        }

        th.sticky-col {
            background: #f8fbf9;
            z-index: 3;
        }

        .agency-name {
            font-weight: 700;
            color: var(--accent);
        }

        .muted {
            color: var(--muted);
            font-size: 0.84rem;
            margin-top: 4px;
        }

        .row-form {
            display: grid;
            grid-template-columns: 1.2fr 1fr 1fr 1fr auto auto;
            gap: 10px;
            align-items: start;
        }

        .row-actions {
            display: flex;
            gap: 8px;
        }

        .accounts-panel {
            margin-top: 24px;
        }

        .account-row {
            display: grid;
            grid-template-columns: 1.2fr 1fr 1fr auto auto;
            gap: 10px;
            align-items: start;
        }

        .empty {
            padding: 28px;
            text-align: center;
            color: var(--muted);
        }

        @media (max-width: 1180px) {
            .layout {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="page">
        <div class="topbar">
            <div class="topbar-left">
                <a href="admin.php" class="back-link">⬅ Retour admin</a>
                <div>
                    <h1>Agences intérim</h1>
                    <p class="subtitle">Gère les agences, les personnes de contact et jusqu'à deux adresses email par agence.</p>
                </div>
            </div>
            <a href="#add-agence" class="top-action">＋ Nouvelle agence</a>
        </div>

        <?php echo $message; ?>

        <div class="layout">
            <section class="panel" id="add-agence">
                <div class="panel-head">Ajouter une agence</div>
                <div class="panel-body">
                    <form method="POST" class="form-grid">
                        <?php echo csrfField(); ?>
                        <div>
                            <label for="nom_agence">Nom de l'agence</label>
                            <input type="text" id="nom_agence" name="nom_agence" placeholder="Ex. Start People" required>
                        </div>
                        <div>
                            <label for="nom_contact">Personne de contact</label>
                            <input type="text" id="nom_contact" name="nom_contact" placeholder="Nom du contact" required>
                        </div>
                        <div>
                            <label for="email_1">Email principal</label>
                            <input type="email" id="email_1" name="email_1" placeholder="contact@agence.be">
                        </div>
                        <div>
                            <label for="email_2">Email secondaire</label>
                            <input type="email" id="email_2" name="email_2" placeholder="back-up@agence.be">
                        </div>
                        <button type="submit" name="create_agence" class="btn-primary">Enregistrer l'agence</button>
                    </form>
                    <p class="panel-note">Le contact est obligatoire. Les emails sont facultatifs, mais tu peux en renseigner un ou deux selon l'agence.</p>

                    <hr class="separator">

                    <h3 class="section-title">Créer un compte agence</h3>
                    <form method="POST" class="form-grid">
                        <?php echo csrfField(); ?>
                        <div>
                            <label for="agence_user_agence">Agence</label>
                            <select id="agence_user_agence" name="agence_id" required>
                                <option value="">Sélectionner une agence</option>
                                <?php foreach ($agences as $agence): ?>
                                    <option value="<?php echo (int) $agence['id']; ?>"><?php echo e($agence['nom_agence']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label for="agence_user_username">Nom d'utilisateur</label>
                            <input type="text" id="agence_user_username" name="username" placeholder="Ex. tempo.bxl" required>
                        </div>
                        <div>
                            <label for="agence_user_email">Adresse email</label>
                            <input type="email" id="agence_user_email" name="email" placeholder="contact@agence.be" required>
                        </div>
                        <div>
                            <label for="agence_user_password">Mot de passe</label>
                            <input type="password" id="agence_user_password" name="password" placeholder="Mot de passe initial" required>
                        </div>
                        <button type="submit" name="create_agence_user" class="btn-primary">Créer le compte</button>
                    </form>
                    <p class="panel-note">Les comptes créés ici utilisent le rôle interne <strong>agence_interim</strong> et n'apparaissent pas dans la liste principale des collaborateurs.</p>
                </div>
            </section>

            <section class="panel bulk-scope">
                <?php require_once __DIR__ . '/includes/bulkselect.php'; echo bulkAssets(); ?>
                <div class="panel-head">Répertoire des agences</div>
                <div class="panel-body">
                    <div class="table-toolbar" style="display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap;">
                        <div class="table-meta"><?php echo count($agences); ?> agence<?php echo count($agences) > 1 ? 's' : ''; ?> enregistrée<?php echo count($agences) > 1 ? 's' : ''; ?></div>
                        <?php if (!empty($agences)): ?><button type="button" class="btn-secondary bulk-toggle" data-bulk-toggle="agences" data-bulk-entity="agence" data-bulk-label="agence">☑️ Sélectionner</button><?php endif; ?>
                    </div>

                    <?php if (empty($agences)): ?>
                        <div class="empty">Aucune agence encodée pour le moment.</div>
                    <?php else: ?>
                        <div class="table-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <th class="bulk-col"></th>
                                        <th class="sticky-col">Agence</th>
                                        <th>Contact</th>
                                        <th>Email 1</th>
                                        <th>Email 2</th>
                                        <th colspan="2">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($agences as $agence): ?>
                                        <tr>
                                            <td class="bulk-col"><input type="checkbox" class="bulk-cb" data-bulk="agences" value="<?php echo (int) $agence['id']; ?>"></td>
                                            <td class="sticky-col">
                                                <div class="agency-name"><?php echo e($agence['nom_agence']); ?></div>
                                                <div class="muted">Mise à jour : <?php echo !empty($agence['updated_at']) ? date('d/m/Y H:i', strtotime($agence['updated_at'])) : '-'; ?></div>
                                            </td>
                                            <td colspan="5">
                                                <form method="POST" class="row-form">
                                                    <?php echo csrfField(); ?>
                                                    <input type="hidden" name="agence_id" value="<?php echo (int) $agence['id']; ?>">
                                                    <input type="text" name="nom_agence" value="<?php echo e($agence['nom_agence']); ?>" placeholder="Nom de l'agence" required>
                                                    <input type="text" name="nom_contact" value="<?php echo e($agence['nom_contact']); ?>" placeholder="Contact" required>
                                                    <input type="email" name="email_1" value="<?php echo e($agence['email_1'] ?? ''); ?>" placeholder="Email principal">
                                                    <input type="email" name="email_2" value="<?php echo e($agence['email_2'] ?? ''); ?>" placeholder="Email secondaire">
                                                    <button type="submit" name="update_agence" class="btn-secondary">Enregistrer</button>
                                                    <button type="submit" name="delete_agence" class="btn-danger" onclick="return confirm('Supprimer cette agence ?');">Supprimer</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
        </div>

        <section class="panel accounts-panel">
            <div class="panel-head">Comptes des agences intérim</div>
            <div class="panel-body">
                <div class="table-toolbar">
                    <div class="table-meta"><?php echo count($agenceUsers); ?> compte<?php echo count($agenceUsers) > 1 ? 's' : ''; ?> agence</div>
                </div>

                <?php if (empty($agenceUsers)): ?>
                    <div class="empty">Aucun compte agence créé pour le moment.</div>
                <?php else: ?>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th class="sticky-col">Agence</th>
                                    <th>Nom d'utilisateur</th>
                                    <th>Email</th>
                                    <th>Nouveau mot de passe</th>
                                    <th colspan="2">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($agenceUsers as $agenceUser): ?>
                                    <tr>
                                        <td class="sticky-col">
                                            <div class="agency-name"><?php echo e($agenceUser['nom_agence']); ?></div>
                                            <div class="muted">Créé le : <?php echo !empty($agenceUser['linked_at']) ? date('d/m/Y H:i', strtotime($agenceUser['linked_at'])) : '-'; ?></div>
                                        </td>
                                        <td colspan="5">
                                            <form method="POST" class="account-row">
                                                <?php echo csrfField(); ?>
                                                <input type="hidden" name="link_id" value="<?php echo (int) $agenceUser['link_id']; ?>">
                                                <input type="text" value="<?php echo e($agenceUser['identifiant']); ?>" disabled>
                                                <input type="email" name="email" value="<?php echo e($agenceUser['email'] ?? ''); ?>" placeholder="Email" required>
                                                <input type="password" name="new_password" placeholder="Laisser vide pour ne pas changer">
                                                <button type="submit" name="update_agence_user" class="btn-secondary">Enregistrer</button>
                                                <button type="submit" name="delete_agence_user" class="btn-danger" onclick="return confirm('Supprimer ce compte agence ?');">Supprimer</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    </div>
</body>
</html>