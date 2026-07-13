<?php
// ============================================================
// theme_skins.php — HABILLAGES d'événement (« skins »).
//
// POURQUOI (retour de Jimmy : « les anciens thèmes étaient bidons ») :
// un thème = une couleur d'accent + une pluie d'emojis. C'est l'EMOJI qui faisait
// pauvre. Ici un habillage est une vraie direction artistique : fond composé en
// couches, ornements SVG dessinés, typographie travaillée, ambiance lente.
//
// ARCHITECTURE — un habillage est une PEAU (CSS + décor) posée sur la structure
// existante, PAS une page HTML par événement. Une seule structure, N habillages :
// on ajoute un module → il est joli dans TOUS les thèmes, sans rien refaire.
// Générer une page par événement aurait dupliqué tout le site (×10) : le coût
// n'aurait pas été le poids (~10 Ko, un seul chargé à la fois) mais la MAINTENANCE.
//
// ⚠️ LE PIÈGE CORRIGÉ ICI (bug remonté par Jimmy : « le guide reste vert »)
// content_view.php émet son CSS EN LIGNE, donc PLUS BAS dans le document que notre
// habillage (injecté juste après <body>). À spécificité égale (.fami-doc contre
// .fami-doc), c'est le DERNIER écrit qui gagne → nos variables étaient écrasées en
// silence. D'où le sélecteur `body .fami-doc` (spécificité 0,1,1 > 0,1,0) : il
// l'emporte quel que soit l'ordre. C'est ce qui rend enfin le guide thémable.
//
// MAINTENANCE — chaque événement est une simple CONFIG (couleurs + ambiance +
// ornement). Le CSS est généré une seule fois par skinBuild(). Ajouter un
// événement = ajouter une entrée, pas 120 lignes de CSS.
// ============================================================

if (!function_exists('skinParticles')) {
    /**
     * Décor ambiant. Trois familles, aucune n'utilise d'emoji (c'est tout l'enjeu) :
     *   - 'motes'    : poussière lumineuse qui MONTE lentement (élégant, à contre-courant)
     *   - 'confetti' : formes géométriques (rectangles + disques) dans une palette choisie
     *   - 'drift'    : particules douces qui descendent (neige, pétales, cendres)
     *   - 'none'     : aucun mouvement (recueillement — ex. 11 novembre)
     *
     * Valeurs figées côté serveur : aucun JS, donc aucun coût au chargement.
     */
    function skinParticles($type, array $colors, $count = 26)
    {
        if ($type === 'none' || $count <= 0) {
            return '';
        }
        $h = '<div class="skin-fx" aria-hidden="true">';
        for ($i = 0; $i < $count; $i++) {
            $c = $colors[$i % max(1, count($colors))];
            $left = mt_rand(0, 1000) / 10;
            $drift = mt_rand(-80, 80);
            $delay = mt_rand(0, 280) / 10;

            if ($type === 'confetti') {
                $w = mt_rand(4, 9);
                $isDisc = ($i % 4 === 0);
                $hg = $isDisc ? $w : mt_rand(9, 16);
                $h .= '<span class="sk-conf" style="left:' . $left . '%;'
                    . 'width:' . $w . 'px;height:' . $hg . 'px;background:' . $c . ';'
                    . ($isDisc ? 'border-radius:50%;' : 'border-radius:1px;')
                    . 'animation-duration:' . (mt_rand(90, 190) / 10) . 's;'
                    . 'animation-delay:-' . $delay . 's;'
                    . '--spin:' . mt_rand(240, 900) . 'deg;--drift:' . $drift . 'px;"></span>';
            } elseif ($type === 'drift') {
                $s = mt_rand(4, 11);
                $h .= '<span class="sk-drift" style="left:' . $left . '%;'
                    . 'width:' . $s . 'px;height:' . $s . 'px;background:' . $c . ';'
                    . 'animation-duration:' . (mt_rand(140, 300) / 10) . 's;'
                    . 'animation-delay:-' . $delay . 's;'
                    . '--drift:' . $drift . 'px;opacity:' . (mt_rand(25, 70) / 100) . ';"></span>';
            } else { // motes
                $s = mt_rand(3, 9);
                $h .= '<span class="sk-mote" style="left:' . $left . '%;'
                    . 'width:' . $s . 'px;height:' . $s . 'px;'
                    . 'background:radial-gradient(circle,#FFF 0%,' . $c . ' 55%,rgba(0,0,0,0) 72%);'
                    . 'animation-duration:' . (mt_rand(160, 340) / 10) . 's;'
                    . 'animation-delay:-' . $delay . 's;'
                    . '--drift:' . $drift . 'px;opacity:' . (mt_rand(18, 55) / 100) . ';"></span>';
            }
        }
        return $h . '</div>';
    }
}

if (!function_exists('skinBase')) {
    /** Règles communes : le décor ne gêne jamais, et le contenu passe au-dessus. */
    function skinBase()
    {
        return '
        .skin-fx{ position:fixed; inset:0; top:0; left:0; right:0; bottom:0; pointer-events:none; overflow:hidden; z-index:0; }
        .skin-fx > span{ position:absolute; will-change:transform; }
        body > *:not(.skin-fx){ position:relative; z-index:1; }

        .skin-fx .sk-mote{ border-radius:50%; top:auto; bottom:-6%; filter:blur(.3px);
            animation-name:skRise; animation-timing-function:linear; animation-iteration-count:infinite; }
        @keyframes skRise{ from{ transform:translate3d(0,0,0) scale(.85); }
                           to  { transform:translate3d(var(--drift),-116vh,0) scale(1.15); } }

        .skin-fx .sk-conf{ top:-8%; opacity:.82;
            animation-name:skFall; animation-timing-function:linear; animation-iteration-count:infinite; }
        @keyframes skFall{ from{ transform:translate3d(0,-10vh,0) rotate(0deg); }
                           to  { transform:translate3d(var(--drift),112vh,0) rotate(var(--spin)); } }

        .skin-fx .sk-drift{ top:-8%; border-radius:50%; filter:blur(.6px);
            animation-name:skDrift; animation-timing-function:linear; animation-iteration-count:infinite; }
        @keyframes skDrift{ from{ transform:translate3d(0,-10vh,0); }
                            to  { transform:translate3d(var(--drift),112vh,0); } }

        h1{ background-color:transparent !important; box-shadow:none !important; }
        @keyframes skFoil{ to{ background-position:220% center; } }
        @media (prefers-reduced-motion: reduce){ .skin-fx{ display:none; } }
        ';
    }
}

if (!function_exists('skinBuild')) {
    /**
     * Génère le CSS complet d'un habillage à partir d'une config.
     * Écrit UNE fois, réutilisé par les 10 événements → pas de CSS dupliqué.
     *
     * Clés attendues : bg, pattern, vignette, foil, tilePaper, tileBorder,
     * tileBar, tileOrnament, tileTitle, tileDesc, cardBg, cardBorder, btn,
     * doc (les variables de .fami-doc), heroBg, heroRule, heroBrand.
     */
    function skinBuild(array $c)
    {
        $d = $c['doc'];

        // Les variables du guide, en une ligne.
        $docVars = '';
        foreach ($d as $k => $v) {
            $docVars .= '--' . $k . ':' . $v . ';';
        }

        $vignette = empty($c['vignette']) ? '' : '
        body::after{ content:""; position:fixed; inset:0; top:0; left:0; right:0; bottom:0; pointer-events:none; z-index:0;
            background:radial-gradient(120% 80% at 50% 40%, transparent 55%, ' . $c['vignette'] . ' 100%); }';

        $pattern = empty($c['pattern']) ? '' : '
        body::before{ content:""; position:fixed; inset:0; top:0; left:0; right:0; bottom:0; pointer-events:none; z-index:0;
            opacity:' . ($c['patternOpacity'] ?? '.5') . ';
            background:' . $c['pattern'] . '; }';

        $ornament = empty($c['tileOrnament']) ? '' : '
        .tile::after{ content:""; position:absolute; ' . ($c['ornamentPos'] ?? 'right:-12px; bottom:-14px;') . '
            width:92px; height:92px; opacity:' . ($c['ornamentOpacity'] ?? '.14') . ';
            background:' . $c['tileOrnament'] . ' no-repeat center/contain;
            transition:opacity .3s, transform .3s; }
        .tile:hover::after{ opacity:' . ($c['ornamentHover'] ?? '.26') . '; transform:rotate(-8deg) scale(1.06); }';

        return skinBase() . '
        /* ---- Fond : plusieurs couches → de la PROFONDEUR (un dégradé seul fait plat). ---- */
        body{ background:' . $c['bg'] . ' fixed !important; color:' . $c['ink'] . '; }
        ' . $pattern . $vignette . '

        /* ---- Titres : feuille métallique qui miroite lentement. ---- */
        h1{ background:' . $c['foil'] . '; background-size:220% auto;
            -webkit-background-clip:text; background-clip:text;
            -webkit-text-fill-color:transparent; color:transparent;
            animation:skFoil 9s linear infinite; }

        /* ---- Tuiles : papier, filet en tête, ornement dessiné, relief au survol. ---- */
        .tile{ background:' . $c['tilePaper'] . ' !important;
               border:1px solid ' . $c['tileBorder'] . ' !important;
               box-shadow:' . $c['tileShadow'] . ' !important; overflow:hidden; }
        .tile::before{ content:""; position:absolute; top:0; left:0; right:0; height:3px;
            background:' . $c['tileBar'] . '; }
        ' . $ornament . '
        .tile:hover{ transform:translateY(-8px); box-shadow:' . $c['tileShadowHover'] . ' !important; }
        .tile-title{ color:' . $c['tileTitle'] . ' !important; }
        .tile-desc{ color:' . $c['tileDesc'] . ' !important; }

        .content-card{ background:' . $c['cardBg'] . ' !important;
            border:1px solid ' . $c['cardBorder'] . ' !important;
            box-shadow:' . $c['cardShadow'] . ' !important; }
        .btn-create, .btn-primary{ background:' . $c['btn'] . ' !important;
            color:' . ($c['btnInk'] ?? '#fff') . ' !important; }

        /* ================================================================
           LE GUIDE — habillé PAR SES VARIABLES, structure intacte.
           `body .fami-doc` et non `.fami-doc` : content_view.php émet son CSS
           plus bas dans le document, donc à spécificité égale il gagnerait.
           Ce sélecteur (0,1,1) l\'emporte quel que soit l\'ordre.
           ================================================================ */
        body .fami-doc{ ' . $docVars . ' }
        body .fami-doc .hero{ background:' . $c['heroBg'] . ' !important; }
        body .fami-doc .hero::after{ content:""; position:absolute; left:0; right:0; bottom:0; height:3px;
            background:' . $c['heroRule'] . '; }
        body .fami-doc .hero__brand{ color:' . $c['heroBrand'] . ' !important; }
        body .fami-doc .section__title{ color:' . $d['forest'] . ' !important; }
        body .fami-doc .section__eyebrow{ color:' . $d['pollen'] . ' !important; }
        body .fami-doc .cover{ background:' . $c['heroBg'] . ' !important; }
        ';
    }
}

if (!function_exists('themeSkinCatalog')) {
    /**
     * Une entrée par événement. Chacun a sa propre direction artistique, mais tous
     * partagent la même grammaire (fond en couches, filet, ornement, ambiance lente)
     * → l\'ensemble reste cohérent, comme une collection.
     */
    function themeSkinCatalog()
    {
        $leafSvg = "url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='92' height='92' viewBox='0 0 92 92'%3E%3Cg fill='none' stroke='%231B4429' stroke-width='2'%3E%3Cpath d='M14 84 C 34 62, 46 44, 58 14'/%3E%3Cpath d='M34 60 q 26 -16 30 -44 q -30 8 -30 44'/%3E%3Cpath d='M40 42 q -26 -10 -32 -36 q 28 4 32 36'/%3E%3C/g%3E%3C/svg%3E\")";
        $botanical = "url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='320' height='320' viewBox='0 0 320 320'%3E%3Cg fill='none' stroke='%23D4AF37' stroke-opacity='0.10' stroke-width='1.2'%3E%3Cpath d='M36 292 C 56 214, 56 156, 84 88'/%3E%3Cpath d='M58 228 q 32 -22 38 -56 q -38 10 -38 56'/%3E%3Cpath d='M66 184 q -34 -14 -44 -48 q 38 4 44 48'/%3E%3Cpath d='M242 310 C 254 242, 250 194, 272 136'/%3E%3Cpath d='M164 58 q 26 -32 64 -34 q -8 38 -48 44 q -12 2 -16 -10 z'/%3E%3C/g%3E%3C/svg%3E\") 0 0/320px 320px";

        return [

            // 🌿 BIENVENUE — Botanique & Or. Vert profond, or chaud, papier crème.
            'bienvenue' => [
                'fx' => ['motes', ['#D4AF37'], 26],
                'bg' => 'radial-gradient(1100px 620px at 50% -12%, rgba(212,175,55,.16), transparent 62%),'
                    . 'radial-gradient(900px 700px at 8% 108%, rgba(62,142,78,.22), transparent 66%),'
                    . 'linear-gradient(168deg,#0C2215 0%,#143620 46%,#1E4D2B 100%)',
                'pattern' => $botanical, 'vignette' => 'rgba(4,14,8,.45)',
                'ink' => '#EAF1E6',
                'foil' => 'linear-gradient(100deg,#A87C15 0%,#F3E3A6 28%,#D4AF37 50%,#F3E3A6 72%,#A87C15 100%)',
                'tilePaper' => 'linear-gradient(180deg,#FDFBF3 0%,#F4EFDF 100%)',
                'tileBorder' => 'rgba(168,124,21,.28)',
                'tileBar' => 'linear-gradient(90deg,#A87C15,#F3E3A6 45%,#D4AF37 55%,#A87C15)',
                'tileOrnament' => $leafSvg,
                'tileShadow' => '0 2px 4px rgba(4,14,8,.18), 0 16px 40px rgba(4,14,8,.28)',
                'tileShadowHover' => '0 6px 10px rgba(4,14,8,.2), 0 26px 58px rgba(212,175,55,.24)',
                'tileTitle' => '#1B4429', 'tileDesc' => '#5C6B54',
                'cardBg' => 'rgba(251,248,239,.97)', 'cardBorder' => 'rgba(168,124,21,.22)',
                'cardShadow' => '0 18px 48px rgba(4,14,8,.3)',
                'btn' => 'linear-gradient(180deg,#26623A,#1B4429)',
                // Papier CRÈME DORÉ (pas le blanc-vert d'origine) → la différence se voit.
                'doc' => ['paper' => '#FBF6E6', 'paper-deep' => '#F3EAD0', 'line' => '#E0D2AC',
                    'forest' => '#1B4429', 'leaf' => '#3E8E4E', 'moss' => '#8A7A3F',
                    'pollen' => '#A87C15', 'pollen-bg' => '#FAF0D6',
                    'ink' => '#22301D', 'ink-soft' => '#55603F'],
                'heroBg' => 'linear-gradient(155deg,#0E2717 0%,#1B4429 52%,#2C6B3D 100%)',
                'heroRule' => 'linear-gradient(90deg,#A87C15,#F3E3A6 50%,#A87C15)',
                'heroBrand' => '#F3E3A6',
            ],

            // 🎂 ANNIVERSAIRE — Champagne & Confettis. Festif mais chic.
            'anniversaire' => [
                'fx' => ['confetti', ['#C9971B', '#E9C766', '#E8927C', '#7FB09A', '#8A6BA1', '#FFFFFF'], 30],
                'bg' => 'radial-gradient(900px 520px at 18% -6%, rgba(233,199,102,.42), transparent 62%),'
                    . 'radial-gradient(760px 520px at 88% 6%, rgba(232,146,124,.24), transparent 62%),'
                    . 'radial-gradient(900px 640px at 50% 112%, rgba(127,176,154,.20), transparent 66%),'
                    . 'linear-gradient(172deg,#FFFDF7 0%,#FBF2DC 55%,#F5E7C6 100%)',
                'pattern' => "url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='600' height='150' viewBox='0 0 600 150'%3E%3Cg fill='none' stroke='%23C9971B' stroke-opacity='0.35' stroke-width='1.6'%3E%3Cpath d='M0 18 Q 75 66 150 18 T 300 18 T 450 18 T 600 18'/%3E%3C/g%3E%3Cg fill='%23C9971B' fill-opacity='0.30'%3E%3Ccircle cx='75' cy='44' r='3'/%3E%3Ccircle cx='225' cy='44' r='3'/%3E%3Ccircle cx='375' cy='44' r='3'/%3E%3Ccircle cx='525' cy='44' r='3'/%3E%3C/g%3E%3C/svg%3E\") repeat-x top center/600px 150px",
                'patternOpacity' => '.5', 'vignette' => '',
                'ink' => '#3A2E1A',
                'foil' => 'linear-gradient(100deg,#A87C15 0%,#F6E3A4 30%,#C9971B 50%,#F6E3A4 70%,#A87C15 100%)',
                'tilePaper' => 'linear-gradient(180deg,#FFFDF7 0%,#FBF3E2 100%)',
                'tileBorder' => 'rgba(201,151,27,.3)',
                'tileBar' => 'linear-gradient(90deg,#C9971B,#F6E3A4 40%,#E8927C 62%,#7FB09A 82%,#C9971B)',
                'tileOrnament' => "url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='78' height='78' viewBox='0 0 78 78'%3E%3Crect x='12' y='22' width='4' height='9' rx='1' fill='%23E8927C' transform='rotate(24 14 26)'/%3E%3Crect x='34' y='10' width='4' height='9' rx='1' fill='%237FB09A' transform='rotate(-32 36 14)'/%3E%3Crect x='56' y='30' width='4' height='9' rx='1' fill='%23C9971B' transform='rotate(14 58 34)'/%3E%3Ccircle cx='26' cy='44' r='2.6' fill='%238A6BA1'/%3E%3Ccircle cx='50' cy='16' r='2.6' fill='%23E9C766'/%3E%3C/svg%3E\")",
                'ornamentPos' => 'right:-8px; top:-8px;', 'ornamentOpacity' => '.5', 'ornamentHover' => '.75',
                'tileShadow' => '0 2px 4px rgba(90,66,20,.10), 0 16px 40px rgba(90,66,20,.14)',
                'tileShadowHover' => '0 6px 12px rgba(90,66,20,.14), 0 26px 58px rgba(201,151,27,.26)',
                'tileTitle' => '#8A5A00', 'tileDesc' => '#6B5B3E',
                'cardBg' => 'rgba(255,253,247,.97)', 'cardBorder' => 'rgba(201,151,27,.24)',
                'cardShadow' => '0 18px 46px rgba(90,66,20,.16)',
                'btn' => 'linear-gradient(180deg,#D8A62A,#B9860F)', 'btnInk' => '#2B2007',
                'doc' => ['paper' => '#FFFCF4', 'paper-deep' => '#FBF2DC', 'line' => '#EBDCB6',
                    'forest' => '#8A5A00', 'leaf' => '#C9971B', 'moss' => '#B08A3A',
                    'pollen' => '#C9971B', 'pollen-bg' => '#FCF3DC',
                    'ink' => '#3A2E1A', 'ink-soft' => '#6B5B3E'],
                'heroBg' => 'linear-gradient(155deg,#7A4E06 0%,#A9741A 52%,#C9971B 100%)',
                'heroRule' => 'linear-gradient(90deg,#C9971B,#F6E3A4 40%,#E8927C 62%,#7FB09A 82%,#C9971B)',
                'heroBrand' => '#F6E3A4',
            ],

            // 🎄 NOËL — Nuit bleutée, givre, or. Pas un flocon emoji.
            'noel' => [
                'fx' => ['drift', ['#FFFFFF', '#DCEBFA', '#F3E3A6'], 34],
                'bg' => 'radial-gradient(1000px 560px at 50% -10%, rgba(212,175,55,.14), transparent 60%),'
                    . 'radial-gradient(800px 620px at 12% 104%, rgba(46,110,80,.26), transparent 66%),'
                    . 'linear-gradient(170deg,#07131F 0%,#0D2233 45%,#123243 100%)',
                'pattern' => "url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='240' height='240' viewBox='0 0 240 240'%3E%3Cg fill='none' stroke='%23BFE0F2' stroke-opacity='0.10' stroke-width='1.1'%3E%3Cpath d='M60 20 v80 M20 60 h80 M32 32 l56 56 M88 32 l-56 56'/%3E%3Cpath d='M180 140 v70 M145 175 h70 M156 151 l48 48 M204 151 l-48 48'/%3E%3C/g%3E%3C/svg%3E\") 0 0/240px 240px",
                'patternOpacity' => '.45', 'vignette' => 'rgba(2,8,14,.5)',
                'ink' => '#E6EFF6',
                'foil' => 'linear-gradient(100deg,#9C7A16 0%,#F3E3A6 30%,#D4AF37 52%,#F3E3A6 72%,#9C7A16 100%)',
                'tilePaper' => 'linear-gradient(180deg,#FBFDFF 0%,#EDF3F8 100%)',
                'tileBorder' => 'rgba(168,124,21,.26)',
                'tileBar' => 'linear-gradient(90deg,#1E6B3A,#F3E3A6 45%,#C0392B 55%,#1E6B3A)',
                'tileOrnament' => "url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='92' height='92' viewBox='0 0 92 92'%3E%3Cg fill='none' stroke='%231E6B3A' stroke-width='2'%3E%3Cpath d='M46 8 L26 40 h12 L20 66 h20 v18 h12 V66 h20 L54 40 h12 z'/%3E%3C/g%3E%3C/svg%3E\")",
                'tileShadow' => '0 2px 4px rgba(2,10,18,.22), 0 16px 40px rgba(2,10,18,.32)',
                'tileShadowHover' => '0 6px 10px rgba(2,10,18,.24), 0 26px 58px rgba(212,175,55,.22)',
                'tileTitle' => '#123243', 'tileDesc' => '#5A6B76',
                'cardBg' => 'rgba(251,253,255,.97)', 'cardBorder' => 'rgba(168,124,21,.2)',
                'cardShadow' => '0 18px 48px rgba(2,10,18,.34)',
                'btn' => 'linear-gradient(180deg,#26783F,#1A5730)',
                'doc' => ['paper' => '#F7FAFD', 'paper-deep' => '#E9F1F7', 'line' => '#CFDEE9',
                    'forest' => '#123243', 'leaf' => '#1E6B3A', 'moss' => '#6C8494',
                    'pollen' => '#A87C15', 'pollen-bg' => '#FAF1DA',
                    'ink' => '#16242E', 'ink-soft' => '#4E606C'],
                'heroBg' => 'linear-gradient(155deg,#07131F 0%,#0D2233 55%,#154257 100%)',
                'heroRule' => 'linear-gradient(90deg,#1E6B3A,#F3E3A6 50%,#C0392B)',
                'heroBrand' => '#F3E3A6',
            ],

            // 🎃 HALLOWEEN — Violet profond, lueur de citrouille. Aucun fantôme emoji.
            'halloween' => [
                'fx' => ['motes', ['#E8710A', '#8A4FBF'], 24],
                'bg' => 'radial-gradient(900px 520px at 50% -8%, rgba(232,113,10,.22), transparent 58%),'
                    . 'radial-gradient(760px 620px at 90% 106%, rgba(138,79,191,.24), transparent 64%),'
                    . 'linear-gradient(170deg,#150A1F 0%,#241134 48%,#160D22 100%)',
                'pattern' => "url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='280' height='280' viewBox='0 0 280 280'%3E%3Cg fill='none' stroke='%23E8710A' stroke-opacity='0.09' stroke-width='1.2'%3E%3Cpath d='M20 260 C 60 190, 40 130, 90 60'/%3E%3Cpath d='M60 180 q 40 -20 46 -70'/%3E%3Cpath d='M230 270 C 250 200, 230 150, 260 90'/%3E%3Cpath d='M120 40 q 40 -24 78 -8'/%3E%3C/g%3E%3C/svg%3E\") 0 0/280px 280px",
                'patternOpacity' => '.55', 'vignette' => 'rgba(8,3,14,.55)',
                'ink' => '#F0E6F7',
                'foil' => 'linear-gradient(100deg,#B4560A 0%,#FFC98A 30%,#E8710A 52%,#FFC98A 72%,#B4560A 100%)',
                'tilePaper' => 'linear-gradient(180deg,#FFF6ED 0%,#F6E7D6 100%)',
                'tileBorder' => 'rgba(180,86,10,.3)',
                'tileBar' => 'linear-gradient(90deg,#8A4FBF,#E8710A 50%,#8A4FBF)',
                'tileOrnament' => "url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='92' height='92' viewBox='0 0 92 92'%3E%3Cg fill='none' stroke='%23241134' stroke-width='2'%3E%3Cpath d='M46 18 c-18 0 -30 14 -30 32 s12 30 30 30 s30 -12 30 -30 s-12 -32 -30 -32 z'/%3E%3Cpath d='M46 18 v-8 M38 26 q8 -10 16 0'/%3E%3C/g%3E%3C/svg%3E\")",
                'tileShadow' => '0 2px 4px rgba(10,4,18,.26), 0 16px 40px rgba(10,4,18,.36)',
                'tileShadowHover' => '0 6px 10px rgba(10,4,18,.28), 0 26px 58px rgba(232,113,10,.26)',
                'tileTitle' => '#3B1D52', 'tileDesc' => '#6B5670',
                'cardBg' => 'rgba(255,246,237,.97)', 'cardBorder' => 'rgba(180,86,10,.22)',
                'cardShadow' => '0 18px 48px rgba(10,4,18,.38)',
                'btn' => 'linear-gradient(180deg,#E8710A,#B4560A)', 'btnInk' => '#1E0E2B',
                'doc' => ['paper' => '#FFF7EF', 'paper-deep' => '#F7E9DA', 'line' => '#E4CDB6',
                    'forest' => '#3B1D52', 'leaf' => '#8A4FBF', 'moss' => '#8A6E96',
                    'pollen' => '#B4560A', 'pollen-bg' => '#FDEBD8',
                    'ink' => '#2A1A33', 'ink-soft' => '#5E4B67'],
                'heroBg' => 'linear-gradient(155deg,#150A1F 0%,#3B1D52 52%,#7A3D18 100%)',
                'heroRule' => 'linear-gradient(90deg,#8A4FBF,#E8710A 50%,#8A4FBF)',
                'heroBrand' => '#FFC98A',
            ],

            // 🐰 PÂQUES — Pastels crayeux, lumière matinale. Aucun œuf emoji.
            'paques' => [
                'fx' => ['drift', ['#F6C9D8', '#CDE7C2', '#F7E3A1', '#FFFFFF'], 26],
                'bg' => 'radial-gradient(900px 500px at 22% -6%, rgba(247,227,161,.5), transparent 62%),'
                    . 'radial-gradient(760px 520px at 84% 4%, rgba(205,231,194,.5), transparent 62%),'
                    . 'linear-gradient(172deg,#FFFEF8 0%,#F5F8EC 55%,#EAF2E0 100%)',
                'pattern' => "url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='200' height='200' viewBox='0 0 200 200'%3E%3Cg fill='none' stroke='%237FB069' stroke-opacity='0.16' stroke-width='1.2'%3E%3Cpath d='M40 150 q 20 -40 0 -80 q -20 40 0 80'/%3E%3Cpath d='M150 60 q 22 -44 0 -88'/%3E%3Ccircle cx='120' cy='140' r='16'/%3E%3Ccircle cx='60' cy='40' r='10'/%3E%3C/g%3E%3C/svg%3E\") 0 0/200px 200px",
                'patternOpacity' => '.6', 'vignette' => '',
                'ink' => '#33452C',
                'foil' => 'linear-gradient(100deg,#B8912A 0%,#F7E3A1 30%,#E6A817 52%,#F7E3A1 72%,#B8912A 100%)',
                'tilePaper' => 'linear-gradient(180deg,#FFFFFF 0%,#F4F8EC 100%)',
                'tileBorder' => 'rgba(127,176,105,.34)',
                'tileBar' => 'linear-gradient(90deg,#F6C9D8,#F7E3A1 45%,#CDE7C2 75%,#F6C9D8)',
                'tileOrnament' => "url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='92' height='92' viewBox='0 0 92 92'%3E%3Cg fill='none' stroke='%237FB069' stroke-width='2'%3E%3Cellipse cx='46' cy='52' rx='22' ry='28'/%3E%3Cpath d='M28 46 q18 10 36 0 M30 60 q16 -8 32 0'/%3E%3C/g%3E%3C/svg%3E\")",
                'ornamentOpacity' => '.2', 'ornamentHover' => '.36',
                'tileShadow' => '0 2px 4px rgba(60,80,50,.08), 0 16px 40px rgba(60,80,50,.12)',
                'tileShadowHover' => '0 6px 12px rgba(60,80,50,.12), 0 26px 58px rgba(127,176,105,.24)',
                'tileTitle' => '#3F6B33', 'tileDesc' => '#66755C',
                'cardBg' => 'rgba(255,255,255,.97)', 'cardBorder' => 'rgba(127,176,105,.24)',
                'cardShadow' => '0 18px 46px rgba(60,80,50,.14)',
                'btn' => 'linear-gradient(180deg,#69A455,#4E8340)',
                'doc' => ['paper' => '#FFFEF8', 'paper-deep' => '#F2F7E9', 'line' => '#DCE7CE',
                    'forest' => '#3F6B33', 'leaf' => '#7FB069', 'moss' => '#8FA37E',
                    'pollen' => '#C79A1F', 'pollen-bg' => '#FBF3DC',
                    'ink' => '#2F3D28', 'ink-soft' => '#5F6C56'],
                'heroBg' => 'linear-gradient(155deg,#4E8340 0%,#7FB069 55%,#A9C96B 100%)',
                'heroRule' => 'linear-gradient(90deg,#F6C9D8,#F7E3A1 50%,#CDE7C2)',
                'heroBrand' => '#FFFDF0',
            ],

            // ❤️ SAINT-VALENTIN — Rose poudré, encre bordeaux. Élégant, pas mièvre.
            'saint_valentin' => [
                'fx' => ['drift', ['#F3C6CE', '#E8A0AE', '#FFFFFF'], 24],
                'bg' => 'radial-gradient(900px 520px at 24% -8%, rgba(232,160,174,.42), transparent 62%),'
                    . 'radial-gradient(760px 560px at 86% 8%, rgba(158,44,74,.16), transparent 64%),'
                    . 'linear-gradient(172deg,#FFF9FA 0%,#FBEDF0 55%,#F5DDE3 100%)',
                'pattern' => "url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='220' height='220' viewBox='0 0 220 220'%3E%3Cg fill='none' stroke='%239E2C4A' stroke-opacity='0.10' stroke-width='1.2'%3E%3Cpath d='M60 90 q -22 -26 4 -40 q 18 -10 26 8 q 8 -18 26 -8 q 26 14 4 40 q -18 22 -30 34 q -12 -12 -30 -34 z'/%3E%3Cpath d='M150 190 q -14 -16 2 -25 q 11 -6 16 5 q 5 -11 16 -5 q 16 9 2 25 q -11 14 -18 21 q -7 -7 -18 -21 z'/%3E%3C/g%3E%3C/svg%3E\") 0 0/220px 220px",
                'patternOpacity' => '.6', 'vignette' => '',
                'ink' => '#4A2530',
                'foil' => 'linear-gradient(100deg,#9E2C4A 0%,#F0B7C4 30%,#C74A66 52%,#F0B7C4 72%,#9E2C4A 100%)',
                'tilePaper' => 'linear-gradient(180deg,#FFFFFF 0%,#FBEFF2 100%)',
                'tileBorder' => 'rgba(158,44,74,.24)',
                'tileBar' => 'linear-gradient(90deg,#9E2C4A,#F0B7C4 50%,#9E2C4A)',
                'tileOrnament' => "url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='92' height='92' viewBox='0 0 92 92'%3E%3Cg fill='none' stroke='%239E2C4A' stroke-width='2'%3E%3Cpath d='M46 78 C 20 56, 10 40, 18 26 q 12 -18 28 2 q 16 -20 28 -2 q 8 14 -18 40 z'/%3E%3C/g%3E%3C/svg%3E\")",
                'ornamentOpacity' => '.16', 'ornamentHover' => '.3',
                'tileShadow' => '0 2px 4px rgba(74,37,48,.10), 0 16px 40px rgba(74,37,48,.14)',
                'tileShadowHover' => '0 6px 12px rgba(74,37,48,.14), 0 26px 58px rgba(199,74,102,.24)',
                'tileTitle' => '#9E2C4A', 'tileDesc' => '#7A5A63',
                'cardBg' => 'rgba(255,255,255,.97)', 'cardBorder' => 'rgba(158,44,74,.2)',
                'cardShadow' => '0 18px 46px rgba(74,37,48,.16)',
                'btn' => 'linear-gradient(180deg,#C74A66,#9E2C4A)',
                'doc' => ['paper' => '#FFFAFB', 'paper-deep' => '#FBEDF0', 'line' => '#EBD3D9',
                    'forest' => '#9E2C4A', 'leaf' => '#C74A66', 'moss' => '#A8828C',
                    'pollen' => '#C08A2A', 'pollen-bg' => '#FBF2DE',
                    'ink' => '#3F2129', 'ink-soft' => '#6E5158'],
                'heroBg' => 'linear-gradient(155deg,#5E1327 0%,#9E2C4A 55%,#C74A66 100%)',
                'heroRule' => 'linear-gradient(90deg,#9E2C4A,#F0B7C4 50%,#9E2C4A)',
                'heroBrand' => '#F5C9D3',
            ],

            // 🇧🇪 FÊTE NATIONALE — Noir / or / rouge. Graphique et sobre.
            'fete_nationale' => [
                'fx' => ['confetti', ['#1A1A1A', '#E9C766', '#E30613'], 24],
                'bg' => 'radial-gradient(900px 500px at 50% -8%, rgba(233,199,102,.34), transparent 60%),'
                    . 'linear-gradient(172deg,#FFFDF6 0%,#FBF3DC 60%,#F3E6BE 100%)',
                'pattern' => "url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='180' height='180' viewBox='0 0 180 180'%3E%3Cg stroke='%231A1A1A' stroke-opacity='0.07' stroke-width='10'%3E%3Cpath d='M-20 200 L200 -20' /%3E%3C/g%3E%3Cg stroke='%23E30613' stroke-opacity='0.06' stroke-width='10'%3E%3Cpath d='M-20 240 L240 -20' /%3E%3C/g%3E%3C/svg%3E\") 0 0/180px 180px",
                'patternOpacity' => '.7', 'vignette' => '',
                'ink' => '#2A2510',
                'foil' => 'linear-gradient(100deg,#8A6A10 0%,#F6E3A4 30%,#D4AF37 52%,#F6E3A4 72%,#8A6A10 100%)',
                'tilePaper' => 'linear-gradient(180deg,#FFFFFF 0%,#FBF3E0 100%)',
                'tileBorder' => 'rgba(26,26,26,.18)',
                'tileBar' => 'linear-gradient(90deg,#1A1A1A 33%,#E9C766 33% 66%,#E30613 66%)',
                'tileOrnament' => '', 'tileShadow' => '0 2px 4px rgba(30,26,10,.10), 0 16px 40px rgba(30,26,10,.14)',
                'tileShadowHover' => '0 6px 12px rgba(30,26,10,.14), 0 26px 58px rgba(212,175,55,.24)',
                'tileTitle' => '#1A1A1A', 'tileDesc' => '#6A6350',
                'cardBg' => 'rgba(255,255,255,.97)', 'cardBorder' => 'rgba(26,26,26,.14)',
                'cardShadow' => '0 18px 46px rgba(30,26,10,.16)',
                'btn' => 'linear-gradient(180deg,#2C2C2C,#151515)',
                'doc' => ['paper' => '#FFFDF6', 'paper-deep' => '#F9F1DC', 'line' => '#E6DBBB',
                    'forest' => '#1A1A1A', 'leaf' => '#B8901F', 'moss' => '#8A8064',
                    'pollen' => '#C0392B', 'pollen-bg' => '#FBE9E7',
                    'ink' => '#25220F', 'ink-soft' => '#5E5A45'],
                'heroBg' => 'linear-gradient(120deg,#111111 0%,#111111 33%,#C79B1E 33%,#C79B1E 66%,#B0141F 66%)',
                'heroRule' => 'linear-gradient(90deg,#1A1A1A 33%,#E9C766 33% 66%,#E30613 66%)',
                'heroBrand' => '#F6E3A4',
            ],

            // 🌺 11 NOVEMBRE — Gris pierre, coquelicot. AUCUN mouvement : recueillement.
            'armistice' => [
                'fx' => ['none', [], 0],
                'bg' => 'radial-gradient(900px 520px at 50% -6%, rgba(139,26,26,.10), transparent 60%),'
                    . 'linear-gradient(174deg,#F6F4F2 0%,#EBE7E4 60%,#DFDAD6 100%)',
                'pattern' => "url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='240' height='240' viewBox='0 0 240 240'%3E%3Cg fill='none' stroke='%238B1A1A' stroke-opacity='0.07' stroke-width='1.4'%3E%3Cpath d='M60 180 q -20 -30 6 -46 q 18 -12 26 6 q 8 -20 26 -6 q 26 16 6 46 q -18 26 -32 40 q -14 -14 -32 -40 z'/%3E%3C/g%3E%3C/svg%3E\") 0 0/240px 240px",
                'patternOpacity' => '.6', 'vignette' => '',
                'ink' => '#3A3532',
                'foil' => 'linear-gradient(100deg,#6E1414 0%,#C97A7A 32%,#8B1A1A 52%,#C97A7A 72%,#6E1414 100%)',
                'tilePaper' => 'linear-gradient(180deg,#FFFFFF 0%,#F2EFEC 100%)',
                'tileBorder' => 'rgba(74,74,74,.2)',
                'tileBar' => 'linear-gradient(90deg,#4A4A4A,#8B1A1A 50%,#4A4A4A)',
                'tileOrnament' => "url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='92' height='92' viewBox='0 0 92 92'%3E%3Cg fill='none' stroke='%238B1A1A' stroke-width='2'%3E%3Cpath d='M46 74 q -22 -26 -6 -44 q 12 -14 24 0 q 16 -14 26 4 q 12 22 -14 40 z'/%3E%3Ccircle cx='46' cy='50' r='5'/%3E%3C/g%3E%3C/svg%3E\")",
                'ornamentOpacity' => '.14', 'ornamentHover' => '.24',
                'tileShadow' => '0 2px 4px rgba(40,36,34,.08), 0 14px 34px rgba(40,36,34,.12)',
                'tileShadowHover' => '0 5px 10px rgba(40,36,34,.12), 0 22px 48px rgba(139,26,26,.16)',
                'tileTitle' => '#3A3532', 'tileDesc' => '#6E6863',
                'cardBg' => 'rgba(255,255,255,.97)', 'cardBorder' => 'rgba(74,74,74,.16)',
                'cardShadow' => '0 16px 40px rgba(40,36,34,.14)',
                'btn' => 'linear-gradient(180deg,#5A5A5A,#3E3E3E)',
                'doc' => ['paper' => '#FAF8F6', 'paper-deep' => '#EFEBE8', 'line' => '#DCD6D1',
                    'forest' => '#3A3532', 'leaf' => '#8B1A1A', 'moss' => '#8A827C',
                    'pollen' => '#8B1A1A', 'pollen-bg' => '#F7EBEB',
                    'ink' => '#302B28', 'ink-soft' => '#635C57'],
                'heroBg' => 'linear-gradient(155deg,#2E2A27 0%,#4A4340 55%,#6B403E 100%)',
                'heroRule' => 'linear-gradient(90deg,#4A4A4A,#8B1A1A 50%,#4A4A4A)',
                'heroBrand' => '#E3C9C9',
            ],

            // 🎁 SAINT-NICOLAS — Rouge chaud, dorures, papier cadeau discret.
            'saint_nicolas' => [
                'fx' => ['drift', ['#F6E3A4', '#FFFFFF', '#E8B4AC'], 24],
                'bg' => 'radial-gradient(900px 520px at 24% -6%, rgba(233,199,102,.4), transparent 62%),'
                    . 'radial-gradient(780px 540px at 86% 6%, rgba(192,57,43,.20), transparent 64%),'
                    . 'linear-gradient(172deg,#FFFBF5 0%,#FBEFE7 55%,#F6E1D6 100%)',
                'pattern' => "url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='160' height='160' viewBox='0 0 160 160'%3E%3Cg fill='none' stroke='%23C0392B' stroke-opacity='0.09' stroke-width='1.4'%3E%3Cpath d='M0 40 H160 M0 120 H160 M40 0 V160 M120 0 V160'/%3E%3C/g%3E%3Cg fill='%23D4AF37' fill-opacity='0.10'%3E%3Ccircle cx='40' cy='40' r='3'/%3E%3Ccircle cx='120' cy='120' r='3'/%3E%3C/g%3E%3C/svg%3E\") 0 0/160px 160px",
                'patternOpacity' => '.7', 'vignette' => '',
                'ink' => '#4A2A24',
                'foil' => 'linear-gradient(100deg,#9C7A16 0%,#F6E3A4 30%,#D4AF37 52%,#F6E3A4 72%,#9C7A16 100%)',
                'tilePaper' => 'linear-gradient(180deg,#FFFFFF 0%,#FBF0E8 100%)',
                'tileBorder' => 'rgba(192,57,43,.24)',
                'tileBar' => 'linear-gradient(90deg,#C0392B,#F6E3A4 50%,#C0392B)',
                'tileOrnament' => "url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='92' height='92' viewBox='0 0 92 92'%3E%3Cg fill='none' stroke='%23C0392B' stroke-width='2'%3E%3Crect x='18' y='38' width='56' height='38' rx='3'/%3E%3Cpath d='M18 50 h56 M46 38 v38'/%3E%3Cpath d='M46 38 q -16 -18 -4 -22 q 10 -4 4 22 z M46 38 q 16 -18 4 -22 q -10 -4 -4 22 z'/%3E%3C/g%3E%3C/svg%3E\")",
                'ornamentOpacity' => '.16', 'ornamentHover' => '.3',
                'tileShadow' => '0 2px 4px rgba(74,42,36,.10), 0 16px 40px rgba(74,42,36,.14)',
                'tileShadowHover' => '0 6px 12px rgba(74,42,36,.14), 0 26px 58px rgba(212,175,55,.24)',
                'tileTitle' => '#A02D22', 'tileDesc' => '#7A5A52',
                'cardBg' => 'rgba(255,255,255,.97)', 'cardBorder' => 'rgba(192,57,43,.2)',
                'cardShadow' => '0 18px 46px rgba(74,42,36,.16)',
                'btn' => 'linear-gradient(180deg,#D0483A,#A02D22)',
                'doc' => ['paper' => '#FFFBF5', 'paper-deep' => '#FBEFE7', 'line' => '#EBD9CE',
                    'forest' => '#A02D22', 'leaf' => '#C0392B', 'moss' => '#A88880',
                    'pollen' => '#B8901F', 'pollen-bg' => '#FBF2DC',
                    'ink' => '#40241F', 'ink-soft' => '#6E524B'],
                'heroBg' => 'linear-gradient(155deg,#6E1810 0%,#A02D22 55%,#C0392B 100%)',
                'heroRule' => 'linear-gradient(90deg,#C0392B,#F6E3A4 50%,#C0392B)',
                'heroBrand' => '#F6E3A4',
            ],

            // ✨ NOUVEL AN — Noir, or, éclat de champagne.
            'nouvel_an' => [
                'fx' => ['motes', ['#D4AF37', '#FFFFFF'], 30],
                'bg' => 'radial-gradient(1000px 560px at 50% -10%, rgba(212,175,55,.24), transparent 60%),'
                    . 'radial-gradient(800px 600px at 14% 106%, rgba(212,175,55,.10), transparent 64%),'
                    . 'linear-gradient(172deg,#070604 0%,#14110A 48%,#1E1A10 100%)',
                'pattern' => "url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='260' height='260' viewBox='0 0 260 260'%3E%3Cg fill='none' stroke='%23D4AF37' stroke-opacity='0.10' stroke-width='1'%3E%3Cpath d='M60 20 v50 M35 45 h50'/%3E%3Cpath d='M200 160 v40 M180 180 h40'/%3E%3Cpath d='M130 110 v28 M116 124 h28'/%3E%3C/g%3E%3C/svg%3E\") 0 0/260px 260px",
                'patternOpacity' => '.6', 'vignette' => 'rgba(0,0,0,.55)',
                'ink' => '#F2ECDD',
                'foil' => 'linear-gradient(100deg,#8A6A10 0%,#FFF3C4 28%,#D4AF37 50%,#FFF3C4 72%,#8A6A10 100%)',
                'tilePaper' => 'linear-gradient(180deg,#FFFDF5 0%,#F5EDD9 100%)',
                'tileBorder' => 'rgba(212,175,55,.34)',
                'tileBar' => 'linear-gradient(90deg,#8A6A10,#FFF3C4 50%,#8A6A10)',
                'tileOrnament' => "url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='92' height='92' viewBox='0 0 92 92'%3E%3Cg fill='none' stroke='%231E1A10' stroke-width='2'%3E%3Cpath d='M46 14 v22 M46 56 v22 M14 46 h22 M56 46 h22'/%3E%3Cpath d='M26 26 l14 14 M66 26 l-14 14 M26 66 l14 -14 M66 66 l-14 -14'/%3E%3C/g%3E%3C/svg%3E\")",
                'ornamentOpacity' => '.12', 'ornamentHover' => '.24',
                'tileShadow' => '0 2px 4px rgba(0,0,0,.3), 0 16px 40px rgba(0,0,0,.42)',
                'tileShadowHover' => '0 6px 10px rgba(0,0,0,.32), 0 26px 58px rgba(212,175,55,.3)',
                'tileTitle' => '#1E1A10', 'tileDesc' => '#6B6350',
                'cardBg' => 'rgba(255,253,245,.97)', 'cardBorder' => 'rgba(212,175,55,.26)',
                'cardShadow' => '0 18px 48px rgba(0,0,0,.45)',
                'btn' => 'linear-gradient(180deg,#D4AF37,#A8871F)', 'btnInk' => '#1E1A10',
                'doc' => ['paper' => '#FFFDF5', 'paper-deep' => '#F7EFDB', 'line' => '#E7D9B4',
                    'forest' => '#1E1A10', 'leaf' => '#B8901F', 'moss' => '#8A8064',
                    'pollen' => '#A8871F', 'pollen-bg' => '#FBF2DA',
                    'ink' => '#25220F', 'ink-soft' => '#5E5A45'],
                'heroBg' => 'linear-gradient(155deg,#070604 0%,#1E1A10 52%,#4A3C12 100%)',
                'heroRule' => 'linear-gradient(90deg,#8A6A10,#FFF3C4 50%,#8A6A10)',
                'heroBrand' => '#FFF3C4',
            ],
        ];
    }
}

if (!function_exists('themeSkin')) {
    /**
     * Habillage complet d'un événement.
     * @return array|null ['css' => string, 'decor' => string] — null = pas d'habillage
     *                    (l'événement garde alors son thème simple : aucune régression).
     */
    function themeSkin($key)
    {
        $cat = themeSkinCatalog();
        $k = (string) $key;
        if (!isset($cat[$k])) {
            return null;
        }
        $c = $cat[$k];
        list($fxType, $fxColors, $fxCount) = $c['fx'];
        return [
            'css' => skinBuild($c),
            'decor' => skinParticles($fxType, $fxColors, $fxCount),
        ];
    }
}
