<?php
// ============================================================
// content_view.php — affichage soigné du contenu uniformisé (IA).
//   renderUniformContent($md, $pdfUrl, $showPdfView, $images) :
//     - texte INTÉGRÉ À LA PAGE (pas de fenêtre/boîte), mise en page designée
//     - images placées par l'IA via [[IMG n]] (aucune image « en trop » ajoutée)
//     - pagination depuis la page (barre collée en bas)
// Additif : autonome.
// ============================================================
require_once __DIR__ . '/ai_uniformise.php';

if (!function_exists('_uniInline')) {
    /** Formatage inline sur texte DÉJÀ échappé : **gras**, *italique*. */
    function _uniInline($escaped)
    {
        $t = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $escaped);
        $t = preg_replace('/(?<!\*)\*(?!\s)([^\*\n]+?)\*(?!\*)/', '<em>$1</em>', $t);
        return $t;
    }
}
if (!function_exists('_uniImgUrl')) {
    function _uniImgUrl($key)
    {
        return function_exists('moduleFileUrl') ? moduleFileUrl($key) : ('media.php?f=' . rawurlencode((string) $key));
    }
}
if (!function_exists('_uniFigure')) {
    function _uniFigure($key)
    {
        return '<figure class="doc-fig"><img src="' . htmlspecialchars(_uniImgUrl($key)) . '" alt="" loading="lazy"></figure>';
    }
}
if (!function_exists('_splitUniformPages')) {
    function _splitUniformPages($md)
    {
        $lines = preg_split('/\r\n|\r|\n/', (string) $md);
        $pages = [];
        $cur = [];
        $hasBody = function ($arr) {
            foreach ($arr as $x) { $t = trim($x); if ($t !== '' && strpos($t, '#') !== 0) { return true; } }
            return false;
        };
        foreach ($lines as $l) {
            if (preg_match('/^##\s+\S/', $l) && $hasBody($cur)) { $pages[] = implode("\n", $cur); $cur = []; }
            $cur[] = $l;
        }
        if (!empty(array_filter($cur, function ($x) { return trim($x) !== ''; }))) { $pages[] = implode("\n", $cur); }
        if (empty($pages)) { $pages = [(string) $md]; }
        return $pages;
    }
}
if (!function_exists('_uniRenderPageHtml')) {
    /** Markdown (gras/italique/titres/listes) + images [[IMG n]] / [IMAGE] (n = numéro). */
    function _uniRenderPageHtml($md, $images, &$used)
    {
        $out = [];
        $inList = false;
        $seq = 0;
        $emit = function ($idx) use ($images, &$used, &$out) {
            $idx = (int) $idx;
            if ($idx >= 0 && $idx < count($images) && empty($used[$idx])) { $out[] = _uniFigure($images[$idx]); $used[$idx] = true; }
        };
        foreach (preg_split('/\r\n|\r|\n/', (string) $md) as $line) {
            $t = rtrim($line);
            if ($t === '') { if ($inList) { $out[] = '</ul>'; $inList = false; } continue; }
            if (preg_match('/^\s*\[+\s*IMG(?:AGE)?\s*:?\s*([0-9]*)\s*\]+\s*$/i', $t, $mm)) {
                if ($inList) { $out[] = '</ul>'; $inList = false; }
                if ($mm[1] !== '') { $emit((int) $mm[1] - 1); }
                else { while ($seq < count($images) && !empty($used[$seq])) { $seq++; } $emit($seq); $seq++; }
                continue;
            }
            if (strpos($t, '### ') === 0)      { if ($inList) { $out[] = '</ul>'; $inList = false; } $out[] = '<h4>' . _uniInline(htmlspecialchars(substr($t, 4))) . '</h4>'; }
            elseif (strpos($t, '## ') === 0)   { if ($inList) { $out[] = '</ul>'; $inList = false; } $out[] = '<h3>' . _uniInline(htmlspecialchars(substr($t, 3))) . '</h3>'; }
            elseif (strpos($t, '# ') === 0)    { if ($inList) { $out[] = '</ul>'; $inList = false; } $out[] = '<h2>' . _uniInline(htmlspecialchars(substr($t, 2))) . '</h2>'; }
            elseif (preg_match('/^\s*[-*]\s+(.*)$/', $t, $li)) { if (!$inList) { $out[] = '<ul>'; $inList = true; } $out[] = '<li>' . _uniInline(htmlspecialchars($li[1])) . '</li>'; }
            else { if ($inList) { $out[] = '</ul>'; $inList = false; } $out[] = '<p>' . _uniInline(htmlspecialchars($t)) . '</p>'; }
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
        $used = [];
        $pages = _splitUniformPages($md);
        $rendered = [];
        foreach ($pages as $p) { $rendered[] = _uniRenderPageHtml($p, $images, $used); }
        // NOTE : on n'ajoute PAS les images non placées (ex. logos) -> fini les images « en trop ».
        $n = count($rendered);
        $withPdf = ($showPdfView && $pdfUrl !== '');
        ?>
        <style>
        /* Texte intégré à la page : bande claire pleine largeur, pas de fenêtre flottante. */
        .doc { width:100%; box-sizing:border-box; background:linear-gradient(180deg,#ffffff 0%, #f7faf7 100%); border-top:5px solid #2d5a37; }
        .doc-inner { max-width:840px; margin:0 auto; padding:40px clamp(18px,5vw,40px) 30px; min-height:300px; }
        .doc-page { color:#2a3b31; font-size:1.08rem; line-height:1.8; }
        .doc-page > :first-child { margin-top:0; }
        .doc-page h2 { color:#1f4a2b; font-size:2rem; line-height:1.2; margin:0 0 .5em; }
        .doc-page h2::after { content:""; display:block; width:70px; height:4px; background:linear-gradient(90deg,#2d5a37,#7cb98f); border-radius:3px; margin-top:.35em; }
        .doc-page h3 { color:#2d5a37; font-size:1.35rem; margin:1.6em 0 .5em; padding:.15em 0 .15em .7em; border-left:5px solid #2d5a37; background:linear-gradient(90deg,#eef6f0,transparent); border-radius:0 8px 8px 0; }
        .doc-page h4 { color:#3a5145; font-size:1.1rem; margin:1.2em 0 .35em; }
        .doc-page p { margin:.75em 0; }
        .doc-page strong { color:#22402e; }
        .doc-page ul { list-style:none; margin:.6em 0 1em; padding:0; }
        .doc-page li { position:relative; padding:.15em 0 .15em 1.5em; margin:.35em 0; }
        .doc-page li::before { content:"▸"; position:absolute; left:.2em; color:#2d5a37; font-weight:900; }
        .doc-fig { margin:1.5em auto; text-align:center; }
        .doc-fig img { max-width:100%; height:auto; border-radius:14px; box-shadow:0 8px 26px rgba(0,0,0,.16); border:1px solid #eef1ee; }
        /* Barre de navigation collée en bas, intégrée à la page. */
        .doc-nav { position:sticky; bottom:0; z-index:20; display:flex; align-items:center; justify-content:center; gap:18px; padding:12px; background:rgba(255,255,255,.94); backdrop-filter:blur(6px); border-top:1px solid #e3ece5; }
        .doc-arrow { border:none; width:46px; height:46px; border-radius:50%; background:#2d5a37; color:#fff; font-size:1.15rem; cursor:pointer; box-shadow:0 4px 12px rgba(45,90,55,.35); }
        .doc-arrow:disabled { background:#c3ccc6; box-shadow:none; cursor:not-allowed; }
        .doc-count { font-weight:800; color:#2d5a37; min-width:78px; text-align:center; }
        .doc-eye { border:1px solid #2d5a37; background:#fff; color:#2d5a37; border-radius:10px; padding:9px 16px; font-weight:700; cursor:pointer; }
        .doc-eye:hover { background:#2d5a37; color:#fff; }
        .doc-pdf iframe { width:100%; height:82vh; border:none; display:block; background:#f4f7f6; }
        </style>

        <div class="doc">
            <div class="doc-view doc-read">
                <div class="doc-inner">
                    <?php foreach ($rendered as $i => $html): ?>
                        <div class="doc-page" data-page="<?= (int) $i ?>" <?= $i === 0 ? '' : 'style="display:none;"' ?>><?= $html ?></div>
                    <?php endforeach; ?>
                </div>
                <?php if ($n > 1): ?>
                    <div class="doc-nav">
                        <button type="button" class="doc-arrow" id="uniPrev" onclick="uniPage(-1)" title="Page précédente">◀</button>
                        <span class="doc-count"><span id="uniCur">1</span> / <?= (int) $n ?></span>
                        <button type="button" class="doc-arrow" id="uniNext" onclick="uniPage(1)" title="Page suivante">▶</button>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($withPdf): ?>
                <div class="doc-view doc-pdf" style="display:none;">
                    <iframe src="<?= htmlspecialchars($pdfUrl) ?>" title="PDF original"></iframe>
                </div>
            <?php endif; ?>
        </div>

        <script>
        (function () {
            var idx = 0, total = <?= (int) $n ?>;
            window.uniTogglePdf = function () {
                var read = document.querySelector('.doc-read'), pdf = document.querySelector('.doc-pdf'), eye = document.getElementById('uniEye');
                if (!pdf) { return; }
                var showPdf = (pdf.style.display === 'none');
                pdf.style.display = showPdf ? '' : 'none';
                if (read) { read.style.display = showPdf ? 'none' : ''; }
                if (eye) { eye.textContent = showPdf ? '📖' : '👁'; eye.title = showPdf ? 'Revenir à la lecture' : 'Voir le PDF original'; }
                window.scrollTo({ top: 0, behavior: 'smooth' });
            };
            function show(i) {
                idx = Math.max(0, Math.min(total - 1, i));
                document.querySelectorAll('.doc-page').forEach(function (p) {
                    p.style.display = (parseInt(p.getAttribute('data-page'), 10) === idx) ? '' : 'none';
                });
                var c = document.getElementById('uniCur'); if (c) { c.textContent = idx + 1; }
                var pv = document.getElementById('uniPrev'), nx = document.getElementById('uniNext');
                if (pv) { pv.disabled = (idx === 0); }
                if (nx) { nx.disabled = (idx === total - 1); }
                var d = document.querySelector('.doc');
                if (d && d.scrollIntoView) { d.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
            }
            window.uniPage = function (d) { show(idx + d); };
            show(0);
        })();
        </script>
        <?php
    }
}
