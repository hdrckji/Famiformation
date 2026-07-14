<?php
// ============================================================
// topbar.php — RUBAN UNIQUE de toutes les pages (sauf l'accueil, qui a le sien).
//
//   ┌────────────────────────────────────────────────────────────────────┐
//   │  ⬅ Retour        Titre de la page / du module        🔔 🏠 ⚙️ ⏻   │
//   │                                                        FR   NL      │
//   └────────────────────────────────────────────────────────────────────┘
//
//   Transparent, collé en haut (sticky) : il reste visible où qu'on soit dans la
//   page. Avant, chaque page avait SON propre bandeau + mes boutons flottants
//   par-dessus : deux barres qui se chevauchaient, un rendu différent partout.
//   Ici, une seule barre, un seul style.
//
//   Déconnexion : modale de confirmation (jamais un clic accidentel).
// ============================================================

if (!function_exists('famiTopbar')) {
    /**
     * @param PDO   $db
     * @param array $opts ['back' => url du retour (défaut index.php),
     *                     'title' => titre affiché au centre,
     *                     'back_label' => libellé du retour]
     */
    function famiTopbar(PDO $db, $opts = [])
    {
        if (empty($_SESSION['user_id'])) { return; }
        if (!is_array($opts)) { $opts = []; } // ancien appel famiTopbar($db, false)

        require_once __DIR__ . '/events.php';
        $isAdmin = (($_SESSION['role'] ?? '') === 'admin');
        $role = function_exists('currentDisplayRole') ? currentDisplayRole() : (string) ($_SESSION['role'] ?? '');

        $back = (string) ($opts['back'] ?? 'index.php');
        $backLabel = (string) ($opts['back_label'] ?? t('Retour', 'Terug'));
        $title = (string) ($opts['title'] ?? '');
        $actions = (string) ($opts['actions'] ?? ''); // icônes propres à la page (PDF, vidéo…)

        // Pastille : ce qu'il y a de neuf POUR MOI.
        $n = 0;
        try {
            $n = $isAdmin
                ? eventsPendingCount($db) + eventsUnseenCount($db, (int) $_SESSION['user_id'], $role)
                : eventsUnseenCount($db, (int) $_SESSION['user_id'], $role);
        } catch (Exception $e) {}

        // Changer de langue ne doit pas quitter la page : on rejoue l'URL courante.
        $self = strtok((string) ($_SERVER['REQUEST_URI'] ?? 'index.php'), '#');
        $self = preg_replace('/([?&])lang=(fr|nl)(&|$)/', '$1', $self);
        $self = rtrim($self, '?&');
        $sep = (strpos($self, '?') !== false) ? '&' : '?';
        $lang = function_exists('currentLang') ? currentLang() : 'fr';
        ?>
        <style>
        /* MÊME STRUCTURE ET MÊMES STYLES QUE LE RUBAN DE L'ACCUEIL (.top-nav / .btn-param /
           .lang-btn) : retour à gauche, titre au centre, boutons à droite avec FR-NL dessous.
           Différence voulue : le ruban lui-même est TRANSPARENT (ce sont les pastilles blanches
           qui portent le contraste), et il reste collé en haut. */
        .fami-rib {
            width: 100%;
            box-sizing: border-box;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 14px;
            padding: 8px 16px;
            position: sticky;
            top: 0;
            z-index: 300;
            background: transparent;
            flex: none;
            align-self: stretch;
            position: relative;   /* repère pour le titre centré sur la page */
        }
        .fami-rib .rb-back {
            background: rgba(255,255,255,0.9); color: #2d5a37; text-decoration: none;
            padding: 12px 18px; border-radius: 30px; font-weight: bold; font-size: 0.9rem;
            box-shadow: 0 4px 10px rgba(0,0,0,0.1); transition: all 0.3s ease;
            display: inline-flex; align-items: center; gap: 6px; flex: none; border: none; cursor: pointer; font-family: inherit;
        }
        .fami-rib .rb-back:hover { background: #fff; transform: scale(1.05); }
        /* Titre centré sur la PAGE (et non entre le bouton retour et les boutons de droite,
           qui n'ont pas la même largeur : le titre paraissait alors décalé). */
        .fami-rib .rb-title {
            position: absolute; left: 50%; transform: translateX(-50%);
            font-weight: 800; color: #2d5a37; font-size: 1.05rem;
            background: rgba(255,255,255,0.9); border-radius: 30px; padding: 10px 20px;
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 40%;
            pointer-events: none;
        }
        .fami-rib .rb-right { display: flex; flex-direction: column; align-items: flex-end; gap: 8px; flex: none; }
        .fami-rib .rb-row { display: flex; align-items: center; gap: 10px; }
        .fami-rib .rb-btn {
            background: rgba(255,255,255,0.9); color: #2d5a37; text-decoration: none;
            padding: 12px 18px; border-radius: 30px; font-weight: bold; font-size: 0.9rem;
            box-shadow: 0 4px 10px rgba(0,0,0,0.1); transition: all 0.3s ease;
            border: none; cursor: pointer; font-family: inherit; position: relative;
            display: inline-flex; align-items: center; justify-content: center;
        }
        .fami-rib .rb-btn:hover { background: #fff; transform: scale(1.05); }
        .fami-rib .rb-btn.rb-out { background: rgba(255,255,255,0.9); color: #d93025; }
        .fami-rib .rb-btn.rb-out:hover { background: #fff; }
        .fami-rib .rb-lang {
            background: rgba(255,255,255,0.9); color: #2d5a37; text-decoration: none;
            padding: 6px 12px; border-radius: 20px; font-weight: 700; font-size: 0.8rem;
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
        }
        .fami-rib .rb-lang.active { background: #2d5a37; color: #fff; }
        .fami-rib .rb-lang:hover { background: #fff; }
        .fami-rib .rb-lang.active:hover { background: #357a44; }
        .fami-rib .rb-dot {
            position: absolute; top: -6px; right: -6px; background: #c0392b; color: #fff;
            border-radius: 999px; font-size: 0.7rem; font-weight: 800; padding: 1px 6px; line-height: 1.4;
            box-shadow: 0 0 0 2px #fff;
        }
        @media (max-width: 760px) {
            .fami-rib { gap: 8px; padding: 8px 10px; }
            .fami-rib .rb-title { display: none; }
            .fami-rib .rb-back span { display: none; }
            .fami-rib .rb-back, .fami-rib .rb-btn { padding: 10px 14px; }
        }
        </style>

        <div class="fami-rib">
            <a href="<?= htmlspecialchars($back) ?>" class="rb-back">⬅ <span><?= htmlspecialchars($backLabel) ?></span></a>
            <div class="rb-title"><?= htmlspecialchars($title) ?></div>
            <div class="rb-right">
                <div class="rb-row">
                    <?= $actions ?>
                    <a href="events.php" class="rb-btn" title="<?= t('Notifications', 'Meldingen') ?>">🔔<?php if ($n > 0): ?><span class="rb-dot"><?= (int) $n ?></span><?php endif; ?></a>
                    <a href="parametres.php" class="rb-btn" title="<?= $isAdmin ? t('Paramètres', 'Instellingen') : t('Préférences', 'Voorkeuren') ?>">⚙️</a>
                    <a href="index.php" class="rb-btn" title="<?= t('Accueil', 'Start') ?>">🏠</a>
                    <button type="button" class="rb-btn rb-out" title="<?= t('Déconnexion', 'Afmelden') ?>" onclick="famiLogoutAsk()">⏻</button>
                </div>
                <div class="rb-row">
                    <a href="<?= htmlspecialchars($self . $sep) ?>lang=fr" class="rb-lang<?= $lang === 'fr' ? ' active' : '' ?>">FR</a>
                    <a href="<?= htmlspecialchars($self . $sep) ?>lang=nl" class="rb-lang<?= $lang === 'nl' ? ' active' : '' ?>">NL</a>
                </div>
            </div>
        </div>

        <?php famiLogoutModal(); ?>
        <?php
    }
}

if (!function_exists('famiLogoutModal')) {
    /**
     * Modale « Êtes-vous sûr de vouloir vous déconnecter ? ».
     * Séparée du ruban : l'ACCUEIL garde son propre ruban et n'a besoin que de ça.
     */
    function famiLogoutModal()
    {
        if (empty($_SESSION['user_id'])) { return; }
        static $done = false;
        if ($done) { return; }
        $done = true;
        ?>
        <style>
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
