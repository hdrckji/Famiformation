<?php
// ============================================================
// content_view.php — affichage soigné du contenu uniformisé (IA).
//   renderUniformContent($md, $pdfUrl, $canDownload) :
//     - 2 boutons icônes en haut à gauche : 👁 voir le PDF original (tous),
//       ⤓ télécharger (uploader + admins uniquement — le download a un coût).
//     - lecture paginée (une page par section ##), CADRE À TAILLE FIXE
//       (défilement interne), navigation ◀ / ▶.
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
    function renderUniformContent($md, $pdfUrl = '', $canDownload = false)
    {
        $pages = _splitUniformPages($md);
        $n = count($pages);
        ?>
        <style>
        .uni-wrap { position:relative; background:#fff; border-radius:18px; box-shadow:0 8px 30px rgba(0,0,0,.10); overflow:hidden; }
        .uni-bar { position:absolute; top:14px; left:14px; z-index:5; display:flex; gap:8px; }
        .uni-ico { width:42px; height:42px; display:inline-flex; align-items:center; justify-content:center; font-size:1.15rem; background:#fff; color:#2d5a37; border:1px solid #d5e4d9; border-radius:11px; cursor:pointer; text-decoration:none; box-shadow:0 2px 8px rgba(0,0,0,.08); transition:background .15s, color .15s; }
        .uni-ico:hover { background:#2d5a37; color:#fff; }
        /* Zone de lecture à HAUTEUR FIXE : le cadre ne change pas de taille d'une page à l'autre. */
        .uni-body { height:64vh; overflow-y:auto; padding:60px clamp(16px,5vw,56px) 22px; }
        .uni-page { max-width:760px; margin:0 auto; color:#243b2e; font-size:1.06rem; line-height:1.78; }
        .uni-page h2 { color:#2d5a37; font-size:1.7rem; line-height:1.25; margin:0 0 .55em; padding-bottom:.3em; border-bottom:3px solid #e2efe5; }
        .uni-page h3 { color:#33633f; font-size:1.28rem; margin:1.5em 0 .4em; }
        .uni-page h4 { color:#3a5145; font-size:1.08rem; margin:1.1em 0 .3em; }
        .uni-page p { margin:.75em 0; }
        .uni-page ul { margin:.6em 0 .6em .3em; padding-left:1.3em; }
        .uni-page li { margin:.4em 0; padding-left:.2em; }
        .uni-page li::marker { color:#2d5a37; }
        .uni-nav { display:flex; align-items:center; justify-content:center; gap:20px; padding:16px; border-top:1px solid #eef3f0; background:#fff; }
        .uni-btn { border:1px solid #2d5a37; background:#fff; color:#2d5a37; border-radius:10px; padding:10px 20px; font-weight:700; cursor:pointer; font-size:.92rem; }
        .uni-btn:hover:not(:disabled) { background:#2d5a37; color:#fff; }
        .uni-btn:disabled { opacity:.35; cursor:not-allowed; }
        .uni-count { font-weight:800; color:#5a6b60; }
        .uni-pdf iframe { width:100%; height:64vh; border:none; display:block; background:#f4f7f6; }
        </style>

        <div class="uni-wrap">
            <div class="uni-bar">
                <?php if ($pdfUrl !== ''): ?>
                    <button type="button" class="uni-ico" id="uniEye" title="Voir le PDF original" onclick="uniTogglePdf()">👁</button>
                    <?php if ($canDownload): ?>
                        <a class="uni-ico" href="<?= htmlspecialchars($pdfUrl) ?>" download title="Télécharger le PDF">⤓</a>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <div class="uni-view uni-read">
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

            <?php if ($pdfUrl !== ''): ?>
                <div class="uni-view uni-pdf" style="display:none;">
                    <iframe src="<?= htmlspecialchars($pdfUrl) ?>" title="PDF original"></iframe>
                </div>
            <?php endif; ?>
        </div>

        <script>
        (function () {
            var idx = 0, total = <?= (int) $n ?>;
            window.uniTogglePdf = function () {
                var read = document.querySelector('.uni-read'), pdf = document.querySelector('.uni-pdf'), eye = document.getElementById('uniEye');
                if (!pdf) { return; }
                var showPdf = (pdf.style.display === 'none');
                pdf.style.display = showPdf ? '' : 'none';
                if (read) { read.style.display = showPdf ? 'none' : ''; }
                if (eye) { eye.textContent = showPdf ? '📖' : '👁'; eye.title = showPdf ? 'Revenir à la lecture' : 'Voir le PDF original'; }
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
                var b = document.querySelector('.uni-body'); if (b) { b.scrollTop = 0; }
            }
            window.uniPage = function (d) { show(idx + d); };
            show(0);
        })();
        </script>
        <?php
    }
}
