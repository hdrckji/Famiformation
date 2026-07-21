<?php
// ============================================================
// topbar.php — RUBAN FamiJob : widget profil + cloche de notifications + boutons.
//
//   Inspiré du ruban de FamiFormation, mais recentré sur la BOÎTE À NOTIF.
//   Posé en position flottante (haut/droite) pour s'ajouter à n'importe quelle
//   page sans entrer en conflit avec son en-tête existant.
//
//   Usage : après <body>, appeler   famijobRibbon($db);
// ============================================================

require_once __DIR__ . '/notifications.php';

if (!function_exists('famijobRibbonHtml')) {
    function famijobRibbonHtml(PDO $db, $opts = [])
    {
        if (empty($_SESSION['user_id'])) {
            return '';
        }
        if (!is_array($opts)) {
            $opts = [];
        }

        $userId = (int) $_SESSION['user_id'];
        $role = (string) ($_SESSION['role'] ?? '');
        $roleLabel = $role === 'admin' ? 'Administrateur' : ($role === 'teamcoach' ? 'TeamCoach' : ucfirst($role));
        $fullName = trim(trim((string) ($_SESSION['prenom'] ?? '')) . ' ' . trim((string) ($_SESSION['nom'] ?? '')));
        if ($fullName === '') {
            $fullName = (string) ($_SESSION['username'] ?? 'Utilisateur');
        }
        $initials = '';
        foreach (preg_split('/\s+/', $fullName) as $part) {
            if ($part !== '') {
                $initials .= mb_strtoupper(mb_substr($part, 0, 1));
            }
            if (mb_strlen($initials) >= 2) {
                break;
            }
        }
        if ($initials === '') {
            $initials = 'U';
        }

        $unread = famijobNotifUnreadCount($db, $userId);

        $showHome = !empty($opts['home']);
        $homeHref = (string) ($opts['home_href'] ?? 'index.php');

        $nameH = htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8');
        $roleH = htmlspecialchars($roleLabel, ENT_QUOTES, 'UTF-8');
        $initialsH = htmlspecialchars($initials, ENT_QUOTES, 'UTF-8');
        $homeHrefH = htmlspecialchars($homeHref, ENT_QUOTES, 'UTF-8');
        $dotHtml = $unread > 0 ? '<span class="fjr-dot">' . (int) $unread . '</span>' : '';

        $homeBtn = $showHome
            ? '<a href="' . $homeHrefH . '" class="fjr-btn" title="Accueil FamiJob">🏠</a>'
            : '';

        return <<<HTML
        <style>
        .fjr-ribbon {
            position: fixed; top: 14px; right: 16px; z-index: 4000;
            display: flex; align-items: center; gap: 8px;
            font-family: 'Manrope','Open Sans',sans-serif;
        }
        .fjr-profile {
            display: flex; align-items: center; gap: 9px;
            background: rgba(255,255,255,0.96); border-radius: 999px;
            padding: 5px 12px 5px 5px; box-shadow: 0 6px 18px rgba(8,22,17,0.18);
            border: 1px solid rgba(18,49,39,0.10);
        }
        .fjr-avatar {
            width: 34px; height: 34px; border-radius: 50%; flex: none;
            display: flex; align-items: center; justify-content: center;
            background: linear-gradient(145deg,#2e5e4f,#17372d); color: #fff;
            font-weight: 800; font-size: 0.82rem; letter-spacing: .02em;
        }
        .fjr-meta { display: flex; flex-direction: column; line-height: 1.15; }
        .fjr-name { font-weight: 800; color: #17372d; font-size: 0.82rem; white-space: nowrap; }
        .fjr-role { font-weight: 700; color: #5c6f67; font-size: 0.68rem; text-transform: uppercase; letter-spacing: .04em; }
        .fjr-btn {
            position: relative; text-decoration: none;
            width: 40px; height: 40px; border-radius: 50%;
            display: inline-flex; align-items: center; justify-content: center;
            background: rgba(255,255,255,0.96); font-size: 1.05rem;
            box-shadow: 0 6px 18px rgba(8,22,17,0.18); border: 1px solid rgba(18,49,39,0.10);
            transition: transform .18s ease, background .18s ease; cursor: pointer;
        }
        .fjr-btn:hover { transform: translateY(-1px) scale(1.04); background: #fff; }
        .fjr-btn.fjr-out { color: #c0392b; }
        .fjr-dot {
            position: absolute; top: -5px; right: -5px; min-width: 18px; height: 18px;
            padding: 0 5px; box-sizing: border-box; background: #c0392b; color: #fff;
            border-radius: 999px; font-size: 0.7rem; font-weight: 800;
            display: flex; align-items: center; justify-content: center;
            box-shadow: 0 0 0 2px #fff;
        }
        @media (max-width: 760px) {
            .fjr-ribbon { top: 8px; right: 8px; gap: 6px; }
            .fjr-meta { display: none; }
            .fjr-profile { padding: 4px; }
            .fjr-btn { width: 38px; height: 38px; }
        }
        </style>
        <div class="fjr-ribbon">
            <div class="fjr-profile" title="{$nameH}">
                <span class="fjr-avatar">{$initialsH}</span>
                <span class="fjr-meta">
                    <span class="fjr-name">{$nameH}</span>
                    <span class="fjr-role">{$roleH}</span>
                </span>
            </div>
            <a href="notifications.php" class="fjr-btn" title="Notifications">🔔{$dotHtml}</a>
            {$homeBtn}
            <a href="logout.php" class="fjr-btn fjr-out" title="Déconnexion">⏻</a>
        </div>
HTML;
    }
}

if (!function_exists('famijobRibbon')) {
    function famijobRibbon(PDO $db, $opts = [])
    {
        if (!empty($GLOBALS['__famijob_ribbon_done'])) {
            return;
        }
        $GLOBALS['__famijob_ribbon_done'] = true;
        echo famijobRibbonHtml($db, $opts);
    }
}
