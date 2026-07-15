<?php
// ============================================================
// fee.php — LA FÉE FAMIFLORA : animation d'attente.
//
// La fée agite sa baguette au-dessus d'un pot et fait pousser une plante DIFFÉRENTE
// toutes les 3 s (fleur, cactus, arbre, fleur bleue, tournesol, arbre à glaces…).
// Comme le serveur ne renvoie aucun avancement mesurable pendant qu'il travaille,
// ce petit jardin qui défile occupe l'utilisateur sans prétendre à un pourcentage.
//
// DEUX USAGES, ET UNE HONNÊTETÉ DIFFÉRENTE POUR CHACUN :
//
//  1) IMPORT d'un fichier (le cas qui a motivé tout ça — c'est long).
//     L'envoi du fichier a un VRAI pourcentage : le navigateur nous le donne
//     (XHR upload.onprogress) → 0 à 50 %.
//     La suite (lecture du PDF par l'IA, mise en forme, quiz) ne renvoie AUCUNE
//     progression : impossible de la mesurer sans réécrire tout le serveur. On
//     l'ESTIME donc (50 → 95 %), et on passe à 100 % quand la réponse arrive.
//     La barre n'atteint jamais 95 % « pour faire joli » : elle ralentit, ce qui
//     est le comportement honnête quand on ne sait pas.
//
//  2) NAVIGATION entre pages : la fée n'apparaît QUE si la page met plus de 300 ms
//     à venir. Sur un clic instantané, rien ne clignote — sinon on ajouterait une
//     sensation de lenteur à un site qui est rapide.
//
// Le dessin de la fée vient du logo Famiflora (ailes = feuilles du symbole).
// Autonome : SVG + CSS + JS ici. Injecté sur toutes les pages par config.php.
// ============================================================

if (!function_exists('feeSvg')) {
    /**
     * La fée. Le bras + la baguette + l'étoile sont groupés dans .fee-bras :
     * c'est ce groupe qui pivote (autour de l'épaule) pour donner le coup de
     * baguette — inutile de dessiner 5 images, une seule suffit.
     */
    function feeSvg()
    {
        return <<<'SVG'
<svg class="fee-perso" viewBox="130 20 360 490" xmlns="http://www.w3.org/2000/svg">
  <defs>
    <linearGradient id="feeSkin" x1="0" y1="0" x2="0" y2="1">
      <stop offset="0" stop-color="#FBE1C4"/><stop offset="1" stop-color="#F3CBA3"/>
    </linearGradient>
    <linearGradient id="feeHair" x1="0" y1="0" x2="1" y2="1">
      <stop offset="0" stop-color="#94512F"/><stop offset="1" stop-color="#6E3A22"/>
    </linearGradient>
    <linearGradient id="feeDress" x1="0" y1="0" x2="0" y2="1">
      <stop offset="0" stop-color="#A9D454"/><stop offset="1" stop-color="#84BD3C"/>
    </linearGradient>
    <radialGradient id="feeGlow">
      <stop offset="0" stop-color="#EAF6CE" stop-opacity="0.85"/>
      <stop offset="0.5" stop-color="#C9E58B" stop-opacity="0.4"/>
      <stop offset="1" stop-color="#8DC63F" stop-opacity="0"/>
    </radialGradient>
  </defs>

  <!-- AILES = les feuilles du symbole Famiflora -->
  <g class="fee-ailes">
    <path d="M298 318 Q 156 330 140 160 Q 270 152 298 318 Z" fill="#1E6B33"/>
    <path d="M280 296 Q 186 306 176 192 Q 256 186 280 296 Z" fill="#8DC63F"/>
    <path d="M302 318 Q 444 330 460 160 Q 330 152 302 318 Z" fill="#1E6B33"/>
    <path d="M320 296 Q 414 306 424 192 Q 344 186 320 296 Z" fill="#8DC63F"/>
    <path d="M298 330 Q 178 322 170 456 Q 262 466 298 330 Z" fill="#1E6B33"/>
    <path d="M290 340 Q 210 334 206 416 Q 258 424 290 340 Z" fill="#8DC63F"/>
    <path d="M302 330 Q 422 322 430 456 Q 338 466 302 330 Z" fill="#1E6B33"/>
    <path d="M310 340 Q 390 334 394 416 Q 342 424 310 340 Z" fill="#8DC63F"/>
  </g>

  <!-- JAMBES + CHAUSSONS -->
  <path d="M282 436 Q 279 462 277 484" stroke="url(#feeSkin)" stroke-width="14" stroke-linecap="round" fill="none"/>
  <path d="M318 436 Q 321 462 323 484" stroke="url(#feeSkin)" stroke-width="14" stroke-linecap="round" fill="none"/>
  <ellipse cx="274" cy="492" rx="16" ry="9" fill="#1E6B33"/>
  <ellipse cx="326" cy="492" rx="16" ry="9" fill="#1E6B33"/>
  <ellipse cx="272" cy="488" rx="4.5" ry="3" fill="#8DC63F"/>
  <ellipse cx="324" cy="488" rx="4.5" ry="3" fill="#8DC63F"/>

  <!-- COU -->
  <path d="M288 298 L 312 298 L 311 336 L 289 336 Z" fill="#F3CBA3"/>

  <!-- ROBE EN FEUILLES -->
  <path d="M250 414 L 262 444 L 276 416 L 290 448 L 300 418 L 310 448 L 324 416 L 338 444 L 350 414 Z" fill="#1E6B33"/>
  <path d="M258 330 Q 300 318 342 330 L 352 422 Q 300 438 248 422 Z" fill="url(#feeDress)"/>
  <g fill="#2E7D3B">
    <path d="M262 342 Q 272 334 282 342 Q 272 350 262 342 Z"/>
    <path d="M290 336 Q 300 328 310 336 Q 300 344 290 336 Z"/>
    <path d="M318 342 Q 328 334 338 342 Q 328 350 318 342 Z"/>
    <path d="M272 374 Q 282 366 292 374 Q 282 382 272 374 Z"/>
    <path d="M304 372 Q 314 364 324 372 Q 314 380 304 372 Z"/>
    <path d="M288 404 Q 298 396 308 404 Q 298 412 288 404 Z"/>
  </g>
  <g transform="translate(330,388)">
    <circle cx="-6" cy="0" r="4.8" fill="#F5EFDF"/><circle cx="6" cy="0" r="4.8" fill="#F5EFDF"/>
    <circle cx="0" cy="-6" r="4.8" fill="#F5EFDF"/><circle cx="0" cy="6" r="4.8" fill="#F5EFDF"/>
    <circle r="3.6" fill="#E8C46B"/>
  </g>

  <!-- BRAS GAUCHE : main sur la hanche -->
  <path d="M260 342 Q 240 360 246 384 Q 252 396 264 396" stroke="url(#feeSkin)" stroke-width="12" stroke-linecap="round" fill="none"/>

  <!-- TÊTE -->
  <circle cx="300" cy="220" r="92" fill="url(#feeSkin)"/>
  <circle cx="300" cy="72" r="37" fill="url(#feeHair)"/>
  <path d="M326 52 Q 342 34 364 36 Q 356 58 334 64 Z" fill="#8DC63F"/>
  <path d="M328 50 Q 342 42 356 40" stroke="#5E9430" stroke-width="2.5" fill="none"/>
  <path d="M204 258 Q 184 112 300 88 Q 416 112 396 258 Q 386 262 380 252
           Q 386 202 356 178 Q 336 158 300 152 Q 264 158 244 178
           Q 214 202 220 252 Q 214 262 204 258 Z" fill="url(#feeHair)"/>
  <path d="M222 236 Q 216 262 226 284 Q 236 274 234 248 Q 228 238 222 236 Z" fill="url(#feeHair)"/>
  <path d="M378 236 Q 384 262 374 284 Q 364 274 366 248 Q 372 238 378 236 Z" fill="url(#feeHair)"/>
  <path d="M252 136 Q 280 110 318 106" stroke="#B5794A" stroke-width="5" stroke-linecap="round" fill="none" opacity="0.8"/>
  <path d="M286 52 Q 298 46 310 50" stroke="#B5794A" stroke-width="4" stroke-linecap="round" fill="none" opacity="0.8"/>

  <!-- VISAGE -->
  <path d="M241 214 Q 262 199 283 212" stroke="#8DC63F" stroke-width="7" stroke-linecap="round" fill="none" opacity="0.8"/>
  <path d="M317 212 Q 338 199 359 214" stroke="#8DC63F" stroke-width="7" stroke-linecap="round" fill="none" opacity="0.8"/>
  <g class="fee-yeux">
    <circle cx="262" cy="232" r="20" fill="#1E4020"/>
    <circle cx="338" cy="232" r="20" fill="#1E4020"/>
    <circle cx="269" cy="224" r="7" fill="#FFFFFF"/>
    <circle cx="345" cy="224" r="7" fill="#FFFFFF"/>
  </g>
  <path d="M242 218 Q 248 210 256 207 M344 207 Q 352 210 358 218" stroke="#1E4020" stroke-width="3.2" stroke-linecap="round" fill="none"/>
  <ellipse cx="230" cy="268" rx="17" ry="10.5" fill="#F0A96B" opacity="0.6"/>
  <ellipse cx="370" cy="268" rx="17" ry="10.5" fill="#F0A96B" opacity="0.6"/>
  <path d="M280 270 Q 300 292 320 270 Q 312 286 300 286 Q 288 286 280 270 Z" fill="#1A1A1A"/>
  <path d="M293 283 Q 300 287 307 283" stroke="#1A1A1A" stroke-width="2" fill="none"/>

  <!-- BRAS DROIT + BAGUETTE + ÉTOILE : le groupe qui PIVOTE (le coup de baguette).
       Dessiné après la tête pour passer par-dessus, comme dans les images fournies. -->
  <g class="fee-bras">
    <path d="M340 342 Q 366 330 380 306" stroke="url(#feeSkin)" stroke-width="12" stroke-linecap="round" fill="none"/>
    <circle cx="381" cy="303" r="9" fill="#F3CBA3"/>
    <line x1="382" y1="300" x2="402" y2="254" stroke="#8A5B36" stroke-width="6" stroke-linecap="round"/>
    <circle class="fee-halo" cx="405" cy="247" r="42" fill="url(#feeGlow)"/>
    <path d="M405 218 l8 21 21 8 -21 8 -8 21 -8 -21 -21 -8 21 -8 Z" fill="#8DC63F"/>
    <circle cx="405" cy="247" r="6.5" fill="#1E6B33"/>
    <path d="M442 214 l3.5 9 9 3.5 -9 3.5 -3.5 9 -3.5 -9 -9 -3.5 9 -3.5 Z" fill="#B5D95A"/>
    <circle cx="434" cy="286" r="3.2" fill="#B5D95A"/>
  </g>
</svg>
SVG;
    }
}

if (!function_exists('feePlanteSvg')) {
    /**
     * LE POT + UNE COLLECTION DE PLANTES, toutes dessinées empilées mais masquées.
     * Le serveur ne nous dit RIEN de son avancement (mise en forme IA, quiz…), donc
     * plutôt qu'un faux pourcentage on OCCUPE l'utilisateur : la fée fait pousser une
     * plante DIFFÉRENTE toutes les 3 s (le JS en révèle une à la fois, avec un effet
     * de pousse). Une par une : fleur, cactus, arbre, fleur bleue, tournesol… et, pour
     * le clin d'œil, un ARBRE À GLACES. Toutes partent du même point (110,172 = terreau).
     */
    function feePlanteSvg()
    {
        return <<<'SVG'
<svg class="fee-plante" viewBox="0 0 220 260" xmlns="http://www.w3.org/2000/svg">
  <defs>
    <linearGradient id="feePot" x1="0" y1="0" x2="0" y2="1">
      <stop offset="0" stop-color="#C97B4A"/><stop offset="1" stop-color="#9C5730"/>
    </linearGradient>
    <linearGradient id="feeTige" x1="0" y1="1" x2="0" y2="0">
      <stop offset="0" stop-color="#2E7D3B"/><stop offset="1" stop-color="#8DC63F"/>
    </linearGradient>
  </defs>

  <!-- POT -->
  <path d="M56 168 L164 168 L152 246 Q 110 254 68 246 Z" fill="url(#feePot)"/>
  <rect x="48" y="150" width="124" height="24" rx="7" fill="#D98A57"/>
  <ellipse cx="110" cy="172" rx="52" ry="9" fill="#5C3A21"/>

  <!-- 1. FLEUR ROSE -->
  <g class="fee-plant">
    <path d="M110 170 Q 106 118 111 74" stroke="url(#feeTige)" stroke-width="7.5" stroke-linecap="round" fill="none"/>
    <path d="M110 96 Q 78 76 72 102 Q 92 120 110 96 Z" fill="#8DC63F"/>
    <path d="M111 108 Q 144 88 150 114 Q 129 132 111 108 Z" fill="#A9D454"/>
    <g transform="translate(111,62)">
      <ellipse cx="-17" cy="0" rx="13" ry="9" fill="#F2A9C4"/>
      <ellipse cx="17" cy="0" rx="13" ry="9" fill="#F2A9C4"/>
      <ellipse cx="0" cy="-16" rx="9" ry="13" fill="#F6BDD2"/>
      <ellipse cx="0" cy="16" rx="9" ry="13" fill="#F6BDD2"/>
      <ellipse cx="-12" cy="-12" rx="9" ry="9" fill="#F5B6CD"/>
      <ellipse cx="12" cy="12" rx="9" ry="9" fill="#F5B6CD"/>
      <circle r="9" fill="#E8C46B"/><circle r="4" fill="#D0A03F"/>
    </g>
  </g>

  <!-- 2. CACTUS -->
  <g class="fee-plant">
    <rect x="97" y="90" width="26" height="82" rx="13" fill="#4E9E52"/>
    <path d="M97 126 Q 80 126 80 110 Q 80 102 87 102" stroke="#4E9E52" stroke-width="12" stroke-linecap="round" fill="none"/>
    <path d="M123 138 Q 140 138 140 122 Q 140 114 133 114" stroke="#4E9E52" stroke-width="12" stroke-linecap="round" fill="none"/>
    <g stroke="#2E6B38" stroke-width="1.6" stroke-linecap="round">
      <path d="M110 104 v6 M110 124 v6 M110 144 v6 M102 114 h-4 M118 114 h4 M102 134 h-4 M118 134 h4"/>
    </g>
    <ellipse cx="110" cy="84" rx="10" ry="7.5" fill="#F26E7E"/>
    <ellipse cx="110" cy="84" rx="4" ry="3" fill="#FBD0A8"/>
  </g>

  <!-- 3. ARBRE (grand feuillage) -->
  <g class="fee-plant">
    <rect x="102" y="96" width="16" height="74" rx="5" fill="#8A5B36"/>
    <circle cx="110" cy="64" r="40" fill="#2E7D3B"/>
    <circle cx="78" cy="84" r="28" fill="#3C9147"/>
    <circle cx="142" cy="84" r="28" fill="#3C9147"/>
    <circle cx="110" cy="82" r="32" fill="#5EA843"/>
    <circle cx="92" cy="58" r="6" fill="#F2A9C4"/>
    <circle cx="130" cy="66" r="6" fill="#F6BDD2"/>
    <circle cx="110" cy="44" r="5" fill="#F6BDD2"/>
  </g>

  <!-- 4. FLEUR BLEUE -->
  <g class="fee-plant">
    <path d="M110 170 Q 106 120 111 78" stroke="url(#feeTige)" stroke-width="7" stroke-linecap="round" fill="none"/>
    <path d="M110 100 Q 80 82 74 106 Q 93 122 110 100 Z" fill="#8DC63F"/>
    <path d="M111 112 Q 140 94 146 118 Q 127 134 111 112 Z" fill="#A9D454"/>
    <g transform="translate(111,66)">
      <ellipse cx="-16" cy="0" rx="12" ry="8" fill="#6FA8DC"/>
      <ellipse cx="16" cy="0" rx="12" ry="8" fill="#6FA8DC"/>
      <ellipse cx="0" cy="-15" rx="8" ry="12" fill="#8EC5F0"/>
      <ellipse cx="0" cy="15" rx="8" ry="12" fill="#8EC5F0"/>
      <circle r="8" fill="#F5EFDF"/><circle r="3.5" fill="#E8C46B"/>
    </g>
  </g>

  <!-- 5. TOURNESOL -->
  <g class="fee-plant">
    <path d="M110 170 Q 108 122 111 76" stroke="url(#feeTige)" stroke-width="7" stroke-linecap="round" fill="none"/>
    <path d="M110 116 Q 82 104 78 126 Q 96 136 110 116 Z" fill="#3C9147"/>
    <path d="M112 126 Q 140 116 142 136 Q 124 144 112 126 Z" fill="#3C9147"/>
    <g transform="translate(111,64)" fill="#F4B41A">
      <ellipse cx="0" cy="-18" rx="6" ry="12"/>
      <ellipse cx="0" cy="18" rx="6" ry="12"/>
      <ellipse cx="-18" cy="0" rx="12" ry="6"/>
      <ellipse cx="18" cy="0" rx="12" ry="6"/>
      <ellipse cx="-13" cy="-13" rx="9" ry="7"/>
      <ellipse cx="13" cy="-13" rx="9" ry="7"/>
      <ellipse cx="-13" cy="13" rx="9" ry="7"/>
      <ellipse cx="13" cy="13" rx="9" ry="7"/>
    </g>
    <circle cx="111" cy="64" r="11" fill="#6E4523"/>
  </g>

  <!-- 6. ARBRE À GLACES (le clin d'œil) : grand arbre dont pendent de VRAIES glaces —
       un bâtonnet enrobé chocolat, une fusée tricolore, et un cornet à boules en vedette. -->
  <g class="fee-plant">
    <rect x="102" y="98" width="16" height="72" rx="5" fill="#8A5B36"/>
    <circle cx="110" cy="60" r="40" fill="#2E7D3B"/>
    <circle cx="76" cy="80" r="27" fill="#3C9147"/>
    <circle cx="144" cy="80" r="27" fill="#3C9147"/>
    <circle cx="110" cy="78" r="31" fill="#4E9E52"/>

    <!-- Bâtonnet enrobé chocolat (gauche) -->
    <g transform="translate(80,92)">
      <line x1="0" y1="-16" x2="0" y2="-2" stroke="#C98A4B" stroke-width="2.5"/>
      <rect x="-9" y="-2" width="18" height="26" rx="6" fill="#5E3E22"/>
      <rect x="-9" y="-2" width="18" height="7" rx="5" fill="#754B29"/>
      <circle cx="-4" cy="6" r="1.4" fill="#EAD8B0"/><circle cx="4" cy="12" r="1.4" fill="#EAD8B0"/>
      <rect x="-2.5" y="23" width="5" height="11" rx="2" fill="#E8CFA0"/>
    </g>

    <!-- Glace fusée tricolore (droite) -->
    <g transform="translate(140,92)">
      <line x1="0" y1="-16" x2="0" y2="-6" stroke="#C98A4B" stroke-width="2.5"/>
      <path d="M-8 -6 Q 0 -18 8 -6 L 8 0 L -8 0 Z" fill="#E4483B"/>
      <rect x="-8" y="0" width="16" height="9" fill="#FBF3E7"/>
      <rect x="-8" y="9" width="16" height="9" fill="#4C8FD0"/>
      <rect x="-2.5" y="18" width="5" height="11" rx="2" fill="#E8CFA0"/>
    </g>

    <!-- Cornet à boules (centre, la vedette) -->
    <g transform="translate(110,96)">
      <path d="M-10 8 L 10 8 L 0 34 Z" fill="#E8A94B"/>
      <path d="M-6 14 L 1 26 M2 14 L -3 22 M7 14 L 3 20" stroke="#C6852F" stroke-width="1" fill="none"/>
      <circle cx="-5" cy="2" r="10" fill="#F7B7CE"/>
      <circle cx="6" cy="0" r="9" fill="#FDF0DA"/>
      <circle cx="0" cy="-8" r="8" fill="#B9E0A6"/>
      <circle cx="0" cy="-18" r="3.6" fill="#D0342B"/>
      <path d="M0 -20 Q 3 -26 7 -26" stroke="#6B3E1E" stroke-width="1.2" fill="none"/>
    </g>
  </g>

  <!-- 7. CHAMPIGNON -->
  <g class="fee-plant">
    <path d="M100 170 Q 97 142 104 124 L 118 124 Q 125 142 122 170 Z" fill="#F3E9D6"/>
    <path d="M72 126 Q 82 86 111 86 Q 140 86 150 126 Q 111 138 72 126 Z" fill="#D8483B"/>
    <circle cx="96" cy="110" r="5" fill="#FBEFE0"/>
    <circle cx="120" cy="104" r="6" fill="#FBEFE0"/>
    <circle cx="132" cy="118" r="4" fill="#FBEFE0"/>
    <circle cx="108" cy="120" r="4.5" fill="#FBEFE0"/>
    <path d="M104 150 q 6 4 12 0" stroke="#D8B89A" stroke-width="1.5" fill="none"/>
  </g>

  <!-- 8. TULIPE -->
  <g class="fee-plant">
    <path d="M110 170 Q 108 128 110 96" stroke="url(#feeTige)" stroke-width="6.5" stroke-linecap="round" fill="none"/>
    <path d="M110 130 Q 84 118 82 90 Q 100 104 110 130 Z" fill="#5EA843"/>
    <path d="M110 122 Q 138 112 140 86 Q 120 100 110 122 Z" fill="#7FB539"/>
    <path d="M92 92 Q 92 66 110 60 Q 128 66 128 92 Q 118 100 110 92 Q 102 100 92 92 Z" fill="#E4572E"/>
    <path d="M110 60 Q 110 78 110 92" stroke="#C63D1B" stroke-width="1.5" fill="none"/>
  </g>
</svg>
SVG;
    }
}

if (!function_exists('feeOverlay')) {
    /**
     * L'écran d'attente complet (masqué au départ). Injecté sur toutes les pages.
     * Le JS expose deux entrées : feeShow(texte) et feeSet(pourcentage).
     */
    function feeOverlay()
    {
        $t = function ($fr, $nl) {
            return function_exists('t') ? t($fr, $nl) : $fr;
        };
        // Deux échappements DIFFÉRENTS, et c'est indispensable :
        //  - dans le HTML, htmlspecialchars ;
        //  - dans le JavaScript, json_encode (qui fournit AUSSI les guillemets).
        // Passer une chaîne htmlspecialchars à du JS afficherait « C&#039;est prêt »
        // au lieu de « C'est prêt » — l'apostrophe devient une entité HTML, que
        // textContent ne décode pas.
        $txtAttente = $t('Un instant…', 'Een ogenblik…');
        $txtEnvoi = $t('Envoi du fichier…', 'Bestand versturen…');
        $txtMagie = $t('La fée met ton contenu en forme…', 'De fee brengt je inhoud in vorm…');
        $txtFini = $t('C\'est prêt !', 'Klaar!');

        $lblAttente = htmlspecialchars($txtAttente, ENT_QUOTES, 'UTF-8'); // pour le HTML
        $jsAttente = json_encode($txtAttente, JSON_UNESCAPED_UNICODE);    // pour le JS
        $jsEnvoi = json_encode($txtEnvoi, JSON_UNESCAPED_UNICODE);
        $jsMagie = json_encode($txtMagie, JSON_UNESCAPED_UNICODE);
        $jsFini = json_encode($txtFini, JSON_UNESCAPED_UNICODE);

        $fee = feeSvg();
        $plante = feePlanteSvg();

        // Messages qui défilent pendant l'attente : blagues + fun facts jardinage,
        // auxquels on AJOUTE les phrases du widget d'accueil (base de données), pour ne
        // pas afficher un plat « la fée prépare votre contenu ».
        $feeLang = function_exists('currentLang') ? currentLang() : 'fr';
        $feeJardin = [
            ["Pourquoi les jardiniers sont-ils si zen ? Ils savent cultiver la patience. 🌱",
             "Waarom zijn tuiniers zo zen? Ze weten geduld te kweken. 🌱"],
            ["Que dit une fleur à une abeille ? « Butine-moi tant que je suis en fleur ! » 🐝",
             "Wat zegt een bloem tegen een bij? « Kom langs zolang ik bloei! » 🐝"],
            ["Quel est le comble pour un jardinier ? Raconter des salades. 🥬",
             "Wat is het toppunt voor een tuinier? Praatjes (sla) verkopen. 🥬"],
            ["Pourquoi les tomates rougissent-elles ? Elles ont vu la salade se déshabiller. 🍅",
             "Waarom worden tomaten rood? Ze zagen de sla zich uitkleden. 🍅"],
            ["Un jardinier ne part jamais à la retraite : il se met simplement au vert. 🌿",
             "Een tuinier gaat nooit met pensioen: hij trekt gewoon de natuur in. 🌿"],
            ["Une abeille visite jusqu'à 7 000 fleurs par jour. 🐝",
             "Een bij bezoekt tot 7.000 bloemen per dag. 🐝"],
            ["La lavande éloigne naturellement les moustiques. 💜",
             "Lavendel houdt muggen op natuurlijke wijze weg. 💜"],
            ["Les fraises ne sont pas des baies… mais les bananes, si ! 🍓",
             "Aardbeien zijn geen bessen… bananen wél! 🍓"],
            ["Le tournesol suit le soleil : on appelle ça l'héliotropisme. 🌻",
             "De zonnebloem volgt de zon: dat heet heliotropie. 🌻"],
            ["Les coccinelles dévorent les pucerons : la meilleure amie du jardinier. 🐞",
             "Lieveheersbeestjes eten bladluizen: de beste vriend van de tuinier. 🐞"],
            ["Le marc de café est un excellent engrais naturel. ☕",
             "Koffiedik is een uitstekende natuurlijke meststof. ☕"],
            ["Le basilic planté près des tomates renforce leur goût. 🌿",
             "Basilicum naast tomaten versterkt hun smaak. 🌿"],
            ["Certains bambous poussent de près d'un mètre par jour ! 🎋",
             "Sommige bamboe groeit bijna een meter per dag! 🎋"],
        ];
        $feeMsgs = [];
        foreach ($feeJardin as $g) {
            $feeMsgs[] = ($feeLang === 'nl') ? $g[1] : $g[0];
        }
        global $db;
        if (isset($db) && $db instanceof PDO && function_exists('widgetPhrases')) {
            try {
                foreach (widgetPhrases($db, true) as $p) {
                    $txt = ($feeLang === 'nl' && !empty($p['texte_nl'])) ? $p['texte_nl'] : ($p['texte'] ?? '');
                    $txt = trim((string) $txt);
                    if ($txt !== '') { $feeMsgs[] = $txt; }
                }
            } catch (Exception $e) {
                // base indisponible : on garde juste les blagues/fun facts.
            }
        }
        $feeMsgs = array_values(array_unique($feeMsgs));
        $jsMsgs = json_encode($feeMsgs, JSON_UNESCAPED_UNICODE);

        return <<<HTML
<div class="fee-back" id="feeBack" aria-hidden="true">
  <div class="fee-scene">
    <div class="fee-duo">
      {$fee}
      <div class="fee-etincelles" id="feeEtincelles"></div>
      {$plante}
    </div>
    <div class="fee-pct" id="feePct">0 %</div>
    <div class="fee-barre"><span id="feeBarre"></span></div>
    <div class="fee-txt" id="feeTxt">{$lblAttente}</div>
  </div>
</div>
<style>
.fee-back { position:fixed; inset:0; top:0; left:0; right:0; bottom:0; z-index:200000;
    background:radial-gradient(circle at 50% 40%, #14261a, #070d09);
    display:none; align-items:center; justify-content:center;
    opacity:0; transition:opacity .25s; }
.fee-back.on { display:flex; opacity:1; }

.fee-scene { text-align:center; width:min(560px, 92vw); }
.fee-duo { display:flex; align-items:flex-end; justify-content:center; gap:8px; position:relative; }

.fee-perso { width:min(210px, 38vw); height:auto; display:block;
    animation:feeFlotte 3s ease-in-out infinite; transform-origin:50% 70%; }
@keyframes feeFlotte { 0%,100% { transform:translateY(0) rotate(-1deg); } 50% { transform:translateY(-9px) rotate(1deg); } }

/* LES AILES battent, doucement (elles sont faites des feuilles du logo). */
.fee-ailes { animation:feeAiles 1.5s ease-in-out infinite; transform-origin:300px 300px; }
@keyframes feeAiles { 0%,100% { transform:scaleX(1); } 50% { transform:scaleX(.9); } }

/* LE COUP DE BAGUETTE : le bras pivote autour de l'épaule, sec puis retour souple. */
.fee-bras { animation:feeCoup 2s cubic-bezier(.36,.07,.3,1) infinite; transform-origin:340px 342px; }
@keyframes feeCoup {
    0%, 40% { transform:rotate(0); }
    52%     { transform:rotate(-26deg); }   /* on lève */
    62%     { transform:rotate(22deg); }    /* on frappe vers la graine */
    72%     { transform:rotate(-6deg); }
    82%,100%{ transform:rotate(0); }
}
.fee-halo { animation:feeHalo 2s ease-in-out infinite; transform-origin:405px 247px; }
@keyframes feeHalo { 0%,45%,100% { opacity:.5; transform:scale(.85); } 62% { opacity:1; transform:scale(1.35); } }
.fee-yeux { animation:feeCligne 4.5s infinite; transform-origin:300px 232px; }
@keyframes feeCligne { 0%,94%,100% { transform:scaleY(1); } 97% { transform:scaleY(.1); } }

.fee-plante { width:min(160px, 30vw); height:auto; display:block; }
/* Une seule plante visible à la fois. Elle SURGIT du terreau (pousse), puis se
   balance doucement jusqu'à ce que la suivante la remplace (3 s plus tard). */
.fee-plant { display:none; }
.fee-plant.on { display:block; animation:feePousse .55s cubic-bezier(.2,1.6,.4,1), feeBalance 3.2s ease-in-out .55s infinite; transform-origin:110px 172px; }
@keyframes feePousse { from { transform:scale(.15) translateY(20px); opacity:0; } to { transform:scale(1) translateY(0); opacity:1; } }
@keyframes feeBalance { 0%,100% { transform:rotate(-1.5deg); } 50% { transform:rotate(1.5deg); } }

/* Les étincelles partent de la baguette vers la graine, au rythme du coup. */
.fee-etincelles { position:absolute; left:52%; top:34%; width:1px; height:1px; }
.fee-et { position:absolute; width:9px; height:9px; background:#B5D95A; border-radius:2px;
    box-shadow:0 0 8px rgba(141,198,63,.9); animation:feeVole 1.4s ease-in forwards; }
@keyframes feeVole {
    0%   { transform:translate(0,0) scale(.4) rotate(0); opacity:0; }
    20%  { opacity:1; }
    100% { transform:translate(var(--dx), var(--dy)) scale(1.1) rotate(220deg); opacity:0; }
}

.fee-pct { font-size:2.4rem; font-weight:900; color:#A9C96B; margin-top:14px; line-height:1;
    font-variant-numeric:tabular-nums; }
.fee-barre { width:min(340px,80%); height:10px; margin:12px auto 0; background:rgba(255,255,255,.14);
    border-radius:999px; overflow:hidden; }
.fee-barre span { display:block; height:100%; width:0%; border-radius:999px;
    background:linear-gradient(90deg,#8DC63F,#1E6B33); transition:width .4s ease; }
.fee-txt { margin-top:12px; color:#DCEBD6; font-weight:700; font-size:.95rem; }

/* MODE INDÉTERMINÉ : le serveur travaille, mais il ne nous dit RIEN de son avancement
   (mise en forme IA, génération du quiz, traduction…). Afficher un pourcentage serait
   un mensonge : il défilerait sans rapport avec le travail réel. On montre donc une
   barre qui va-et-vient — elle dit « ça travaille », sans prétendre savoir combien il reste. */
.fee-back.indef .fee-pct { display:none; }
.fee-back.indef .fee-barre span { width:35% !important; animation:feeIndef 1.15s ease-in-out infinite; }
@keyframes feeIndef {
    0%   { transform:translateX(-115%); }
    100% { transform:translateX(320%); }
}

@media (prefers-reduced-motion: reduce) {
    .fee-perso, .fee-ailes, .fee-bras, .fee-halo, .fee-yeux, .fee-plant.on { animation:none; }
}
</style>
<script>
(function () {
    var back, pctEl, barEl, txtEl, etEl;
    var pct = 0, sparks = null, jardin = null, plantIdx = -1;
    // Blagues + fun facts jardinage + phrases du widget (base de données).
    var messages = {$jsMsgs};
    var msgIdx = -1;

    // Choisit un message AU HASARD (sans répéter le précédent) et l'affiche.
    function prochainMessage() {
        if (!txtEl || !messages.length) { return; }
        if (messages.length === 1) { msgIdx = 0; }
        else {
            var n = msgIdx;
            while (n === msgIdx) { n = Math.floor(Math.random() * messages.length); }
            msgIdx = n;
        }
        txtEl.textContent = messages[msgIdx];
    }

    function els() {
        if (!back) {
            back = document.getElementById('feeBack');
            pctEl = document.getElementById('feePct');
            barEl = document.getElementById('feeBarre');
            txtEl = document.getElementById('feeTxt');
            etEl  = document.getElementById('feeEtincelles');
        }
        return back;
    }

    // LE DÉFILÉ DE PLANTES : la fée fait pousser une plante différente toutes les 5 s
    // pour occuper l'attente (le serveur ne renvoie aucun avancement mesurable). On
    // révèle une seule plante à la fois, dans l'ordre où elles sont dessinées.
    function poussePlante() {
        var all = document.querySelectorAll('.fee-plant');
        if (!all.length) { return; }
        all.forEach(function (g) { g.classList.remove('on'); });
        plantIdx = (plantIdx + 1) % all.length;
        all[plantIdx].classList.add('on');
        prochainMessage();                           // une nouvelle blague/fun fact à chaque plante
    }
    function demarreJardin() {
        if (jardin) { return; }
        plantIdx = -1;
        poussePlante();                              // la première pousse tout de suite
        jardin = setInterval(poussePlante, 3000);    // puis une nouvelle toutes les 3 s
    }
    function arreteJardin() {
        if (jardin) { clearInterval(jardin); jardin = null; }
        document.querySelectorAll('.fee-plant').forEach(function (g) { g.classList.remove('on'); });
    }

    window.feeSet = function (p, txt) {
        if (!els()) { return; }
        pct = Math.max(0, Math.min(100, p));
        pctEl.textContent = Math.round(pct) + ' %';
        barEl.style.width = pct + '%';
        // Le texte n'est plus piloté ici : ce sont les blagues/fun facts qui défilent
        // (voir prochainMessage / demarreJardin). Le paramètre txt est ignoré.
    };

    window.feeShow = function (txt) {
        if (!els() || back.classList.contains('on')) { return; }
        back.classList.add('on');
        back.setAttribute('aria-hidden', 'false');
        window.feeSet(0);
        demarreJardin();                             // les plantes ET les messages se relaient
        // Étincelles : synchronisées sur le coup de baguette.
        if (!sparks) { sparks = setInterval(etincelle, 700); }
    };

    window.feeHide = function () {
        if (back) { back.classList.remove('indef'); }
        if (!els()) { return; }
        back.classList.remove('on');
        back.setAttribute('aria-hidden', 'true');
        arreteJardin();
        if (sparks) { clearInterval(sparks); sparks = null; }
    };

    function etincelle() {
        if (!etEl || !back.classList.contains('on')) { return; }
        var s = document.createElement('span');
        s.className = 'fee-et';
        s.style.setProperty('--dx', (60 + Math.random() * 70) + 'px');
        s.style.setProperty('--dy', (40 + Math.random() * 60) + 'px');
        s.style.left = (Math.random() * 14 - 7) + 'px';
        etEl.appendChild(s);
        setTimeout(function () { s.remove(); }, 1500);
    }

    /* La progression ESTIMÉE (quand le serveur ne nous dit rien) : elle RALENTIT en
       s'approchant de la cible et ne l'atteint jamais. C'est le comportement honnête
       — on ne sait pas, donc on n'affirme pas « 95 % » alors qu'on n'en sait rien. */
    // (feeCreep supprimée : elle fabriquait un faux pourcentage — voir feeIndef.)

    /* Écran d'attente SANS pourcentage : on ne sait pas combien de temps ça prendra. */
    window.feeIndef = function (txt) {
        var back = document.getElementById('feeBack');
        if (!back) { return; }
        back.classList.add('indef');
        window.feeShow(txt);
    };

    /* ---------- 1) IMPORT : vrai pourcentage sur l'envoi ---------- */
    function aDesFichiers(form) {
        var f = form.querySelectorAll('input[type=file]');
        for (var i = 0; i < f.length; i++) { if (f[i].files && f[i].files.length > 0) { return true; } }
        return false;
    }

    // UPLOAD UNIFIÉ : envoie le formulaire EN ARRIÈRE-PLAN (XHR) avec le VRAI pourcentage
    // d'envoi (0→100), puis mode indéterminé pendant que le serveur travaille, puis on va sur
    // la page renvoyée. Appelable DIRECTEMENT — donc utilisable même quand un bouton de modale
    // soumet le formulaire en JavaScript (form.submit() ne déclenche PAS l'événement submit,
    // c'est pour ça que la fée ne sortait pas à l'import).
    // Affiche la fee puis envoie le formulaire de facon NORMALE (fiable). Appelable
    // directement (les boutons de modale l'utilisent, car form.submit() ne declenche pas
    // l'evenement submit).
    window.feeUpload = function (form) {
        if (!form) { return; }
        window.feeIndef({$jsMagie});
        HTMLFormElement.prototype.submit.call(form); // envoi classique garanti
    };

    // Formulaire POST avec un fichier : on affiche la fee, MAIS on laisse la soumission
    // NORMALE se faire (pas de XHR). L'envoi par XHR faisait « ramer » puis echouer certains
    // uploads ; une soumission classique est fiable a 100 %. On perd le pourcentage exact,
    // pas grave : la fee tourne pendant l'envoi et disparait au rechargement.
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (e.defaultPrevented) { return; }
        if (!form || form.method.toLowerCase() !== 'post') { return; }
        if (!aDesFichiers(form)) { return; }
        window.feeIndef({$jsMagie});                   // pas de preventDefault : l'envoi continue
    }, false);

    /* ---------- 2) OPÉRATIONS LONGUES (data-fee) : écran d'attente SANS pourcentage ----------
       On n'affiche plus rien sur une navigation ordinaire : le navigateur ne dit RIEN de
       l'avancement d'un chargement de page. Un pourcentage y serait inventé, et un écran qui
       surgit après quelques secondes arrive toujours « en décalage » avec ce qu'on voit.
       On ne montre la fée que là où l'attente est CERTAINE et LONGUE : les appels à l'IA
       (génération du quiz, validation finale, restauration…), marqués data-fee. */
    document.addEventListener('submit', function (e) {
        if (e.defaultPrevented || aDesFichiers(e.target)) { return; }   // fichiers : géré plus haut
        var f = e.target;
        if (f && f.hasAttribute && f.hasAttribute('data-fee')) { window.feeIndef({$jsMagie}); }
    }, false);
    document.addEventListener('click', function (e) {
        var a = e.target.closest ? e.target.closest('a[data-fee]') : null;
        if (a) { window.feeIndef({$jsMagie}); }
    }, true);

    // Retour arrière du navigateur : la page revient du cache, la fée doit disparaître.
    window.addEventListener('pageshow', function () { window.feeHide(); });
})();
</script>
HTML;
    }
}
