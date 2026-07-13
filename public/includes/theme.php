<?php
// ============================================================
// Thèmes événementiels (visuel : modules, boutons, widget, décor)
// Conçu pour être étendu à d'autres pays (le catalogue est filtrable
// par 'pays' ; pour l'instant seule la Belgique est renseignée).
// ============================================================

if (!function_exists('easterSunday')) {
    /** Dimanche de Pâques (grégorien) - algorithme de Meeus/Jones/Butcher. AAAA-MM-JJ. */
    function easterSunday($year)
    {
        $year = (int) $year;
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;
        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }
}

if (!function_exists('siteThemeCatalog')) {
    /**
     * Catalogue des thèmes. Chaque thème :
     *  - nom : [FR, NL] (bannière)
     *  - pays : code(s) concerné(s)
     *  - md_range : ['MM-JJ','MM-JJ'] (gère le passage d'année si début > fin)
     *    OU easter : [offsetDébut, offsetFin] en jours autour du dimanche de Pâques
     *  - accent, accent2 : couleurs principales
     *  - particles : emojis qui tombent (décor)
     *  - sober : true = décor discret (ex. Armistice)
     *  - bg : légère teinte de fond
     */
    function siteThemeCatalog()
    {
        return [
            'nouvel_an' => [
                'nom' => ['Bonne année 🎉', 'Gelukkig nieuwjaar 🎉'],
                'pays' => ['BE'], 'md_range' => ['12-31', '01-02'],
                'accent' => '#d4af37', 'accent2' => '#111111',
                'particles' => ['✨', '🎉', '🥂'],
                'page_bg' => 'radial-gradient(circle at 50% -10%, #1a1600, #060606)', 'dark' => true,
            ],
            'saint_valentin' => [
                'nom' => ['Bonne Saint-Valentin ❤️', 'Fijne Valentijn ❤️'],
                'pays' => ['BE'], 'md_range' => ['02-13', '02-14'],
                'accent' => '#e0245e', 'accent2' => '#ff6b9d',
                'particles' => ['❤️', '💕', '🌹'],
                'page_bg' => 'linear-gradient(160deg, #fff0f5, #ffe0ec)', 'dark' => false,
            ],
            'paques' => [
                'nom' => ['Joyeuses Pâques 🐰', 'Vrolijk Pasen 🐰'],
                'pays' => ['BE'], 'easter' => [-2, 1],
                'accent' => '#e6a817', 'accent2' => '#7fb069',
                'particles' => ['🥚', '🐰', '🌷'],
                'page_bg' => 'linear-gradient(160deg, #fbf7e6, #eaf6e2)', 'dark' => false,
            ],
            'fete_nationale' => [
                'nom' => ['Bonne fête nationale 🇧🇪', 'Fijne nationale feestdag 🇧🇪'],
                'pays' => ['BE'], 'md_range' => ['07-21', '07-21'],
                'accent' => '#1a1a1a', 'accent2' => '#e30613',
                'particles' => ['🇧🇪', '🎆'],
                'page_bg' => 'linear-gradient(160deg, #fffef0, #fff5cf)', 'dark' => false,
            ],
            'halloween' => [
                'nom' => ['Joyeux Halloween 🎃', 'Happy Halloween 🎃'],
                'pays' => ['BE'], 'md_range' => ['10-28', '10-31'],
                'accent' => '#e8710a', 'accent2' => '#8a4fbf',
                'particles' => ['🎃', '👻', '🦇'],
                'page_bg' => 'radial-gradient(circle at 50% -10%, #241633, #090909)', 'dark' => true,
            ],
            'armistice' => [
                'nom' => ['11 novembre — Souvenons-nous 🌺', '11 november — Wij herdenken 🌺'],
                'pays' => ['BE'], 'md_range' => ['11-11', '11-11'],
                'accent' => '#8b1a1a', 'accent2' => '#4a4a4a',
                'particles' => ['🌺'], 'sober' => true,
                'page_bg' => 'linear-gradient(160deg, #f2ecec, #e6dede)', 'dark' => false,
            ],
            'saint_nicolas' => [
                'nom' => ['Bonne Saint-Nicolas 🎁', 'Fijne Sinterklaas 🎁'],
                'pays' => ['BE'], 'md_range' => ['12-06', '12-06'],
                'accent' => '#c0392b', 'accent2' => '#d4af37',
                'particles' => ['🎁', '⭐'],
                'page_bg' => 'linear-gradient(160deg, #fff1ee, #ffe0da)', 'dark' => false,
            ],
            'noel' => [
                'nom' => ['Joyeux Noël 🎄', 'Vrolijk Kerstfeest 🎄'],
                'pays' => ['BE'], 'md_range' => ['12-18', '12-26'],
                'accent' => '#c0392b', 'accent2' => '#1e6b3a',
                'particles' => ['❄️', '🎄', '⭐'],
                'page_bg' => 'radial-gradient(circle at 50% -10%, #0f2a1a, #071a10)', 'dark' => true,
            ],
        ];
    }
}

if (!function_exists('persoMasterOn')) {
    /** Interrupteur MAÎTRE « Personnalisation » : coupe toutes les options fun d'un seul clic. */
    function persoMasterOn(PDO $db)
    {
        return function_exists('widgetGet') ? (widgetGet($db, 'perso_enabled', '1') === '1') : true;
    }
}

if (!function_exists('persoFeatureOn')) {
    /** Une option fun (animation, thème, effets...) est active seulement si le MAÎTRE
     *  ET son interrupteur individuel sont tous les deux activés. */
    function persoFeatureOn(PDO $db, $key, $default = '1')
    {
        if (!persoMasterOn($db)) {
            return false;
        }
        return function_exists('widgetGet') ? (widgetGet($db, $key, $default) === '1') : true;
    }
}

if (!function_exists('themesEnabled')) {
    function themesEnabled(PDO $db)
    {
        return persoFeatureOn($db, 'themes_enabled');
    }
}

if (!function_exists('eventEnabled')) {
    /** L'ÉVÉNEMENT lui-même est-il activé ? (interrupteur global de l'événement :
     *  coupé = ni thème, ni effets, ni animation — l'événement n'existe pas.) */
    function eventEnabled($db, $key)
    {
        return !function_exists('widgetGet') || widgetGet($db, 'theme_' . $key . '_event', '1') === '1';
    }
}

if (!function_exists('themeMatchesToday')) {
    function themeMatchesToday(array $t, $today, $md, $easter)
    {
        if (isset($t['easter'])) {
            $start = date('Y-m-d', strtotime($easter . ' ' . (int) $t['easter'][0] . ' days'));
            $end = date('Y-m-d', strtotime($easter . ' ' . (int) $t['easter'][1] . ' days'));
            return $today >= $start && $today <= $end;
        }
        if (isset($t['md_range'])) {
            list($a, $b) = $t['md_range'];
            return ($a <= $b) ? ($md >= $a && $md <= $b) : ($md >= $a || $md <= $b);
        }
        return false;
    }
}

if (!function_exists('activeSiteTheme')) {
    /** Renvoie le thème actif aujourd'hui (ou null). $pays permet d'étendre à d'autres pays. */
    function activeSiteTheme(PDO $db, $pays = 'BE')
    {
        if (!themesEnabled($db)) {
            return null;
        }
        $today = date('Y-m-d');
        $md = date('m-d');
        $easter = easterSunday((int) date('Y'));
        foreach (siteThemeCatalog() as $key => $t) {
            if (!in_array($pays, $t['pays'] ?? ['BE'], true)) {
                continue;
            }
            if (themeMatchesToday($t, $today, $md, $easter)) {
                // Événement coupé en entier, ou thème de l'événement coupé : on l'ignore.
                if (!eventEnabled($db, $key)) {
                    continue;
                }
                if (function_exists('widgetGet') && widgetGet($db, 'theme_' . $key . '_on', '1') !== '1') {
                    continue;
                }
                return ['key' => $key] + $t;
            }
        }
        return null;
    }
}

if (!function_exists('birthdayTheme')) {
    /** Thème visuel de l'anniversaire (fond doré chaleureux). */
    function birthdayTheme()
    {
        return [
            'key' => 'anniversaire',
            'nom' => ['Joyeux anniversaire 🎂', 'Gelukkige verjaardag 🎂'],
            'accent' => '#c9971b', 'accent2' => '#b8860b',
            'particles' => ['🎈', '🎉', '⭐', '🎂'],
            'page_bg' => 'linear-gradient(160deg, #fff7e2, #f8e4b8)', 'dark' => false,
        ];
    }
}

if (!function_exists('welcomeTheme')) {
    /** Thème « Bienvenue » (1ère connexion) : vert Famiflora + doré qui brille. */
    function welcomeTheme()
    {
        return [
            'key' => 'bienvenue',
            'nom' => ['Bienvenue 🌿', 'Welkom 🌿'],
            'accent' => '#2d5a37', 'accent2' => '#d4af37',
            'particles' => ['✨', '🌟', '🌿', '⭐'],
            'page_bg' => 'radial-gradient(circle at 50% 28%, #35794a, #10251a 78%)',
            'dark' => true,
        ];
    }
}

if (!function_exists('activePageTheme')) {
    /**
     * Thème à appliquer GLOBALEMENT (fond du site), sur toutes les pages.
     * Priorité : aperçu admin (session) > anniversaire de l'utilisateur > événement du jour.
     */
    function activePageTheme(PDO $db, $pays = 'BE')
    {
        if (!empty($_SESSION['theme_preview']) && (($_SESSION['role'] ?? '') === 'admin')) {
            $pv = (string) $_SESSION['theme_preview'];
            if ($pv === 'anniversaire') {
                return birthdayTheme();
            }
            if ($pv === 'bienvenue' && function_exists('welcomeTheme')) {
                return welcomeTheme();
            }
            $catalog = siteThemeCatalog();
            if (isset($catalog[$pv])) {
                return ['key' => $pv] + $catalog[$pv];
            }
        }
        // L'anniversaire est un thème événementiel comme les autres : soumis au maître,
        // à la catégorie Thèmes, et à son interrupteur individuel theme_anniversaire_on.
        if (($_SESSION['is_birthday_today'] ?? '') === '1'
            && themesEnabled($db)
            && eventEnabled($db, 'anniversaire')
            && (!function_exists('widgetGet') || widgetGet($db, 'theme_anniversaire_on', '1') === '1')) {
            return birthdayTheme();
        }
        return activeSiteTheme($db, $pays);
    }
}

if (!function_exists('renderPageThemeBackground')) {
    /** CSS de fond appliqué sur TOUTES les pages (injecté après <body>). */
    function renderPageThemeBackground(array $theme)
    {
        $bg = $theme['page_bg'] ?? '';
        if ($bg === '') {
            return '';
        }
        $css = 'html,body{background:' . $bg . ' fixed !important;background-size:cover !important;}';
        return '<style id="fami-page-theme">' . $css . '</style>';
    }
}

if (!function_exists('famiScrollRestoreScript')) {
    /**
     * Script global : mémorise la position de scroll avant un envoi de formulaire /
     * une navigation, et la restaure après le rechargement de la MÊME page (PRG).
     * Évite de « remonter en haut » après un clic (verrouiller, traduire, etc.).
     */
    function famiScrollRestoreScript()
    {
        return '<script>(function(){try{'
            . 'var k="fscroll:"+location.pathname+location.search;'
            . 'var s=sessionStorage.getItem(k);'
            . 'if(s){sessionStorage.removeItem(k);var d=null;try{d=JSON.parse(s);}catch(e){}'
            . 'if(d&&(Date.now()-d.t)<20000&&d.y>0){var y=d.y;var go=function(){window.scrollTo(0,y);};'
            . 'go();document.addEventListener("DOMContentLoaded",go);window.addEventListener("load",go);'
            . 'setTimeout(go,120);setTimeout(go,400);}}'
            . 'function save(){try{sessionStorage.setItem(k,JSON.stringify({y:window.scrollY||window.pageYOffset||0,t:Date.now()}));}catch(e){}}'
            . 'document.addEventListener("submit",save,true);'
            . 'window.addEventListener("beforeunload",save);'
            . '}catch(e){}})();</script>';
    }
}

if (!function_exists('famiInjectPageTheme')) {
    /** Callback ob_start : injecte (toujours) la restauration du scroll + le fond du thème si actif. Uniquement en HTML. */
    /**
     * Fond VECTORIEL par défaut du site (net à toute résolution, sans photo floue).
     * Motif botanique léger (feuillages Famiflora) sur dégradé papier vert très clair.
     * Rendu en SVG inline (data-URI) : parfaitement net, ~1 Ko, thème « nature ».
     */
    function famiDefaultVectorBg()
    {
        $svg = "<svg xmlns='http://www.w3.org/2000/svg' width='220' height='220' viewBox='0 0 220 220'>"
            . "<g fill='none' stroke='#2f6b3c' stroke-opacity='0.09' stroke-width='1.7' stroke-linecap='round' stroke-linejoin='round'>"
            . "<path d='M26 196 C 40 150, 40 116, 60 78'/>"
            . "<path d='M40 150 q 30 -16 36 -50 q -36 10 -36 50'/>"
            . "<path d='M38 120 q -30 -14 -38 -46 q 34 6 38 46'/>"
            . "<path d='M170 210 C 182 164, 178 130, 196 92'/>"
            . "<path d='M182 168 q 28 -16 34 -48 q -34 10 -34 48'/>"
            . "<path d='M176 138 q -30 -12 -40 -42 q 36 4 40 42'/>"
            . "<path d='M128 40 q 26 -32 62 -32 q -6 36 -46 42 q -12 2 -16 -10 z'/>"
            . "<path d='M104 46 q -26 -30 -60 -26 q 6 34 44 40 q 12 2 16 -14 z'/>"
            . "</g>"
            . "<g fill='#3E8E4E' fill-opacity='0.07'>"
            . "<circle cx='96' cy='170' r='3.2'/><circle cx='210' cy='120' r='2.6'/>"
            . "<circle cx='56' cy='28' r='2.6'/><circle cx='150' cy='96' r='2.2'/>"
            . "</g></svg>";
        $uri = 'data:image/svg+xml,' . rawurlencode($svg);
        return 'html,body{background-color:#EEF4E8 !important;'
            . 'background-image:url("' . $uri . '"),linear-gradient(170deg,#F5F9F2 0%,#EAF2E4 55%,#E3EDDA 100%) !important;'
            . 'background-repeat:repeat,no-repeat !important;'
            . 'background-attachment:fixed,fixed !important;'
            . 'background-size:220px 220px,cover !important;'
            . 'background-position:top left,center !important;}';
    }

    function famiInjectPageTheme($buffer)
    {
        foreach (headers_list() as $h) {
            if (stripos($h, 'content-type:') === 0 && stripos($h, 'text/html') === false) {
                return $buffer; // réponse non-HTML (PDF, xlsx, JSON...) : on ne touche pas
            }
        }
        $pos = stripos($buffer, '<body');
        if ($pos === false) {
            return $buffer;
        }
        $tagEnd = strpos($buffer, '>', $pos);
        if ($tagEnd === false) {
            return $buffer;
        }
        // Toujours : restauration de la position de scroll.
        $inject = famiScrollRestoreScript();

        // Aperçu de thème actif (admin) : bandeau visible sur TOUTES les pages,
        // avec un bouton pour revenir à la normale d'où qu'on soit.
        if (!empty($_SESSION['theme_preview']) && (($_SESSION['role'] ?? '') === 'admin')) {
            $pvKey = (string) $_SESSION['theme_preview'];
            $pvTh = $GLOBALS['__fami_page_theme'] ?? null;
            $pvNom = (is_array($pvTh) && !empty($pvTh['nom']))
                ? (is_array($pvTh['nom']) ? $pvTh['nom'][0] : $pvTh['nom'])
                : $pvKey;
            $pvPath = strtok((string) ($_SERVER['REQUEST_URI'] ?? '/'), '?');
            $inject .= '<div style="position:fixed; top:0; left:0; right:0; z-index:99998; background:#1f1f1f; color:#fff; '
                . 'padding:8px 14px; font-size:.86rem; font-weight:700; display:flex; align-items:center; justify-content:center; gap:14px; flex-wrap:wrap; box-shadow:0 2px 12px rgba(0,0,0,.35);">'
                . '<span>🎨 Aperçu du thème « ' . htmlspecialchars($pvNom) . ' » — visible sur tout le site</span>'
                . '<a href="' . htmlspecialchars($pvPath . '?theme=off', ENT_QUOTES) . '" style="background:#fff; color:#1f1f1f; text-decoration:none; border-radius:999px; padding:5px 14px; font-weight:800;">↩ Revenir à la normale</a>'
                . '</div><div style="height:38px;"></div>';
        }
        // Fond de page :
        //  1) un HABILLAGE complet si l'événement en a un (direction artistique :
        //     fond composé, ornements SVG, tuiles retravaillées, guide habillé) ;
        //  2) sinon le simple fond de couleur du thème (ancien comportement) ;
        //  3) sinon le fond vectoriel par défaut.
        $th = $GLOBALS['__fami_page_theme'] ?? null;
        $skin = null;
        if (is_array($th) && !empty($th['key'])) {
            require_once __DIR__ . '/theme_skins.php';
            $skin = themeSkin((string) $th['key']);
        }
        if ($skin !== null) {
            $inject .= '<style id="fami-skin">' . $skin['css'] . '</style>' . $skin['decor'];
        } elseif (is_array($th) && ($th['page_bg'] ?? '') !== '') {
            $inject .= renderPageThemeBackground($th);
        } else {
            $inject .= '<style id="fami-vec-bg">' . famiDefaultVectorBg() . '</style>';
        }
        if ($inject === '') {
            return $buffer;
        }
        return substr($buffer, 0, $tagEnd + 1) . $inject . substr($buffer, $tagEnd + 1);
    }
}

if (!function_exists('renderSiteTheme')) {
    /** CSS + décor (bannière + particules) à injecter juste après <body> sur l'accueil.
     *  $withEffects=false : garde le fond/couleurs du thème mais SANS les particules animées. */
    function renderSiteTheme(array $theme, $withEffects = true)
    {
        // Si l'événement a un HABILLAGE, il apporte déjà son ambiance (poussière d'or,
        // confettis géométriques…). On coupe alors la pluie d'emojis d'origine : les
        // deux ensemble feraient surchargé, et c'est précisément l'effet « bidon »
        // qu'on cherche à supprimer.
        if (!empty($theme['key'])) {
            require_once __DIR__ . '/theme_skins.php';
            if (themeSkin((string) $theme['key']) !== null) {
                $withEffects = false;
            }
        }
        $accent = $theme['accent'];
        $accent2 = $theme['accent2'] ?? $accent;
        $lang = function_exists('currentLang') ? currentLang() : 'fr';
        $banner = is_array($theme['nom']) ? ($lang === 'nl' ? $theme['nom'][1] : $theme['nom'][0]) : (string) $theme['nom'];
        $particles = array_values($theme['particles'] ?? []);
        $count = !empty($theme['sober']) ? 10 : 22;
        $particlesJson = htmlspecialchars(json_encode($particles, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
        ob_start();
        ?>
        <div class="site-theme-ribbon"><?php echo htmlspecialchars($banner); ?></div>
        <?php if ($withEffects): ?>
        <div class="site-theme-fx" data-particles="<?php echo $particlesJson; ?>" data-count="<?php echo (int) $count; ?>"></div>
        <?php endif; ?>
        <style>
        .site-theme-ribbon { width:100%; box-sizing:border-box; text-align:center; font-weight:800; letter-spacing:.4px; color:#fff; padding:6px 12px; font-size:.9rem; background:linear-gradient(90deg, <?php echo $accent; ?>, <?php echo $accent2; ?>); box-shadow:0 2px 12px rgba(0,0,0,.18); position:relative; z-index:20; }
        body.site-theme .tile-title { color: <?php echo $accent; ?> !important; }
        body.site-theme .tile { border-top: 3px solid <?php echo $accent; ?>; }
        body.site-theme .tile:hover { box-shadow: 0 15px 35px <?php echo $accent; ?>40; }
        body.site-theme .btn-param, body.site-theme .lang-btn.active { background: <?php echo $accent; ?> !important; color:#fff !important; }
        body.site-theme .lang-btn.active:hover { background: <?php echo $accent2; ?> !important; }
        body.site-theme .home-widget { border:1px solid <?php echo $accent; ?>66; }
        .site-theme-fx { position:fixed; inset:0; pointer-events:none; z-index:1; overflow:hidden; }
        .stfx-p { position:absolute; top:-32px; opacity:.8; will-change:transform; animation:stfxFall linear infinite; }
        @keyframes stfxFall { to { transform: translateY(112vh) rotate(360deg); } }
        </style>
        <script>
        (function () {
            var fx = document.querySelector('.site-theme-fx');
            if (!fx) { return; }
            var list; try { list = JSON.parse(fx.getAttribute('data-particles') || '[]'); } catch (e) { list = []; }
            if (!list.length) { return; }
            var n = parseInt(fx.getAttribute('data-count') || '20', 10);
            for (var i = 0; i < n; i++) {
                var s = document.createElement('span');
                s.className = 'stfx-p';
                s.textContent = list[i % list.length];
                s.style.left = (Math.random() * 100) + '%';
                s.style.fontSize = (0.9 + Math.random() * 1.3) + 'rem';
                s.style.animationDuration = (6 + Math.random() * 7) + 's';
                s.style.animationDelay = (Math.random() * 8) + 's';
                fx.appendChild(s);
            }
        })();
        </script>
        <?php
        return ob_get_clean();
    }
}
