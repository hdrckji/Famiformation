<?php
// ============================================================
// glace.php — le TICKET GLACE 🍦
//
// Deux occasions de se le voir offrir, et le ticket se pose dans le COIN du widget
// qui correspond à sa raison — on comprend sans explication :
//   • il fait ≥ 30 °C  → ticket en bas à GAUCHE, du côté de la météo
//   • on est dimanche  → ticket en bas à DROITE, du côté de la date
//   • les deux le même jour → les deux tickets, chacun dans son coin.
//
// Il est posé en STICKER (par-dessus le coin, en débord) : il ne prend donc aucune
// place dans le widget et ne pousse ni la météo ni la date.
//
// AU CLIC : une petite bulle sort du ticket. Pas de fenêtre plein écran — pour une
// glace offerte, bloquer tout l'écran serait disproportionné.
//
// Autonome : SVG + CSS + JS + textes, tout est ici. Pour le retirer : supprimer ce
// fichier et l'appel à glaceStickers() dans widget.php.
// ============================================================

if (!function_exists('glaceSeuilChaud')) {
    /** À partir de combien de degrés la glace est offerte. */
    function glaceSeuilChaud()
    {
        return 30;
    }
}

if (!function_exists('glaceTicketSvg')) {
    /**
     * Le ticket, dessiné. ENTIÈREMENT BLEU (demande de Jimmy : le corps vert devait
     * passer au bleu lui aussi, pas seulement les contours).
     */
    function glaceTicketSvg()
    {
        return '<svg viewBox="0 0 170 104" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">'
            . '<defs><linearGradient id="glaceTg" x1="0" y1="0" x2="1" y2="1">'
            . '<stop offset="0" stop-color="#5b9bf5"></stop><stop offset="1" stop-color="#1e40af"></stop></linearGradient></defs>'
            . '<rect x="2" y="2" width="166" height="100" rx="12" fill="url(#glaceTg)" stroke="#152c6b" stroke-width="3"></rect>'
            . '<rect x="10" y="12" width="106" height="80" rx="7" fill="#fff" stroke="#152c6b" stroke-width="1.6"></rect>'
            . '<text x="24" y="52" font-family="Inter,Arial,sans-serif" font-size="34" font-weight="900" fill="#1c1c1c" font-style="italic">1x</text>'
            . '<text x="18" y="72" font-family="Inter,Arial,sans-serif" font-size="15" font-weight="900" fill="#1c1c1c" font-style="italic">GRATUIT</text>'
            . '<text x="22" y="88" font-family="Inter,Arial,sans-serif" font-size="15" font-weight="900" fill="#1c1c1c" font-style="italic">GRATIS</text>'
            . '<g>'
            . '<path d="M118 60 L152 60 L135 98 Z" fill="#e8a94a" stroke="#152c6b" stroke-width="1.5"></path>'
            . '<path d="M122 66 L148 66 M126 74 L144 74 M130 82 L140 82 M126 60 L138 92 M144 60 L133 92" stroke="#152c6b" stroke-width="1.2" opacity=".5"></path>'
            . '<path d="M116 58 C110 40 118 26 128 24 C126 12 146 8 150 20 C160 18 166 30 158 38 C166 44 158 58 150 56 Z" fill="#fdf6e6" stroke="#152c6b" stroke-width="1.6"></path>'
            . '<circle cx="130" cy="40" r="2.6" fill="#2a2a2a"></circle><circle cx="146" cy="40" r="2.6" fill="#2a2a2a"></circle>'
            . '<circle cx="126" cy="47" r="2.4" fill="#ff9d8a" opacity=".6"></circle><circle cx="150" cy="47" r="2.4" fill="#ff9d8a" opacity=".6"></circle>'
            . '<path d="M131 48 Q138 55 145 48" fill="none" stroke="#2a2a2a" stroke-width="2" stroke-linecap="round"></path>'
            . '<path d="M128 64 C124 60 128 54 133 57 C134 53 141 53 142 57 C147 54 151 60 147 64 C144 68 132 68 128 64z" fill="#e5533c" stroke="#152c6b" stroke-width="1.2"></path>'
            . '</g></svg>';
    }
}

if (!function_exists('glaceMessage')) {
    /**
     * UNE phrase, et un clin d'œil au métier : chez un jardinier, quand il fait
     * chaud, on ARROSE — alors autant arroser les équipes aussi.
     * @return array ['titre', 'texte']
     */
    function glaceMessage($raison, $temp = null)
    {
        $nl = (function_exists('currentLang') && currentLang() === 'nl');
        $t = (int) $temp;

        if ($raison === 'chaud') {
            return $nl
                ? ['titre' => 'Het is ' . $t . ' °C !', 'texte' => 'Vanaf ' . glaceSeuilChaud() . ' °C geven we de planten water… en de ploeg verkoeling. Je ijsje is gratis. 🍦']
                : ['titre' => 'Il fait ' . $t . ' °C !', 'texte' => 'Dès ' . glaceSeuilChaud() . ' °C, on arrose les plantes… et on rafraîchit les équipes. Ta glace est offerte. 🍦'];
        }
        return $nl
            ? ['titre' => 'Het is zondag !', 'texte' => 'Zelfs de cactussen nemen het er vandaag van — je ijsje is gratis. 😊']
            : ['titre' => 'On est dimanche !', 'texte' => 'Même les cactus lèvent le pied aujourd\'hui — ta glace est offerte. 😊'];
    }
}

if (!function_exists('glaceSticker')) {
    /** Un ticket collé dans un coin. $pos : 'gauche' | 'droite'. */
    function glaceSticker($raison, $temp, $pos)
    {
        $m = glaceMessage($raison, $temp);
        $id = 'glace-' . $raison;
        return '<div class="glace-st glace-' . $pos . '" id="' . $id . '">'
            . '<button type="button" class="glace-btn" onclick="glaceToggle(event)"'
            . ' title="' . htmlspecialchars($m['titre'], ENT_QUOTES, 'UTF-8') . '">'
            . glaceTicketSvg() . '</button>'
            . '<div class="glace-bulle">'
            . '<div class="glace-bulle-t">🍦 ' . htmlspecialchars($m['titre']) . '</div>'
            . '<div class="glace-bulle-x">' . htmlspecialchars($m['texte']) . '</div>'
            . '</div></div>';
    }
}

if (!function_exists('glaceStickers')) {
    /**
     * Les tickets à coller sur le widget, selon les occasions du jour.
     * Renvoie '' quand il n'y en a aucune — l'appel est donc sans risque.
     *
     * @param int|null $temp température du site de l'utilisateur (météo du widget)
     */
    function glaceStickers($temp = null)
    {
        $chaud = ($temp !== null && (int) $temp >= glaceSeuilChaud());
        $dim = (date('w') === '0'); // 0 = dimanche
        if (!$chaud && !$dim) {
            return '';
        }

        $h = '';
        if ($chaud) {
            $h .= glaceSticker('chaud', $temp, 'gauche');    // côté météo
        }
        if ($dim) {
            $h .= glaceSticker('dimanche', $temp, 'droite'); // côté date
        }

        $h .= '<style>
        /* STICKER : posé par-dessus le coin du widget, en débord. Il ne prend donc
           aucune place dans la barre et ne pousse ni la météo ni la date. */
        .glace-st { position:absolute; bottom:-14px; z-index:50; }
        .glace-st.glace-gauche { left:-10px; }
        .glace-st.glace-droite { right:-10px; }

        .glace-btn { background:none; border:none; padding:0; margin:0; cursor:pointer;
            display:block; width:52px; height:32px;
            filter:drop-shadow(0 3px 6px rgba(21,44,107,.4));
            animation:glaceWiggle 3.4s ease-in-out infinite; transform-origin:50% 60%; }
        .glace-btn svg { width:100%; height:100%; display:block; }
        .glace-btn:hover { animation:none; transform:scale(1.16) rotate(-4deg); transition:transform .15s; }

        /* Il GIGOTE par à-coups : une animation en boucle continue devient un papier
           peint qu\'on ne voit plus. Là, il reste sage puis a un frisson. */
        @keyframes glaceWiggle {
            0%, 64%, 100% { transform:rotate(0) scale(1); }
            68% { transform:rotate(-9deg) scale(1.07); }
            72% { transform:rotate(7deg) scale(1.07); }
            76% { transform:rotate(-5deg) scale(1.04); }
            80% { transform:rotate(3deg) scale(1.01); }
        }
        @media (prefers-reduced-motion: reduce) { .glace-btn { animation:none; } }

        /* La BULLE s\'ouvre EN DESSOUS. Au-dessus, elle se faisait couper par la barre du
           navigateur : le widget est tout en haut de la page, il n\'y a pas la place. */
        .glace-bulle { position:absolute; top:calc(100% + 12px); z-index:9999;
            width:250px; background:#fff; border-radius:14px; padding:13px 15px; text-align:left;
            border:2px solid #2d5a37; box-shadow:0 12px 32px rgba(20,60,35,.26);
            opacity:0; visibility:hidden; transform:translateY(-8px) scale(.94);
            transition:opacity .18s, transform .18s, visibility .18s; }
        .glace-gauche .glace-bulle { left:0; }
        .glace-droite .glace-bulle { right:0; }
        .glace-st.open .glace-bulle { opacity:1; visibility:visible; transform:translateY(0) scale(1); }
        .glace-bulle::after { content:""; position:absolute; top:-9px; width:14px; height:14px;
            background:#fff; border-left:2px solid #2d5a37; border-top:2px solid #2d5a37; transform:rotate(45deg); }
        .glace-gauche .glace-bulle::after { left:24px; }
        .glace-droite .glace-bulle::after { right:24px; }
        .glace-bulle-t { font-weight:800; color:#2d5a37; font-size:.95rem; margin-bottom:3px; }
        .glace-bulle-x { color:#44566b; font-size:.86rem; line-height:1.45; }

        /* 🥚 Œuf de Pâques : au 5e clic, le ticket EN A MARRE et s\'envole. */
        .glace-st.envole { animation:glaceEnvol 1.1s cubic-bezier(.36,.07,.3,1) forwards; pointer-events:none; }
        @keyframes glaceEnvol {
            0%   { transform:translate(0,0) rotate(0) scale(1); opacity:1; }
            18%  { transform:translate(0,10px) rotate(-12deg) scale(.86); opacity:1; }
            100% { transform:translate(90px,-260px) rotate(420deg) scale(.25); opacity:0; }
        }
        </style>';

        $h .= '<script>
        function glaceToggle(e){ e.stopPropagation();
            var st = e.currentTarget.parentNode;

            // 🥚 Cinq clics et il se sauve. Compteur PAR ticket : chacun a sa patience.
            st.dataset.clics = String((parseInt(st.dataset.clics || "0", 10)) + 1);
            if (parseInt(st.dataset.clics, 10) >= 5) {
                st.classList.remove("open");
                st.classList.add("envole");
                setTimeout(function(){ st.remove(); }, 1100);
                return;
            }

            var wasOpen = st.classList.contains("open");
            document.querySelectorAll(".glace-st.open").forEach(function(o){ o.classList.remove("open"); });
            if (!wasOpen) { st.classList.add("open"); } }
        function glaceCloseAll(){ document.querySelectorAll(".glace-st.open").forEach(function(o){ o.classList.remove("open"); }); }
        document.addEventListener("click", glaceCloseAll);
        document.addEventListener("keydown", function(e){ if (e.key === "Escape") { glaceCloseAll(); } });
        </script>';

        return $h;
    }
}
