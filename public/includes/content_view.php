<?php
// ============================================================
// content_view.php — affichage soigné du contenu uniformisé (IA).
//   renderUniformContent($md, $pdfUrl) :
//     - onglets : 📖 Lecture / 📄 PDF original / ⬇ Télécharger
//     - lecture paginée (une page par section ##) avec navigation
//     - mise en page éditoriale (typo, titres, listes)
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
        foreach ($lines as $l) {
            if (preg_match('/^##\s+\S/', $l) && !empty(array_filter($cur, function ($x) { return trim($x) !== ''; }))) {
                $pages[] = implode("\n", $cur);
                $cur = [];
            }
            $cur[] = $l;
        }
        if (!empty(array_filter($cur, function ($x) { return trim($x) !== ''; }))) {
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
        .uni-wrap { background:#fff; border-radius:18px; box-shadow:0 8px 30px rgba(0,0,0,.10); overflow:hidden; }
        .uni-tabs { display:flex; gap:6px; padding:12px 14px; background:#f3f8f4; border-bottom:1px solid #e3ece5; flex-wrap:wrap; }
        .uni-tab { background:#fff; color:#2d5a37; border:1px solid #d5e4d9; border-radius:9px; padding:8px 15px; font-weight:700; cursor:pointer; text-decoration:none; font-size:.9rem; }
        .uni-tab.active { background:#2d5a37; color:#fff; border-color:#2d5a37; }
        .uni-read { padding:34px clamp(16px,5vw,56px); }
        .uni-page { max-width:760px; margin:0 auto; color:#243b2e; font-size:1.06rem; line-height:1.78; }
        .uni-page h2 { color:#2d5a37; font-size:1.7rem; line-height:1.25; margin:0 0 .55em; padding-bottom:.3em; border-bottom:3px solid #e2efe5; }
        .uni-page h3 { color:#33633f; font-size:1.28rem; margin:1.5em 0 .4em; }
        .uni-page h4 { color:#3a5145; font-size:1.08rem; margin:1.1em 0 .3em; }
        .uni-page p { margin:.75em 0; }
        .uni-page ul { margin:.6em 0 .6em .3em; padding-left:1.3em; }
        .uni-page li { margin:.4em 0; padding-left:.2em; }
        .uni-page li::marker { color:#2d5a37; }
        .uni-nav { display:flex; align-items:center; justify-content:center; gap:20px; max-width:760px; margin:30px auto 0; padding-top:20px; border-top:1px solid #eef3f0; }
        .uni-btn { border:1px solid #2d5a37; background:#fff; color:#2d5a37; border-radius:10px; padding:10px 20px; font-weight:700; cursor:pointer; font-size:.92rem; }
        .uni-btn:hover:not(:disabled) { background:#2d5a37; color:#fff; }
        .uni-btn:disabled { opacity:.35; cursor:not-allowed; }
        .uni-count { font-weight:800; color:#5a6b60; }
        .uni-pdf iframe { width:100%; height:82vh; border:none; display:block; background:#f4f7f6; }
        </style>

        <div class="uni-wrap">
            <div class="uni-tabs">
                <button type="button" class="uni-tab active" data-view="read" onclick="uniView(this,'read')">📖 Lecture</button>
                <?php if ($pdfUrl !== ''): ?>
                    <button type="button" class="uni-tab" data-view="pdf" onclick="uniView(this,'pdf')">📄 PDF original</button>
                    <a class="uni-tab" href="<?= htmlspecialchars($pdfUrl) ?>" download>⬇ Télécharger</a>
                <?php endif; ?>
            </div>

            <div class="uni-view uni-read">
                <?php foreach ($pages as $i => $p): ?>
                    <article class="uni-page" data-page="<?= (int) $i ?>" <?= $i === 0 ? '' : 'style="display:none;"' ?>>
                        <?= aiMarkdownToHtml($p) ?>
                    </article>
                <?php endforeach; ?>
                <?php if ($n > 1): ?>
                    <div class="uni-nav">
                        <button type="button" class="uni-btn" id="uniPrev" onclick="uniPage(-1)">◀ Précédent</button>
                        <span class="uni-count"><span id="uniCur">1</span> / <?= (int) $n ?></span>
                        <button type="button" class="uni-btn" id="uniNext" onclick="uniPage(1)">Suivant ▶</button>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($pdfUrl !== ''): ?>
                <div class="uni-view uni-pdf" style="display:none;">
                    <iframe src="<?= htmlspecialchars($pdfUrl) ?>" title="PDF original"></iframe>
                </div>
            <?php endif; ?>
        </div>

        <script>
        (function () {
            var idx = 0, total = <?= (int) $n ?>;
            window.uniView = function (btn, v) {
                document.querySelectorAll('.uni-tab').forEach(function (t) { t.classList.remove('active'); });
                if (btn.classList) { btn.classList.add('active'); }
                var read = document.querySelector('.uni-read'), pdf = document.querySelector('.uni-pdf');
                if (read) { read.style.display = (v === 'read') ? '' : 'none'; }
                if (pdf) { pdf.style.display = (v === 'pdf') ? '' : 'none'; }
            };
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
