<?php
// ============================================================
// content_view.php — affichage soigné du contenu uniformisé (IA).
//   renderUniformContent($md) :
//     - contenu large, intégré à la page (pas une petite fenêtre)
//     - lecture paginée (une page par section ##) + navigation ◀ / ▶
//   Pas d'affichage du PDF (coûte de la bande passante) : seul le
//   téléchargement (créateur + gérant) est proposé, via module.php.
// Additif : autonome.
// ============================================================
require_once __DIR__ . '/ai_uniformise.php'; // aiMarkdownToHtml

if (!function_exists('_splitUniformPages')) {
    /** Découpe le Markdown en pages : une nouvelle page à chaque titre `## `. */
    function _splitUniformPages($md)
    {
        $lines = preg_split('/\r\n|\r|\n/', (string) $md);
        $pages = [];
        $cur = [];
        $notEmpty = function ($arr) {
            foreach ($arr as $x) { if (trim($x) !== '') { return true; } }
            return false;
        };
        foreach ($lines as $l) {
            if (preg_match('/^##\s+\S/', $l) && $notEmpty($cur)) {
                $pages[] = implode("\n", $cur);
                $cur = [];
            }
            $cur[] = $l;
        }
        if ($notEmpty($cur)) {
            $pages[] = implode("\n", $cur);
        }
        if (empty($pages)) {
            $pages = [(string) $md];
        }
        return $pages;
    }
}

if (!function_exists('renderUniformContent')) {
    function renderUniformContent($md, $pdfUrl = '')
    {
        $pages = _splitUniformPages($md);
        $n = count($pages);
        ?>
        <style>
        .uni-wrap { position:relative; width:92%; max-width:1040px; margin:6px auto 44px; background:#fff; border-radius:16px; box-shadow:0 12px 34px rgba(0,0,0,.14); overflow:hidden; }
        .uni-body { min-height:62vh; padding:38px clamp(20px,5vw,64px) 28px; }
        .uni-page { max-width:780px; margin:0 auto; color:#243b2e; font-size:1.08rem; line-height:1.8; }
        .uni-page h2 { color:#2d5a37; font-size:1.75rem; line-height:1.25; margin:0 0 .6em; padding-bottom:.32em; border-bottom:3px solid #e2efe5; }
        .uni-page h3 { color:#33633f; font-size:1.3rem; margin:1.5em 0 .4em; }
        .uni-page h4 { color:#3a5145; font-size:1.1rem; margin:1.1em 0 .3em; }
        .uni-page p { margin:.75em 0; }
        .uni-page ul { margin:.6em 0 .6em .3em; padding-left:1.3em; }
        .uni-page li { margin:.42em 0; padding-left:.2em; }
        .uni-page li::marker { color:#2d5a37; }
        .uni-nav { display:flex; align-items:center; justify-content:center; gap:22px; padding:18px; border-top:1px solid #eef3f0; background:#fbfdfb; }
        .uni-btn { border:1px solid #2d5a37; background:#fff; color:#2d5a37; border-radius:10px; padding:10px 22px; font-weight:700; cursor:pointer; font-size:.94rem; }
        .uni-btn:hover:not(:disabled) { background:#2d5a37; color:#fff; }
        .uni-btn:disabled { opacity:.35; cursor:not-allowed; }
        .uni-count { font-weight:800; color:#5a6b60; }
        </style>

        <div class="uni-wrap">
            <div class="uni-body">
                <?php foreach ($pages as $i => $p): ?>
                    <article class="uni-page" data-page="<?= (int) $i ?>" <?= $i === 0 ? '' : 'style="display:none;"' ?>>
                        <?= aiMarkdownToHtml($p) ?>
                    </article>
                <?php endforeach; ?>
            </div>
            <?php if ($n > 1): ?>
                <div class="uni-nav">
                    <button type="button" class="uni-btn" id="uniPrev" onclick="uniPage(-1)">◀ Précédent</button>
                    <span class="uni-count"><span id="uniCur">1</span> / <?= (int) $n ?></span>
                    <button type="button" class="uni-btn" id="uniNext" onclick="uniPage(1)">Suivant ▶</button>
                </div>
            <?php endif; ?>
        </div>

        <script>
        (function () {
            var idx = 0, total = <?= (int) $n ?>;
            function show(i) {
                idx = Math.max(0, Math.min(total - 1, i));
                document.querySelectorAll('.uni-page').forEach(function (p) {
                    p.style.display = (parseInt(p.getAttribute('data-page'), 10) === idx) ? '' : 'none';
                });
                var c = document.getElementById('uniCur'); if (c) { c.textContent = idx + 1; }
                var pv = document.getElementById('uniPrev'), nx = document.getElementById('uniNext');
                if (pv) { pv.disabled = (idx === 0); }
                if (nx) { nx.disabled = (idx === total - 1); }
                var w = document.querySelector('.uni-wrap');
                if (w && w.scrollIntoView) { w.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
            }
            window.uniPage = function (d) { show(idx + d); };
            show(0);
        })();
        </script>
        <?php
    }
}
