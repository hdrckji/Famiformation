<?php
// ============================================================
// theme_skins.php — HABILLAGES d'événement (« skins »).
//
// POURQUOI (retour de Jimmy : « les anciens thèmes étaient bidons ») :
// jusqu'ici un thème = une couleur d'accent + des emojis qui tombent. C'est ce qui
// faisait « pauvre ». Un habillage, ici, c'est une VRAIE direction artistique :
// fond composé en plusieurs couches, ornements SVG dessinés, typographie travaillée,
// tuiles retravaillées, et surtout une AMBIANCE (mouvement lent et discret) au lieu
// d'une pluie d'emojis.
//
// PRINCIPE D'ARCHITECTURE (important) :
//   Un habillage n'est PAS une page HTML. C'est une PEAU (CSS + décor) posée sur la
//   structure existante. Une seule structure de site, N habillages :
//     - on ajoute un module → il est joli dans TOUS les thèmes, sans rien refaire ;
//     - on corrige un bug → une seule fois.
//   Générer une page HTML par événement aurait dupliqué tout le site (×10) : le coût
//   n'aurait pas été le poids (négligeable) mais la MAINTENANCE.
//
// LA PAGE DE CONTENU (le guide) EST HABILLÉE AUSSI :
//   .fami-doc est bâtie sur des variables CSS (--paper, --forest, --pollen…).
//   On les REDÉFINIT → le guide prend l'ambiance de l'événement sans qu'on touche à
//   sa structure ni à sa mise en page. Rien n'est dupliqué, rien ne peut casser.
//
// Poids : ~10 Ko par habillage, et UN SEUL est chargé à la fois (celui du jour).
// C'est plus léger qu'une seule photo de fond.
// ============================================================

if (!function_exists('skinMotes')) {
    /**
     * Ambiance « poussière » : des particules FINES et LENTES (pas des emojis).
     * C'est ce qui fait la différence entre « décoré » et « chargé » : peu d'éléments,
     * très transparents, mouvement lent — l'œil le perçoit sans être agressé.
     */
    function skinMotes($count, $class)
    {
        $h = '<div class="skin-fx" aria-hidden="true">';
        for ($i = 0; $i < $count; $i++) {
            // Valeurs figées côté serveur : pas de JS, donc aucun coût au chargement.
            $left = mt_rand(0, 1000) / 10;          // %
            $size = mt_rand(3, 9);                  // px
            $dur = mt_rand(160, 340) / 10;          // s (lent = élégant)
            $delay = mt_rand(0, 260) / 10;          // s
            $drift = mt_rand(-60, 60);              // px de dérive latérale
            $op = mt_rand(18, 55) / 100;
            $h .= '<span class="' . $class . '" style="'
                . 'left:' . $left . '%;'
                . 'width:' . $size . 'px; height:' . $size . 'px;'
                . 'animation-duration:' . $dur . 's;'
                . 'animation-delay:-' . $delay . 's;'
                . '--drift:' . $drift . 'px;'
                . 'opacity:' . $op . ';'
                . '"></span>';
        }
        return $h . '</div>';
    }
}

if (!function_exists('skinConfetti')) {
    /**
     * Confettis GÉOMÉTRIQUES (fins rectangles + disques) dans une palette CHOISIE.
     * C'est la clé du « chic » : des emojis 🎉 font enfantin ; des formes simples
     * dans 4 couleurs harmonisées font graphique.
     */
    function skinConfetti($count)
    {
        $colors = ['#C9971B', '#E9C766', '#E8927C', '#7FB09A', '#8A6BA1', '#FFFFFF'];
        $h = '<div class="skin-fx" aria-hidden="true">';
        for ($i = 0; $i < $count; $i++) {
            $c = $colors[$i % count($colors)];
            $left = mt_rand(0, 1000) / 10;
            $w = mt_rand(4, 9);
            $isDisc = ($i % 4 === 0);
            $hgt = $isDisc ? $w : mt_rand(9, 16);
            $dur = mt_rand(90, 190) / 10;
            $delay = mt_rand(0, 160) / 10;
            $spin = mt_rand(240, 900);
            $drift = mt_rand(-90, 90);
            $h .= '<span class="skin-conf" style="'
                . 'left:' . $left . '%;'
                . 'width:' . $w . 'px; height:' . $hgt . 'px;'
                . 'background:' . $c . ';'
                . ($isDisc ? 'border-radius:50%;' : 'border-radius:1px;')
                . 'animation-duration:' . $dur . 's;'
                . 'animation-delay:-' . $delay . 's;'
                . '--spin:' . $spin . 'deg;'
                . '--drift:' . $drift . 'px;'
                . '"></span>';
        }
        return $h . '</div>';
    }
}

if (!function_exists('skinBase')) {
    /** Règles communes à tous les habillages (structure du décor, lisibilité). */
    function skinBase()
    {
        return '
        /* Le décor ne doit JAMAIS gêner : il ne capte aucun clic et reste derrière. */
        .skin-fx{ position:fixed; inset:0; top:0; left:0; right:0; bottom:0; pointer-events:none; overflow:hidden; z-index:0; }
        .skin-fx > span{ position:absolute; top:-8%; will-change:transform; }
        /* Le contenu passe au-dessus du décor. */
        body > *:not(.skin-fx){ position:relative; z-index:1; }
        @media (prefers-reduced-motion: reduce){ .skin-fx{ display:none; } }
        ';
    }
}

if (!function_exists('themeSkin')) {
    /**
     * Habillage complet d'un événement.
     * @return array|null ['css' => string, 'decor' => string]
     */
    function themeSkin($key)
    {
        switch ((string) $key) {

            // ======================================================
            // 🌿 BIENVENUE — « Botanique & Or »
            // Direction : vert profond Famiflora, or chaud, papier crème.
            // Ambiance : poussière d'or qui monte lentement. Sobre, chaleureux, premium.
            // ======================================================
            case 'bienvenue':
                $css = skinBase() . '
                :root{ --sk-gold:#D4AF37; --sk-gold-lt:#F3E3A6; --sk-gold-dk:#A87C15; --sk-green:#1B4429; --sk-green-dp:#0E2717; --sk-cream:#FBF8EF; }

                /* FOND — 4 couches : halo doré, halo vert, dégradé profond, motif botanique.
                   C\'est cette superposition qui donne de la PROFONDEUR (un dégradé seul fait plat). */
                body{
                    background:
                        radial-gradient(1100px 620px at 50% -12%, rgba(212,175,55,.16), transparent 62%),
                        radial-gradient(900px 700px at 8% 108%, rgba(62,142,78,.22), transparent 66%),
                        linear-gradient(168deg, #0C2215 0%, #143620 46%, #1E4D2B 100%) fixed !important;
                    color:#EAF1E6;
                }
                /* Motif botanique gravé, très discret (texture, pas décor). */
                body::before{
                    content:""; position:fixed; inset:0; top:0; left:0; right:0; bottom:0; pointer-events:none; z-index:0; opacity:.5;
                    background-image:url("data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'320\' height=\'320\' viewBox=\'0 0 320 320\'%3E%3Cg fill=\'none\' stroke=\'%23D4AF37\' stroke-opacity=\'0.10\' stroke-width=\'1.2\'%3E%3Cpath d=\'M36 292 C 56 214, 56 156, 84 88\'/%3E%3Cpath d=\'M58 228 q 32 -22 38 -56 q -38 10 -38 56\'/%3E%3Cpath d=\'M66 184 q -34 -14 -44 -48 q 38 4 44 48\'/%3E%3Cpath d=\'M242 310 C 254 242, 250 194, 272 136\'/%3E%3Cpath d=\'M254 252 q 28 -18 34 -48 q -34 8 -34 48\'/%3E%3Cpath d=\'M260 208 q -30 -12 -38 -42 q 34 4 38 42\'/%3E%3Cpath d=\'M164 58 q 26 -32 64 -34 q -8 38 -48 44 q -12 2 -16 -10 z\'/%3E%3Cpath d=\'M144 64 q -26 -30 -60 -30 q 8 34 44 40 q 12 2 16 -10 z\'/%3E%3C/g%3E%3C/svg%3E");
                    background-size:320px 320px;
                }
                /* Vignette : concentre le regard, donne du volume. */
                body::after{
                    content:""; position:fixed; inset:0; top:0; left:0; right:0; bottom:0; pointer-events:none; z-index:0;
                    background:radial-gradient(120% 80% at 50% 40%, transparent 55%, rgba(4,14,8,.45) 100%);
                }

                /* AMBIANCE — poussière d\'or qui MONTE (à contre-courant : plus élégant qu\'une chute). */
                .skin-fx .sk-mote{
                    border-radius:50%;
                    background:radial-gradient(circle, #F6E7B0 0%, #D4AF37 55%, rgba(212,175,55,0) 72%);
                    top:auto; bottom:-6%;
                    animation-name:skRise; animation-timing-function:linear; animation-iteration-count:infinite;
                    filter:blur(.3px);
                }
                @keyframes skRise{
                    from{ transform:translate3d(0,0,0) scale(.85); }
                    to  { transform:translate3d(var(--drift),-116vh,0) scale(1.15); }
                }

                /* TITRES — or bruni qui miroite lentement (pas un néon clignotant). */
                h1{
                    background:linear-gradient(100deg,#A87C15 0%,#F3E3A6 28%,#D4AF37 50%,#F3E3A6 72%,#A87C15 100%);
                    background-size:220% auto; -webkit-background-clip:text; background-clip:text;
                    -webkit-text-fill-color:transparent; color:transparent;
                    animation:skFoil 9s linear infinite;
                    background-color:transparent !important; box-shadow:none !important;
                }
                @keyframes skFoil{ to{ background-position:220% center; } }

                /* TUILES — papier crème, filet d\'or en tête, ornement en coin, relief au survol. */
                .tile{
                    background:linear-gradient(180deg,#FDFBF3 0%,#F4EFDF 100%) !important;
                    border:1px solid rgba(168,124,21,.28) !important;
                    box-shadow:0 2px 4px rgba(4,14,8,.18), 0 16px 40px rgba(4,14,8,.28) !important;
                    overflow:hidden;
                }
                .tile::before{
                    content:""; position:absolute; top:0; left:0; right:0; height:3px;
                    background:linear-gradient(90deg,#A87C15,#F3E3A6 45%,#D4AF37 55%,#A87C15);
                }
                /* Feuille gravée en coin : un détail dessiné, pas un emoji collé. */
                .tile::after{
                    content:""; position:absolute; right:-12px; bottom:-14px; width:92px; height:92px; opacity:.13;
                    background:url("data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'92\' height=\'92\' viewBox=\'0 0 92 92\'%3E%3Cg fill=\'none\' stroke=\'%231B4429\' stroke-width=\'2\'%3E%3Cpath d=\'M14 84 C 34 62, 46 44, 58 14\'/%3E%3Cpath d=\'M34 60 q 26 -16 30 -44 q -30 8 -30 44\'/%3E%3Cpath d=\'M40 42 q -26 -10 -32 -36 q 28 4 32 36\'/%3E%3C/g%3E%3C/svg%3E") no-repeat center/contain;
                    transition:opacity .3s, transform .3s;
                }
                .tile:hover{ transform:translateY(-8px); box-shadow:0 6px 10px rgba(4,14,8,.2), 0 26px 58px rgba(212,175,55,.24) !important; }
                .tile:hover::after{ opacity:.24; transform:rotate(-8deg) scale(1.06); }
                .tile-title{ color:#1B4429 !important; }
                .tile-desc{ color:#5C6B54 !important; }

                /* Cartes / boutons : cohérents avec la même grammaire. */
                .content-card{
                    background:rgba(251,248,239,.97) !important;
                    border:1px solid rgba(168,124,21,.22) !important;
                    box-shadow:0 18px 48px rgba(4,14,8,.3) !important;
                }
                .btn-create, .btn-primary{ background:linear-gradient(180deg,#26623A,#1B4429) !important; box-shadow:0 2px 0 rgba(212,175,55,.5) inset; }

                /* ---- LE GUIDE (.fami-doc) — habillé PAR SES VARIABLES, structure intacte. ---- */
                .fami-doc{
                    --paper:#FBF8EF; --paper-deep:#F3EDDC; --line:#E3D7B6;
                    --forest:#1B4429; --leaf:#3E8E4E; --moss:#8A7A3F;
                    --pollen:#A87C15; --pollen-bg:#FBF2DC;
                    --shadow:0 1px 2px rgba(30,45,25,.07), 0 10px 34px rgba(30,45,25,.10);
                }
                .fami-doc .hero{ background:linear-gradient(155deg,#0E2717 0%,#1B4429 55%,#26623A 100%) !important; }
                /* Filet doré sous le bandeau : la signature du thème, reprise du haut en bas. */
                .fami-doc .hero::after{
                    content:""; position:absolute; left:0; right:0; bottom:0; height:3px;
                    background:linear-gradient(90deg,#A87C15,#F3E3A6 50%,#A87C15);
                }
                .fami-doc .hero__brand{ color:#F3E3A6 !important; }
                .fami-doc .section__title{ color:#1B4429 !important; }
                .fami-doc .section__eyebrow{ color:#A87C15 !important; }
                ';
                return ['css' => $css, 'decor' => skinMotes(26, 'sk-mote')];

            // ======================================================
            // 🎂 ANNIVERSAIRE — « Champagne & Confettis »
            // Direction : champagne crème, or bruni, confettis géométriques choisis.
            // Festif MAIS chic : on évite le côté « fête d\'enfant ».
            // ======================================================
            case 'anniversaire':
                $css = skinBase() . '
                :root{ --sk-gold:#C9971B; --sk-gold-lt:#E9C766; --sk-cream:#FFFCF4; }

                /* FOND — halos chauds (bokeh) sur crème : lumineux, pas criard. */
                body{
                    background:
                        radial-gradient(900px 520px at 18% -6%, rgba(233,199,102,.42), transparent 62%),
                        radial-gradient(760px 520px at 88% 6%, rgba(232,146,124,.24), transparent 62%),
                        radial-gradient(900px 640px at 50% 112%, rgba(127,176,154,.20), transparent 66%),
                        linear-gradient(172deg, #FFFDF7 0%, #FBF2DC 55%, #F5E7C6 100%) fixed !important;
                    color:#3A2E1A;
                }
                /* Fines guirlandes dessinées en haut : un ornement, pas un emoji. */
                body::before{
                    content:""; position:fixed; top:0; left:0; right:0; height:150px; pointer-events:none; z-index:0; opacity:.5;
                    background:url("data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'600\' height=\'150\' viewBox=\'0 0 600 150\'%3E%3Cg fill=\'none\' stroke=\'%23C9971B\' stroke-opacity=\'0.35\' stroke-width=\'1.6\'%3E%3Cpath d=\'M0 18 Q 75 66 150 18 T 300 18 T 450 18 T 600 18\'/%3E%3Cpath d=\'M0 40 Q 100 96 200 40 T 400 40 T 600 40\' stroke-opacity=\'0.18\'/%3E%3C/g%3E%3Cg fill=\'%23C9971B\' fill-opacity=\'0.30\'%3E%3Ccircle cx=\'75\' cy=\'44\' r=\'3\'/%3E%3Ccircle cx=\'225\' cy=\'44\' r=\'3\'/%3E%3Ccircle cx=\'375\' cy=\'44\' r=\'3\'/%3E%3Ccircle cx=\'525\' cy=\'44\' r=\'3\'/%3E%3C/g%3E%3C/svg%3E") repeat-x top center/600px 150px;
                }

                /* AMBIANCE — confettis géométriques : chute lente, rotation, dérive. */
                .skin-fx .skin-conf{
                    animation-name:skFall; animation-timing-function:linear; animation-iteration-count:infinite;
                    opacity:.82;
                }
                @keyframes skFall{
                    from{ transform:translate3d(0,-10vh,0) rotate(0deg); }
                    to  { transform:translate3d(var(--drift),112vh,0) rotate(var(--spin)); }
                }

                /* TITRES — feuille d\'or. */
                h1{
                    background:linear-gradient(100deg,#A87C15 0%,#F6E3A4 30%,#C9971B 50%,#F6E3A4 70%,#A87C15 100%);
                    background-size:220% auto; -webkit-background-clip:text; background-clip:text;
                    -webkit-text-fill-color:transparent; color:transparent;
                    animation:skFoil 9s linear infinite;
                    background-color:transparent !important; box-shadow:none !important;
                }
                @keyframes skFoil{ to{ background-position:220% center; } }

                /* TUILES — carte crème, liseré doré, ruban de confettis en coin. */
                .tile{
                    background:linear-gradient(180deg,#FFFDF7 0%,#FBF3E2 100%) !important;
                    border:1px solid rgba(201,151,27,.3) !important;
                    box-shadow:0 2px 4px rgba(90,66,20,.10), 0 16px 40px rgba(90,66,20,.14) !important;
                    overflow:hidden;
                }
                .tile::before{
                    content:""; position:absolute; top:0; left:0; right:0; height:3px;
                    background:linear-gradient(90deg,#C9971B,#F6E3A4 40%,#E8927C 62%,#7FB09A 82%,#C9971B);
                }
                .tile::after{
                    content:""; position:absolute; right:-8px; top:-8px; width:78px; height:78px; opacity:.5;
                    background:url("data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'78\' height=\'78\' viewBox=\'0 0 78 78\'%3E%3Cg%3E%3Crect x=\'12\' y=\'22\' width=\'4\' height=\'9\' rx=\'1\' fill=\'%23E8927C\' transform=\'rotate(24 14 26)\'/%3E%3Crect x=\'34\' y=\'10\' width=\'4\' height=\'9\' rx=\'1\' fill=\'%237FB09A\' transform=\'rotate(-32 36 14)\'/%3E%3Crect x=\'56\' y=\'30\' width=\'4\' height=\'9\' rx=\'1\' fill=\'%23C9971B\' transform=\'rotate(14 58 34)\'/%3E%3Ccircle cx=\'26\' cy=\'44\' r=\'2.6\' fill=\'%238A6BA1\'/%3E%3Ccircle cx=\'50\' cy=\'16\' r=\'2.6\' fill=\'%23E9C766\'/%3E%3Ccircle cx=\'62\' cy=\'52\' r=\'2.2\' fill=\'%23E8927C\'/%3E%3C/g%3E%3C/svg%3E") no-repeat center/contain;
                    transition:transform .35s;
                }
                .tile:hover{ transform:translateY(-8px); box-shadow:0 6px 12px rgba(90,66,20,.14), 0 26px 58px rgba(201,151,27,.26) !important; }
                .tile:hover::after{ transform:rotate(10deg) scale(1.08); }
                .tile-title{ color:#8A5A00 !important; }
                .tile-desc{ color:#6B5B3E !important; }

                .content-card{
                    background:rgba(255,253,247,.97) !important;
                    border:1px solid rgba(201,151,27,.24) !important;
                    box-shadow:0 18px 46px rgba(90,66,20,.16) !important;
                }
                .btn-create, .btn-primary{ background:linear-gradient(180deg,#D8A62A,#B9860F) !important; color:#2B2007 !important; }

                /* ---- LE GUIDE — mêmes couleurs, même signature dorée. ---- */
                .fami-doc{
                    --paper:#FFFCF4; --paper-deep:#FBF2DC; --line:#EBDCB6;
                    --forest:#8A5A00; --leaf:#C9971B; --moss:#B08A3A;
                    --pollen:#C9971B; --pollen-bg:#FCF3DC;
                    --ink:#3A2E1A; --ink-soft:#6B5B3E;
                    --shadow:0 1px 2px rgba(90,66,20,.08), 0 10px 34px rgba(90,66,20,.12);
                }
                .fami-doc .hero{ background:linear-gradient(155deg,#7A4E06 0%,#A9741A 52%,#C9971B 100%) !important; }
                .fami-doc .hero::after{
                    content:""; position:absolute; left:0; right:0; bottom:0; height:3px;
                    background:linear-gradient(90deg,#C9971B,#F6E3A4 40%,#E8927C 62%,#7FB09A 82%,#C9971B);
                }
                .fami-doc .hero__brand{ color:#F6E3A4 !important; }
                .fami-doc .section__title{ color:#8A5A00 !important; }
                .fami-doc .section__eyebrow{ color:#C9971B !important; }
                ';
                return ['css' => $css, 'decor' => skinConfetti(30)];
        }

        return null; // pas encore d'habillage pour cet événement → thème simple d'origine
    }
}
