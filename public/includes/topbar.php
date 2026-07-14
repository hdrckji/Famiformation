<?php
// ============================================================
// topbar.php — BARRE D'ACCÈS PERMANENTE (toutes les pages).
//   Notifications (avec pastille rouge), langue FR/NL, paramètres, déconnexion.
//   Avant, ces boutons n'existaient que sur l'accueil : dès qu'on entrait dans
//   une formation, on ne pouvait plus changer de langue ni voir ses notifs sans
//   revenir en arrière. Elle est donc flottante, en haut à droite, partout.
//
//   Déconnexion : modale de confirmation (jamais un clic accidentel).
//   Hors accueil, le bouton se réduit à une icône ⏻ pour ne pas encombrer.
// ============================================================

if (!function_exists('famiTopbar')) {
    /**
     * @param PDO  $db
     * @param bool $home true sur l'accueil (bouton « Déconnexion » en toutes lettres).
     */
    function famiTopbar(PDO $db, $home = false)
    {
        if (empty($_SESSION['user_id'])) { return; }

        require_once __DIR__ . '/events.php';
        $isAdmin = (($_SESSION['role'] ?? '') === 'admin');
        $role = function_exists('currentDisplayRole') ? currentDisplayRole() : (string) ($_SESSION['role'] ?? '');

        // Pastille : ce qu'il y a de neuf POUR MOI (à contrôler si admin, sinon non-lu).
        $n = 0;
        try {
            $n = $isAdmin
                ? eventsPendingCount($db) + eventsUnseenCount($db, (int) $_SESSION['user_id'], $role)
                : eventsUnseenCount($db, (int) $_SESSION['user_id'], $role);
        } catch (Exception $e) {}

        // On conserve la page courante quand on change de langue (?lang=..).
        $self = strtok((string) ($_SERVER['REQUEST_URI'] ?? 'index.php'), '#');
        $sep = (strpos($self, '?') !== false) ? '&' : '?';
        $self = preg_replace('/([?&])lang=(fr|nl)(&|$)/', '$1', $self);
        $self = rtrim($self, '?&');
        $sep = (strpos($self, '?') !== false) ? '&' : '?';
        $lang = function_exists('currentLang') ? currentLang() : 'fr';
        ?>
        <style>
        /* Même agencement que le ruban de l'accueil : notifs / paramètres / déconnexion sur une
           ligne, puis FR-NL en dessous, alignés à droite. */
        .fami-tb { position:fixed; top:14px; right:14px; z-index:9000; display:flex; flex-direction:column; align-items:flex-end; gap:8px; }
        .fami-tb .tb-row { display:flex; align-items:center; gap:8px; }
        .fami-tb a, .fami-tb button { text-decoration:none; border:none; cursor:pointer; font:inherit; font-weight:700;
            background:rgba(255,255,255,.94); color:#2d5a37; border-radius:999px; box-shadow:0 4px 14px rgba(0,0,0,.14);
            display:inline-flex; align-items:center; justify-content:center; gap:6px; }
        .fami-tb .tb-ico { width:40px; height:40px; font-size:1.1rem; position:relative; }
        .fami-tb .tb-lang { padding:0 12px; height:40px; font-size:.82rem; }
        .fami-tb .tb-lang.active { background:#2d5a37; color:#fff; }
        .fami-tb .tb-out { height:40px; padding:0 16px; background:#c0392b; color:#fff; }
        .fami-tb a:hover, .fami-tb button:hover { transform:translateY(-1px); }
        .fami-tb .tb-dot { position:absolute; top:-4px; right:-4px; background:#c0392b; color:#fff; border-radius:999px;
            font-size:.68rem; font-weight:800; padding:1px 6px; line-height:1.4; box-shadow:0 0 0 2px #fff; }
        .fami-tb-mask { position:fixed; inset:0; z-index:9500; background:rgba(0,0,0,.5); display:none; align-items:center; justify-content:center; padding:20px; }
        .fami-tb-box { background:#fff; border-radius:16px; padding:26px; max-width:400px; width:100%; text-align:center; box-shadow:0 20px 50px rgba(0,0,0,.3); }
        .fami-tb-box h3 { margin:8px 0 6px; color:#2d5a37; }
        .fami-tb-box p { color:#5a6b60; margin:0 0 18px; }
        .fami-tb-box .row { display:flex; gap:10px; justify-content:center; }
        .fami-tb-box .row a, .fami-tb-box .row button { border:none; border-radius:10px; padding:11px 20px; font-weight:700; cursor:pointer; text-decoration:none; }
        @media (max-width:640px) { .fami-tb { top:8px; right:8px; gap:6px; } .fami-tb .tb-out span { display:none; } }
        </style>

        <div class="fami-tb">
            <div class="tb-row">
                <a href="events.php" class="tb-ico" title="<?= t('Notifications', 'Meldingen') ?>">🔔<?php if ($n > 0): ?><span class="tb-dot"><?= (int) $n ?></span><?php endif; ?></a>
                <a href="parametres.php" class="tb-ico" title="<?= $isAdmin ? t('Paramètres', 'Instellingen') : t('Préférences', 'Voorkeuren') ?>">⚙️</a>
                <button type="button" class="tb-ico" style="background:#c0392b; color:#fff;" title="<?= t('Déconnexion', 'Afmelden') ?>" onclick="famiLogoutAsk()">⏻</button>
            </div>
            <div class="tb-row">
                <a href="<?= htmlspecialchars($self . $sep) ?>lang=fr" class="tb-lang<?= $lang === 'fr' ? ' active' : '' ?>">FR</a>
                <a href="<?= htmlspecialchars($self . $sep) ?>lang=nl" class="tb-lang<?= $lang === 'nl' ? ' active' : '' ?>">NL</a>
            </div>
        </div>

        <?php famiLogoutModal(); ?>
        <?php
    }
}

if (!function_exists('famiLogoutModal')) {
    /**
     * Modale « Êtes-vous sûr de vouloir vous déconnecter ? ».
     * Séparée de la barre : l'ACCUEIL garde son ruban d'origine et n'a besoin que de ça.
     */
    function famiLogoutModal()
    {
        if (empty($_SESSION['user_id'])) { return; }
        static $done = false;
        if ($done) { return; }
        $done = true;
        ?>
        <style>
        /* Styles de la modale portés PAR la modale (et non par la barre) : l'accueil n'affiche
           que celle-ci, sans la barre — elle doit donc être autonome. */
        .fami-tb-mask { position:fixed; inset:0; z-index:9500; background:rgba(0,0,0,.5); display:none; align-items:center; justify-content:center; padding:20px; }
        .fami-tb-box { background:#fff; border-radius:16px; padding:26px; max-width:400px; width:100%; text-align:center; box-shadow:0 20px 50px rgba(0,0,0,.3); }
        .fami-tb-box h3 { margin:8px 0 6px; color:#2d5a37; }
        .fami-tb-box p { color:#5a6b60; margin:0 0 18px; }
        .fami-tb-box .row { display:flex; gap:10px; justify-content:center; }
        .fami-tb-box .row a, .fami-tb-box .row button { border:none; border-radius:10px; padding:11px 20px; font-weight:700; cursor:pointer; text-decoration:none; font:inherit; }
        </style>
        <div class="fami-tb-mask" id="famiLogoutModal">
            <div class="fami-tb-box">
                <div style="font-size:2.2rem;">⏻</div>
                <h3><?= t('Se déconnecter ?', 'Afmelden?') ?></h3>
                <p><?= t('Êtes-vous sûr de vouloir vous déconnecter ?', 'Weet je zeker dat je je wilt afmelden?') ?></p>
                <div class="row">
                    <button type="button" style="background:#e9ecef; color:#333;" onclick="document.getElementById('famiLogoutModal').style.display='none';"><?= t('Annuler', 'Annuleren') ?></button>
                    <a href="logout.php" style="background:#c0392b; color:#fff;"><?= t('Se déconnecter', 'Afmelden') ?></a>
                </div>
            </div>
        </div>
        <script>
        function famiLogoutAsk() { document.getElementById('famiLogoutModal').style.display = 'flex'; }
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') { var m = document.getElementById('famiLogoutModal'); if (m) { m.style.display = 'none'; } }
        });
        </script>
        <?php
    }
}
