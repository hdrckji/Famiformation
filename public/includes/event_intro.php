<?php
// ============================================================
// event_intro.php — animation de 1ère connexion PROPRE À L'ÉVÉNEMENT du jour.
//   - detectEventIntro($db, $showWelcome) : renvoie l'événement à animer (ou null),
//     en respectant l'interrupteur 🎬 (theme_<clé>_intro), le master animations,
//     et « une fois par occurrence » (cookie + session).
//   - renderEventIntroOverlay($ev)        : affiche le splash plein écran.
//   - eventIntroMessage($key)             : titre + sous-titre propres à chaque événement.
// Ajout NON destructif : autonome.
// ============================================================

if (!function_exists('eventIntroMessage')) {
    /** Message (titre, sous-titre) propre à chaque événement. */
    function eventIntroMessage($key)
    {
        $m = [
            'nouvel_an'      => ['Bonne année !',        'Que cette nouvelle année te réussisse chez Famiflora 🥂'],
            'saint_valentin' => ['Joyeuse Saint-Valentin', 'Un peu de douceur dans ta journée ❤️'],
            'paques'         => ['Joyeuses Pâques',      'Bonne chasse aux œufs 🐰'],
            'fete_nationale' => ['Bonne fête nationale', 'Fiers d’avancer ensemble 🇧🇪'],
            'halloween'      => ['Joyeux Halloween',     'Attention aux fantômes… 👻'],
            'armistice'      => ['11 novembre',          'Souvenons-nous 🌺'],
            'saint_nicolas'  => ['Bonne Saint-Nicolas',  'As-tu été sage cette année ? 🎁'],
            'noel'           => ['Joyeux Noël',          'Toute l’équipe Famiflora te souhaite de belles fêtes 🎄'],
        ];
        return $m[$key] ?? ['', ''];
    }
}

if (!function_exists('detectEventIntro')) {
    /**
     * Détermine l'événement dont l'animation de 1ère connexion doit se jouer.
     * @return array|null  tableau du thème (+ 'key'), ou null si rien à jouer.
     */
    function detectEventIntro($db, $showWelcome = false)
    {
        // Aperçu admin : index.php?intro=<clé> (rejoue sans poser de cookie).
        if ((($_SESSION['role'] ?? '') === 'admin') && !empty($_GET['intro']) && function_exists('siteThemeCatalog')) {
            $cat = siteThemeCatalog();
            $pk = (string) $_GET['intro'];
            if (isset($cat[$pk])) {
                return ['key' => $pk] + $cat[$pk];
            }
        }

        if ($showWelcome) {
            return null; // la bienvenue (1ère connexion tout court) prime ce jour-là
        }
        if (function_exists('persoFeatureOn') && !persoFeatureOn($db, 'anim_enabled')) {
            return null; // catégorie Animations coupée
        }
        if (!function_exists('siteThemeCatalog') || !function_exists('themeMatchesToday')) {
            return null;
        }

        $today = date('Y-m-d');
        $md = date('m-d');
        $easter = function_exists('easterSunday') ? easterSunday((int) date('Y')) : $today;

        foreach (siteThemeCatalog() as $ek => $et) {
            // L'événement doit être activé en entier, ET son animation activée.
            $evOn = !function_exists('eventEnabled') || eventEnabled($db, $ek);
            $introOn = !function_exists('widgetGet') || widgetGet($db, 'theme_' . $ek . '_intro', '1') === '1';
            if ($evOn && $introOn && themeMatchesToday($et, $today, $md, $easter)) {
                $occId = $ek . ':' . date('Y');
                // Déjà vu cette occurrence (ce navigateur ou cette session) ?
                if (($_COOKIE['fami_intro'] ?? '') === $occId || ($_SESSION['fami_intro'] ?? '') === $occId) {
                    return null;
                }
                @setcookie('fami_intro', $occId, time() + 40 * 24 * 3600, '/');
                $_COOKIE['fami_intro'] = $occId;
                $_SESSION['fami_intro'] = $occId;
                return ['key' => $ek] + $et;
            }
        }
        return null;
    }
}

if (!function_exists('renderEventIntroOverlay')) {
    /** Affiche le splash plein écran d'animation de 1ère connexion pour l'événement. */
    function renderEventIntroOverlay($ev)
    {
        if (empty($ev)) {
            return;
        }
        $key = $ev['key'] ?? '';
        $accent = $ev['accent'] ?? '#2d5a37';
        $nom = is_array($ev['nom'] ?? null) ? $ev['nom'][0] : ($ev['nom'] ?? '');
        $particles = !empty($ev['particles']) ? array_values($ev['particles']) : ['✨'];
        list($title, $sub) = eventIntroMessage($key);
        if ($title === '') {
            $title = $nom;
        }
        ?>
        <div id="famiEventIntro" onclick="var o=this;o.style.opacity=0;setTimeout(function(){o.remove();},600);"
             style="position:fixed; inset:0; top:0;left:0;right:0;bottom:0; z-index:100000; display:flex; align-items:center; justify-content:center; overflow:hidden; cursor:pointer; transition:opacity .6s; background:radial-gradient(circle at 50% 30%, <?= htmlspecialchars($accent, ENT_QUOTES) ?>, #0e120e 82%);">
            <div style="position:relative; z-index:2; text-align:center; color:#fff; padding:24px; animation:eiPop .7s ease both;">
                <div style="font-size:4.4rem; margin-bottom:6px;"><?= htmlspecialchars($particles[0]) ?></div>
                <div style="font-size:2.4rem; font-weight:800; text-shadow:0 4px 22px rgba(0,0,0,.45);"><?= htmlspecialchars($title) ?></div>
                <?php if ($sub !== ''): ?>
                    <div style="margin-top:14px; font-size:1.05rem; opacity:.92; max-width:520px; margin-left:auto; margin-right:auto; line-height:1.5;"><?= htmlspecialchars($sub) ?></div>
                <?php endif; ?>
                <div style="margin-top:26px; font-size:.75rem; letter-spacing:2px; text-transform:uppercase; opacity:.7;">clique pour continuer</div>
            </div>
        </div>
        <style>
        @keyframes eiPop  { from { transform:scale(.85) translateY(20px); opacity:0; } to { transform:none; opacity:1; } }
        @keyframes eiFall { to { transform: translateY(115vh) rotate(360deg); } }
        </style>
        <script>
        (function () {
            var ov = document.getElementById('famiEventIntro');
            if (!ov) { return; }
            var parts = <?= json_encode($particles, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
            for (var i = 0; i < 30; i++) {
                var s = document.createElement('span');
                s.textContent = parts[i % parts.length];
                s.style.cssText = 'position:absolute; top:-10%; left:' + (Math.random() * 100) + '%; font-size:' + (18 + Math.random() * 22) + 'px; opacity:.9; z-index:1; animation:eiFall ' + (2.8 + Math.random() * 2.6) + 's linear ' + (Math.random() * 1.4) + 's forwards;';
                ov.appendChild(s);
            }
            setTimeout(function () { if (ov.parentNode) { ov.style.opacity = '0'; setTimeout(function () { ov.remove(); }, 600); } }, 5200);
        })();
        </script>
        <?php
    }
}
