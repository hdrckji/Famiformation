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
        $title = function_exists('moduleNom') ? moduleNom($module) : (string) ($module['nom'] ?? 'Formation');
        $descRaw = function_exists('moduleDesc') ? moduleDesc($module) : (string) ($module['description'] ?? '');
        $status = (string) ($module['video_status'] ?? '');
        $hasVideo = !empty($module['video_path']);
        $videoUrl = $hasVideo ? _vidUrl($module['video_path']) : '';
        $quizAvailable = ($quizHref !== '');

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

            <?php if ($hasVideo && $status !== 'processing' && $status !== 'failed'): ?>
                <main class="player">
                    <figure style="margin:0;">
                        <div class="player__frame">
                            <?php
                                // SOUS-TITRES BILINGUES. Les navigateurs ne lisent que le WebVTT
                                // (d'où la conversion faite par le worker). La piste de la langue
                                // courante est activée par défaut : un néerlandophone voit du NL
                                // sans rien régler.
                                $subFr = trim((string) ($module['sub_fr_path'] ?? ''));
                                $subNl = trim((string) ($module['sub_nl_path'] ?? ''));
                                $isNl = (function_exists('currentLang') && currentLang() === 'nl');
                            ?>
                            <video id="famiVideo" class="player__video" controls controlsList="nodownload" playsinline preload="metadata"<?= ($subFr !== '' || $subNl !== '') ? ' crossorigin="anonymous"' : '' ?>>
                                <source src="<?= htmlspecialchars($videoUrl) ?>">
                                <?php if ($subFr !== ''): ?>
                                    <track kind="subtitles" srclang="fr" label="Français"
                                           src="<?= htmlspecialchars(moduleFileUrl($subFr)) ?>"
                                           <?= $isNl ? '' : 'default' ?>>
                                <?php endif; ?>
                                <?php if ($subNl !== ''): ?>
                                    <track kind="subtitles" srclang="nl" label="Nederlands"
                                           src="<?= htmlspecialchars(moduleFileUrl($subNl)) ?>"
                                           <?= $isNl ? 'default' : '' ?>>
                                <?php endif; ?>
                                <?= t('Votre navigateur ne peut pas lire cette vidéo.', 'Uw browser kan deze video niet afspelen.') ?>
                            </video>
                        </div>
                        <p class="player__note">⏱️ <?= t('Avance rapide désactivée — le retour en arrière reste possible.', 'Vooruitspoelen uitgeschakeld — terugspoelen blijft mogelijk.') ?></p>
                    </figure>
                </main>
                <script>
                (function () {
                    var v = document.getElementById('famiVideo');
                    if (!v) { return; }
                    var maxT = 0;
                    v.addEventListener('timeupdate', function () {
                        if (!v.seeking) { maxT = Math.max(maxT, v.currentTime); }
                        // Vidéo considérée « vue » à partir de 95 % (ou fin atteinte).
                        if (v.duration && v.duration > 0 && v.currentTime >= v.duration * 0.95) { window._famiVideoDone = true; }
                    });
                    v.addEventListener('ended', function () { window._famiVideoDone = true; });
                    v.addEventListener('seeking', function () { if (v.currentTime > maxT + 1) { v.currentTime = maxT; } });
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
