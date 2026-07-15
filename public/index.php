<?php
require_once 'config.php';
verifierConnexion($db);
require_once 'includes/quizz_status.php';
require_once 'includes/modules.php';
require_once 'includes/widget.php';
require_once 'includes/theme.php';
require_once 'includes/events.php';

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
ensureUserProfileColumns($db);
// --- LOGIQUE DE NOTIFICATION NEW ---
$nouvelles_formations = 0;
try {
    $stmt = $db->prepare("SELECT derniere_visite, nom, prenom, photo_profil, date_naissance, last_birthday_wish, welcome_seen FROM utilisateurs WHERE id = ?");
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

// --- Anniversaire ---
// Anniversaire = thème événementiel : soumis au maître + catégorie Thèmes + son
// interrupteur individuel. Son animation (overlay festif + particules) a sa propre clé.
$birthdayEnabled = function_exists('themesEnabled')
    ? (themesEnabled($db)
        && (!function_exists('eventEnabled') || eventEnabled($db, 'anniversaire'))
        && (!function_exists('widgetGet') || widgetGet($db, 'theme_anniversaire_on', '1') === '1'))
    : true;
$birthdayAnim = !function_exists('widgetGet') || widgetGet($db, 'theme_anniversaire_anim', '1') === '1';
$isBirthday = false;
$birthdayFirstOpen = false;
$birthdayName = '';
if ($birthdayEnabled && !empty($user_data['date_naissance'])) {
    $dob = (string) $user_data['date_naissance'];
    if ($dob !== '0000-00-00' && substr($dob, 5, 5) === date('m-d')) {
        $isBirthday = true;
        $birthdayName = ucfirst(strtolower((string) ($user_data['prenom'] ?? '')));
        $todayStr = date('Y-m-d');
        if ((string) ($user_data['last_birthday_wish'] ?? '') !== $todayStr) {
            $birthdayFirstOpen = true;
            try {
                $db->prepare("UPDATE utilisateurs SET last_birthday_wish = ? WHERE id = ?")->execute([$todayStr, $user_id]);
            } catch (Exception $e) {
                // pas critique
            }
        }
    }
}

// Aperçu admin de l'animation d'anniversaire : ?bday=preview rejoue la VRAIE animation
// (avec le prénom du compte connecté), sans toucher au statut de l'utilisateur.
if ((($_SESSION['role'] ?? '') === 'admin') && (($_GET['bday'] ?? '') === 'preview')) {
    $isBirthday = true;
    $birthdayFirstOpen = true;
    $birthdayAnim = true;
    $birthdayName = ucfirst(strtolower((string) ($user_data['prenom'] ?? '')));
}

// --- Message de bienvenue (toute première connexion de l'utilisateur) ---
$showWelcome = false;
// « Bienvenue » est désormais un ÉVÉNEMENT comme les autres : son animation est pilotée
// par theme_bienvenue_intro (son thème et ses effets par theme_bienvenue_on / _anim).
$welcomeEnabled = function_exists('persoFeatureOn')
    ? (persoFeatureOn($db, 'anim_enabled') && widgetGet($db, 'theme_bienvenue_intro', '1') === '1')
    : true;
// Aperçu admin : ?welcome=preview rejoue l'animation sans toucher au statut de l'utilisateur.
$welcomePreview = (($_SESSION['role'] ?? '') === 'admin') && (($_GET['welcome'] ?? '') === 'preview');
if ($welcomePreview) {
    $showWelcome = true;
} elseif ($welcomeEnabled && isset($user_data['welcome_seen']) && (int) $user_data['welcome_seen'] === 0) {
    $showWelcome = true;
    try {
        $db->prepare("UPDATE utilisateurs SET welcome_seen = 1 WHERE id = ?")->execute([$user_id]);
    } catch (Exception $e) {
        // pas critique
    }
}
$welcomeName = ucfirst(strtolower((string) ($user_data['prenom'] ?? '')));
require_once __DIR__ . '/includes/event_intro.php';
$eventIntro = detectEventIntro($db, $showWelcome);

// --- Thème (calculé globalement dans config.php : événement / anniversaire / aperçu admin) ---
$siteTheme = $GLOBALS['__fami_page_theme'] ?? null;
// Message de fête pour le widget (alterne avec les phrases) — sauf anniversaire (bandeau dédié).
$festiveMsg = null;
if (!$isBirthday && $siteTheme && !empty($siteTheme['nom'])) {
    $festiveMsg = is_array($siteTheme['nom'])
        ? (currentLang() === 'nl' ? ($siteTheme['nom'][1] ?? $siteTheme['nom'][0]) : $siteTheme['nom'][0])
        : (string) $siteTheme['nom'];
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
famiFixLegacyDrafts($db); // débloque le contenu admin caché par l'ancienne règle (une seule fois)
$dynamicModules = getModules($db, null, !$isAdmin); // l'admin voit aussi les modules inactifs
// Carte nom -> id des modules racine (pour router les tuiles conteneur vers le moteur module.php)
$rootModuleIds = [];
foreach (getModules($db, null, false) as $rm) {
    $rootModuleIds[(string) $rm['nom']] = (int) $rm['id'];
}
$moduleFlash = '';
if (!empty($_SESSION['module_flash'])) {
    $moduleFlash = $_SESSION['module_flash'];
    unset($_SESSION['module_flash']);
}
?>

<!DOCTYPE html>
<html lang="<?= currentLang() ?>">
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
        /* Repère pour le widget, centré sur la page (voir includes/widget.php). */
        .top-nav { position: sticky; }
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
            border: none;            /* <button> : sinon le navigateur dessine un contour */
            cursor: pointer;
            font-family: inherit;
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
<body class="<?php echo trim(($isBirthday ? 'birthday-mode ' : '') . ($siteTheme ? 'site-theme' : '')); ?>">
<?php
if ($siteTheme) {
    // Animation (particules) propre au thème actif : clé theme_<clé>_anim.
    $fxKey = is_array($siteTheme) ? ($siteTheme['key'] ?? '') : '';
    $withFx = $fxKey !== '' && (!function_exists('widgetGet') || widgetGet($db, 'theme_' . $fxKey . '_anim', '1') === '1');
    echo renderSiteTheme($siteTheme, $withFx);
}
?>
<?php if ($isBirthday): ?>
<style>
body.birthday-mode::before { content:''; position:fixed; top:0; left:0; right:0; height:5px; z-index:9999; background:linear-gradient(90deg,#b8860b,#d4af37,#fff6cf,#d4af37,#b8860b); background-size:200% auto; animation:bdGold 3s linear infinite; pointer-events:none; }
@keyframes bdGold { to { background-position:200% center; } }
</style>
<?php endif; ?>
<?php if (!empty($eventIntro)) { renderEventIntroOverlay($eventIntro); } ?>
<?php if ($showWelcome): ?>
<?php
// « Bienvenue » = événement à part entière : thème vert + doré brillant, pilotable
// (theme_bienvenue_on = thème, theme_bienvenue_anim = effets). Un événement du jour prime.
$wcT       = function_exists('welcomeTheme') ? welcomeTheme() : [];
$wcThemeOn = (!function_exists('widgetGet') || widgetGet($db, 'theme_bienvenue_on', '1') === '1');
$wcFxOn    = (!function_exists('widgetGet') || widgetGet($db, 'theme_bienvenue_anim', '1') === '1');

$wcParticles = $wcFxOn ? ($wcT['particles'] ?? ['✨', '🌟', '🌿', '⭐']) : [];
$wcAccent    = $wcT['accent'] ?? '#2d5a37';
$wcGold      = $wcT['accent2'] ?? '#d4af37';
$wcBg        = $wcThemeOn
    ? ($wcT['page_bg'] ?? 'radial-gradient(circle at 50% 28%, #35794a, #10251a 78%)')
    : 'radial-gradient(circle at 50% 30%, #24402e, #101a13 78%)'; // thème coupé : fond sobre
$wcThemeName = '';
$wcShine     = $wcThemeOn; // titre doré scintillant

// Si un événement du jour est actif (Noël, Halloween...), il habille la bienvenue.
if ($wcThemeOn && !empty($siteTheme) && is_array($siteTheme)) {
    if (!empty($siteTheme['particles']) && $wcFxOn) { $wcParticles = $siteTheme['particles']; }
    if (!empty($siteTheme['accent'])) { $wcAccent = $siteTheme['accent']; }
    $wcThemeName = is_array($siteTheme['nom'] ?? null) ? $siteTheme['nom'][0] : ($siteTheme['nom'] ?? '');
    $wcBg = 'radial-gradient(circle at 50% 30%, ' . $wcAccent . ', #0e120e 80%)';
    $wcShine = false; // on garde les couleurs de l'événement
}
?>
<?php if ($wcShine): ?>
<style>
/* Doré qui brille (thème Bienvenue) */
#wcOverlay .wc-hi { background:linear-gradient(90deg,<?= htmlspecialchars($wcGold, ENT_QUOTES) ?>,#fff6cf,<?= htmlspecialchars($wcGold, ENT_QUOTES) ?>); background-size:200% auto; -webkit-background-clip:text; background-clip:text; -webkit-text-fill-color:transparent; color:transparent; animation:wcGold 3s linear infinite; }
@keyframes wcGold { to { background-position:200% center; } }
</style>
<?php endif; ?>
<div id="wcOverlay" class="wc-overlay" onclick="this.classList.add('wc-hide')" style="background:<?php echo htmlspecialchars($wcBg, ENT_QUOTES); ?>;">
    <div class="wc-fx"></div>
    <div class="wc-card">
        <?php if ($wcThemeName): ?><div style="display:inline-block; background:<?php echo htmlspecialchars($wcAccent, ENT_QUOTES); ?>; color:#fff; padding:5px 16px; border-radius:999px; font-weight:800; font-size:.95rem; margin-bottom:12px;"><?php echo htmlspecialchars($wcThemeName); ?></div><?php endif; ?>
        <div class="wc-logo"><?php echo htmlspecialchars($wcThemeName ? $wcParticles[0] : '🌿'); ?></div>
        <div class="wc-hi"><?php echo t('Bienvenue sur Famiformation', 'Welkom op Famiformation'); ?></div>
        <div class="wc-name"><?php echo htmlspecialchars($welcomeName); ?></div>
        <div class="wc-sub"><?php echo t('Ravis de t\'accueillir chez Famiflora. Prends le temps de découvrir ton espace 🌱', 'Fijn dat je er bent bij Famiflora. Ontdek rustig jouw ruimte 🌱'); ?></div>
        <div class="wc-cta"><?php echo t('Clique pour commencer', 'Klik om te beginnen'); ?></div>
    </div>
</div>
<style>
.wc-overlay { position:fixed; inset:0; z-index:99999; display:flex; align-items:center; justify-content:center; background:radial-gradient(circle at 50% 30%, #2d5a37, #123020 75%); overflow:hidden; cursor:pointer; transition:opacity .8s ease; }
.wc-overlay.wc-hide { opacity:0; pointer-events:none; }
.wc-card { position:relative; z-index:2; text-align:center; color:#fff; padding:24px; animation:wcIn 1s cubic-bezier(.2,.8,.2,1) both; }
@keyframes wcIn { from { opacity:0; transform:scale(.9) translateY(24px); } to { opacity:1; transform:none; } }
.wc-logo { font-size:4.6rem; margin-bottom:6px; animation:wcFloat 2.4s ease-in-out infinite; }
@keyframes wcFloat { 0%,100% { transform:translateY(0); } 50% { transform:translateY(-12px); } }
.wc-hi { font-size:1.2rem; letter-spacing:1.5px; text-transform:uppercase; color:#bfe6cc; font-weight:700; }
.wc-name { font-size:3rem; font-weight:800; margin-top:2px; text-shadow:0 4px 24px rgba(0,0,0,.4); }
.wc-sub { margin-top:16px; font-size:1.05rem; color:#dcefe2; max-width:540px; margin-left:auto; margin-right:auto; line-height:1.55; }
.wc-cta { margin-top:30px; font-size:.8rem; letter-spacing:2px; text-transform:uppercase; color:#9ccbac; }
.wc-fx { position:absolute; inset:0; pointer-events:none; overflow:hidden; }
.wc-leaf { position:absolute; top:-30px; opacity:.85; animation:wcFall linear infinite; }
@keyframes wcFall { to { transform:translateY(112vh) rotate(360deg); } }
</style>
<script>
(function(){
  var ov=document.getElementById('wcOverlay'); if(!ov){return;}
  var fx=ov.querySelector('.wc-fx'); var leaves=<?php echo json_encode($wcParticles, JSON_UNESCAPED_UNICODE); ?>;
  if(fx&&leaves&&leaves.length){for(var i=0;i<26;i++){var s=document.createElement('span');s.className='wc-leaf';s.textContent=leaves[i%leaves.length];s.style.left=(Math.random()*100)+'%';s.style.fontSize=(0.9+Math.random()*1.3)+'rem';s.style.animationDuration=(6+Math.random()*6)+'s';s.style.animationDelay=(Math.random()*7)+'s';fx.appendChild(s);}}
  setTimeout(function(){ov.classList.add('wc-hide');},7500);
})();
</script>
<?php endif; ?>
<?php if ($birthdayFirstOpen && !$showWelcome && $birthdayAnim): ?>
<div id="bdOverlay" class="bd-overlay" onclick="this.classList.add('bd-hide')">
    <div class="bd-confetti"></div>
    <div class="bd-card">
        <div class="bd-emoji">🎂</div>
        <div class="bd-title"><?php echo t('Joyeux anniversaire', 'Gelukkige verjaardag'); ?></div>
        <div class="bd-name"><?php echo htmlspecialchars($birthdayName); ?> !</div>
        <div class="bd-sub"><?php echo t('Toute l\'équipe Famiflora te souhaite une magnifique journée', 'Het hele Famiflora-team wenst je een prachtige dag'); ?> 🎉</div>
        <div class="bd-close"><?php echo t('Clique pour continuer', 'Klik om verder te gaan'); ?></div>
    </div>
</div>
<style>
.bd-overlay { position:fixed; inset:0; z-index:99999; display:flex; align-items:center; justify-content:center; background:radial-gradient(circle at 50% 38%, #161616, #000 70%); overflow:hidden; cursor:pointer; transition:opacity .8s ease; }
.bd-overlay.bd-hide { opacity:0; pointer-events:none; }
.bd-card { position:relative; z-index:2; text-align:center; padding:20px; animation:bdIn .9s cubic-bezier(.2,.8,.2,1) both; }
@keyframes bdIn { from { opacity:0; transform:scale(.85) translateY(20px); } to { opacity:1; transform:none; } }
.bd-emoji { font-size:4.5rem; margin-bottom:8px; animation:bdPop 1.2s ease infinite alternate; }
@keyframes bdPop { to { transform:scale(1.12) rotate(-5deg); } }
.bd-title { font-size:2.7rem; font-weight:800; letter-spacing:1px; background:linear-gradient(90deg,#b8860b,#d4af37,#fff6cf,#d4af37,#b8860b); background-size:200% auto; -webkit-background-clip:text; background-clip:text; -webkit-text-fill-color:transparent; color:transparent; animation:bdGold 3s linear infinite; }
@keyframes bdGold { to { background-position:200% center; } }
.bd-name { font-size:2.1rem; font-weight:800; color:#fff6cf; margin-top:2px; text-shadow:0 0 22px rgba(212,175,55,.6); }
.bd-sub { color:#cdb96a; margin-top:16px; font-size:1.02rem; max-width:520px; margin-left:auto; margin-right:auto; line-height:1.5; }
.bd-close { margin-top:28px; color:#8a7b45; font-size:.8rem; letter-spacing:1.5px; text-transform:uppercase; }
.bd-piece { position:absolute; top:-24px; width:10px; height:15px; border-radius:2px; opacity:.9; animation-name:bdFall; animation-timing-function:linear; animation-iteration-count:infinite; }
@keyframes bdFall { to { transform:translateY(108vh) rotate(720deg); } }
</style>
<script>
(function () {
    var ov = document.getElementById('bdOverlay');
    if (!ov) { return; }
    var c = ov.querySelector('.bd-confetti');
    var colors = ['#d4af37', '#fff6cf', '#b8860b', '#ffffff'];
    for (var i = 0; i < 44; i++) {
        var p = document.createElement('span');
        p.className = 'bd-piece';
        p.style.left = (Math.random() * 100) + '%';
        p.style.background = colors[i % colors.length];
        p.style.animationDelay = (Math.random() * 3) + 's';
        p.style.animationDuration = (2.5 + Math.random() * 2.5) + 's';
        c.appendChild(p);
    }
    setTimeout(function () { ov.classList.add('bd-hide'); }, 7000);
})();
</script>
<?php endif; ?>
    <?= apercuBanner($db) ?>

    <div class="top-nav">
        <?php
            $userNom = trim(($_SESSION['prenom'] ?? '') . ' ' . ($_SESSION['nom'] ?? ''));
            $userPhoto = $_SESSION['photo_profil'] ?? null;
        ?>
        <a href="profil.php" class="user-info">
            <?php if ($userPhoto && famiStoredFileExists($userPhoto)): ?>
                <img src="<?= htmlspecialchars(moduleFileUrl($userPhoto)) ?>" alt="<?= t('Photo de profil', 'Profielfoto') ?>" class="user-avatar">
            <?php else: ?>
                <span class="user-avatar-placeholder">👤</span>
            <?php endif; ?>
            <span><?= htmlspecialchars($userNom ?: ($_SESSION['username'] ?? '')) ?></span>
        </a>

        <?php if (userSeesWidget($db, $role)): ?>
            <?= renderWidget($db, $isBirthday ? $birthdayName : null, $festiveMsg) ?>
        <?php endif; ?>

        <?php
            // L'ACCUEIL GARDE SON RUBAN D'ORIGINE (rien ne bouge ici) : la barre flottante
            // n'existe que sur les AUTRES pages, où ces boutons n'étaient pas accessibles.
            // On ne récupère que la modale de déconnexion, pour la même confirmation partout.
            require_once __DIR__ . '/includes/topbar.php';
        ?>
        <div style="display:flex; flex-direction:column; align-items:flex-end; gap:8px;">
            <div style="display:flex; align-items:center; gap:10px;">
                <a href="events.php" class="btn-param" title="<?= t('Notifications', 'Meldingen') ?>" style="position:relative;">🔔<?php $pc = $isAdmin ? eventsPendingCount($db) : eventsUnseenCount($db, (int) ($_SESSION['user_id'] ?? 0), $role); if ($pc > 0): ?><span style="position:absolute; top:-6px; right:-6px; background:#c0392b; color:#fff; border-radius:999px; font-size:0.7rem; font-weight:800; padding:1px 6px; line-height:1.4;"><?= (int) $pc ?></span><?php endif; ?></a>
                <a href="parametres.php" class="btn-param" title="<?= $isAdmin ? t('Paramètres', 'Instellingen') : t('Préférences', 'Voorkeuren') ?>">⚙️</a>
                <button type="button" class="btn-logout" onclick="famiLogoutAsk()">⏻ <?= t('Déconnexion', 'Afmelden') ?></button>
            </div>
            <div class="lang-switch">
                <a href="?lang=fr" class="lang-btn<?= currentLang() === 'fr' ? ' active' : '' ?>">FR</a>
                <a href="?lang=nl" class="lang-btn<?= currentLang() === 'nl' ? ' active' : '' ?>">NL</a>
            </div>
        </div>
        <?php famiLogoutModal(); ?>
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
        <a href="module.php?id=<?= (int) ($rootModuleIds['Magasin'] ?? 0) ?>" class="tile">
            <div class="tile-media"><span class="tile-icon">🛒</span></div>
            <div class="tile-title"><?= t('Magasin', 'Winkel') ?></div>
            <div class="tile-desc"><?= t('Procédures de vente et caisses.', 'Verkoop- en kassaprocedures.') ?></div>
        </a>
        <?php if ($role !== 'employe_magasin'): ?>
        <a href="module.php?id=<?= (int) ($rootModuleIds['Management'] ?? 0) ?>" class="tile">
            <div class="tile-media"><span class="tile-icon">🧑‍💼</span></div>
            <div class="tile-title"><?= t('Management', 'Management') ?></div>
            <div class="tile-desc"><?= t('Outils et formations pour managers et mentors.', 'Tools en opleidingen voor managers en mentoren.') ?></div>
        </a>
        <?php endif; ?>
        <?php endif; ?>

        <?php if ($role !== 'etudiant'): ?>
        <a href="module.php?id=<?= (int) ($rootModuleIds['Becosoft'] ?? 0) ?>" class="tile">
            <div class="tile-media-beco"><img src="beco.png" alt="Becosoft" class="logo-beco-tile"></div>
            <div class="tile-title">Becosoft</div>
            <div class="tile-desc"><?= t('Logiciel de gestion de stock.', 'Software voor voorraadbeheer.') ?></div>
        </a>
        <?php endif; ?>

        <!-- tuile caisse réservée aux étudiants -->
        <?php if ($role === 'etudiant'): ?>
        <a href="module.php?id=<?= (int) ($rootModuleIds['Formation Caisse'] ?? 0) ?>" class="tile">
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
        <a href="module.php?id=<?= (int) ($rootModuleIds['Logistique'] ?? 0) ?>" class="tile">
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
        <a href="module.php?id=<?= (int) ($rootModuleIds['Sécurité au travail'] ?? 0) ?>" class="tile">
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
        <a href="gestion_quiz.php" class="tile tile-admin">
            <div class="tile-media"><span class="tile-icon">🧩</span></div>
            <div class="tile-title"><?= t('Gestion Quiz', 'Quizbeheer') ?></div>
            <div class="tile-desc"><?= t('Contrôler et corriger tous les quiz.', 'Alle quizzen nakijken en corrigeren.') ?></div>
        </a>
        <a href="admin_questions.php" class="tile tile-admin">
            <div class="tile-media"><span class="tile-icon">🗄️</span></div>
            <div class="tile-title"><?= t('Gestion Questions', 'Beheer vragen') ?> <span style="font-size:.7rem; color:#8a968f; font-weight:600;">(<?= t('historique', 'geschiedenis') ?>)</span></div>
            <div class="tile-desc"><?= t('Ancien système — conservé pour l\'historique.', 'Oud systeem — bewaard als geschiedenis.') ?></div>
        </a>
        <?php endif; ?>

        <?php foreach ($dynamicModules as $mod): ?>
        <?php if (!empty($mod['link']) || !empty($mod['is_locked'])) { continue; } // modules de base (verrouillés) : déjà affichés par les tuiles ci-dessus ?>
        <?php if (!$isAdmin && !userCanSeeModule($mod, $role)) { continue; } ?>
        <?php if ((int) $mod['is_active'] === 1): ?>
        <a href="module.php?id=<?= (int) $mod['id'] ?>" class="tile">
            <div class="tile-media"><?= moduleIconHtml($mod) ?></div>
            <div class="tile-title"><?= htmlspecialchars(moduleNom($mod)) ?></div>
            <div class="tile-desc"><?= htmlspecialchars(moduleDesc($mod)) ?></div>
        </a>
        <?php else: ?>
        <div class="tile tile-inactive" title="<?= t('Module inactif — réactive-le dans Gestion des modules', 'Module niet actief — heractiveer hem in Modulebeheer') ?>" style="cursor:not-allowed;">
            <div class="tile-media"><?= moduleIconHtml($mod) ?></div>
            <div class="tile-title"><?= htmlspecialchars(moduleNom($mod)) ?></div>
            <div class="tile-desc"><?= htmlspecialchars(moduleDesc($mod)) ?></div>
        </div>
        <?php endif; ?>
        <?php endforeach; ?>
    </div>

    <?php if ($isAdmin): ?>
    <button type="button" class="quick-create-btn" onclick="document.getElementById('moduleCreateModal').style.display='flex';">➕ Créer</button>

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