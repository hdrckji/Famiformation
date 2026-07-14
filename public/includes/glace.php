<?php
// ============================================================
// glace.php — le TICKET GLACE 🍦
//
// Deux occasions de se le voir offrir :
//   • il fait ≥ 30 °C sur SON site (météo Open-Meteo du lieu de travail)
//   • on est dimanche
//   • les deux le même jour → un seul ticket, et le message signale le doublé.
//
// EMPLACEMENT : dans le RUBAN, avec les autres boutons (🔔 ⚙️ 🏠). Il est donc
// présent sur TOUTES les pages, et surtout il ne prend pas la place du widget :
// la date et la météo restent visibles, intactes.
//
// AU CLIC : une petite BULLE s'ouvre sous le ticket. Pas une fenêtre plein écran —
// pour une glace offerte, bloquer tout l'écran était disproportionné.
//
// Autonome : SVG + CSS + JS + textes, tout est ici. Pour le retirer : supprimer ce
// fichier et les appels à glaceRuban() dans index.php et includes/topbar.php.
// ============================================================

if (!function_exists('glaceSeuilChaud')) {
    /** À partir de combien de degrés la glace est offerte. */
    function glaceSeuilChaud()
    {
        return 30;
    }
}

if (!function_exists('glaceTempDuSite')) {
    /**
     * Température du SITE de l'utilisateur (son lieu de travail), pas d'ailleurs.
     * Réutilise la météo du widget — déjà mise en cache 30 min en base, donc
     * l'afficher dans le ruban de chaque page ne coûte aucun appel réseau de plus.
     * @return int|null null si aucun site ou météo indisponible.
     */
    function glaceTempDuSite(PDO $db)
    {
        if (!function_exists('userSite') || !function_exists('widgetWeather')) {
            return null;
        }
        $site = userSite($db, $_SESSION['user_id'] ?? null);
        if (!$site) {
            return null;
        }
        $w = widgetWeather($db, $site);
        return (is_array($w) && isset($w['temp'])) ? (int) $w['temp'] : null;
    }
}

if (!function_exists('glaceRaisons')) {
    /**
     * Pourquoi (et si) le ticket est offert aujourd'hui.
     * @return array liste parmi 'chaud', 'dimanche' — vide si aucune occasion.
     */
    function glaceRaisons($temp = null)
    {
        $r = [];
        if ($temp !== null && (int) $temp >= glaceSeuilChaud()) {
            $r[] = 'chaud';
        }
        if (date('w') === '0') { // 0 = dimanche
            $r[] = 'dimanche';
        }
        return $r;
    }
}

if (!function_exists('glaceTicketSvg')) {
    /** Le ticket, dessiné. Contours en BLEU (demande de Jimmy). */
    function glaceTicketSvg()
    {
        return '<svg viewBox="0 0 170 104" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">'
            . '<defs><linearGradient id="glaceTg" x1="0" y1="0" x2="1" y2="1">'
            . '<stop offset="0" stop-color="#4bb063"></stop><stop offset="1" stop-color="#1f5c34"></stop></linearGradient></defs>'
            . '<rect x="2" y="2" width="166" height="100" rx="12" fill="url(#glaceTg)" stroke="#1d4ed8" stroke-width="3"></rect>'
            . '<rect x="10" y="12" width="106" height="80" rx="7" fill="#fff" stroke="#1d4ed8" stroke-width="1.6"></rect>'
            . '<text x="24" y="52" font-family="Inter,Arial,sans-serif" font-size="34" font-weight="900" fill="#1c1c1c" font-style="italic">1x</text>'
            . '<text x="18" y="72" font-family="Inter,Arial,sans-serif" font-size="15" font-weight="900" fill="#1c1c1c" font-style="italic">GRATUIT</text>'
            . '<text x="22" y="88" font-family="Inter,Arial,sans-serif" font-size="15" font-weight="900" fill="#1c1c1c" font-style="italic">GRATIS</text>'
            . '<g>'
            . '<path d="M118 60 L152 60 L135 98 Z" fill="#e8a94a" stroke="#1d4ed8" stroke-width="1.5"></path>'
            . '<path d="M122 66 L148 66 M126 74 L144 74 M130 82 L140 82 M126 60 L138 92 M144 60 L133 92" stroke="#1d4ed8" stroke-width="1.2" opacity=".55"></path>'
            . '<path d="M116 58 C110 40 118 26 128 24 C126 12 146 8 150 20 C160 18 166 30 158 38 C166 44 158 58 150 56 Z" fill="#fdf6e6" stroke="#1d4ed8" stroke-width="1.6"></path>'
            . '<circle cx="130" cy="40" r="2.6" fill="#2a2a2a"></circle><circle cx="146" cy="40" r="2.6" fill="#2a2a2a"></circle>'
            . '<circle cx="126" cy="47" r="2.4" fill="#ff9d8a" opacity=".6"></circle><circle cx="150" cy="47" r="2.4" fill="#ff9d8a" opacity=".6"></circle>'
            . '<path d="M131 48 Q138 55 145 48" fill="none" stroke="#2a2a2a" stroke-width="2" stroke-linecap="round"></path>'
            . '<path d="M128 64 C124 60 128 54 133 57 C134 53 141 53 142 57 C147 54 151 60 147 64 C144 68 132 68 128 64z" fill="#e5533c" stroke="#1d4ed8" stroke-width="1.2"></path>'
            . '</g></svg>';
    }
}

if (!function_exists('glaceMessage')) {
    /**
     * UNE phrase, pas un paragraphe. C'est une glace offerte : le message doit se
     * lire d'un coup d'œil, sans qu'on ait à « lire ».
     * @return array ['titre', 'texte']
     */
    function glaceMessage(array $raisons, $temp = null)
    {
        $nl = (function_exists('currentLang') && currentLang() === 'nl');
        $chaud = in_array('chaud', $raisons, true);
        $dim = in_array('dimanche', $raisons, true);
        $t = (int) $temp;

        // Clin d'œil au métier : on est jardinier. Quand il fait chaud, on ARROSE —
        // alors autant arroser les équipes aussi. Le dimanche, même les cactus lèvent
        // le pied. Le message doit sentir la jardinerie, pas la note de service.
        if ($chaud && $dim) {
            return $nl
                ? ['titre' => $t . ' °C én zondag !', 'texte' => 'Zelfs de cactussen vragen om schaduw — dubbele reden voor je gratis ijsje. 😎']
                : ['titre' => $t . ' °C et dimanche !', 'texte' => 'Même les cactus réclament de l\'ombre — double raison de prendre ta glace gratuite. 😎'];
        }
        if ($chaud) {
            return $nl
                ? ['titre' => 'Het is ' . $t . ' °C !', 'texte' => 'Vanaf ' . glaceSeuilChaud() . ' °C geven we de planten water… en de ploeg verkoeling. Je ijsje is gratis. 🍦']
                : ['titre' => 'Il fait ' . $t . ' °C !', 'texte' => 'Dès ' . glaceSeuilChaud() . ' °C, on arrose les plantes… et on rafraîchit les équipes. Ta glace est offerte. 🍦'];
        }
        return $nl
            ? ['titre' => 'Het is zondag !', 'texte' => 'Zelfs de cactussen nemen het er vandaag van — je ijsje is gratis. 😊']
            : ['titre' => 'On est dimanche !', 'texte' => 'Même les cactus lèvent le pied aujourd\'hui — ta glace est offerte. 😊'];
    }
}

if (!function_exists('glaceRuban')) {
    /**
     * Le ticket, prêt à être posé dans un ruban, à côté des autres boutons.
     * Renvoie '' quand aucune occasion — donc l'appel est sans risque partout.
     */
    function glaceRuban(PDO $db)
    {
        $temp = glaceTempDuSite($db);
        $raisons = glaceRaisons($temp);
        if (empty($raisons)) {
            return '';
        }
        $m = glaceMessage($raisons, $temp);
        $titreAttr = htmlspecialchars($m['titre'], ENT_QUOTES, 'UTF-8');

        $h = '<div class="glace-wrap">'
            . '<button type="button" class="glace-btn" onclick="glaceToggle(event)" title="' . $titreAttr . '">'
            . glaceTicketSvg() . '</button>'
            . '<div class="glace-bulle" id="glaceBulle">'
            . '<div class="glace-bulle-t">🍦 ' . htmlspecialchars($m['titre']) . '</div>'
            . '<div class="glace-bulle-x">' . htmlspecialchars($m['texte']) . '</div>'
            . '</div></div>';

        $h .= '<style>
        .glace-wrap { position:relative; display:inline-flex; align-items:center; }

        /* Même pastille blanche arrondie que les autres boutons du ruban : le ticket
           s\'y intègre au lieu de flotter comme un corps étranger. */
        .glace-btn { background:rgba(255,255,255,0.9); border:none; border-radius:30px; cursor:pointer;
            padding:6px 12px; height:44px; box-sizing:border-box; display:inline-flex; align-items:center;
            box-shadow:0 4px 10px rgba(0,0,0,0.1); transition:background .2s, transform .2s; }
        .glace-btn:hover { background:#fff; transform:scale(1.05); }
        .glace-btn svg { width:48px; height:29px; display:block;
            animation:glaceWiggle 3.4s ease-in-out infinite; transform-origin:50% 60%; }

        /* Il GIGOTE par à-coups : une animation en boucle continue devient un papier
           peint qu\'on ne voit plus. Là, il reste sage puis a un frisson. */
        @keyframes glaceWiggle {
            0%, 64%, 100% { transform:rotate(0) scale(1); }
            68% { transform:rotate(-9deg) scale(1.07); }
            72% { transform:rotate(7deg) scale(1.07); }
            76% { transform:rotate(-5deg) scale(1.04); }
            80% { transform:rotate(3deg) scale(1.01); }
        }
        @media (prefers-reduced-motion: reduce) { .glace-btn svg { animation:none; } }

        /* La BULLE : elle sort du ticket, elle ne bloque pas la page. */
        .glace-bulle { position:absolute; top:calc(100% + 12px); right:0; z-index:9999;
            width:250px; background:#fff; border-radius:14px; padding:13px 15px; text-align:left;
            border:2px solid #1d4ed8; box-shadow:0 12px 32px rgba(12,30,60,.26);
            opacity:0; visibility:hidden; transform:translateY(-8px) scale(.94);
            transition:opacity .18s, transform .18s, visibility .18s; }
        .glace-wrap.open .glace-bulle { opacity:1; visibility:visible; transform:translateY(0) scale(1); }
        .glace-bulle::before { content:""; position:absolute; top:-9px; right:24px; width:14px; height:14px;
            background:#fff; border-left:2px solid #1d4ed8; border-top:2px solid #1d4ed8; transform:rotate(45deg); }
        .glace-bulle-t { font-weight:800; color:#1d4ed8; font-size:.95rem; margin-bottom:3px; }
        .glace-bulle-x { color:#44566b; font-size:.86rem; line-height:1.45; }
        </style>';

        $h .= '<script>
        function glaceToggle(e){ e.stopPropagation();
            var w = e.currentTarget.parentNode; w.classList.toggle("open"); }
        document.addEventListener("click", function(){
            var w = document.querySelector(".glace-wrap.open"); if (w) { w.classList.remove("open"); } });
        document.addEventListener("keydown", function(e){ if (e.key === "Escape") {
            var w = document.querySelector(".glace-wrap.open"); if (w) { w.classList.remove("open"); } } });
        </script>';

        return $h;
    }
}
