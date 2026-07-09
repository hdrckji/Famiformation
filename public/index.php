<?php
require_once 'config.php';
verifierConnexion($db);
require_once 'includes/quizz_status.php';
require_once 'includes/modules.php';
require_once 'includes/widget.php';

$role = currentDisplayRole(); // rôle d'AFFICHAGE (tient compte de l'aperçu admin), pas le rôle réel
if ($role === 'agence_interim') {
    header('Location: interim_horaires.php');
    exit();
}
if ($role === 'evaluateur') {
    header('Location: evaluation.php');
    exit();
}

$user_id = $_SESSION['user_id'];
// --- LOGIQUE DE NOTIFICATION NEW ---
$nouvelles_formations = 0;
try {
    $stmt = $db->prepare("SELECT derniere_visite, nom, prenom, photo_profil FROM utilisateurs WHERE id = ?");
    $stmt->execute([$user_id]);
    $user_data = $stmt->fetch();
    $derniere_visite = ($user_data && $user_data['derniere_visite']) ? $user_data['derniere_visite'] : '2000-01-01 00:00:00';
    // Sync session avec la DB pour garantir fraicheur
    if ($user_data) {
        $_SESSION['nom'] = $user_data['nom'];
        $_SESSION['prenom'] = $user_data['prenom'];
        $_SESSION['photo_profil'] = $user_data['photo_profil'];
    }

    $stmtCount = $db->prepare("SELECT COUNT(*) FROM formations_sessions WHERE created_at > ?");
    $stmtCount->execute([$derniere_visite]);
    $nouvelles_formations = $stmtCount->fetchColumn();

    $stmtUpdate = $db->prepare("UPDATE utilisateurs SET derniere_visite = NOW() WHERE id = ?");
    $stmtUpdate->execute([$user_id]);
} catch (Exception $e) {
    $nouvelles_formations = 0;
}

$caisse_valid = false;
$onboarding_unlocked = false;
$onboarding_completed = false;
if ($role === 'etudiant') {
    $caisse_valid = hasCompletedCaisseQuizzes($user_id);
    $onboarding_unlocked = hasUnlockedOnboarding($user_id);
    $onboarding_completed = hasCompletedOnboarding($user_id);
}

// --- Modules dynamiques (créés par l'admin) ---
$isAdmin = ($role === 'admin');
ensureModulesTable($db);
$dynamicModules = getModules($db, null, !$isAdmin); // l'admin voit aussi les modules inactifs
$moduleFlash = '';
if (!empty($_SESSION['module_flash'])) {
    $moduleFlash = $_SESSION['module_flash'];
    unset($_SESSION['module_flash']);
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= t('Accueil', 'Home') ?> - FamiFormation</title>
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Open Sans', sans-serif; background: url('background.jpg') no-repeat center center fixed; background-size: cover; margin: 0; display: flex; flex-direction: column; align-items: center; min-height: 100vh; }
        
        /* AJUSTEMENT DE LA POSITION DU BOUTON DÉCONNEXION */
        .top-nav {
            width: 100%;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 14px;
            padding: 8px 16px;
            box-sizing: border-box;
            position: sticky;
            top: 0;
            z-index: 300;
            background: rgba(255,255,255,0.72);
            box-shadow: 0 2px 12px rgba(0,0,0,0.10);
        }
        .user-info {
            display: flex;
            align-items: center;
            gap: 10px;
            background: rgba(255,255,255,0.9);
            padding: 8px 16px;
            border-radius: 30px;
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
            text-decoration: none;
            color: #333;
            font-weight: 600;
            font-size: 0.9rem;
            transition: all 0.3s ease;
        }
        .user-info:hover { background: #fff; transform: scale(1.03); box-shadow: 0 6px 15px rgba(0,0,0,0.15); }
        .user-avatar {
            width: 54px;
            height: 54px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid #2d5a37;
        }
        .user-avatar-placeholder {
            width: 54px;
            height: 54px;
            border-radius: 50%;
            background: #e8f5e9;
            border: 3px solid #2d5a37;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
        }
        
        .btn-logout { 
            background: rgba(255, 255, 255, 0.9); 
            color: #d93025; 
            text-decoration: none; 
            padding: 12px 25px; 
            border-radius: 30px; 
            font-weight: bold; 
            font-size: 0.9rem; 
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
        }
        .btn-logout:hover {
            background: #fff;
            transform: scale(1.05);
            box-shadow: 0 6px 15px rgba(0,0,0,0.15);
        }

        .header { text-align: center; padding: 0px 20px 2px; } 
        .logo-main { max-width: 250px; filter: drop-shadow(0 5px 15px rgba(0,0,0,0.2)); }
        .tiles-container { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 25px; width: 90%; max-width: 1200px; margin-top: 0; padding-bottom: 0; }
        .tile { background: rgba(255, 255, 255, 0.95); border-radius: 20px; padding: 30px; text-align: center; text-decoration: none; color: #333; box-shadow: 0 10px 25px rgba(0,0,0,0.1); transition: all 0.3s ease; display: flex; flex-direction: column; align-items: center; position: relative; }
        .tile:hover { transform: translateY(-10px); box-shadow: 0 15px 35px rgba(0,0,0,0.2); }
        .tile-icon { font-size: 3.5rem; margin-bottom: 15px; }
        .tile-title { font-size: 1.4rem; font-weight: 700; color: #2d5a37; margin-bottom: 10px; }
        .tile-desc { font-size: 0.95rem; color: #666; line-height: 1.4; }
        .tile-title-stack { display: flex; flex-direction: column; align-items: center; gap: 10px; }
        .tile-badges-row { display: flex; flex-wrap: wrap; justify-content: center; gap: 6px; }
        .tile-badge { display: inline-flex; align-items: center; justify-content: center; padding: 4px 10px; border-radius: 999px; font-size: 0.72rem; font-weight: 700; line-height: 1.2; }
        .tile-badge.required { background:#e74c3c; color:#fff; }
        .tile-badge.valid { background:#27ae60; color:#fff; }

        .tile-admin { border: 2px solid #2d5a37; }
        
        .tile-media-beco { height: 3.5rem; margin-bottom: 15px; display: flex; justify-content: center; align-items: center; }
        .logo-beco-tile { max-width: 120px; max-height: 100%; display: block; }

        .badge-new { position: absolute; top: -10px; right: -10px; background: #d93025; color: white; font-size: 0.75rem; font-weight: bold; padding: 5px 12px; border-radius: 20px; animation: pulse 2s infinite; box-shadow: 0 4px 8px rgba(0,0,0,0.2); z-index: 10; }
        @keyframes pulse { 0% { transform: scale(1); } 50% { transform: scale(1.1); } 100% { transform: scale(1); } }

        .tile-inactive { opacity: 0.45; }
        .btn-param { background: rgba(255,255,255,0.9); color: #2d5a37; text-decoration: none; padding: 12px 18px; border-radius: 30px; font-weight: bold; font-size: 0.9rem; box-shadow: 0 4px 10px rgba(0,0,0,0.1); transition: all 0.3s ease; }
        .btn-param:hover { background: #fff; transform: scale(1.05); }
        .lang-switch { display: flex; gap: 6px; }
        .lang-btn { background: rgba(255,255,255,0.9); color: #2d5a37; text-decoration: none; padding: 6px 12px; border-radius: 20px; font-weight: 700; font-size: 0.8rem; box-shadow: 0 4px 10px rgba(0,0,0,0.1); }
        .lang-btn.active { background: #2d5a37; color: #fff; }
        .lang-btn:hover { background: #fff; }
        .lang-btn.active:hover { background: #357a44; }
        .quick-create-btn { position: fixed; bottom: 20px; right: 20px; background: #2d5a37; color: #fff; border: none; border-radius: 30px; padding: 12px 20px; font-weight: 700; cursor: pointer; box-shadow: 0 6px 18px rgba(0,0,0,0.2); z-index: 1500; }
        .quick-create-btn:hover { background: #357a44; }
        .module-flash { background: #fff8e1; border: 1px solid #ffe082; color: #6a5400; padding: 12px 20px; border-radius: 14px; font-weight: 700; margin: 8px auto 0; max-width: 600px; text-align: center; }

        /* Bloc jaune "Gestion des modules" (admin, en bas à droite) */
        .module-manager { position: fixed; bottom: 20px; right: 20px; width: 280px; background: #fff8e1; border: 2px solid #f6c945; border-radius: 16px; box-shadow: 0 10px 30px rgba(0,0,0,0.2); padding: 14px; z-index: 1500; }
        .module-manager-header { font-weight: 800; color: #8a6d00; margin-bottom: 10px; font-size: 1rem; }
        .btn-mm-create { width: 100%; background: #2d5a37; color: #fff; border: none; border-radius: 10px; padding: 10px; font-weight: 700; cursor: pointer; }
        .btn-mm-create:hover { background: #357a44; }
        .module-manager-list { margin-top: 10px; max-height: 220px; overflow-y: auto; }
        .mm-item { display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 6px 4px; border-bottom: 1px solid #f0e2b0; }
        .mm-item a { color: #2d5a37; text-decoration: none; font-weight: 600; font-size: 0.88rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .mm-del { background: none; border: none; cursor: pointer; font-size: 1rem; }
        .mm-empty { color: #8a6d00; font-size: 0.85rem; padding: 6px 4px; }

        /* Modale de création de module */
        .mm-modal-backdrop { position: fixed; top:0; left:0; right:0; bottom:0; background: rgba(0,0,0,0.5); display: none; align-items: center; justify-content: center; z-index: 2000; }
        .mm-modal-card { background: #fff; border-radius: 14px; padding: 28px; max-width: 460px; width: 90%; max-height: 90vh; overflow-y: auto; box-shadow: 0 20px 50px rgba(0,0,0,0.3); }
        .mm-modal-card h3 { margin-top: 0; color: #2d5a37; }
        .mm-modal-card label { display: block; font-weight: 700; color: #244230; margin: 12px 0 4px; }
        .mm-modal-card input[type=text], .mm-modal-card textarea { width: 100%; box-sizing: border-box; padding: 10px; border: 1px solid #ccc; border-radius: 8px; font: inherit; }
        .mm-modal-card .mm-check { font-weight: 600; display: flex; align-items: center; gap: 8px; }
        .mm-modal-actions { display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px; }
        .btn-cancel { background: #e9ecef; color: #333; border: none; border-radius: 10px; padding: 10px 18px; font-weight: 700; cursor: pointer; }
        /* Champs du formulaire de module (icône / accès) */
        .mm-modal-card .chk { font-weight: 600; display: flex; align-items: center; gap: 8px; }
        .mm-modal-card label { display: block; font-weight: 700; color: #244230; margin: 12px 0 4px; }
        .mm-modal-card input[type=file] { width: 100%; }
        .icon-wrap { display: flex; flex-wrap: wrap; gap: 6px; }
        .icon-opt { font-size: 1.3rem; background: #f4f7f6; border: 2px solid transparent; border-radius: 10px; padding: 6px 8px; cursor: pointer; }
        .icon-opt.sel { border-color: #2d5a37; background: #e8f5e9; }
        .roles-wrap { display: flex; flex-wrap: wrap; gap: 12px; }
        .role-chk { font-weight: 600; display: flex; align-items: center; gap: 6px; }
    </style>
</head>
<body>
    <?= apercuBanner($db) ?>

    <div class="top-nav">
        <?php
            $userNom = trim(($_SESSION['prenom'] ?? '') . ' ' . ($_SESSION['nom'] ?? ''));
            $userPhoto = $_SESSION['photo_profil'] ?? null;
        ?>
        <a href="profil.php" class="user-info">
            <?php if ($userPhoto && is_file(__DIR__ . '/' . $userPhoto)): ?>
                <img src="<?= htmlspecialchars($userPhoto) ?>" alt="Photo de profil" class="user-avatar">
            <?php else: ?>
                <span class="user-avatar-placeholder">👤</span>
            <?php endif; ?>
            <span><?= htmlspecialchars($userNom ?: ($_SESSION['username'] ?? '')) ?></span>
        </a>

        <?php if (userSeesWidget($db, $role)): ?>
            <?= renderWidget($db) ?>
        <?php endif; ?>

        <div style="display:flex; flex-direction:column; align-items:flex-end; gap:8px;">
            <div style="display:flex; align-items:center; gap:10px;">
                <a href="parametres.php" class="btn-param" title="<?= $isAdmin ? t('Paramètres', 'Instellingen') : t('Préférences', 'Voorkeuren') ?>">⚙️</a>
                <a href="logout.php" class="btn-logout" onclick="return confirm('<?= t('Êtes-vous sûr de vouloir vous déconnecter ?', 'Weet je zeker dat je je wilt afmelden?') ?>');"><?= t('Déconnexion', 'Afmelden') ?></a>
            </div>
            <div class="lang-switch">
                <a href="?lang=fr" class="lang-btn<?= currentLang() === 'fr' ? ' active' : '' ?>">FR</a>
                <a href="?lang=nl" class="lang-btn<?= currentLang() === 'nl' ? ' active' : '' ?>">NL</a>
            </div>
        </div>
    </div>

    <div class="header">
        <img src="logo.png" alt="Famiflora" class="logo-main">
    </div>

    <?php if (!empty($moduleFlash)): ?>
        <div class="module-flash"><?= htmlspecialchars($moduleFlash) ?></div>
    <?php endif; ?>

    <div class="tiles-container">
        <?php if ($role !== 'etudiant' || $onboarding_unlocked): ?>
        <a href="onboarding.php" class="tile">
            <div class="tile-media"><span class="tile-icon">🚀</span></div>
            <div class="tile-title"><?= t('Onboarding', 'Onboarding') ?>
            </div>
            <div class="tile-desc"><?= t('Bienvenue chez Famiflora ! Découvrez notre univers.', 'Welkom bij Famiflora! Ontdek onze wereld.') ?></div>
        </a>
        <?php endif; ?>

        <!-- planning des formations : désormais visible pour tous les rôles -->
        <a href="formation.php" class="tile">
            <?php if ($nouvelles_formations > 0): ?>
                <span class="badge-new"><?= t('NOUVEAU', 'NIEUW') ?></span>
            <?php endif; ?>
            <div class="tile-media"><span class="tile-icon">📅</span></div>
            <div class="tile-title"><?= t('Formation', 'Opleiding') ?></div>
            <div class="tile-desc"><?= t('Formations en ligne et en présentiel.', 'Opleidingen online en ter plaatse.') ?></div>
        </a>

        <?php if ($role === 'admin' || $role === 'teamcoach' || $role === 'mentor' || $role === 'employe_magasin'): ?>
        <a href="magasin.php" class="tile">
            <div class="tile-media"><span class="tile-icon">🛒</span></div>
            <div class="tile-title"><?= t('Magasin', 'Winkel') ?></div>
            <div class="tile-desc"><?= t('Procédures de vente et caisses.', 'Verkoop- en kassaprocedures.') ?></div>
        </a>
        <?php if ($role !== 'employe_magasin'): ?>
        <a href="management.php" class="tile">
            <div class="tile-media"><span class="tile-icon">🧑‍💼</span></div>
            <div class="tile-title"><?= t('Management', 'Management') ?></div>
            <div class="tile-desc"><?= t('Outils et formations pour managers et mentors.', 'Tools en opleidingen voor managers en mentoren.') ?></div>
        </a>
        <?php endif; ?>
        <?php endif; ?>

        <?php if ($role !== 'etudiant'): ?>
        <a href="formation_becosoft.php" class="tile">
            <div class="tile-media-beco"><img src="beco.png" alt="Becosoft" class="logo-beco-tile"></div>
            <div class="tile-title">Becosoft</div>
            <div class="tile-desc"><?= t('Logiciel de gestion de stock.', 'Software voor voorraadbeheer.') ?></div>
        </a>
        <?php endif; ?>

        <!-- tuile caisse réservée aux étudiants -->
        <?php if ($role === 'etudiant'): ?>
        <a href="formation-caisse.php" class="tile">
            <div class="tile-media"><span class="tile-icon">💳</span></div>
            <div class="tile-title tile-title-stack">
                <span><?= t('Formation Caisse', 'Kassaopleiding') ?></span>
                <span class="tile-badges-row">
                    <span class="tile-badge required"><?= t('Obligatoire', 'Verplicht') ?></span>
                    <?php if ($caisse_valid): ?>
                        <span class="tile-badge valid"><?= t('Quiz validés', 'Quiz geslaagd') ?></span>
                    <?php endif; ?>
                </span>
            </div>
            <div class="tile-desc"><?= t('Parcours rapide sur l’utilisation de la caisse.', 'Snelle module over het gebruik van de kassa.') ?></div>
        </a>

        <?php if ($onboarding_completed): ?>
        <a href="student_disponibilites.php" class="tile">
            <div class="tile-media"><span class="tile-icon">🗓️</span></div>
            <div class="tile-title"><?= t('Mes disponibilités', 'Mijn beschikbaarheden') ?></div>
            <div class="tile-desc"><?= t('Indique tes jours de disponibilité sur les 30 prochains jours.', 'Geef je beschikbare dagen voor de komende 30 dagen aan.') ?></div>
        </a>
        <?php endif; ?>

        <a href="mon_horaire.php" class="tile">
            <div class="tile-media"><span class="tile-icon">🕒</span></div>
            <div class="tile-title"><?= t('Mes horaires attribués', 'Mijn toegewezen uren') ?></div>
            <div class="tile-desc"><?= t('Consulte tes créneaux passés, du jour et futurs en lecture seule.', 'Bekijk je vroegere, huidige en toekomstige uren (alleen lezen).') ?></div>
        </a>
        <?php endif; ?>

        <?php if ($role === 'admin' || $role === 'employe_logistique' || $role === 'teamcoach' || $role === 'mentor'): ?>
        <a href="logistique.php" class="tile">
            <div class="tile-media"><span class="tile-icon">📦</span></div>
            <div class="tile-title"><?= t('Logistique', 'Logistiek') ?></div>
            <div class="tile-desc"><?= t('Gestion des flux et des stocks.', 'Beheer van stromen en voorraden.') ?></div>
        </a>
        <?php endif; ?>
      
        <?php if ($role !== 'etudiant'): ?>
        <a href="classement.php" class="tile">
            <div class="tile-media"><span class="tile-icon">🏆</span></div>
            <div class="tile-title"><?= t('Classement', 'Klassement') ?></div>
            <div class="tile-desc"><?= t('Tableau des scores et points.', 'Scorebord en punten.') ?></div>
        </a>
        <a href="securite_travail.php" class="tile">
            <div class="tile-media"><span class="tile-icon">🦺</span></div>
            <div class="tile-title"><?= t('Sécurité au travail', 'Veiligheid op het werk') ?></div>
            <div class="tile-desc"><?= t('Chaussure de sécurité & secourisme', 'Veiligheidsschoenen & EHBO') ?></div>
        </a>
        <?php endif; ?>

        <?php if ($role === 'admin' || $role === 'teamcoach'): ?>
        <a href="famijob/index.php" class="tile tile-admin">
            <div class="tile-media"><span class="tile-icon">💼</span></div>
            <div class="tile-title">Famijob</div>
            <div class="tile-desc"><?= t('Accéder à la plateforme Famijob (gestion des jobs étudiants).', 'Toegang tot het Famijob-platform (beheer van studentenjobs).') ?></div>
        </a>
        <?php if ($role === 'admin'): ?>
        <a href="interim_horaires_demandes.php" class="tile tile-admin">
            <div class="tile-media"><span class="tile-icon">📝</span></div>
            <div class="tile-title"><?= t('Demandes Horaires Intérim', 'Aanvragen uren interim') ?></div>
            <div class="tile-desc"><?= t('Créer, modifier ou supprimer les demandes d\'horaires pour les agences intérim.', 'Uuraanvragen voor de interimkantoren aanmaken, wijzigen of verwijderen.') ?></div>
        </a>
        <a href="interim_horaires.php" class="tile tile-admin">
            <div class="tile-media"><span class="tile-icon">🤝</span></div>
            <div class="tile-title"><?= t('Matching Intérim', 'Matching interim') ?></div>
            <div class="tile-desc"><?= t('Assigner les étudiants aux créneaux intérim, matching manuel ou automatique.', 'Studenten toewijzen aan interim-tijdslots, handmatig of automatisch.') ?></div>
        </a>
        <a href="validation_demandes_horaires.php" class="tile tile-admin">
            <div class="tile-media"><span class="tile-icon">✅</span></div>
            <div class="tile-title"><?= t('Validation demandes horaires', 'Validatie uuraanvragen') ?></div>
            <div class="tile-desc"><?= t('Valider ou refuser les demandes d\'horaires avant publication dans le matching.', 'Uuraanvragen goedkeuren of weigeren vóór publicatie in de matching.') ?></div>
        </a>
        <a href="admin.php" class="tile tile-admin">
            <div class="tile-media"><span class="tile-icon">👥</span></div>
            <div class="tile-title"><?= t('RH', 'HR') ?></div>
            <div class="tile-desc"><?= t('Gestion des comptes et scores.', 'Beheer van accounts en scores.') ?></div>
        </a>
        <a href="admin_disponibilites_etudiants.php" class="tile tile-admin">
            <div class="tile-media"><span class="tile-icon">🗓️</span></div>
            <div class="tile-title"><?= t('Dispos Etudiants', 'Beschikbaarheid studenten') ?></div>
            <div class="tile-desc"><?= t('Vue par semaine et par secteur des disponibilités étudiantes.', 'Overzicht per week en per sector van de beschikbaarheid van studenten.') ?></div>
        </a>
        <?php endif; ?>
        <a href="admin_questions.php" class="tile tile-admin">
            <div class="tile-media"><span class="tile-icon">⚙️</span></div>
            <div class="tile-title"><?= t('Gestion Questions', 'Beheer vragen') ?></div>
            <div class="tile-desc"><?= t('Ajouter/Modifier les quiz.', 'Quizvragen toevoegen/wijzigen.') ?></div>
        </a>
        <?php endif; ?>

        <?php foreach ($dynamicModules as $mod): ?>
        <?php if (!empty($mod['link'])) { continue; } // modules de base : déjà affichés par les tuiles ci-dessus ?>
        <?php if (!$isAdmin && !userCanSeeModule($mod, $role)) { continue; } ?>
        <a href="module.php?id=<?= (int) $mod['id'] ?>" class="tile<?= ((int) $mod['is_active'] !== 1) ? ' tile-inactive' : '' ?>">
            <div class="tile-media"><?= moduleIconHtml($mod) ?></div>
            <div class="tile-title"><?= htmlspecialchars(moduleNom($mod)) ?></div>
            <div class="tile-desc"><?= htmlspecialchars(moduleDesc($mod)) ?></div>
        </a>
        <?php endforeach; ?>
    </div>

    <?php if ($isAdmin): ?>
    <button type="button" class="quick-create-btn" onclick="document.getElementById('moduleCreateModal').style.display='flex';">➕ Créer un module</button>

    <div id="moduleCreateModal" class="mm-modal-backdrop">
        <div class="mm-modal-card">
            <h3>Nouveau module</h3>
            <form method="POST" action="module_save.php" enctype="multipart/form-data">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="create">
                <input type="hidden" name="return" value="index.php">
                <?php renderModuleFields('qcreate', [], moduleProfiles($db), moduleIconChoices()); ?>
                <div class="mm-modal-actions">
                    <button type="button" class="btn-cancel" onclick="document.getElementById('moduleCreateModal').style.display='none';">Annuler</button>
                    <button type="submit" class="btn-mm-create">Créer le module</button>
                </div>
            </form>
        </div>
    </div>
    <?= moduleFormScript() ?>
    <?php endif; ?>

</body>
</html>