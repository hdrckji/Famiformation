<?php
// ============================================================
// video_view.php — rendu DESIGNÉ de la page vidéo (gabarit Famiformation).
//   En-tête « videohero » + lecteur « player » (anti-avance-rapide) + « quizcta ».
//   Même identité que la fiche (content_view.php). CSS scopé sous .fami-vid.
// ============================================================

if (!function_exists('_vidUrl')) {
    function _vidUrl($key)
    {
        return function_exists('moduleFileUrl') ? moduleFileUrl($key) : ('media.php?f=' . rawurlencode((string) $key));
    }
}

if (!function_exists('renderVideoPage')) {
    /** Affiche la page vidéo d'un sous-module (ou d'un module vidéo). */
    function renderVideoPage(array $module, $isAdmin = false, $quizHref = '')
    {
        $title = function_exists('moduleNom') ? moduleNom($module) : (string) ($module['nom'] ?? t('Formation', 'Opleiding'));
        $descRaw = function_exists('moduleDesc') ? moduleDesc($module) : (string) ($module['description'] ?? '');
        $status = (string) ($module['video_status'] ?? '');
        $hasVideo = !empty($module['video_path']);
        $videoUrl = $hasVideo ? _vidUrl($module['video_path']) : '';

        // FILET DE SÉCURITÉ : la compression 720p tourne en tâche de fond et peut ne jamais
        // partir (worker injoignable). Plutôt que de laisser l'apprenant sur « en préparation… »
        // indéfiniment, on sert la vidéo D'ORIGINE : mp4 et mov sont lus par les navigateurs.
        // La compression, si elle finit un jour, prendra simplement le relais.
        $rawFallback = false;
        if (!$hasVideo && !empty($module['video_src_path'])) {
            $rawExt = strtolower(pathinfo((string) $module['video_src_path'], PATHINFO_EXTENSION));
            if (in_array($rawExt, ['mp4', 'mov', 'm4v'], true)) {
                $hasVideo = true;
                $rawFallback = true;
                $videoUrl = _vidUrl($module['video_src_path']);
            }
        }
        $quizAvailable = ($quizHref !== '');

        // HABILLAGE : image de fond DERRIÈRE le lecteur (Paramètres → Préférences → Créateur).
        // Une vidéo 9:16 ne couvre pas toute la boîte 16:9 : au lieu de bandes NOIRES, on voit
        // cette image. Une vidéo 16:9 la recouvre entièrement, donc elle ne se voit pas.
        // (branding.php doit être chargé ICI : il ne l'était que dans les paramètres, donc
        //  function_exists() renvoyait faux et l'image n'était jamais affichée.)
        require_once __DIR__ . '/branding.php';
        $backdrop = '';
        global $db;
        if (isset($db) && $db instanceof PDO) { $backdrop = brandingVideoBackdropUrl($db); }
        if ($backdrop !== '') {
            $backdrop .= (strpos($backdrop, '?') !== false ? '&' : '?')
                . 'l=' . (function_exists('currentLang') ? currentLang() : 'fr');
        }
        // Le quiz est-il validé ? Tant qu'il ne l'est pas, on ne peut pas SAUTER librement à
        // l'intro ou à l'outro : on regarde le déroulé normal (intro → formation → fin). Seul
        // le bouton « La formation » reste actif. La navigation se débloque de DEUX façons :
        //  • on a regardé jusqu'au bout (fin de l'outro) → déblocage pour la session (JS) ;
        //  • on a VALIDÉ le quiz → déblocage définitif (ci-dessous, côté serveur).
        // NB : l'admin n'a PLUS de passe-droit ici — c'était pour ça que la navigation
        // paraissait « libre » : en test admin, tout était déverrouillé d'emblée.
        require_once __DIR__ . '/quiz_pass.php';
        global $db;
        $pid = !empty($module['parent_id']) ? (int) $module['parent_id'] : (int) ($module['id'] ?? 0);
        $quizOk = (isset($db) && $db instanceof PDO) ? quizUserPassed($db, $pid, (int) ($_SESSION['user_id'] ?? 0)) : false;

        // INTRO / OUTRO : jouées avant et après la formation. On ne colle rien bout à bout —
        // le lecteur enchaîne les fichiers (voir plus bas). Changer l'intro change toutes les
        // vidéos aussitôt, sans le moindre ré-encodage.
        $introUrl = '';
        $outroUrl = '';
        if (isset($db) && $db instanceof PDO) {
            $introUrl = brandingClipUrl($db, 'intro');
            $outroUrl = brandingClipUrl($db, 'outro');
        }

        $frameCls = $backdrop !== '' ? ' has-backdrop' : '';
        $frameSty = $backdrop !== '' ? ' style="background-image:url(&quot;' . htmlspecialchars($backdrop, ENT_QUOTES) . '&quot;);"' : '';

        // Sous-titre : seulement si une description existe (jamais de texte par défaut).
        $subtitle = trim($descRaw) !== ''
            ? '<p class="videohero__subtitle">' . htmlspecialchars($descRaw) . '</p>'
            : '';
        // Méta : on n'affiche que ce que l'on sait réellement.
        $meta = $quizAvailable ? '<ul class="videohero__meta"><li>' . t('Quiz en fin de vidéo', 'Quiz na de video') . '</li></ul>' : '';

        $flora = '<svg class="videohero__flora" viewBox="0 0 1200 500" preserveAspectRatio="xMidYMid slice" aria-hidden="true">'
            . '<path class="flora--soft flora--draw" d="M -30 520 C 110 400, 140 290, 170 160 C 184 100, 214 50, 270 20"/>'
            . '<path class="flora--soft" d="M 140 340 q 82 -44 96 -126 q -90 24 -96 126"/>'
            . '<path class="flora--soft" d="M 156 262 q -78 -30 -98 -110 q 86 12 98 110"/>'
            . '<path class="flora--faint" d="M 176 180 q 68 -40 80 -108 q -76 20 -80 108"/>'
            . '<path class="flora--soft flora--draw" d="M 1230 480 C 1090 370, 1064 260, 1040 130 C 1028 76, 1000 34, 950 8"/>'
            . '<path class="flora--soft" d="M 1062 320 q -84 -42 -100 -122 q 92 22 100 122"/>'
            . '<path class="flora--soft" d="M 1048 244 q 76 -32 94 -110 q -84 14 -94 110"/>'
            . '<path class="flora--bright flora--draw" d="M 76 500 q 34 -110 26 -210"/>'
            . '<path class="flora--bright" d="M 88 390 q 46 -22 55 -74 q -52 12 -55 74"/>'
            . '<path class="flora--bright flora--draw" d="M 1126 490 q -30 -104 -20 -196"/>'
            . '</svg>';
        ?>
        <style>
        .fami-vid{ --paper:#F7F8F2; --paper-deep:#EEF1E6; --ink:#21301F; --ink-soft:#46543F; --forest:#1E4D2B; --leaf:#3E8E4E; --moss:#74975B; --sprout:#A9C96B; --line:#D8DECB;
            --font-display:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif;
            --font-body:Charter,"Bitstream Charter","Sitka Text",Cambria,Georgia,"Times New Roman",serif;
            --font-label:ui-monospace,"SF Mono","Cascadia Mono","Segoe UI Mono",Consolas,"Liberation Mono",monospace;
            --measure:900px; --radius:14px; --shadow:0 1px 2px rgba(30,55,30,.06),0 8px 28px rgba(30,55,30,.07);
            width:100%; background:var(--paper); color:var(--ink); font-family:var(--font-body); font-size:1.075rem; line-height:1.7; -webkit-font-smoothing:antialiased; text-rendering:optimizeLegibility; }
        .fami-vid *, .fami-vid *::before, .fami-vid *::after{ box-sizing:border-box; }
        .fami-vid .videohero{ position:relative; overflow:hidden; color:#F3F7EE; background:radial-gradient(120% 100% at 85% -20%,#2F6B3C 0%,transparent 55%),linear-gradient(160deg,#17381F 0%,var(--forest) 60%,#235831 100%); border-radius:0 0 28px 28px; padding:clamp(40px,6vw,64px) 24px clamp(120px,16vw,180px); }
        .fami-vid .videohero__flora{ position:absolute; inset:0; width:100%; height:100%; pointer-events:none; }
        .fami-vid .videohero__flora path{ fill:none; stroke:#BFE0B8; stroke-width:1.5; stroke-linecap:round; vector-effect:non-scaling-stroke; }
        .fami-vid .videohero__flora .flora--soft{ stroke-opacity:.18; } .fami-vid .videohero__flora .flora--faint{ stroke-opacity:.10; } .fami-vid .videohero__flora .flora--bright{ stroke:var(--sprout); stroke-opacity:.5; }
        .fami-vid .videohero__flora .flora--draw{ stroke-dasharray:900; animation:vid-flora-draw 2.2s cubic-bezier(.4,0,.2,1) both; }
        .fami-vid .videohero__inner{ position:relative; max-width:var(--measure); margin:0 auto; text-align:center; animation:vid-rise .8s ease-out both; }
        .fami-vid .videohero__brand{ font-family:var(--font-label); font-size:.78rem; letter-spacing:.26em; text-transform:uppercase; color:var(--sprout); margin:0 0 18px; }
        .fami-vid .videohero__brand::before,.fami-vid .videohero__brand::after{ content:""; display:inline-block; width:10px; height:10px; background:var(--sprout); border-radius:0 70% 0 70%; transform:rotate(45deg); margin:0 14px; vertical-align:middle; }
        .fami-vid .videohero__title{ font-family:var(--font-display); font-weight:800; font-size:clamp(2rem,5.6vw,3.4rem); line-height:1.08; letter-spacing:-.02em; margin:0 0 14px; text-wrap:balance; }
        .fami-vid .videohero__subtitle{ font-size:clamp(1.05rem,2.4vw,1.25rem); line-height:1.55; color:#DEEBD6; max-width:54ch; margin:0 auto 24px; text-wrap:balance; }
        .fami-vid .videohero__meta{ display:flex; flex-wrap:wrap; justify-content:center; gap:10px; padding:0; margin:0; list-style:none; }
        .fami-vid .videohero__meta li{ font-family:var(--font-label); font-size:.8rem; letter-spacing:.04em; background:rgba(255,255,255,.10); border:1px solid rgba(255,255,255,.28); border-radius:999px; padding:7px 15px 7px 12px; display:inline-flex; align-items:center; gap:8px; }
        .fami-vid .videohero__meta li::before{ content:""; width:9px; height:9px; background:var(--sprout); border-radius:0 70% 0 70%; transform:rotate(45deg); flex:none; }
        .fami-vid .player{ max-width:var(--measure); margin:calc(clamp(120px,16vw,180px) * -0.62) auto 0; padding:0 24px; position:relative; animation:vid-rise .9s ease-out .15s both; }
        .fami-vid .player__frame{ position:relative; border-radius:18px; overflow:hidden; background:#10241633; border:1px solid rgba(255,255,255,.5); box-shadow:0 2px 6px rgba(20,40,22,.18),0 24px 60px rgba(20,40,22,.28); }
        .fami-vid .player__video{ display:block; width:100%; aspect-ratio:16/9; background:#0c1a11; object-fit:contain; }
        /* Habillage actif : le fond du lecteur devient l'image, et la video se pose dessus. */
        .fami-vid .player__frame.has-backdrop{ background-size:cover; background-position:center; background-repeat:no-repeat; }
        /* La vidéo porte la MÊME image en fond : en plein écran, le navigateur ne met que la
           balise <video> en grand (le cadre disparaît). Sans ça, l'habillage s'évanouissait
           et les bandes redevenaient noires dès qu'on passait en plein écran. */
        .fami-vid .player__frame.has-backdrop .player__video{ background-size:cover; background-position:center; background-repeat:no-repeat; }
        .fami-vid .player__video:fullscreen{ background-size:cover; background-position:center; }
        .fami-vid .player__seg{ position:absolute; top:14px; left:14px; z-index:3; background:rgba(20,40,22,.78); color:#EAF3E6;
            font-family:var(--font-label); font-size:.72rem; letter-spacing:.12em; text-transform:uppercase; font-weight:700;
            padding:6px 12px; border-radius:999px; pointer-events:none; }
        .fami-vid .player__segnav{ display:flex; gap:8px; flex-wrap:wrap; justify-content:center; margin-top:14px; }
        .fami-vid .segbtn{ border:1px solid var(--line); background:#fff; color:var(--ink-soft); font:inherit; font-weight:700;
            font-size:.84rem; border-radius:999px; padding:8px 16px; cursor:pointer; transition:all .14s; }
        .fami-vid .segbtn:hover{ border-color:var(--leaf); color:var(--forest); }
        .fami-vid .segbtn.on{ background:var(--forest); color:#fff; border-color:var(--forest); }
        /* « La formation » : plus gros, pour qu'on clique dessus par instinct. */
        .fami-vid .segbtn--main{ font-size:.98rem; padding:11px 24px; font-weight:800; }
        /* Verrouillé (quiz non validé) : visible mais transparent et non cliquable. */
        .fami-vid .segbtn.locked{ opacity:.4; cursor:not-allowed; }
        .fami-vid .segbtn.locked:hover{ border-color:var(--line); color:var(--ink-soft); }
        .fami-vid .player__caption{ font-family:var(--font-label); font-size:.8rem; color:var(--ink-soft); margin-top:14px; padding-left:14px; border-left:3px solid var(--sprout); }
        .fami-vid .player__note{ text-align:center; color:#7a8a80; font-size:.82rem; margin-top:10px; }
        .fami-vid .videostate{ max-width:var(--measure); margin:calc(clamp(120px,16vw,180px) * -0.5) auto 0; padding:0 24px; }
        .fami-vid .videostate__card{ background:#fff; border:1px solid var(--line); border-radius:18px; box-shadow:var(--shadow); padding:40px 28px; text-align:center; }
        .fami-vid .videostate__icon{ font-size:2.6rem; }
        .fami-vid .videostate__title{ font-family:var(--font-display); font-weight:800; color:var(--forest); font-size:1.2rem; margin:8px 0 6px; }
        .fami-vid .videostate__text{ color:var(--ink-soft); margin:0; }
        .fami-vid .quizcta{ max-width:var(--measure); margin:clamp(40px,7vw,64px) auto 0; padding:0 24px; text-align:center; }
        .fami-vid .quizcta__rule{ height:12px; border:0; margin:0 auto 26px; width:120px; background:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='120' height='12' viewBox='0 0 120 12'%3E%3Cpath d='M0 8 H 96' stroke='%2374975B' stroke-width='2' stroke-linecap='round'/%3E%3Cpath d='M96 8 q 10 -8 22 -7 q -4 10 -16 9 q -4 0 -6 -2 z' fill='%233E8E4E'/%3E%3C/svg%3E") center / 120px 12px no-repeat; }
        .fami-vid .quizcta__lead{ font-size:clamp(1.05rem,2.4vw,1.2rem); color:var(--ink-soft); max-width:46ch; margin:0 auto 26px; text-wrap:balance; }
        .fami-vid .quizcta__lead strong{ color:var(--forest); }
        .fami-vid .quizcta__button{ font-family:var(--font-display); font-weight:800; font-size:1.1rem; text-decoration:none; color:#F3F7EE; background:linear-gradient(180deg,#2A6339 0%,var(--forest) 100%); border:1px solid #163A20; border-radius:999px; padding:17px 38px; display:inline-flex; align-items:center; gap:12px; box-shadow:0 10px 30px rgba(30,77,43,.35),inset 0 1px 0 rgba(255,255,255,.25); transition:transform .18s ease,box-shadow .18s ease; }
        .fami-vid .quizcta__button:hover{ transform:translateY(-2px); box-shadow:0 14px 36px rgba(30,77,43,.45),inset 0 1px 0 rgba(255,255,255,.25); color:#F3F7EE; }
        .fami-vid .quizcta__button::before{ content:""; width:11px; height:11px; background:var(--sprout); border-radius:0 70% 0 70%; transform:rotate(45deg); flex:none; }
        .fami-vid .quizcta__button .arrow{ transition:transform .18s ease; } .fami-vid .quizcta__button:hover .arrow{ transform:translateX(4px); }
        .fami-vid .quizcta__hint{ font-family:var(--font-label); font-size:.74rem; letter-spacing:.12em; text-transform:uppercase; color:var(--ink-soft); margin:16px 0 0; }
        .fami-vid .vid-footer{ padding:clamp(36px,6vw,56px) 24px 8px; text-align:center; font-family:var(--font-label); font-size:.74rem; letter-spacing:.14em; text-transform:uppercase; color:var(--ink-soft); }
        @keyframes vid-flora-draw{ from{ stroke-dashoffset:900; } to{ stroke-dashoffset:0; } }
        @keyframes vid-rise{ from{ opacity:0; transform:translateY(12px); } to{ opacity:1; transform:none; } }
        @media (prefers-reduced-motion: reduce){ .fami-vid *{ animation:none !important; transition:none !important; } }
        </style>

        <div class="fami-vid">
            <header class="videohero">
                <?= $flora ?>
                <div class="videohero__inner">
                    <p class="videohero__brand">Famiformation</p>
                    <h1 class="videohero__title"><?= htmlspecialchars($title) ?></h1>
                    <?= $subtitle ?>
                    <?= $meta ?>
                </div>
            </header>

            <?php if ($hasVideo && ($rawFallback || ($status !== 'processing' && $status !== 'failed'))): ?>
                <main class="player">
                    <figure style="margin:0;">
                        <div class="player__frame<?= $frameCls ?>"<?= $frameSty ?>>
                            <?php
                                // SOUS-TITRES BILINGUES. Les navigateurs ne lisent que le WebVTT
                                // (d'où la conversion faite par le worker). La piste de la langue
                                // courante est activée par défaut : un néerlandophone voit du NL
                                // sans rien régler.
                                $subFr = trim((string) ($module['sub_fr_path'] ?? ''));
                                $subNl = trim((string) ($module['sub_nl_path'] ?? ''));
                                $isNl = (function_exists('currentLang') && currentLang() === 'nl');
                            ?>
                            <video id="famiVideo" class="player__video"<?= $frameSty ?> controls controlsList="nodownload" playsinline preload="auto"<?= ($subFr !== '' || $subNl !== '') ? ' crossorigin="anonymous"' : '' ?>>
                                <source src="<?= htmlspecialchars($videoUrl) ?>">
                                <?php if ($subFr !== ''): ?>
                                    <track kind="subtitles" srclang="fr" label="<?= t('Français', 'Frans') ?>"
                                           src="<?= htmlspecialchars(moduleFileUrl($subFr)) ?>"
                                           <?= $isNl ? '' : 'default' ?>>
                                <?php endif; ?>
                                <?php if ($subNl !== ''): ?>
                                    <track kind="subtitles" srclang="nl" label="<?= t('Néerlandais', 'Nederlands') ?>"
                                           src="<?= htmlspecialchars(moduleFileUrl($subNl)) ?>"
                                           <?= $isNl ? 'default' : '' ?>>
                                <?php endif; ?>
                                <?= t('Votre navigateur ne peut pas lire cette vidéo.', 'Uw browser kan deze video niet afspelen.') ?>
                            </video>
                            <span class="player__seg" id="famiSeg" hidden></span>
                        </div>
                        <?php if ($introUrl !== '' || $outroUrl !== ''): ?>
                        <nav class="player__segnav" id="famiSegNav" aria-label="<?= t('Navigation dans la vidéo', 'Navigatie in de video') ?>">
                            <?php if ($introUrl !== ''): ?>
                                <button type="button" class="segbtn<?= $quizOk ? '' : ' locked' ?>" data-seg="intro"<?= $quizOk ? '' : ' disabled' ?> title="<?= $quizOk ? '' : htmlspecialchars(t("Regardez la vidéo jusqu'au bout pour débloquer la navigation", 'Bekijk de video tot het einde om de navigatie vrij te maken'), ENT_QUOTES) ?>">▶ <?= t('Introduction', 'Inleiding') ?></button>
                            <?php endif; ?>
                            <button type="button" class="segbtn segbtn--main" data-seg="main">🎬 <?= t('La formation', 'De opleiding') ?></button>
                            <?php if ($outroUrl !== ''): ?>
                                <button type="button" class="segbtn<?= $quizOk ? '' : ' locked' ?>" data-seg="outro"<?= $quizOk ? '' : ' disabled' ?> title="<?= $quizOk ? '' : htmlspecialchars(t("Regardez la vidéo jusqu'au bout pour débloquer la navigation", 'Bekijk de video tot het einde om de navigatie vrij te maken'), ENT_QUOTES) ?>">🏁 <?= t('Fin', 'Slot') ?></button>
                            <?php endif; ?>
                        </nav>
                        <?php endif; ?>
                        <p class="player__note">⏱️ <?= t('Avance rapide désactivée — le retour en arrière reste possible.', 'Vooruitspoelen uitgeschakeld — terugspoelen blijft mogelijk.') ?></p>
                    </figure>
                </main>
                <script>
                (function () {
                    var v = document.getElementById('famiVideo');
                    if (!v) { return; }

                    // ── FOND (bandes) : UNIQUEMENT pour une vidéo VERTICALE (9:16). Avant, le
                    // fond etait pose sur toutes les videos en comptant sur object-fit pour le
                    // masquer en 16:9 — mais sur mobile, des arrondis de pixels le laissaient
                    // depasser. On regarde donc les VRAIES dimensions : fond seulement si portrait.
                    var BACKDROP = <?= json_encode($backdrop !== '' ? $backdrop : '', JSON_UNESCAPED_SLASHES) ?>;
                    var frameEl = v.closest ? v.closest('.player__frame') : null;
                    function applyBackdrop() {
                        if (!BACKDROP) { return; }
                        var portrait = v.videoWidth && v.videoHeight && (v.videoWidth / v.videoHeight) < 1.05;
                        var css = portrait ? ('url("' + BACKDROP + '")') : 'none';
                        if (frameEl) { frameEl.style.backgroundImage = css; }
                        v.style.backgroundImage = css;
                    }
                    v.addEventListener('loadedmetadata', applyBackdrop); // se rejoue a chaque segment
                    // Course possible : si les métadonnées sont DÉJÀ chargées quand ce script
                    // s'exécute (vidéo unique dont la <source> est dans le HTML), l'événement
                    // est déjà passé — on applique donc le fond tout de suite. Sinon le fond
                    // d'une 9:16 ne s'affichait jamais et on gardait des bandes noires.
                    if (v.readyState >= 1) { applyBackdrop(); }

                    // ── LISTE DE LECTURE : intro → formation → outro.
                    // Les fichiers ne sont PAS collés (aucun ré-encodage) : on change la source
                    // du lecteur à la fin de chaque segment. Les sous-titres n'appartiennent
                    // qu'à la formation, on les masque pendant l'intro et l'outro.
                    var MAIN = <?= json_encode($videoUrl, JSON_UNESCAPED_SLASHES) ?>;
                    var INTRO = <?= json_encode($introUrl, JSON_UNESCAPED_SLASHES) ?>;
                    var OUTRO = <?= json_encode($outroUrl, JSON_UNESCAPED_SLASHES) ?>;

                    var seq = [];
                    if (INTRO) { seq.push({ url: INTRO, kind: 'intro' }); }
                    seq.push({ url: MAIN, kind: 'main' });
                    if (OUTRO) { seq.push({ url: OUTRO, kind: 'outro' }); }

                    var i = 0;
                    var maxT = 0;              // progression atteinte DANS le segment courant
                    var badge = document.getElementById('famiSeg');

                    function tracksVisible(on) {
                        for (var k = 0; k < v.textTracks.length; k++) {
                            var tt = v.textTracks[k];
                            if (!on) { tt.mode = 'disabled'; }
                            else if (tt.mode === 'disabled') { tt.mode = (k === <?= $isNl && $subNl !== '' ? 1 : 0 ?>) ? 'showing' : 'hidden'; }
                        }
                    }

                    function load(n, autoplay) {
                        i = n;
                        maxT = 0;
                        v.src = seq[i].url;
                        v.load();
                        var isMain = (seq[i].kind === 'main');
                        tracksVisible(isMain);
                        if (badge) {
                            badge.hidden = isMain;
                            badge.textContent = (seq[i].kind === 'intro')
                                ? <?= json_encode(t('Introduction', 'Inleiding')) ?>
                                : <?= json_encode(t('Fin', 'Slot')) ?>;
                        }
                        if (autoplay) { v.play().catch(function () { /* le navigateur peut refuser : l'utilisateur relancera */ }); }
                    }

                    if (seq.length > 1) { load(0, false); }

                    v.addEventListener('timeupdate', function () {
                        if (!v.seeking) { maxT = Math.max(maxT, v.currentTime); }
                        // « Vue » = la FORMATION est vue à 95 % (l'intro et l'outro ne comptent pas).
                        if (seq[i].kind === 'main' && v.duration > 0 && v.currentTime >= v.duration * 0.95) {
                            window._famiVideoDone = true;
                        }
                    });

                    v.addEventListener('ended', function () {
                        if (seq[i].kind === 'main') { window._famiVideoDone = true; }
                        if (i + 1 < seq.length) { load(i + 1, true); }   // segment suivant, sans coupure
                        else { unlockNav(); }                            // fin de l'outro -> navigation libre
                    });

                    // Avance rapide interdite : sur CHAQUE segment (on ne bricole pas la barre).
                    v.addEventListener('seeking', function () {
                        if (v.currentTime > maxT + 1) { v.currentTime = maxT; }
                    });

                    // ── NAVIGATION entre les segments : on ne reste plus prisonnier de la
                    // formation. Un clic revient à l'introduction ou saute à la fin.
                    // (Le compteur « vu » ne bouge que sur la formation : sauter l'intro ne
                    //  déverrouille rien, et revenir sur l'intro ne fait rien perdre.)
                    var nav = document.getElementById('famiSegNav');
                    // Une fois la video regardee jusqu'au bout (fin de l'outro), on debloque les
                    // onglets : l'apprenant peut alors revenir librement sur l'intro/la fin.
                    function unlockNav() {
                        if (!nav) { return; }
                        nav.querySelectorAll('.segbtn.locked').forEach(function (b) {
                            b.classList.remove('locked');
                            b.disabled = false;
                            b.removeAttribute('title');
                        });
                    }
                    function markNav() {
                        if (!nav) { return; }
                        nav.querySelectorAll('.segbtn').forEach(function (b) {
                            b.classList.toggle('on', b.getAttribute('data-seg') === seq[i].kind);
                        });
                    }
                    if (nav) {
                        nav.querySelectorAll('.segbtn').forEach(function (b) {
                            b.addEventListener('click', function () {
                                if (b.disabled || b.classList.contains('locked')) { return; }
                                var want = b.getAttribute('data-seg');
                                for (var k = 0; k < seq.length; k++) {
                                    if (seq[k].kind === want) {
                                        if (k !== i) { load(k, true); }
                                        else { v.currentTime = 0; v.play().catch(function () {}); }
                                        markNav();
                                        return;
                                    }
                                }
                            });
                        });
                        markNav();
                    }
                    var _load = load;
                    load = function (n, autoplay) { _load(n, autoplay); markNav(); };
                })();
                </script>
            <?php elseif ($status === 'processing'): ?>
                <section class="videostate">
                    <div class="videostate__card">
                        <div class="videostate__icon">🎬</div>
                        <div class="videostate__title"><?= t('Vidéo en cours de préparation…', 'Video wordt voorbereid…') ?></div>
                        <p class="videostate__text"><?= t('Compression automatique en 720p pour une lecture fluide. La vidéo apparaîtra ici toute seule.', 'Automatische compressie naar 720p voor een vlotte weergave. De video verschijnt hier vanzelf.') ?></p>
                    </div>
                </section>
                <script>setTimeout(function () { location.reload(); }, 15000);</script>
            <?php elseif ($status === 'failed'): ?>
                <section class="videostate">
                    <div class="videostate__card">
                        <div class="videostate__icon">⚠️</div>
                        <div class="videostate__title" style="color:#b3261e;"><?= t('La préparation de la vidéo a échoué.', 'De voorbereiding van de video is mislukt.') ?></div>
                        <?php if ($isAdmin): ?><p class="videostate__text"><?= t('Réessaie en redéposant la vidéo via « Ajout de contenu ».', 'Probeer opnieuw door de video opnieuw toe te voegen via « Inhoud toevoegen ».') ?></p><?php endif; ?>
                    </div>
                </section>
            <?php endif; ?>

            <?php if ($quizAvailable): ?>
                <section class="quizcta" aria-label="<?= t('Passer au quiz', 'Naar de quiz') ?>">
                    <hr class="quizcta__rule">
                    <p class="quizcta__lead"><?= t('Vidéo terminée&nbsp;? Vérifiez que tout est bien en place avec <strong>quelques questions rapides</strong>.', 'Video bekeken&nbsp;? Controleer of alles duidelijk is met <strong>een paar snelle vragen</strong>.') ?></p>
                    <a class="quizcta__button" href="<?= htmlspecialchars($quizHref) ?>" onclick="return famiVideoQuizGuard(event, this.href);"><?= t('Passer le quiz', 'Naar de quiz') ?> <span class="arrow" aria-hidden="true">→</span></a>
                    <p class="quizcta__hint"><?= t('Résultat immédiat', 'Onmiddellijk resultaat') ?></p>
                </section>
                <div id="famiVideoModal" style="display:none; position:fixed; inset:0; z-index:100000; background:rgba(18,32,20,.55); align-items:center; justify-content:center; padding:20px;">
                    <div style="background:#fff; border-radius:20px; max-width:460px; width:100%; padding:30px 28px; box-shadow:0 24px 60px rgba(0,0,0,.35); text-align:center; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;">
                        <div style="font-size:2.4rem; line-height:1; margin-bottom:12px;">🎬</div>
                        <h3 style="margin:0 0 10px; color:#1E4D2B; font-size:1.3rem;"><?= t('Vidéo pas encore terminée', 'Video nog niet bekeken') ?></h3>
                        <p style="margin:0 0 22px; color:#46543F; line-height:1.55;"><?= t('Nous vous recommandons fortement de <strong>regarder la vidéo jusqu\'au bout</strong> avant de passer le quiz&nbsp;: les réponses s\'y trouvent. 🙂', 'We raden je sterk aan om <strong>de video volledig te bekijken</strong> voordat je de quiz maakt&nbsp;: de antwoorden staan erin. 🙂') ?></p>
                        <div style="display:flex; gap:10px; flex-wrap:wrap; justify-content:center;">
                            <button type="button" onclick="famiVideoModalClose()" style="background:#1E4D2B; color:#fff; border:none; border-radius:999px; padding:13px 22px; font-weight:700; cursor:pointer; font-size:1rem;">↩ <?= t('Regarder la vidéo', 'De video bekijken') ?></button>
                            <button type="button" onclick="famiVideoModalProceed()" style="background:#eef1e6; color:#46543F; border:none; border-radius:999px; padding:13px 22px; font-weight:700; cursor:pointer; font-size:.95rem;"><?= t('Passer quand même', 'Toch doorgaan') ?></button>
                        </div>
                    </div>
                </div>
                <script>
                (function () {
                    var pendingHref = '';
                    window.famiVideoQuizGuard = function (ev, href) {
                        var v = document.getElementById('famiVideo');
                        // Pas de vidéo lisible (en préparation / échec) : rien à regarder, on laisse passer.
                        if (!v || window._famiVideoDone) { return true; }
                        if (ev) { ev.preventDefault(); }
                        pendingHref = href;
                        var m = document.getElementById('famiVideoModal'); if (m) { m.style.display = 'flex'; }
                        return false;
                    };
                    window.famiVideoModalClose = function () { var m = document.getElementById('famiVideoModal'); if (m) { m.style.display = 'none'; } var v = document.getElementById('famiVideo'); if (v && v.scrollIntoView) { v.scrollIntoView({ behavior: 'smooth', block: 'center' }); } };
                    window.famiVideoModalProceed = function () { if (pendingHref) { window.location.href = pendingHref; } };
                })();
                </script>
            <?php endif; ?>

            <p class="vid-footer"><?= t('Famiformation · Document interne', 'Famiformation · Intern document') ?></p>
        </div>
        <?php
    }
}
