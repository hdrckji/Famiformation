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
                'particles' => ['✨', '🎉', '🥂'], 'bg' => 'rgba(212,175,55,0.06)',
            ],
            'saint_valentin' => [
                'nom' => ['Bonne Saint-Valentin ❤️', 'Fijne Valentijn ❤️'],
                'pays' => ['BE'], 'md_range' => ['02-13', '02-14'],
                'accent' => '#e0245e', 'accent2' => '#ff6b9d',
                'particles' => ['❤️', '💕', '🌹'], 'bg' => 'rgba(224,36,94,0.05)',
            ],
            'paques' => [
                'nom' => ['Joyeuses Pâques 🐰', 'Vrolijk Pasen 🐰'],
                'pays' => ['BE'], 'easter' => [-2, 1],
                'accent' => '#e6a817', 'accent2' => '#7fb069',
                'particles' => ['🥚', '🐰', '🌷'], 'bg' => 'rgba(230,168,23,0.06)',
            ],
            'fete_nationale' => [
                'nom' => ['Bonne fête nationale 🇧🇪', 'Fijne nationale feestdag 🇧🇪'],
                'pays' => ['BE'], 'md_range' => ['07-21', '07-21'],
                'accent' => '#1a1a1a', 'accent2' => '#e30613',
                'particles' => ['🇧🇪', '🎆'], 'bg' => 'rgba(253,218,36,0.12)',
            ],
            'halloween' => [
                'nom' => ['Joyeux Halloween 🎃', 'Happy Halloween 🎃'],
                'pays' => ['BE'], 'md_range' => ['10-28', '10-31'],
                'accent' => '#e8710a', 'accent2' => '#5b2a86',
                'particles' => ['🎃', '👻', '🦇'], 'bg' => 'rgba(232,113,10,0.06)',
            ],
            'armistice' => [
                'nom' => ['11 novembre — Souvenons-nous 🌺', '11 november — Wij herdenken 🌺'],
                'pays' => ['BE'], 'md_range' => ['11-11', '11-11'],
                'accent' => '#8b1a1a', 'accent2' => '#4a4a4a',
                'particles' => ['🌺'], 'sober' => true, 'bg' => 'rgba(139,26,26,0.05)',
            ],
            'saint_nicolas' => [
                'nom' => ['Bonne Saint-Nicolas 🎁', 'Fijne Sinterklaas 🎁'],
                'pays' => ['BE'], 'md_range' => ['12-06', '12-06'],
                'accent' => '#c0392b', 'accent2' => '#d4af37',
                'particles' => ['🎁', '⭐'], 'bg' => 'rgba(192,57,43,0.05)',
            ],
            'noel' => [
                'nom' => ['Joyeux Noël 🎄', 'Vrolijk Kerstfeest 🎄'],
                'pays' => ['BE'], 'md_range' => ['12-18', '12-26'],
                'accent' => '#c0392b', 'accent2' => '#1e6b3a',
                'particles' => ['❄️', '🎄', '⭐'], 'bg' => 'rgba(192,57,43,0.05)',
            ],
        ];
    }
}

if (!function_exists('themesEnabled')) {
    function themesEnabled(PDO $db)
    {
        return function_exists('widgetGet') ? (widgetGet($db, 'themes_enabled', '1') === '1') : true;
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
                return ['key' => $key] + $t;
            }
        }
        return null;
    }
}

if (!function_exists('renderSiteTheme')) {
    /** CSS + décor (bannière + particules) à injecter juste après <body> sur l'accueil. */
    function renderSiteTheme(array $theme)
    {
        $accent = $theme['accent'];
        $accent2 = $theme['accent2'] ?? $accent;
        $bg = $theme['bg'] ?? 'transparent';
        $lang = function_exists('currentLang') ? currentLang() : 'fr';
        $banner = is_array($theme['nom']) ? ($lang === 'nl' ? $theme['nom'][1] : $theme['nom'][0]) : (string) $theme['nom'];
        $particles = array_values($theme['particles'] ?? []);
        $count = !empty($theme['sober']) ? 10 : 22;
        $particlesJson = htmlspecialchars(json_encode($particles, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
        ob_start();
        ?>
        <div class="site-theme-ribbon"><?php echo htmlspecialchars($banner); ?></div>
        <div class="site-theme-fx" data-particles="<?php echo $particlesJson; ?>" data-count="<?php echo (int) $count; ?>"></div>
        <style>
        body.site-theme { background-color: <?php echo $bg; ?>; }
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
