<?php
// ============================================================
// glace.php — le TICKET GLACE 🍦
//
// Deux occasions de se le voir offrir, et le ticket se pose là où se trouve
// la RAISON — c'est ce qui le rend lisible sans explication :
//   • il fait ≥ 30 °C   → le ticket se colle à la MÉTÉO
//   • on est dimanche   → le ticket se colle à la DATE
//   • les deux à la fois → un seul ticket (on n'en offre pas deux), posé sur la
//     météo, et le message signale le doublé.
//
// Il gigote pour attirer l'œil, et au clic une petite modale explique la règle
// sur un ton léger. Autonome : tout est ici (SVG + CSS + JS + textes).
// Pour le retirer : supprimer ce fichier et les 3 appels dans widget.php.
// ============================================================

if (!function_exists('glaceSeuilChaud')) {
    /** À partir de combien de degrés la glace est offerte. */
    function glaceSeuilChaud()
    {
        return 30;
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
    /**
     * Le ticket, dessiné. Contours en BLEU (demande de Jimmy) : le liseré tranche
     * sur le vert du ticket et sur le fond clair du ruban.
     */
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
     * Le message de la modale. Ton volontairement LÉGER : c'est un cadeau, pas une
     * procédure. Le titre annonce, le texte explique, la dernière ligne pousse à y aller.
     * @return array ['titre', 'texte', 'bouton']
     */
    function glaceMessage(array $raisons, $temp = null)
    {
        $nl = (function_exists('currentLang') && currentLang() === 'nl');
        $chaud = in_array('chaud', $raisons, true);
        $dim = in_array('dimanche', $raisons, true);
        $t = (int) $temp;

        if ($chaud && $dim) {
            return $nl ? [
                'titre' => '🍦 Dubbele prijs !',
                'texte' => 'Het is <strong>' . $t . ' °C</strong> én het is <strong>zondag</strong>. '
                    . 'Het universum wil duidelijk dat je een ijsje eet — en wij gaan daar niet tegenin. '
                    . 'Vraag gerust je <strong>gratis ijsjesticket</strong>, je hebt het dubbel en dwars verdiend. 😎',
                'bouton' => 'Lekker, ik ga erheen !',
            ] : [
                'titre' => '🍦 Doublé : chaleur ET dimanche !',
                'texte' => 'Il fait <strong>' . $t . ' °C</strong> <em>et</em> on est <strong>dimanche</strong>. '
                    . 'L\'univers insiste pour que tu manges une glace, et franchement on ne va pas le contredire. '
                    . 'Passe demander ton <strong>ticket glace gratuit</strong> — tu l\'as mérité deux fois. 😎',
                'bouton' => 'J\'y cours !',
            ];
        }

        if ($chaud) {
            return $nl ? [
                'titre' => '🥵 ' . $t . ' °C... het smelt !',
                'texte' => 'Vanaf <strong>' . glaceSeuilChaud() . ' °C</strong> trakteert Famiflora. '
                    . 'Vraag gerust je <strong>gratis ijsjesticket</strong> — het is het enige moment waarop '
                    . 'smelten toegestaan is. 🍦',
                'bouton' => 'Top, ik ga erheen !',
            ] : [
                'titre' => '🥵 ' . $t . ' °C... on fond !',
                'texte' => 'À partir de <strong>' . glaceSeuilChaud() . ' °C</strong>, la maison régale. '
                    . 'N\'hésite pas à demander ton <strong>ticket glace gratuit</strong> — c\'est le seul moment '
                    . 'de l\'année où fondre est officiellement autorisé. 🍦',
                'bouton' => 'J\'y cours !',
            ];
        }

        return $nl ? [
            'titre' => '🍦 Het is zondag !',
            'texte' => 'En op <strong>zondag</strong> heb je recht op je <strong>gratis ijsjesticket</strong>. '
                . 'Geen enkele reden nodig, geen enkel excuus: het is zondag, punt uit. '
                . 'Vraag het gerust — daar is het voor. 😊',
            'bouton' => 'Lekker, ik ga erheen !',
        ] : [
            'titre' => '🍦 Eh, on est dimanche !',
            'texte' => 'Et le <strong>dimanche</strong>, tu as droit à ton <strong>ticket glace gratuit</strong>. '
                . 'Pas besoin de raison, pas besoin d\'excuse : c\'est dimanche, point. '
                . 'N\'hésite pas à le demander, il est là pour ça. 😊',
            'bouton' => 'J\'y cours !',
        ];
    }
}

if (!function_exists('glaceBadge')) {
    /**
     * Le ticket cliquable, à coller à côté de la météo ou de la date.
     * Le CSS/JS/modale ne sont émis QU'UNE FOIS, même si la fonction est appelée
     * deux fois — d'où le drapeau statique.
     */
    function glaceBadge(array $raisons, $temp = null)
    {
        if (empty($raisons)) {
            return '';
        }
        static $done = false;

        $m = glaceMessage($raisons, $temp);
        $titreAttr = htmlspecialchars(strip_tags($m['titre']), ENT_QUOTES, 'UTF-8');

        $h = '<button type="button" class="glace-badge" onclick="glaceOpen()" title="' . $titreAttr . '">'
            . glaceTicketSvg() . '</button>';

        if ($done) {
            return $h;
        }
        $done = true;

        $h .= '<div class="glace-back" id="glaceBack" onclick="if(event.target===this)glaceClose()">'
            . '<div class="glace-card">'
            . '<div class="glace-card-img">' . glaceTicketSvg() . '</div>'
            . '<h3>' . $m['titre'] . '</h3>'
            . '<p>' . $m['texte'] . '</p>'
            . '<button type="button" class="glace-ok" onclick="glaceClose()">' . htmlspecialchars($m['bouton']) . '</button>'
            . '</div></div>';

        $h .= '<style>
        /* Le ticket GIGOTE : un balancement irrégulier, avec de longues pauses.
           Une animation qui tourne en continu devient un papier peint qu\'on ne voit
           plus ; ici elle se déclenche par à-coups, donc l\'œil l\'attrape. */
        .glace-badge { background:none; border:none; padding:0; margin:0 0 0 8px; cursor:pointer;
            width:42px; height:26px; flex-shrink:0; vertical-align:middle;
            filter:drop-shadow(0 2px 4px rgba(29,78,216,.35));
            animation:glaceWiggle 3.2s ease-in-out infinite; transform-origin:50% 60%; }
        .glace-badge svg { width:100%; height:100%; display:block; }
        .glace-badge:hover { animation:none; transform:scale(1.18) rotate(-3deg); transition:transform .15s; }
        @keyframes glaceWiggle {
            0%, 62%, 100% { transform:rotate(0) scale(1); }
            66% { transform:rotate(-9deg) scale(1.06); }
            70% { transform:rotate(7deg) scale(1.06); }
            74% { transform:rotate(-6deg) scale(1.04); }
            78% { transform:rotate(4deg) scale(1.02); }
            82% { transform:rotate(-2deg) scale(1); }
        }
        @media (prefers-reduced-motion: reduce) { .glace-badge { animation:none; } }

        .glace-back { position:fixed; inset:0; top:0; left:0; right:0; bottom:0; z-index:100000;
            background:rgba(12,30,60,.55); display:none; align-items:center; justify-content:center; padding:20px; }
        .glace-back.open { display:flex; }
        .glace-card { background:#fff; border-radius:20px; border:3px solid #1d4ed8; max-width:430px; width:100%;
            padding:26px 28px 24px; text-align:center; box-shadow:0 24px 60px rgba(12,30,60,.4);
            animation:glacePop .28s cubic-bezier(.2,1.4,.4,1); }
        @keyframes glacePop { from { transform:scale(.85) translateY(14px); opacity:0; } }
        .glace-card-img { width:132px; margin:0 auto 12px; animation:glaceFloat 2.6s ease-in-out infinite; }
        .glace-card-img svg { width:100%; height:auto; display:block; }
        @keyframes glaceFloat { 0%,100% { transform:translateY(0) rotate(-2deg); } 50% { transform:translateY(-7px) rotate(2deg); } }
        .glace-card h3 { margin:0 0 10px; color:#1d4ed8; font-size:1.32rem; }
        .glace-card p { margin:0 0 20px; color:#3a4a5c; line-height:1.55; font-size:.96rem; }
        .glace-ok { background:linear-gradient(180deg,#2563eb,#1d4ed8); color:#fff; border:none; border-radius:12px;
            padding:12px 26px; font:inherit; font-weight:800; cursor:pointer; box-shadow:0 5px 14px rgba(29,78,216,.4); }
        .glace-ok:hover { filter:brightness(1.1); }
        </style>';

        $h .= '<script>
        function glaceOpen(){ var b=document.getElementById("glaceBack"); if(b){ b.classList.add("open"); } }
        function glaceClose(){ var b=document.getElementById("glaceBack"); if(b){ b.classList.remove("open"); } }
        document.addEventListener("keydown", function(e){ if(e.key==="Escape"){ glaceClose(); } });
        </script>';

        return $h;
    }
}
