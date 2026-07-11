<?php
// ============================================================
// content_view.php — rendu DESIGNÉ du contenu uniformisé (IA).
//   Le contenu est un JSON de blocs (hero/section/text/list/steps/callout/
//   keyfigures/image/quote) -> mise en page « fiche » stylée Famiflora.
//   Repli : ancien contenu en Markdown -> rendu simple.
//   Texte intégré à la page, navigation par page collée en bas.
// ============================================================
require_once __DIR__ . '/ai_uniformise.php';

if (!function_exists('_uniInline')) {
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

if (!function_exists('_dBlock')) {
    /** Rendu HTML d'un bloc de design. */
    function _dBlock($b, $images, &$used)
    {
        $esc = function ($s) { return _uniInline(htmlspecialchars((string) $s)); };
        switch ($b['type']) {
            case 'hero':
                return '<div class="d-hero"><div class="d-hero-in"><h1>' . $esc($b['title']) . '</h1>'
                    . (($b['subtitle'] ?? '') !== '' ? '<p>' . $esc($b['subtitle']) . '</p>' : '') . '</div></div>';
            case 'section':
                return '<h2 class="d-sec">' . $esc($b['title']) . '</h2>';
            case 'text':
                return '<p class="d-text">' . $esc($b['text']) . '</p>';
            case 'list':
                $li = '';
                foreach ($b['items'] as $it) { $li .= '<li>' . $esc($it) . '</li>'; }
                return '<ul class="d-list">' . $li . '</ul>';
            case 'steps':
                $li = ''; $k = 1;
                foreach ($b['items'] as $it) { $li .= '<li><span class="d-step-n">' . $k . '</span><span>' . $esc($it) . '</span></li>'; $k++; }
                return '<ol class="d-steps">' . $li . '</ol>';
            case 'callout':
                $ic = ['info' => 'ℹ️', 'tip' => '💡', 'warning' => '⚠️'];
                $i = $ic[$b['style']] ?? 'ℹ️';
                $h = ($b['title'] ?? '') !== '' ? '<div class="d-call-h">' . $i . ' ' . $esc($b['title']) . '</div>' : '';
                return '<div class="d-call d-call-' . htmlspecialchars($b['style']) . '">' . $h . '<div class="d-call-t">' . $esc($b['text']) . '</div></div>';
            case 'keyfigures':
                $t = '';
                foreach ($b['items'] as $it) {
                    $t .= '<div class="d-kf-i"><div class="d-kf-v">' . htmlspecialchars((string) $it['value']) . '</div><div class="d-kf-l">' . htmlspecialchars((string) $it['label']) . '</div></div>';
                }
                return '<div class="d-kf">' . $t . '</div>';
            case 'image':
                $n = (int) $b['n'] - 1;
                if ($n >= 0 && $n < count($images) && empty($used[$n])) {
                    $used[$n] = true;
                    $cap = ($b['caption'] ?? '') !== '' ? '<figcaption>' . htmlspecialchars((string) $b['caption']) . '</figcaption>' : '';
                    return '<figure class="d-fig"><img src="' . htmlspecialchars(_uniImgUrl($images[$n])) . '" alt="" loading="lazy">' . $cap . '</figure>';
                }
                return '';
            case 'quote':
                return '<blockquote class="d-quote">' . $esc($b['text']) . '</blockquote>';
        }
        return '';
    }
}

if (!function_exists('_designedPages')) {
    /** Paginate les blocs (nouvelle page à chaque section) -> tableau de HTML de pages. */
    function _designedPages($blocks, $images, &$used)
    {
        $groups = [[]];
        foreach ($blocks as $b) {
            if (($b['type'] ?? '') === 'section' && !empty($groups[count($groups) - 1])) { $groups[] = []; }
            $groups[count($groups) - 1][] = $b;
        }
        $pages = [];
        foreach ($groups as $g) {
            if (empty($g)) { continue; }
            $html = '';
            $rest = $g;
            if (($g[0]['type'] ?? '') === 'hero') { $html .= _dBlock($g[0], $images, $used); $rest = array_slice($g, 1); }
            $inner = '';
            foreach ($rest as $b) { $inner .= _dBlock($b, $images, $used); }
            $html .= '<div class="d-inner">' . $inner . '</div>';
            $pages[] = $html;
        }
        if (empty($pages)) { $pages = ['<div class="d-inner"></div>']; }
        return $pages;
    }
}

if (!function_exists('_mdPages')) {
    /** Repli Markdown (ancien contenu) -> pages simples. */
    function _mdPages($md, $images, &$used)
    {
        $lines = preg_split('/\r\n|\r|\n/', (string) $md);
        $chunks = []; $cur = [];
        $body = function ($a) { foreach ($a as $x) { $t = trim($x); if ($t !== '' && strpos($t, '#') !== 0) { return true; } } return false; };
        foreach ($lines as $l) { if (preg_match('/^##\s+\S/', $l) && $body($cur)) { $chunks[] = $cur; $cur = []; } $cur[] = $l; }
        if ($body($cur) || !empty(array_filter($cur, function ($x) { return trim($x) !== ''; }))) { $chunks[] = $cur; }
        if (empty($chunks)) { $chunks = [[(string) $md]]; }
        $pages = [];
        foreach ($chunks as $ch) {
            $out = ''; $inList = false;
            foreach ($ch as $line) {
                $t = rtrim($line);
                if ($t === '') { if ($inList) { $out .= '</ul>'; $inList = false; } continue; }
                if (strpos($t, '### ') === 0)    { if ($inList) { $out .= '</ul>'; $inList = false; } $out .= '<h4>' . _uniInline(htmlspecialchars(substr($t, 4))) . '</h4>'; }
                elseif (strpos($t, '## ') === 0) { if ($inList) { $out .= '</ul>'; $inList = false; } $out .= '<h2 class="d-sec">' . _uniInline(htmlspecialchars(substr($t, 3))) . '</h2>'; }
                elseif (strpos($t, '# ') === 0)  { if ($inList) { $out .= '</ul>'; $inList = false; } $out .= '<h2 class="d-sec">' . _uniInline(htmlspecialchars(substr($t, 2))) . '</h2>'; }
                elseif (preg_match('/^\s*[-*]\s+(.*)$/', $t, $li)) { if (!$inList) { $out .= '<ul class="d-list">'; $inList = true; } $out .= '<li>' . _uniInline(htmlspecialchars($li[1])) . '</li>'; }
                else { if ($inList) { $out .= '</ul>'; $inList = false; } $out .= '<p class="d-text">' . _uniInline(htmlspecialchars($t)) . '</p>'; }
            }
            if ($inList) { $out .= '</ul>'; }
            $pages[] = '<div class="d-inner">' . $out . '</div>';
        }
        return $pages;
    }
}

if (!function_exists('renderUniformContent')) {
    function renderUniformContent($md, $pdfUrl = '', $showPdfView = false, $images = [])
    {
        $images = array_values((array) $images);
        $used = [];
        $data = json_decode((string) $md, true);
        $blocks = (is_array($data) && !empty($data['blocks']) && is_array($data['blocks'])) ? $data['blocks'] : null;
        $pages = $blocks ? _designedPages($blocks, $images, $used) : _mdPages($md, $images, $used);
        // Images de contenu non placées par l'IA -> ajoutées en fin (les logos sont déjà filtrés à l'extraction).
        $extra = '';
        for ($i = 0; $i < count($images); $i++) {
            if (empty($used[$i])) { $extra .= '<figure class="d-fig"><img src="' . htmlspecialchars(_uniImgUrl($images[$i])) . '" alt="" loading="lazy"></figure>'; }
        }
        if ($extra !== '' && !empty($pages)) { $pages[count($pages) - 1] .= '<div class="d-inner" style="padding-top:8px;">' . $extra . '</div>'; }
        $n = count($pages);
        $withPdf = ($showPdfView && $pdfUrl !== '');
        ?>
        <style>
        .doc { width:100%; box-sizing:border-box; background:#f4f8f4; }
        .d-hero { background:linear-gradient(135deg,#2d5a37,#4e8a5f); color:#fff; padding:52px clamp(20px,6vw,60px); position:relative; overflow:hidden; }
        .d-hero::after { content:"🌿"; position:absolute; right:6px; bottom:-24px; font-size:9rem; opacity:.13; transform:rotate(-15deg); }
        .d-hero-in { max-width:860px; margin:0 auto; position:relative; z-index:1; }
        .d-hero h1 { font-family:'Open Sans',sans-serif; font-weight:800; font-size:clamp(2rem,5vw,3rem); margin:0; line-height:1.12; }
        .d-hero p { font-size:1.15rem; opacity:.96; margin:.6em 0 0; }
        .d-inner { max-width:860px; margin:0 auto; padding:36px clamp(18px,5vw,44px) 30px; color:#2a3b31; font-size:1.07rem; line-height:1.8; min-height:200px; }
        .d-inner > :first-child { margin-top:0; }
        .d-sec { font-family:'Open Sans',sans-serif; color:#1f4a2b; font-size:1.7rem; font-weight:700; margin:1.1em 0 .55em; display:flex; align-items:center; gap:.5em; }
        .d-sec::before { content:""; width:12px; height:32px; background:linear-gradient(#2d5a37,#7cb98f); border-radius:6px; flex:none; }
        .d-inner > .d-sec:first-child { margin-top:0; }
        .d-text { margin:.7em 0; }
        .d-text strong, .d-call strong, .d-quote strong { color:#22402e; }
        .d-list { list-style:none; margin:.6em 0 1.1em; padding:0; }
        .d-list li { position:relative; padding:.2em 0 .2em 1.7em; margin:.25em 0; }
        .d-list li::before { content:"🌱"; position:absolute; left:0; font-size:.95em; }
        .d-steps { list-style:none; margin:1em 0; padding:0; display:flex; flex-direction:column; gap:12px; }
        .d-steps li { display:flex; gap:14px; align-items:flex-start; background:#fff; border:1px solid #e6efe8; border-radius:14px; padding:13px 16px; box-shadow:0 3px 10px rgba(0,0,0,.05); }
        .d-step-n { flex:none; width:34px; height:34px; border-radius:50%; background:#2d5a37; color:#fff; font-weight:800; display:flex; align-items:center; justify-content:center; }
        .d-call { border-radius:14px; padding:15px 18px; margin:1.2em 0; border-left:6px solid; }
        .d-call-info { background:#eef6f0; border-color:#2d5a37; }
        .d-call-tip { background:#e6f6f1; border-color:#12967e; }
        .d-call-warning { background:#fdf3e2; border-color:#d99425; }
        .d-call-h { font-weight:800; margin-bottom:5px; color:#22402e; }
        .d-kf { display:flex; flex-wrap:wrap; gap:14px; margin:1.3em 0; }
        .d-kf-i { flex:1 1 140px; background:#fff; border:1px solid #e6efe8; border-radius:16px; padding:18px 14px; text-align:center; box-shadow:0 4px 14px rgba(0,0,0,.06); }
        .d-kf-v { font-family:'Open Sans',sans-serif; font-size:2rem; font-weight:800; color:#2d5a37; line-height:1; }
        .d-kf-l { color:#5a6b60; margin-top:6px; font-size:.9rem; }
        .d-fig { margin:1.5em 0; text-align:center; }
        .d-fig img { max-width:100%; height:auto; border-radius:16px; box-shadow:0 10px 30px rgba(0,0,0,.16); }
        .d-fig figcaption { color:#7a8a80; font-style:italic; font-size:.9rem; margin-top:.5em; }
        .d-quote { margin:1.3em 0; padding:14px 20px; border-left:5px solid #7cb98f; background:#eef7f1; border-radius:0 12px 12px 0; font-style:italic; color:#2f5540; font-size:1.12rem; }
        .d-inner h4 { color:#3a5145; margin:1.1em 0 .3em; }
        .doc-nav { position:sticky; bottom:0; z-index:20; display:flex; align-items:center; justify-content:center; gap:18px; padding:12px; background:rgba(255,255,255,.94); backdrop-filter:blur(6px); border-top:1px solid #e3ece5; }
        .doc-arrow { border:none; width:46px; height:46px; border-radius:50%; background:#2d5a37; color:#fff; font-size:1.15rem; cursor:pointer; box-shadow:0 4px 12px rgba(45,90,55,.35); }
        .doc-arrow:disabled { background:#c3ccc6; box-shadow:none; cursor:not-allowed; }
        .doc-count { font-weight:800; color:#2d5a37; min-width:78px; text-align:center; }
        .doc-pdf iframe { width:100%; height:82vh; border:none; display:block; background:#f4f7f6; }
        </style>

        <div class="doc">
            <div class="doc-view doc-read">
                <?php foreach ($pages as $i => $html): ?>
                    <div class="doc-page" data-page="<?= (int) $i ?>" <?= $i === 0 ? '' : 'style="display:none;"' ?>><?= $html ?></div>
                <?php endforeach; ?>
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
