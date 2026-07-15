<?php
// ============================================================
// fee.php — LA FÉE FAMIFLORA : animation d'attente.
//
// La fée agite sa baguette sur une GRAINE qui pousse au rythme de l'avancement :
// un nouvel état de la plante tous les 10 % (11 états, de la graine enfouie à la
// plante en fleur). Le pourcentage s'affiche dessous.
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
     * La GRAINE qui devient une plante. Les 11 états (un par tranche de 10 %) sont
     * tous dessinés ici, empilés ; le JS n'affiche que celui qui correspond.
     * Dessiner puis masquer coûte moins cher que de reconstruire du SVG à chaque
     * étape, et le changement d'état est instantané.
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

  <!-- Les 11 états. `data-p` = pourcentage à partir duquel l'état s'affiche. -->
  <g class="fee-stage" data-p="0"></g>

  <g class="fee-stage" data-p="10">
    <ellipse cx="110" cy="166" rx="8" ry="6" fill="#B07A46"/>
  </g>

  <g class="fee-stage" data-p="20">
    <ellipse cx="110" cy="164" rx="8.5" ry="6.5" fill="#C08A50"/>
    <path d="M104 162 L110 166 L116 161" stroke="#6E4523" stroke-width="1.6" fill="none"/>
  </g>

  <g class="fee-stage" data-p="30">
    <ellipse cx="110" cy="164" rx="8.5" ry="6.5" fill="#C08A50"/>
    <path d="M110 160 Q 109 150 112 144" stroke="#8DC63F" stroke-width="4" stroke-linecap="round" fill="none"/>
  </g>

  <g class="fee-stage" data-p="40">
    <path d="M110 168 Q 108 148 111 130" stroke="url(#feeTige)" stroke-width="5" stroke-linecap="round" fill="none"/>
    <path d="M111 132 Q 98 122 96 134 Q 104 140 111 132 Z" fill="#8DC63F"/>
  </g>

  <g class="fee-stage" data-p="50">
    <path d="M110 168 Q 108 142 111 118" stroke="url(#feeTige)" stroke-width="5.5" stroke-linecap="round" fill="none"/>
    <path d="M111 122 Q 92 110 88 126 Q 100 136 111 122 Z" fill="#8DC63F"/>
    <path d="M111 128 Q 130 116 134 132 Q 122 142 111 128 Z" fill="#A9D454"/>
  </g>

  <g class="fee-stage" data-p="60">
    <path d="M110 168 Q 107 134 111 104" stroke="url(#feeTige)" stroke-width="6" stroke-linecap="round" fill="none"/>
    <path d="M110 110 Q 86 96 80 116 Q 96 128 110 110 Z" fill="#8DC63F"/>
    <path d="M111 118 Q 136 104 142 124 Q 125 136 111 118 Z" fill="#A9D454"/>
    <path d="M109 140 Q 90 132 88 146 Q 100 152 109 140 Z" fill="#2E7D3B"/>
  </g>

  <g class="fee-stage" data-p="70">
    <path d="M110 168 Q 106 128 111 92" stroke="url(#feeTige)" stroke-width="6.5" stroke-linecap="round" fill="none"/>
    <path d="M110 98 Q 82 82 76 104 Q 94 118 110 98 Z" fill="#8DC63F"/>
    <path d="M111 108 Q 140 92 146 114 Q 127 128 111 108 Z" fill="#A9D454"/>
    <path d="M109 134 Q 84 124 82 142 Q 98 150 109 134 Z" fill="#2E7D3B"/>
    <path d="M112 142 Q 137 132 139 150 Q 123 158 112 142 Z" fill="#2E7D3B"/>
  </g>

  <g class="fee-stage" data-p="80">
    <path d="M110 168 Q 106 122 111 82" stroke="url(#feeTige)" stroke-width="7" stroke-linecap="round" fill="none"/>
    <path d="M110 96 Q 80 78 74 102 Q 93 118 110 96 Z" fill="#8DC63F"/>
    <path d="M111 106 Q 142 88 148 112 Q 128 128 111 106 Z" fill="#A9D454"/>
    <path d="M109 134 Q 82 122 80 142 Q 97 151 109 134 Z" fill="#2E7D3B"/>
    <path d="M112 142 Q 139 130 141 150 Q 124 159 112 142 Z" fill="#2E7D3B"/>
    <ellipse cx="111" cy="74" rx="11" ry="14" fill="#7FB539"/>
    <path d="M111 60 Q 104 68 106 78" stroke="#5E9430" stroke-width="2" fill="none"/>
  </g>

  <g class="fee-stage" data-p="90">
    <path d="M110 168 Q 106 120 111 78" stroke="url(#feeTige)" stroke-width="7" stroke-linecap="round" fill="none"/>
    <path d="M110 96 Q 80 78 74 102 Q 93 118 110 96 Z" fill="#8DC63F"/>
    <path d="M111 106 Q 142 88 148 112 Q 128 128 111 106 Z" fill="#A9D454"/>
    <path d="M109 134 Q 82 122 80 142 Q 97 151 109 134 Z" fill="#2E7D3B"/>
    <path d="M112 142 Q 139 130 141 150 Q 124 159 112 142 Z" fill="#2E7D3B"/>
    <g transform="translate(111,68)">
      <ellipse cx="-13" cy="0" rx="10" ry="7" fill="#F2A9C4"/>
      <ellipse cx="13" cy="0" rx="10" ry="7" fill="#F2A9C4"/>
      <ellipse cx="0" cy="-11" rx="7" ry="10" fill="#F6BDD2"/>
      <circle r="6" fill="#E8C46B"/>
    </g>
  </g>

  <!-- 100 % : la plante en pleine fleur -->
  <g class="fee-stage" data-p="100">
    <path d="M110 168 Q 106 118 111 74" stroke="url(#feeTige)" stroke-width="7.5" stroke-linecap="round" fill="none"/>
    <path d="M110 94 Q 78 74 72 100 Q 92 118 110 94 Z" fill="#8DC63F"/>
    <path d="M111 104 Q 144 84 150 110 Q 129 128 111 104 Z" fill="#A9D454"/>
    <path d="M109 132 Q 80 118 78 140 Q 96 151 109 132 Z" fill="#2E7D3B"/>
    <path d="M112 140 Q 141 126 143 148 Q 125 159 112 140 Z" fill="#2E7D3B"/>
    <g class="fee-fleur" transform="translate(111,60)">
      <ellipse cx="-17" cy="0" rx="13" ry="9" fill="#F2A9C4"/>
      <ellipse cx="17" cy="0" rx="13" ry="9" fill="#F2A9C4"/>
      <ellipse cx="0" cy="-16" rx="9" ry="13" fill="#F6BDD2"/>
      <ellipse cx="0" cy="16" rx="9" ry="13" fill="#F6BDD2"/>
      <ellipse cx="-12" cy="-12" rx="9" ry="9" fill="#F5B6CD" transform="rotate(-45)"/>
      <circle r="9" fill="#E8C46B"/>
      <circle r="4" fill="#D0A03F"/>
    </g>
    <circle cx="150" cy="60" r="3.5" fill="#B5D95A"/>
    <circle cx="70" cy="76" r="3" fill="#B5D95A"/>
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
.fee-stage { display:none; }
.fee-stage.on { display:block; animation:feePousse .5s cubic-bezier(.2,1.5,.4,1); transform-origin:110px 168px; }
@keyframes feePousse { from { transform:scale(.5); opacity:0; } }
.fee-fleur { animation:feeFleur 2.4s ease-in-out infinite; }
@keyframes feeFleur { 0%,100% { transform:translate(111px,60px) scale(1); } 50% { transform:translate(111px,60px) scale(1.08); } }

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
    .fee-perso, .fee-ailes, .fee-bras, .fee-halo, .fee-yeux, .fee-fleur { animation:none; }
}
</style>
<script>
(function () {
    var back, pctEl, barEl, txtEl, etEl;
    var pct = 0, creep = null, sparks = null;

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

    // Un état de plante TOUS LES 10 % : on prend le palier inférieur.
    function stage(p) {
        var pal = Math.min(100, Math.floor(p / 10) * 10);
        document.querySelectorAll('.fee-stage').forEach(function (g) {
            var on = (parseInt(g.getAttribute('data-p'), 10) === pal);
            if (on !== g.classList.contains('on')) { g.classList.toggle('on', on); }
        });
    }

    window.feeSet = function (p, txt) {
        if (!els()) { return; }
        pct = Math.max(0, Math.min(100, p));
        pctEl.textContent = Math.round(pct) + ' %';
        barEl.style.width = pct + '%';
        if (txt) { txtEl.textContent = txt; }
        stage(pct);
    };

    window.feeShow = function (txt) {
        if (!els() || back.classList.contains('on')) { return; }
        back.classList.add('on');
        back.setAttribute('aria-hidden', 'false');
        window.feeSet(0, txt || {$jsAttente});
        // Étincelles : synchronisées sur le coup de baguette (toutes les 2 s).
        if (!sparks) { sparks = setInterval(etincelle, 700); }
    };

    window.feeHide = function () {
        if (back) { back.classList.remove('indef'); }
        if (!els()) { return; }
        back.classList.remove('on');
        back.setAttribute('aria-hidden', 'true');
        if (creep) { clearInterval(creep); creep = null; }
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

    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (e.defaultPrevented) { return; }            // une validation a déjà refusé
        if (!form || form.method.toLowerCase() !== 'post') { return; }
        if (!aDesFichiers(form)) { return; }           // pas de fichier → chargement normal
        if (form.hasAttribute && form.hasAttribute('data-nofee')) {
            // Upload admin (habillage, intro/outro) : soumission NORMALE (pas de XHR), mais
            // on affiche quand meme la fee pendant l'envoi -> elle reste visible jusqu'au
            // rechargement de la page. On ne prend PAS la main (pas de preventDefault).
            window.feeIndef({$jsMagie});
            return;
        }
        if (!window.FormData || !window.XMLHttpRequest) { return; }

        e.preventDefault();
        window.feeShow({$jsEnvoi});

        var xhr = new XMLHttpRequest();
        xhr.open('POST', form.action || location.href, true);

        // L'ENVOI : le navigateur donne le VRAI pourcentage → 0 à 100 %, aucune invention.
        xhr.upload.onprogress = function (ev) {
            if (ev.lengthComputable) { window.feeSet((ev.loaded / ev.total) * 100, {$jsEnvoi}); }
        };
        // Fichier parti, le serveur travaille (IA) : il ne nous dit RIEN de son avancement.
        // On passe donc en mode INDÉTERMINÉ plutôt que de faire défiler un faux compteur.
        xhr.upload.onload = function () { window.feeIndef({$jsMagie}); };

        xhr.onload = function () {
            location.href = xhr.responseURL || location.href;
        };
        // REPLI : si l'envoi par XHR échoue, on refait un envoi CLASSIQUE. L'import ne
        // doit jamais être casse par l'animation — c'est le coeur du site.
        xhr.onerror = function () { window.feeHide(); form.submit(); };

        xhr.send(new FormData(form));
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
