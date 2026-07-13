<?php
// ============================================================
// content_view.php — rendu DESIGNÉ (template Famiflora Académie).
//   Le contenu est un JSON de blocs (hero/section/text/list/steps/callout/
//   keyfigures/image/quote) -> mise en page « fiche de formation ».
//   Pagination par section, navigation en bas, vue PDF original.
//   CSS scopé sous .fami-doc pour ne pas toucher au reste de la page.
// ============================================================
require_once __DIR__ . '/ai_uniformise.php';

if (!function_exists('_uniInline')) {
    function _uniInline($escaped)
    {
        $t = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $escaped);
        $t = preg_replace('/(?<!\*)\*(?!\s)([^\*\n]+?)\*(?!\*)/', '<em>$1</em>', $t);
        // Couleur retirée (instable) : on ne garde que le texte des éventuels marqueurs.
        $t = preg_replace('/\[\[c:[a-z]+\]\](.+?)\[\[\/c\]\]/s', '$1', $t);
        return $t;
    }
}
if (!function_exists('_uniImgUrl')) {
    function _uniImgUrl($key)
    {
        return function_exists('moduleFileUrl') ? moduleFileUrl($key) : ('media.php?f=' . rawurlencode((string) $key));
    }
}
if (!function_exists('_uniCalloutIcon')) {
    function _uniCalloutIcon($style)
    {
        $svg = [
            'info'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><line x1="12" y1="11" x2="12" y2="16"/><circle cx="12" cy="8" r="0.6" fill="currentColor"/></svg>',
            'tip'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21 q -1 -8 2 -13 q 5 1 5.5 7 q 0.5 6 -7.5 6 z"/><path d="M12 21 q 0.5 -7 -2.5 -11 q -4.5 1.5 -4.5 6.5 q 0 4.5 7 4.5 z"/></svg>',
            'warning' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3 L22 20 H2 Z"/><line x1="12" y1="10" x2="12" y2="14"/><circle cx="12" cy="17" r="0.6" fill="currentColor"/></svg>',
        ];
        return $svg[$style] ?? $svg['info'];
    }
}

if (!function_exists('_dBlockHtml')) {
    /** Un bloc -> HTML du template. $ctx : ['sec'=>compteur section, 'images'=>[], 'used'=>&]. */
    function _dBlockHtml($b, &$ctx)
    {
        $esc = function ($s) { return _uniInline(htmlspecialchars((string) $s)); };
        $alStyle = in_array(($b['align'] ?? ''), ['center', 'right', 'left'], true) ? ' style="text-align:' . $b['align'] . '"' : '';
        switch ($b['type']) {
            case 'section':
                $ctx['sec']++;
                $eye = t('Partie', 'Deel') . ' ' . $ctx['sec'];
                return '<section class="section"' . $alStyle . '><p class="section__eyebrow">' . htmlspecialchars($eye) . '</p>'
                    . '<h2 class="section__title">' . $esc($b['title']) . '</h2><hr class="section__rule"></section>';
            case 'text':
                return '<p class="text"' . $alStyle . '>' . $esc($b['text']) . '</p>';
            case 'list':
                $li = '';
                foreach ($b['items'] as $it) { $li .= '<li>' . $esc($it) . '</li>'; }
                return '<ul class="list">' . $li . '</ul>';
            case 'steps':
                $li = '';
                foreach ($b['items'] as $it) {
                    $ti = is_array($it) ? (string) ($it['title'] ?? '') : '';
                    $de = is_array($it) ? (string) ($it['desc'] ?? '') : (string) $it;
                    $li .= '<li>' . ($ti !== '' ? '<span class="steps__title">' . $esc($ti) . '</span>' : '')
                        . '<p class="steps__desc">' . $esc($de) . '</p></li>';
                }
                return '<ol class="steps">' . $li . '</ol>';
            case 'callout':
                $st = in_array($b['style'], ['info', 'tip', 'warning'], true) ? $b['style'] : 'info';
                $ttl = ($b['title'] ?? '') !== '' ? '<p class="callout__title">' . $esc($b['title']) . '</p>' : '';
                return '<aside class="callout callout--' . $st . '"><span class="callout__icon" aria-hidden="true">' . _uniCalloutIcon($st) . '</span>'
                    . '<div' . $alStyle . '>' . $ttl . '<p class="callout__text">' . $esc($b['text']) . '</p></div></aside>';
            case 'keyfigures':
                $t = '';
                foreach ($b['items'] as $it) {
                    $t .= '<div class="keyfigure"><span class="keyfigure__number">' . htmlspecialchars((string) $it['value']) . '</span>'
                        . '<span class="keyfigure__label">' . htmlspecialchars((string) $it['label']) . '</span></div>';
                }
                return '<div class="keyfigures">' . $t . '</div>';
            case 'image':
                $size = ($b['size'] ?? 'm'); if (!in_array($size, ['s', 'm', 'l'], true)) { $size = 'm'; }
                $cap = ($b['caption'] ?? '') !== '' ? '<figcaption class="image__caption">' . htmlspecialchars((string) $b['caption']) . '</figcaption>' : '';
                $src = trim((string) ($b['src'] ?? ''));
                if ($src !== '') {
                    // Image ajoutée depuis l'éditeur visuel (clé directe sur le volume).
                    return '<figure class="image image--' . $size . '"><img class="image__real" src="' . htmlspecialchars(_uniImgUrl($src)) . '" alt="" loading="lazy">' . $cap . '</figure>';
                }
                $n = (int) ($b['n'] ?? 0) - 1;
                if ($n >= 0 && $n < count($ctx['images']) && empty($ctx['used'][$n])) {
                    $ctx['used'][$n] = true;
                    return '<figure class="image image--' . $size . '"><img class="image__real" src="' . htmlspecialchars(_uniImgUrl($ctx['images'][$n])) . '" alt="" loading="lazy">' . $cap . '</figure>';
                }
                return '';
            case 'quote':
                return '<blockquote class="quote"' . $alStyle . '><p class="quote__text">' . $esc($b['text']) . '</p></blockquote>';
        }
        return '';
    }
}

if (!function_exists('_uniShort')) {
    function _uniShort($s, $len)
    {
        $s = trim(preg_replace('/\s+/', ' ', strip_tags(str_replace(['**', '*'], '', (string) $s))));
        return (mb_strlen($s) <= $len) ? $s : (mb_substr($s, 0, $len - 1) . '…');
    }
}

if (!function_exists('_uniCoverHtml')) {
    /** Couverture plein écran + sommaire (page 0). */
    function _uniCoverHtml($hero, $secNum, $minutes, $toc, $docDate = '')
    {
        $title = _uniInline(htmlspecialchars($hero['title'] ?? 'Formation'));
        $sub = ($hero && ($hero['subtitle'] ?? '') !== '') ? '<p class="cover__subtitle">' . _uniInline(htmlspecialchars($hero['subtitle'])) . '</p>' : '';
        $meta = '<ul class="cover__meta">';
        if ($secNum > 0) { $meta .= '<li>' . $secNum . ' ' . ($secNum > 1 ? t('parties', 'delen') : t('partie', 'deel')) . '</li>'; }
        $meta .= '<li>' . t('Lecture ~', 'Leestijd ~') . (int) $minutes . ' min</li>';
        if (trim((string) $docDate) !== '') { $meta .= '<li>' . htmlspecialchars((string) $docDate) . '</li>'; }
        $meta .= '</ul>';

        $items = '';
        foreach ($toc as $t) {
            $desc = $t['desc'] !== '' ? '<span class="toc__desc">' . htmlspecialchars($t['desc']) . '</span>' : '';
            $items .= '<li class="toc__item"><a class="toc__link" href="#" onclick="uniGoto(' . (int) $t['page'] . ');return false;">'
                . '<span class="toc__num" aria-hidden="true">' . htmlspecialchars($t['num']) . '</span>'
                . '<span class="toc__label"><span class="toc__name">' . _uniInline(htmlspecialchars($t['name'])) . '</span>' . $desc . '</span>'
                . '<span class="toc__arrow" aria-hidden="true">→</span></a></li>';
        }

        $flora = '<svg class="cover__flora" viewBox="0 0 1200 800" preserveAspectRatio="xMidYMid slice" aria-hidden="true">'
            . '<path class="flora--soft flora--draw" d="M -40 820 C 120 640, 150 480, 190 300 C 210 210, 250 140, 320 90"/>'
            . '<path class="flora--soft" d="M 160 520 q 90 -50 106 -140 q -100 26 -106 140"/>'
            . '<path class="flora--soft" d="M 178 430 q -86 -34 -108 -122 q 96 12 108 122"/>'
            . '<path class="flora--faint" d="M 200 330 q 76 -44 90 -120 q -86 22 -90 120"/>'
            . '<path class="flora--soft flora--draw" d="M 1240 760 C 1080 620, 1050 470, 1020 300 C 1004 210, 970 130, 900 80"/>'
            . '<path class="flora--soft" d="M 1046 500 q -92 -48 -110 -136 q 102 24 110 136"/>'
            . '<path class="flora--soft" d="M 1030 410 q 84 -36 104 -122 q -94 14 -104 122"/>'
            . '<path class="flora--bright flora--draw" d="M 90 800 q 40 -140 30 -260"/>'
            . '<path class="flora--bright" d="M 104 660 q 52 -26 62 -84 q -60 14 -62 84"/>'
            . '<path class="flora--bright flora--draw" d="M 1120 780 q -34 -130 -22 -240"/>'
            . '</svg>';

        return '<header class="cover">' . $flora . '<div class="cover__inner">'
            . '<p class="cover__brand">Famiformation</p>'
            . '<h1 class="cover__title">' . $title . '</h1>' . $sub . $meta
            . '<button type="button" class="cover__cta" onclick="uniGoto(1)">' . t('Commencer la formation', 'De opleiding starten') . ' <span class="arrow" aria-hidden="true">→</span></button>'
            . '</div><a class="cover__scrollhint" href="#uni-toc">' . t('Au programme', 'Op het programma') . '</a></header>'
            . '<nav class="toc" id="uni-toc" aria-label="' . t('Sommaire de la formation', 'Inhoudsopgave van de opleiding') . '"><p class="toc__eyebrow">' . t('Au programme', 'Op het programma') . '</p>'
            . '<h2 class="toc__title">' . t('Sommaire', 'Inhoud') . '</h2><hr class="toc__rule"><ol class="toc__list">' . $items . '</ol></nav>';
    }
}

if (!function_exists('_designedPages')) {
    /** Page 0 = couverture + sommaire ; pages 1..N = une par section. */
    function _designedPages($blocks, $images, &$used, $docDate = '')
    {
        $ctx = ['sec' => 0, 'images' => $images, 'used' => &$used];

        $hero = null;
        $rest = $blocks;
        if (!empty($rest) && ($rest[0]['type'] ?? '') === 'hero') { $hero = $rest[0]; $rest = array_slice($rest, 1); }

        $groups = [[]];
        foreach ($rest as $b) {
            if (($b['type'] ?? '') === 'section' && !empty($groups[count($groups) - 1])) { $groups[] = []; }
            $groups[count($groups) - 1][] = $b;
        }
        $groups = array_values(array_filter($groups, function ($g) { return !empty($g); }));

        $contentPages = [];
        $toc = [];
        $secNum = 0;
        $pageIndex = 1;
        foreach ($groups as $g) {
            $isSection = (($g[0]['type'] ?? '') === 'section');
            $title = t('Introduction', 'Inleiding');
            if ($isSection) { $secNum++; $title = (string) $g[0]['title']; }
            $desc = '';
            foreach ($g as $bb) { if (($bb['type'] ?? '') === 'text') { $desc = _uniShort((string) $bb['text'], 90); break; } }
            $toc[] = ['num' => $isSection ? sprintf('%02d', $secNum) : '•', 'name' => $title, 'desc' => $desc, 'page' => $pageIndex];

            $inner = '';
            foreach ($g as $b) { $inner .= _dBlockHtml($b, $ctx); }
            $contentPages[] = '<main class="page">' . $inner . '</main>';
            $pageIndex++;
        }

        // Temps de lecture estimé.
        $allText = ' ' . ($hero['title'] ?? '') . ' ' . ($hero['subtitle'] ?? '');
        foreach ($rest as $b) {
            $allText .= ' ' . ($b['title'] ?? '') . ' ' . ($b['text'] ?? '');
            foreach ((array) ($b['items'] ?? []) as $it) {
                $allText .= ' ' . (is_array($it) ? (($it['title'] ?? '') . ' ' . ($it['desc'] ?? '') . ' ' . ($it['value'] ?? '') . ' ' . ($it['label'] ?? '')) : $it);
            }
        }
        $minutes = max(1, (int) round(str_word_count(strip_tags($allText)) / 180));

        if (empty($contentPages)) { $contentPages = ['<main class="page"></main>']; }
        return array_merge([_uniCoverHtml($hero, $secNum, $minutes, $toc, $docDate)], $contentPages);
    }
}

if (!function_exists('_mdPages')) {
    /** Repli Markdown (ancien contenu) -> pages simples avec les classes du template. */
    function _mdPages($md, $images, &$used)
    {
        $lines = preg_split('/\r\n|\r|\n/', (string) $md);
        $chunks = []; $cur = [];
        $body = function ($a) { foreach ($a as $x) { $t = trim($x); if ($t !== '' && strpos($t, '#') !== 0) { return true; } } return false; };
        foreach ($lines as $l) { if (preg_match('/^##\s+\S/', $l) && $body($cur)) { $chunks[] = $cur; $cur = []; } $cur[] = $l; }
        if (!empty(array_filter($cur, function ($x) { return trim($x) !== ''; }))) { $chunks[] = $cur; }
        if (empty($chunks)) { $chunks = [[(string) $md]]; }
        $pages = [];
        foreach ($chunks as $ch) {
            $out = ''; $inList = false;
            foreach ($ch as $line) {
                $t = rtrim($line);
                if ($t === '') { if ($inList) { $out .= '</ul>'; $inList = false; } continue; }
                if (strpos($t, '## ') === 0 || strpos($t, '# ') === 0) {
                    if ($inList) { $out .= '</ul>'; $inList = false; }
                    $title = _uniInline(htmlspecialchars(ltrim($t, '# ')));
                    $out .= '<section class="section"><h2 class="section__title">' . $title . '</h2><hr class="section__rule"></section>';
                } elseif (strpos($t, '### ') === 0) { if ($inList) { $out .= '</ul>'; $inList = false; } $out .= '<h3 class="section__title" style="font-size:1.2rem">' . _uniInline(htmlspecialchars(substr($t, 4))) . '</h3>'; }
                elseif (preg_match('/^\s*[-*]\s+(.*)$/', $t, $li)) { if (!$inList) { $out .= '<ul class="list">'; $inList = true; } $out .= '<li>' . _uniInline(htmlspecialchars($li[1])) . '</li>'; }
                else { if ($inList) { $out .= '</ul>'; $inList = false; } $out .= '<p class="text">' . _uniInline(htmlspecialchars($t)) . '</p>'; }
            }
            if ($inList) { $out .= '</ul>'; }
            $pages[] = '<main class="page">' . $out . '</main>';
        }
        return $pages;
    }
}

if (!function_exists('renderUniformContent')) {
    function renderUniformContent($md, $pdfUrl = '', $showPdfView = false, $images = [], $quizHref = '', $docDate = '')
    {
        $images = array_values((array) $images);
        $used = [];
        $data = json_decode((string) $md, true);
        $blocks = (is_array($data) && !empty($data['blocks']) && is_array($data['blocks'])) ? $data['blocks'] : null;
        $pages = $blocks ? _designedPages($blocks, $images, $used, $docDate) : _mdPages($md, $images, $used);
        // Page de fin AUTOMATIQUE sur CHAQUE guide (ne dépend pas du PDF d'origine).
        $outroCta = ($quizHref !== '')
            ? '<a class="outro__cta" href="' . htmlspecialchars($quizHref) . '" onclick="return famiGuideQuizGuard(event, this.href);">' . t('Passer le quiz', 'Naar de quiz') . ' <span class="arrow" aria-hidden="true">→</span></a>'
            : '';
        $pages[] = '<main class="page"><section class="outro"><div class="outro__card">'
            . '<div class="outro__leaf">🌿</div>'
            . '<p class="outro__eyebrow">' . t('Formation terminée', 'Opleiding voltooid') . '</p>'
            . '<h2 class="outro__title">' . t('Bravo, vous avez tout parcouru&nbsp;!', 'Proficiat, je hebt alles doorlopen&nbsp;!') . '</h2>'
            . '<p class="outro__message">' . t('Une question&nbsp;? N\'hésitez pas à demander au personnel.', 'Een vraag&nbsp;? Aarzel niet om het personeel te vragen.') . '</p>'
            . '<p class="outro__thanks">' . t('Merci pour votre écoute 🌿', 'Bedankt voor je aandacht 🌿') . '</p>'
            . $outroCta
            . '</div></section></main>';
        $n = count($pages);
        $withPdf = ($showPdfView && $pdfUrl !== '');
        ?>
        <style>
        .fami-doc{ --paper:#F7F8F2; --paper-deep:#EEF1E6; --ink:#21301F; --ink-soft:#46543F; --forest:#1E4D2B; --leaf:#3E8E4E; --moss:#74975B; --sprout:#A9C96B; --pollen:#C98A1B; --pollen-bg:#FBF3DF; --tip-bg:#EDF4E0; --info-bg:#E7F0E9; --line:#D8DECB;
            --font-display:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif;
            --font-body:Charter,"Bitstream Charter","Sitka Text",Cambria,Georgia,"Times New Roman",serif;
            --font-label:ui-monospace,"SF Mono","Cascadia Mono","Segoe UI Mono",Consolas,"Liberation Mono",monospace;
            --radius:14px; --shadow:0 1px 2px rgba(30,55,30,.06),0 8px 28px rgba(30,55,30,.07);
            width:100%; background:var(--paper); color:var(--ink); font-family:var(--font-body); font-size:1.075rem; line-height:1.7; -webkit-font-smoothing:antialiased; text-rendering:optimizeLegibility; }
        .fami-doc a{ color:var(--forest); text-decoration-color:var(--moss); text-underline-offset:3px; }
        .fami-doc a:hover{ color:var(--leaf); }
        .fami-doc strong{ color:var(--forest); }
        .fami-doc em{ font-style:italic; }
        .fami-doc .page{ max-width:800px; margin:0 auto; padding:0 24px 40px; }
        .fami-doc .hero{ position:relative; overflow:hidden; background:linear-gradient(155deg,#17381F 0%,var(--forest) 55%,#2A6339 100%); color:#F3F7EE; border-radius:0 0 28px 28px; padding:clamp(40px,7vw,72px) 24px clamp(36px,6vw,56px); }
        .fami-doc .hero::before{ content:""; position:absolute; inset:0; pointer-events:none; opacity:.55; background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='340' height='340' viewBox='0 0 340 340'%3E%3Cg fill='none' stroke='%23BFE0B8' stroke-opacity='0.14' stroke-width='1.4'%3E%3Cpath d='M40 300 C 60 220, 60 160, 90 90'/%3E%3Cpath d='M62 235 q 34 -22 40 -58 q -40 10 -40 58'/%3E%3Cpath d='M70 190 q -36 -14 -46 -50 q 40 4 46 50'/%3E%3Cpath d='M250 320 C 262 250, 258 200, 280 140'/%3E%3Cpath d='M262 260 q 30 -18 36 -50 q -36 8 -36 50'/%3E%3Cpath d='M268 215 q -32 -12 -40 -44 q 36 4 40 44'/%3E%3Cpath d='M170 60 q 26 -34 66 -36 q -8 40 -50 46 q -12 2 -16 -10 z'/%3E%3Cpath d='M150 66 q -26 -30 -62 -30 q 8 36 46 42 q 12 2 16 -12 z'/%3E%3C/g%3E%3C/svg%3E"); background-size:340px 340px; }
        .fami-doc .hero__inner{ position:relative; max-width:752px; margin:0 auto; }
        .fami-doc .hero__brand{ font-family:var(--font-label); font-size:.78rem; letter-spacing:.22em; text-transform:uppercase; color:var(--sprout); margin:0 0 16px; }
        .fami-doc .hero__title{ font-family:var(--font-display); font-weight:800; font-size:clamp(2rem,5vw,3.2rem); line-height:1.08; letter-spacing:-.02em; margin:0 0 12px; text-wrap:balance; }
        .fami-doc .hero__subtitle{ font-size:clamp(1.02rem,2.2vw,1.2rem); line-height:1.55; color:#DEEBD6; max-width:56ch; margin:0; }
        .fami-doc .section{ margin:52px 0 8px; }
        .fami-doc .page > .section:first-child{ margin-top:20px; }
        .fami-doc .section__eyebrow{ font-family:var(--font-label); font-size:.74rem; letter-spacing:.2em; text-transform:uppercase; color:var(--leaf); margin:0 0 8px; }
        .fami-doc .section__title{ font-family:var(--font-display); font-weight:800; font-size:clamp(1.45rem,3.4vw,1.9rem); letter-spacing:-.015em; line-height:1.2; margin:0 0 14px; color:var(--forest); text-wrap:balance; }
        .fami-doc .section__rule{ height:12px; border:0; margin:0 0 10px; background:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='120' height='12' viewBox='0 0 120 12'%3E%3Cpath d='M0 8 H 96' stroke='%2374975B' stroke-width='2' stroke-linecap='round'/%3E%3Cpath d='M96 8 q 10 -8 22 -7 q -4 10 -16 9 q -4 0 -6 -2 z' fill='%233E8E4E'/%3E%3C/svg%3E") left center / 120px 12px no-repeat; }
        .fami-doc .text{ margin:0 0 1.2em; max-width:70ch; }
        .fami-doc .list{ list-style:none; margin:0 0 1.4em; padding:0; max-width:70ch; }
        .fami-doc .list li{ position:relative; padding-left:30px; margin-bottom:.55em; }
        .fami-doc .list li::before{ content:""; position:absolute; left:4px; top:.52em; width:12px; height:12px; background:var(--leaf); border-radius:0 70% 0 70%; transform:rotate(45deg); }
        .fami-doc .steps{ list-style:none; counter-reset:step; margin:8px 0 32px; padding:0; display:grid; gap:14px; }
        .fami-doc .steps li{ counter-increment:step; position:relative; background:#fff; border:1px solid var(--line); border-left:4px solid var(--leaf); border-radius:var(--radius); box-shadow:var(--shadow); padding:20px 24px 20px 78px; }
        .fami-doc .steps li::before{ content:counter(step,decimal-leading-zero); position:absolute; left:22px; top:20px; font-family:var(--font-display); font-weight:800; font-size:1.3rem; color:var(--leaf); letter-spacing:-.02em; }
        .fami-doc .steps__title{ display:block; font-family:var(--font-display); font-weight:700; font-size:1.05rem; color:var(--forest); margin-bottom:4px; }
        .fami-doc .steps__desc{ margin:0; color:var(--ink-soft); }
        .fami-doc .callout{ display:grid; grid-template-columns:40px 1fr; gap:14px; align-items:start; border-radius:var(--radius); padding:20px 22px; margin:26px 0; border:1px solid; }
        .fami-doc .callout__icon{ width:40px; height:40px; border-radius:50%; display:grid; place-items:center; }
        .fami-doc .callout__icon svg{ width:22px; height:22px; display:block; }
        .fami-doc .callout__title{ font-family:var(--font-display); font-weight:700; font-size:1rem; margin:0 0 4px; }
        .fami-doc .callout__text{ margin:0; }
        .fami-doc .callout--info{ background:var(--info-bg); border-color:#C4D8C9; } .fami-doc .callout--info .callout__icon{ background:var(--forest); color:#EAF4EC; } .fami-doc .callout--info .callout__title{ color:var(--forest); }
        .fami-doc .callout--tip{ background:var(--tip-bg); border-color:#CFDFAF; } .fami-doc .callout--tip .callout__icon{ background:#5E8A3A; color:#F1F7E4; } .fami-doc .callout--tip .callout__title{ color:#4A6E2D; }
        .fami-doc .callout--warning{ background:var(--pollen-bg); border-color:#E8D3A4; } .fami-doc .callout--warning .callout__icon{ background:var(--pollen); color:#FFF8E9; } .fami-doc .callout--warning .callout__title{ color:#8F6210; }
        .fami-doc .keyfigures{ display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:14px; margin:28px 0 32px; }
        .fami-doc .keyfigure{ background:#fff; border:1px solid var(--line); border-top:4px solid var(--sprout); border-radius:var(--radius); box-shadow:var(--shadow); padding:20px 18px 16px; text-align:center; }
        .fami-doc .keyfigure__number{ display:block; font-family:var(--font-display); font-weight:800; font-size:clamp(1.9rem,4.5vw,2.5rem); letter-spacing:-.03em; line-height:1.1; color:var(--forest); }
        .fami-doc .keyfigure__label{ display:block; font-family:var(--font-label); font-size:.74rem; letter-spacing:.1em; text-transform:uppercase; color:var(--ink-soft); margin-top:8px; }
        .fami-doc .image{ margin:30px 0 34px; text-align:center; }
        .fami-doc .image__real{ max-width:min(100%,420px); max-height:400px; width:auto; height:auto; display:inline-block; border-radius:var(--radius); box-shadow:var(--shadow); object-fit:contain; }
        .fami-doc .image--s .image__real{ max-width:min(100%,280px); max-height:300px; }
        .fami-doc .image--m .image__real{ max-width:min(100%,420px); max-height:400px; }
        .fami-doc .image--l .image__real{ max-width:min(100%,560px); max-height:480px; }
        .fami-doc .image__caption{ font-family:var(--font-label); font-size:.8rem; color:var(--ink-soft); margin-top:10px; text-align:center; }
        .fami-doc .quote{ margin:36px 0; padding:8px 8px 8px 30px; border-left:4px solid var(--leaf); }
        .fami-doc .quote__text{ font-size:clamp(1.2rem,2.8vw,1.45rem); line-height:1.5; font-style:italic; color:var(--forest); margin:0; text-wrap:balance; }
        .fami-doc .quote__text::before{ content:"«\00A0"; color:var(--moss); } .fami-doc .quote__text::after{ content:"\00A0»"; color:var(--moss); }
        .fami-doc .outro{ margin:24px 0; }
        .fami-doc .outro__card{ text-align:center; background:radial-gradient(110% 90% at 50% -20%,#2F6B3C 0%,transparent 60%),linear-gradient(165deg,#17381F,#1E4D2B 65%,#235831); color:#F3F7EE; border-radius:24px; padding:clamp(44px,8vw,72px) clamp(24px,6vw,56px); }
        .fami-doc .outro__leaf{ font-size:2.6rem; }
        .fami-doc .outro__eyebrow{ font-family:var(--font-label); font-size:.74rem; letter-spacing:.24em; text-transform:uppercase; color:var(--sprout); margin:8px 0 10px; }
        .fami-doc .outro__title{ font-family:var(--font-display); font-weight:800; font-size:clamp(1.6rem,4vw,2.4rem); margin:0 0 14px; color:#fff; }
        .fami-doc .outro__message{ color:#DEEBD6; font-size:1.1rem; max-width:48ch; margin:0 auto 8px; }
        .fami-doc .outro__thanks{ font-style:italic; color:#C9DEBE; margin:0 0 28px; }
        .fami-doc .outro__cta{ font-family:var(--font-display); font-weight:800; font-size:1.05rem; text-decoration:none; color:var(--forest); background:linear-gradient(180deg,#FDFEF9,#EAF2DE); border:1px solid #fff; border-radius:999px; padding:15px 32px; display:inline-flex; align-items:center; gap:12px; box-shadow:0 10px 30px rgba(0,0,0,.28); transition:transform .18s ease; }
        .fami-doc .outro__cta:hover{ transform:translateY(-2px); color:var(--forest); } .fami-doc .outro__cta .arrow{ transition:transform .18s ease; } .fami-doc .outro__cta:hover .arrow{ transform:translateX(4px); }
        .fami-doc .pagenav{ position:sticky; bottom:0; margin:40px auto 0; max-width:800px; padding:14px 24px; background:rgba(247,248,242,.94); backdrop-filter:blur(6px); border-top:1px solid var(--line); display:grid; grid-template-columns:1fr auto 1fr; align-items:center; gap:14px; }
        .fami-doc .pagenav__link{ font-family:var(--font-display); font-weight:700; font-size:.95rem; color:var(--forest); background:#fff; border:1px solid var(--line); border-radius:999px; padding:11px 20px; display:inline-flex; align-items:center; gap:8px; box-shadow:var(--shadow); cursor:pointer; }
        .fami-doc .pagenav__link:hover:not(:disabled){ background:var(--info-bg); border-color:var(--leaf); }
        .fami-doc .pagenav__link--prev{ justify-self:start; } .fami-doc .pagenav__link--next{ justify-self:end; }
        .fami-doc .pagenav__link:disabled{ opacity:.4; cursor:not-allowed; }
        .fami-doc .pagenav__counter{ font-family:var(--font-label); font-size:.8rem; letter-spacing:.12em; color:var(--ink-soft); text-align:center; white-space:nowrap; }
        .fami-doc .pagenav__counter strong{ color:var(--forest); }
        .fami-doc .doc-pdf iframe{ width:100%; height:82vh; border:none; display:block; background:#f4f7f6; }
        .fami-doc .cover{ position:relative; min-height:100svh; display:grid; place-items:center; overflow:hidden; color:#F3F7EE; background:radial-gradient(120% 90% at 85% -10%,#2F6B3C 0%,transparent 55%),radial-gradient(110% 80% at 0% 110%,#123018 0%,transparent 60%),linear-gradient(160deg,#17381F 0%,var(--forest) 60%,#235831 100%); padding:clamp(48px,8vh,96px) 24px; }
        .fami-doc .cover__flora{ position:absolute; inset:0; width:100%; height:100%; pointer-events:none; }
        .fami-doc .cover__flora path{ fill:none; stroke:#BFE0B8; stroke-width:1.6; stroke-linecap:round; vector-effect:non-scaling-stroke; }
        .fami-doc .cover__flora .flora--faint{ stroke-opacity:.10; } .fami-doc .cover__flora .flora--soft{ stroke-opacity:.18; } .fami-doc .cover__flora .flora--bright{ stroke:var(--sprout); stroke-opacity:.55; }
        .fami-doc .cover__flora .flora--draw{ stroke-dasharray:900; animation:flora-draw 2.4s cubic-bezier(.4,0,.2,1) both; }
        .fami-doc .cover__inner{ position:relative; width:100%; max-width:var(--measure); text-align:center; animation:cover-in .8s ease-out both; }
        .fami-doc .cover__brand{ font-family:var(--font-label); font-size:.8rem; letter-spacing:.26em; text-transform:uppercase; color:var(--sprout); margin:0 0 22px; }
        .fami-doc .cover__brand::before,.fami-doc .cover__brand::after{ content:""; display:inline-block; width:10px; height:10px; background:var(--sprout); border-radius:0 70% 0 70%; transform:rotate(45deg); margin:0 14px; vertical-align:middle; }
        .fami-doc .cover__title{ font-family:var(--font-display); font-weight:800; font-size:clamp(2.5rem,7.5vw,4.6rem); line-height:1.04; letter-spacing:-.025em; margin:0 0 20px; text-wrap:balance; }
        .fami-doc .cover__subtitle{ font-size:clamp(1.1rem,2.6vw,1.3rem); line-height:1.55; color:#DEEBD6; max-width:52ch; margin:0 auto 30px; text-wrap:balance; }
        .fami-doc .cover__meta{ display:flex; flex-wrap:wrap; justify-content:center; gap:10px; padding:0; margin:0 0 40px; list-style:none; }
        .fami-doc .cover__meta li{ font-family:var(--font-label); font-size:.8rem; letter-spacing:.04em; background:rgba(255,255,255,.10); border:1px solid rgba(255,255,255,.28); border-radius:999px; padding:7px 15px 7px 12px; display:inline-flex; align-items:center; gap:8px; }
        .fami-doc .cover__meta li::before{ content:""; width:9px; height:9px; background:var(--sprout); border-radius:0 70% 0 70%; transform:rotate(45deg); flex:none; }
        .fami-doc .cover__cta{ font-family:var(--font-display); font-weight:800; font-size:1.05rem; color:var(--forest); background:linear-gradient(180deg,#FDFEF9 0%,#EAF2DE 100%); border:1px solid #fff; border-radius:999px; padding:16px 34px; display:inline-flex; align-items:center; gap:12px; box-shadow:0 10px 30px rgba(0,0,0,.28),inset 0 1px 0 #fff; cursor:pointer; transition:transform .18s ease; }
        .fami-doc .cover__cta:hover{ transform:translateY(-2px); } .fami-doc .cover__cta .arrow{ transition:transform .18s ease; } .fami-doc .cover__cta:hover .arrow{ transform:translateX(4px); }
        .fami-doc .cover__scrollhint{ position:absolute; left:50%; bottom:22px; transform:translateX(-50%); font-family:var(--font-label); font-size:.7rem; letter-spacing:.22em; text-transform:uppercase; color:rgba(243,247,238,.6); text-decoration:none; }
        .fami-doc .cover__scrollhint::after{ content:"↓"; display:block; text-align:center; margin-top:4px; animation:hint-bob 2s ease-in-out infinite; }
        .fami-doc .toc{ max-width:var(--measure); margin:0 auto; padding:clamp(56px,9vw,88px) 24px 40px; }
        .fami-doc .toc__eyebrow{ font-family:var(--font-label); font-size:.74rem; letter-spacing:.2em; text-transform:uppercase; color:var(--leaf); margin:0 0 8px; }
        .fami-doc .toc__title{ font-family:var(--font-display); font-weight:800; font-size:clamp(1.5rem,3.6vw,2rem); letter-spacing:-.015em; line-height:1.2; color:var(--forest); margin:0 0 14px; }
        .fami-doc .toc__rule{ height:12px; border:0; margin:0 0 30px; background:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='120' height='12' viewBox='0 0 120 12'%3E%3Cpath d='M0 8 H 96' stroke='%2374975B' stroke-width='2' stroke-linecap='round'/%3E%3Cpath d='M96 8 q 10 -8 22 -7 q -4 10 -16 9 q -4 0 -6 -2 z' fill='%233E8E4E'/%3E%3C/svg%3E") left center / 120px 12px no-repeat; }
        .fami-doc .toc__list{ list-style:none; margin:0; padding:0; display:grid; gap:14px; }
        .fami-doc .toc__link{ display:grid; grid-template-columns:64px 1fr auto; align-items:center; gap:18px; text-decoration:none; color:inherit; background:#fff; border:1px solid var(--line); border-radius:var(--radius); box-shadow:var(--shadow); padding:20px 22px; position:relative; overflow:hidden; transition:transform .2s ease,border-color .2s ease; }
        .fami-doc .toc__link::before{ content:""; position:absolute; left:0; top:0; bottom:0; width:4px; background:linear-gradient(180deg,var(--sprout),var(--leaf)); transform:scaleY(0); transform-origin:bottom; transition:transform .25s ease; }
        .fami-doc .toc__link:hover,.fami-doc .toc__link:focus-visible{ transform:translateY(-2px); border-color:var(--leaf); }
        .fami-doc .toc__link:hover::before,.fami-doc .toc__link:focus-visible::before{ transform:scaleY(1); }
        .fami-doc .toc__num{ font-family:var(--font-display); font-weight:800; font-size:1.7rem; letter-spacing:-.02em; color:var(--leaf); line-height:1; text-align:center; }
        .fami-doc .toc__name{ display:block; font-family:var(--font-display); font-weight:700; font-size:1.1rem; color:var(--forest); line-height:1.3; }
        .fami-doc .toc__desc{ display:block; font-size:.95rem; color:var(--ink-soft); margin-top:3px; }
        .fami-doc .toc__arrow{ font-size:1.25rem; color:var(--moss); transition:transform .2s ease,color .2s ease; }
        .fami-doc .toc__link:hover .toc__arrow,.fami-doc .toc__link:focus-visible .toc__arrow{ transform:translateX(5px); color:var(--leaf); }
        @keyframes flora-draw{ from{ stroke-dashoffset:900; } to{ stroke-dashoffset:0; } }
        @keyframes cover-in{ from{ opacity:0; transform:translateY(12px); } to{ opacity:1; transform:none; } }
        @keyframes hint-bob{ 0%,100%{ transform:translateY(0); } 50%{ transform:translateY(4px); } }
        @media (max-width:560px){ .fami-doc .pagenav{ grid-template-columns:1fr 1fr; } .fami-doc .pagenav__counter{ grid-column:1/-1; order:3; } .fami-doc .toc__link{ grid-template-columns:48px 1fr auto; gap:14px; } .fami-doc .toc__num{ font-size:1.4rem; } }
        </style>

        <div class="fami-doc">
            <div class="doc-view doc-read">
                <?php foreach ($pages as $i => $html): ?>
                    <div class="doc-page" data-page="<?= (int) $i ?>" <?= $i === 0 ? '' : 'style="display:none;"' ?>><?= $html ?></div>
                <?php endforeach; ?>
                <?php if ($n > 1): ?>
                    <nav class="pagenav" aria-label="<?= t('Navigation entre les pages', 'Paginanavigatie') ?>">
                        <button type="button" class="pagenav__link pagenav__link--prev" id="uniPrev" onclick="uniPage(-1)"><span aria-hidden="true">←</span> <?= t('Précédent', 'Vorige') ?></button>
                        <p class="pagenav__counter"><?= t('Page', 'Pagina') ?> <strong id="uniCur">1</strong> / <?= (int) $n ?></p>
                        <button type="button" class="pagenav__link pagenav__link--next" id="uniNext" onclick="uniPage(1)"><?= t('Suivant', 'Volgende') ?> <span aria-hidden="true">→</span></button>
                    </nav>
                <?php endif; ?>
            </div>
            <?php if ($withPdf): ?>
                <div class="doc-view doc-pdf" style="display:none;"><iframe src="<?= htmlspecialchars($pdfUrl) ?>" title="<?= t('PDF original', 'Originele PDF') ?>"></iframe></div>
            <?php endif; ?>
        </div>

        <div id="famiDoneModal" style="display:none; position:fixed; inset:0; z-index:100000; background:rgba(18,32,20,.55); align-items:center; justify-content:center; padding:20px;">
            <div style="background:#fff; border-radius:20px; max-width:460px; width:100%; padding:30px 28px; box-shadow:0 24px 60px rgba(0,0,0,.35); text-align:center; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;">
                <div style="font-size:2.4rem; line-height:1; margin-bottom:12px;">🌿</div>
                <h3 style="margin:0 0 10px; color:#1E4D2B; font-size:1.3rem;"><?= t('Vous n\'avez pas tout parcouru', 'Je hebt niet alles doorlopen') ?></h3>
                <p style="margin:0 0 22px; color:#46543F; line-height:1.55;"><?= t('Nous vous recommandons fortement de <strong>terminer la lecture du guide</strong> avant de passer le quiz&nbsp;: vous y trouverez les réponses. 🙂', 'We raden je sterk aan om <strong>de gids volledig te lezen</strong> voordat je de quiz maakt&nbsp;: je vindt er de antwoorden. 🙂') ?></p>
                <div style="display:flex; gap:10px; flex-wrap:wrap; justify-content:center;">
                    <button type="button" onclick="famiDoneClose()" style="background:#1E4D2B; color:#fff; border:none; border-radius:999px; padding:13px 22px; font-weight:700; cursor:pointer; font-size:1rem;">↩ <?= t('Terminer la lecture', 'Verder lezen') ?></button>
                    <button type="button" onclick="famiDoneProceed()" style="background:#eef1e6; color:#46543F; border:none; border-radius:999px; padding:13px 22px; font-weight:700; cursor:pointer; font-size:.95rem;"><?= t('Passer quand même', 'Toch doorgaan') ?></button>
                </div>
            </div>
        </div>

        <script>
        (function () {
            var idx = 0, total = <?= (int) $n ?>;
            // --- Suivi de complétion : le guide est « lu » quand toutes les pages ont été vues ---
            var visited = {}, pendingHref = '';
            function guideComplete() {
                for (var p = 0; p < total; p++) { if (!visited[p]) { return false; } }
                return true;
            }
            window.famiGuideQuizGuard = function (ev, href) {
                if (guideComplete()) { return true; }
                if (ev) { ev.preventDefault(); }
                pendingHref = href;
                var m = document.getElementById('famiDoneModal');
                if (m) { m.style.display = 'flex'; }
                return false;
            };
            window.famiDoneClose = function () { var m = document.getElementById('famiDoneModal'); if (m) { m.style.display = 'none'; } };
            window.famiDoneProceed = function () { if (pendingHref) { window.location.href = pendingHref; } };
            window.uniTogglePdf = function () {
                var read = document.querySelector('.doc-read'), pdf = document.querySelector('.doc-pdf'), eye = document.getElementById('uniEye');
                if (!pdf) { return; }
                var showPdf = (pdf.style.display === 'none');
                pdf.style.display = showPdf ? '' : 'none';
                if (read) { read.style.display = showPdf ? 'none' : ''; }
                if (eye) { eye.textContent = showPdf ? '📖' : '👁'; eye.title = showPdf ? <?= json_encode(t('Revenir à la lecture', 'Terug naar het lezen')) ?> : <?= json_encode(t('Voir le PDF original', 'Originele PDF bekijken')) ?>; }
                window.scrollTo({ top: 0, behavior: 'smooth' });
            };
            function show(i) {
                idx = Math.max(0, Math.min(total - 1, i));
                visited[idx] = true; // page vue (pour le suivi de lecture)
                document.querySelectorAll('.doc-page').forEach(function (p) {
                    p.style.display = (parseInt(p.getAttribute('data-page'), 10) === idx) ? '' : 'none';
                });
                var c = document.getElementById('uniCur'); if (c) { c.textContent = idx + 1; }
                var pv = document.getElementById('uniPrev'), nx = document.getElementById('uniNext');
                if (pv) { pv.disabled = (idx === 0); }
                if (nx) { nx.disabled = (idx === total - 1); }
                var d = document.querySelector('.fami-doc');
                if (d && d.scrollIntoView) { d.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
            }
            window.uniPage = function (d) { show(idx + d); };
            window.uniGoto = function (i) { show(i); };
            show(0);
        })();
        </script>
        <?php
    }
}
