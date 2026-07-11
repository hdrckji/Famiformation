<?php
// ============================================================
// content_view.php — affichage soigné du contenu uniformisé (IA).
//   renderUniformContent($md, $pdfUrl, $showPdfView, $images) :
//     - contenu large, intégré à la page ; lecture paginée (section ##)
//     - marqueurs [IMAGE] remplacés par les photos extraites du PDF (dans l'ordre)
//     - bouton 👁 (voir PDF) rendu par module.php -> window.uniTogglePdf
// Additif : autonome.
// ============================================================
require_once __DIR__ . '/ai_uniformise.php'; // aiMarkdownToHtml (secours)

if (!function_exists('_splitUniformPages')) {
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
        if ($notEmpty($cur)) { $pages[] = implode("\n", $cur); }
        if (empty($pages)) { $pages = [(string) $md]; }
        return $pages;
    }
}

if (!function_exists('_uniImgUrl')) {
    function _uniImgUrl($key)
    {
        return function_exists('moduleFileUrl') ? moduleFileUrl($key) : ('media.php?f=' . rawurlencode((string) $key));
    }
}

if (!function_exists('_uniRenderPageHtml')) {
    /** Rendu d'une page : Markdown + marqueurs [IMAGE] (consomme $images dans l'ordre via $imgIdx). */
    function _uniRenderPageHtml($md, &$imgIdx, $images)
    {
        $out = [];
        $inList = false;
        foreach (preg_split('/\r\n|\r|\n/', (string) $md) as $line) {
            $t = rtrim($line);
            if ($t === '') { if ($inList) { $out[] = '</ul>'; $inList = false; } continue; }
            if (preg_match('/^\s*\[IMAGE[^\]]*\]\s*$/i', $t)) {
                if ($inList) { $out[] = '</ul>'; $inList = false; }
                if (isset($images[$imgIdx])) {
                    $out[] = '<figure class="uni-fig"><img src="' . htmlspecialchars(_uniImgUrl($images[$imgIdx])) . '" alt="" loading="lazy"></figure>';
                    $imgIdx++;
                }
                continue;
            }
            if (strpos($t, '### ') === 0) { if ($inList) { $out[] = '</ul>'; $inList = false; } $out[] = '<h4>' . htmlspecialchars(substr($t, 4)) . '</h4>'; }
            elseif (strpos($t, '## ') === 0) { if ($inList) { $out[] = '</ul>'; $inList = false; } $out[] = '<h3>' . htmlspecialchars(substr($t, 3)) . '</h3>'; }
            elseif (strpos($t, '# ') === 0) { if ($inList) { $out[] = '</ul>'; $inList = false; } $out[] = '<h2>' . htmlspecialchars(substr($t, 2)) . '</h2>'; }
            elseif (strpos($t, '- ') === 0 || strpos($t, '* ') === 0) { if (!$inList) { $out[] = '<ul>'; $inList = true; } $out[] = '<li>' . htmlspecialchars(substr($t, 2)) . '</li>'; }
            else { if ($inList) { $out[] = '</ul>'; $inList = false; } $out[] = '<p>' . htmlspecialchars($t) . '</p>'; }
        }
        if ($inList) { $out[] = '</ul>'; }
        return implode("\n", $out);
    }
}

if (!function_exists('renderUniformContent')) {
    function renderUniformContent($md, $pdfUrl = '', $showPdfView = false, $images = [])
    {
        if (!is_array($images)) { $images = []; }
        $images = array_values($images);

        $pages = _splitUniformPages($md);
        $imgIdx = 0;
        $rendered = [];
        foreach ($pages as $p) { $rendered[] = _uniRenderPageHtml($p, $imgIdx, $images); }
        // Images non placées par un marqueur -> ajoutées à la fin.
        if ($imgIdx < count($images)) {
            $extra = '';
            for (; $imgIdx < count($images); $imgIdx++) {
                $extra .= '<figure class="uni-fig"><img src="' . htmlspecialchars(_uniImgUrl($images[$imgIdx])) . '" alt="" loading="lazy"></figure>';
            }
            if (!empty($rendered)) { $rendered[count($rendered) - 1] .= "\n" . $extra; }
            else { $rendered[] = $extra; }
        }
        $n = count($rendered);
        $withPdf = ($showPdfView && $pdfUrl !== '');
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
        .uni-fig { margin:1.4em auto; text-align:center; }
        .uni-fig img { max-width:100%; height:auto; border-radius:12px; box-shadow:0 4px 16px rgba(0,0,0,.12); }
        .uni-nav { display:flex; align-items:center; justify-content:center; gap:22px; padding:18px; border-top:1px solid #eef3f0; background:#fbfdfb; }
        .uni-btn { border:1px solid #2d5a37; background:#fff; color:#2d5a37; border-radius:10px; padding:10px 22px; font-weight:700; cursor:pointer; font-size:.94rem; }
        .uni-btn:hover:not(:disabled) { background:#2d5a37; color:#fff; }
        .uni-btn:disabled { opacity:.35; cursor:not-allowed; }
        .uni-count { font-weight:800; color:#5a6b60; }
        .uni-pdf iframe { width:100%; height:80vh; border:none; display:block; background:#f4f7f6; }
        </style>

        <div class="uni-wrap">
            <div class="uni-view uni-read">
                <div class="uni-body">
                    <?php foreach ($rendered as $i => $html): ?>
                        <article class="uni-page" data-page="<?= (int) $i ?>" <?= $i === 0 ? '' : 'style="display:none;"' ?>>
                            <?= $html ?>
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

            <?php if ($withPdf): ?>
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
                var w = document.querySelector('.uni-wrap');
                if (w && w.scrollIntoView) { w.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
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
