<?php
// ============================================================
// content_view.php — affichage soigné du contenu uniformisé (IA).
//   renderUniformContent($md, $pdfUrl, $showPdfView, $images) :
//     - Markdown rendu proprement (gras **, italique *, titres, listes)
//     - images placées par l'IA via marqueurs [[IMG n]] (n = numéro d'image)
//     - lecture paginée (section ##), cadre compact (pas de grand blanc)
// Additif : autonome.
// ============================================================
require_once __DIR__ . '/ai_uniformise.php'; // aiMarkdownToHtml (secours ai_test)

if (!function_exists('_uniInline')) {
    /** Formatage inline sur un texte DÉJÀ échappé : **gras**, *italique*. */
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
        return '<figure class="uni-fig"><img src="' . htmlspecialchars(_uniImgUrl($key)) . '" alt="" loading="lazy"></figure>';
    }
}

if (!function_exists('_splitUniformPages')) {
    /** Découpe le Markdown en pages : une nouvelle page à chaque titre `## ` (avec du contenu). */
    function _splitUniformPages($md)
    {
        $lines = preg_split('/\r\n|\r|\n/', (string) $md);
        $pages = [];
        $cur = [];
        $hasBody = function ($arr) {
            foreach ($arr as $x) {
                $t = trim($x);
                if ($t !== '' && strpos($t, '#') !== 0) { return true; } // au moins une ligne non-titre
            }
            return false;
        };
        foreach ($lines as $l) {
            // Nouvelle page seulement si la page en cours a déjà un vrai contenu (évite les pages « titre seul »).
            if (preg_match('/^##\s+\S/', $l) && $hasBody($cur)) {
                $pages[] = implode("\n", $cur);
                $cur = [];
            }
            $cur[] = $l;
        }
        if (!empty(array_filter($cur, function ($x) { return trim($x) !== ''; }))) {
            $pages[] = implode("\n", $cur);
        }
        if (empty($pages)) { $pages = [(string) $md]; }
        return $pages;
    }
}

if (!function_exists('_uniRenderPageHtml')) {
    /**
     * Rendu d'une page : Markdown (gras/italique/titres/listes) + marqueurs image.
     * Marqueurs supportés : [[IMG n]] / [IMG n] (n = numéro) ou [IMAGE] (séquentiel).
     */
    function _uniRenderPageHtml($md, $images, &$used)
    {
        $out = [];
        $inList = false;
        $seq = 0; // pour les marqueurs sans numéro
        $emit = function ($idx) use ($images, &$used, &$out) {
            $idx = (int) $idx;
            if ($idx >= 0 && $idx < count($images) && empty($used[$idx])) {
                $out[] = _uniFigure($images[$idx]);
                $used[$idx] = true;
            }
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

        // Images non placées par l'IA -> ajoutées à la fin.
        $leftover = '';
        for ($i = 0; $i < count($images); $i++) {
            if (empty($used[$i])) { $leftover .= _uniFigure($images[$i]); }
        }
        if ($leftover !== '') {
            if (!empty($rendered)) { $rendered[count($rendered) - 1] .= "\n" . $leftover; }
            else { $rendered[] = $leftover; }
        }
        $n = count($rendered);
        $withPdf = ($showPdfView && $pdfUrl !== '');
        ?>
        <style>
        .uni-wrap { position:relative; width:92%; max-width:1000px; margin:6px auto 44px; background:#fff; border-radius:16px; box-shadow:0 12px 34px rgba(0,0,0,.12); overflow:hidden; }
        .uni-body { padding:34px clamp(20px,5vw,60px) 26px; }
        .uni-page { max-width:760px; margin:0 auto; color:#2a3b31; font-size:1.06rem; line-height:1.75; }
        .uni-page > :first-child { margin-top:0; }
        .uni-page h2 { color:#2d5a37; font-size:1.7rem; line-height:1.25; margin:.2em 0 .5em; }
        .uni-page h3 { color:#2d5a37; font-size:1.32rem; margin:1.4em 0 .45em; padding-bottom:.28em; border-bottom:2px solid #e8f0ea; }
        .uni-page h4 { color:#3a5145; font-size:1.08rem; margin:1.1em 0 .3em; }
        .uni-page p { margin:.7em 0; }
        .uni-page strong { color:#22402e; }
        .uni-page ul { margin:.55em 0 .8em; padding-left:1.25em; }
        .uni-page li { margin:.4em 0; }
        .uni-page li::marker { color:#2d5a37; }
        .uni-fig { margin:1.3em auto; text-align:center; }
        .uni-fig img { max-width:100%; height:auto; border-radius:12px; box-shadow:0 4px 16px rgba(0,0,0,.12); }
        .uni-nav { display:flex; align-items:center; justify-content:center; gap:22px; padding:16px; border-top:1px solid #eef3f0; background:#fbfdfb; }
        .uni-btn { border:1px solid #2d5a37; background:#fff; color:#2d5a37; border-radius:10px; padding:9px 20px; font-weight:700; cursor:pointer; font-size:.92rem; }
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
