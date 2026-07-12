<?php
// ============================================================
// module_edit.php — RELECTURE VISUELLE (WYSIWYG).
//   On affiche la fiche telle qu'elle sera, et on édite le texte DIRECTEMENT sur la page.
//   Images à leur taille (avec pivoter/taille), doutes de l'IA en rouge (Appliquer/Ignorer).
//   À la validation, la page est relue et renvoyée en JSON à module_review.php (save_review).
//   Mode « avancé » (champs) toujours dispo : module_review.php.
// ============================================================
require_once 'config.php';
verifierConnexion($db);
require_once 'includes/modules.php';

$isAdmin = (($_SESSION['role'] ?? '') === 'admin');
$uid = (int) ($_SESSION['user_id'] ?? 0);

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$module = $id > 0 ? getModuleById($db, $id) : null;
if (!$module) { header('Location: index.php'); exit(); }
$canReview = $isAdmin || ((int) ($module['contenu_by'] ?? 0) === $uid && $uid > 0);
if (!$canReview) { header('Location: module.php?id=' . $id); exit(); }

$data = json_decode((string) ($module['contenu_ia'] ?? ''), true);
$blocks = (is_array($data) && !empty($data['blocks']) && is_array($data['blocks'])) ? $data['blocks'] : null;
if (!$blocks) { header('Location: module_review.php?id=' . $id); exit(); }

$images = (array) json_decode((string) ($module['contenu_images'] ?? '[]'), true);
$pdfUrl = !empty($module['pdf_path']) ? moduleFileUrl($module['pdf_path']) : '';

// **gras** -> <strong> pour l'affichage éditable (reconverti en ** à l'enregistrement, côté JS).
function _veInline($s)
{
    $t = htmlspecialchars((string) $s);
    $t = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $t);
    return $t;
}
$sizePx = ['s' => 200, 'm' => 320, 'l' => 460];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Relecture visuelle — <?= htmlspecialchars(moduleNom($module)) ?></title>
<link rel="shortcut icon" type="image/x-icon" href="favicon.ico">
<style>
    :root { --paper:#F7F8F2; --ink:#21301F; --ink-soft:#46543F; --forest:#1E4D2B; --leaf:#3E8E4E; --moss:#74975B; --sprout:#A9C96B; --line:#D8DECB;
        --fdisplay:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif; --fbody:Charter,Georgia,"Times New Roman",serif; }
    * { box-sizing:border-box; }
    body { margin:0; background:var(--paper); color:var(--ink); font-family:var(--fbody); font-size:1.06rem; line-height:1.7; }
    .topbar { position:sticky; top:0; z-index:20; background:#fff; border-bottom:1px solid var(--line); display:flex; align-items:center; justify-content:space-between; gap:10px; padding:12px 16px; flex-wrap:wrap; }
    .topbar .btn { border:none; border-radius:10px; padding:10px 15px; font-weight:700; cursor:pointer; text-decoration:none; font:inherit; }
    .btn-back { background:#e9ecef; color:#333; } .btn-adv { background:#eef2ff; color:#33417a; border:1px solid #ccd4f5; }
    .btn-pdf { background:#eef7f0; color:var(--forest); border:1px solid #cfe3d5; } .btn-save { background:var(--forest); color:#fff; }
    .intro { max-width:820px; margin:16px auto 0; padding:12px 18px; background:#fff; border:1px solid var(--line); border-radius:12px; font-family:var(--fdisplay); font-size:.92rem; color:var(--ink-soft); }
    .intro b { color:var(--forest); }
    .ve-doc { max-width:820px; margin:0 auto; padding:8px 20px 140px; }
    [contenteditable] { outline:none; border-radius:6px; transition:box-shadow .12s, background .12s; }
    body.editing [data-f] { cursor:text; }
    body.editing [contenteditable]:hover { box-shadow:0 0 0 2px #e2ebe1; }
    body.editing [contenteditable]:focus { box-shadow:0 0 0 2px var(--leaf); background:#fff; }
    /* Verrou d'édition : outils et boutons cachés tant qu'on n'a pas cliqué « Modifier ». */
    .ve-tools, .ve-add, .ve-del, .fix { display:none; }
    body.editing .ve-tools { display:flex; }
    body.editing .ve-add { display:inline-block; }
    body.editing .ve-del { display:inline; }
    body.editing .fix { display:block; }
    body.editing .intro::after { content:" — mode édition ACTIF"; color:#8a5a00; font-weight:700; }
    .ve-hero { background:linear-gradient(155deg,#17381F,#1E4D2B 60%,#2A6339); color:#F3F7EE; border-radius:0 0 24px 24px; padding:40px 26px; margin:0 -20px 8px; text-align:center; }
    .ve-hero .eyebrow { font-family:ui-monospace,monospace; letter-spacing:.22em; text-transform:uppercase; font-size:.72rem; color:var(--sprout); }
    .ve-hero h1 { font-family:var(--fdisplay); font-weight:800; font-size:clamp(1.9rem,5vw,3rem); margin:10px 0; letter-spacing:-.02em; }
    .ve-hero .sub { color:#DEEBD6; font-size:1.1rem; max-width:54ch; margin:0 auto; }
    .ve-blk { margin:22px 0; position:relative; }
    h2.ve-sec { font-family:var(--fdisplay); font-weight:800; color:var(--forest); font-size:clamp(1.4rem,3.4vw,1.85rem); border-bottom:2px solid; border-image:linear-gradient(90deg,var(--leaf),transparent) 1; padding-bottom:6px; }
    .ve-text { max-width:70ch; }
    .ve-list { list-style:none; padding:0; margin:0; max-width:70ch; }
    .ve-list .ve-li { position:relative; padding-left:28px; margin:.4em 0; }
    .ve-list .ve-li::before { content:""; position:absolute; left:4px; top:.55em; width:11px; height:11px; background:var(--leaf); border-radius:0 70% 0 70%; transform:rotate(45deg); }
    .ve-steps { display:grid; gap:12px; margin:8px 0; counter-reset:s; }
    .ve-step { background:#fff; border:1px solid var(--line); border-left:4px solid var(--leaf); border-radius:12px; padding:14px 16px 14px 60px; position:relative; counter-increment:s; }
    .ve-step::before { content:counter(s,decimal-leading-zero); position:absolute; left:16px; top:14px; font-family:var(--fdisplay); font-weight:800; color:var(--leaf); font-size:1.2rem; }
    .ve-step .st-t { font-family:var(--fdisplay); font-weight:700; color:var(--forest); }
    .ve-step .st-d { color:var(--ink-soft); }
    .ve-callout { display:grid; grid-template-columns:8px 1fr; gap:14px; border-radius:12px; padding:16px 18px; border:1px solid; }
    .ve-callout .bar { border-radius:6px; }
    .ve-callout .c-t { font-family:var(--fdisplay); font-weight:700; margin-bottom:2px; }
    .ve-callout.info { background:#E7F0E9; border-color:#C4D8C9; } .ve-callout.info .bar { background:var(--forest); } .ve-callout.info .c-t { color:var(--forest); }
    .ve-callout.tip { background:#EDF4E0; border-color:#CFDFAF; } .ve-callout.tip .bar { background:#5E8A3A; } .ve-callout.tip .c-t { color:#4A6E2D; }
    .ve-callout.warning { background:#FBF3DF; border-color:#E8D3A4; } .ve-callout.warning .bar { background:#C98A1B; } .ve-callout.warning .c-t { color:#8F6210; }
    .ve-kf { display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:12px; }
    .ve-kf .kf { background:#fff; border:1px solid var(--line); border-top:4px solid var(--sprout); border-radius:12px; padding:16px; text-align:center; }
    .ve-kf .kf .v { font-family:var(--fdisplay); font-weight:800; color:var(--forest); font-size:1.9rem; }
    .ve-kf .kf .l { font-family:ui-monospace,monospace; font-size:.74rem; text-transform:uppercase; color:var(--ink-soft); }
    .ve-quote { border-left:4px solid var(--leaf); padding:6px 6px 6px 24px; font-style:italic; color:var(--forest); font-size:1.25rem; }
    figure.ve-img { margin:24px 0; text-align:center; }
    figure.ve-img img { max-width:100%; border-radius:12px; box-shadow:0 8px 24px rgba(30,55,30,.12); display:inline-block; }
    figure.ve-img figcaption { font-family:ui-monospace,monospace; font-size:.8rem; color:var(--ink-soft); margin-top:8px; display:block; text-align:center; }
    .ve-tools { display:flex; gap:6px; flex-wrap:wrap; justify-content:center; margin-top:8px; }
    .ve-tools button { border:1px solid var(--line); background:#fff; border-radius:8px; padding:4px 10px; cursor:pointer; font:inherit; font-size:.82rem; }
    .ve-tools button.on { background:var(--forest); color:#fff; border-color:var(--forest); }
    .ve-tools .sep { width:1px; background:var(--line); margin:0 4px; }
    .ve-add { display:inline-block; margin-top:6px; background:#eef7f0; color:var(--forest); border:1px dashed var(--moss); border-radius:8px; padding:5px 12px; cursor:pointer; font-family:var(--fdisplay); font-weight:700; font-size:.85rem; }
    .ve-del { cursor:pointer; color:#b3261e; font-weight:800; margin-left:8px; user-select:none; }
    .fix { margin-top:8px; background:#fdecec; border:1px solid #f3b4b4; border-radius:10px; padding:10px 12px; }
    .fix .lab { font-weight:800; color:#c0392b; font-size:.82rem; }
    .fix .sug { color:#c0392b; font-weight:700; margin:4px 0 8px; }
    .fix button { border:none; border-radius:8px; padding:6px 12px; font-weight:700; cursor:pointer; margin-right:6px; }
    .fix .ap { background:var(--forest); color:#fff; } .fix .ig { background:#e9ecef; color:#444; }
    .savebar { position:fixed; bottom:0; left:0; right:0; background:#fff; border-top:1px solid var(--line); padding:12px; display:flex; justify-content:center; gap:12px; box-shadow:0 -6px 18px rgba(0,0,0,.06); }
    .pdfp { display:none; max-width:820px; margin:12px auto 0; border:1px solid var(--line); border-radius:12px; overflow:hidden; }
    .pdfp.open { display:block; } .pdfp iframe { width:100%; height:70vh; border:none; }
</style>
</head>
<body>
    <div class="topbar">
        <a href="module.php?id=<?= (int) $id ?>" class="btn btn-back">⬅ Quitter</a>
        <strong style="color:#1E4D2B;">✍️ Relecture — édite directement sur la page</strong>
        <div style="display:flex; gap:8px; flex-wrap:wrap;">
            <a href="module_review.php?id=<?= (int) $id ?>" class="btn btn-adv">⚙️ Mode avancé</a>
            <?php if ($pdfUrl !== ''): ?><button type="button" class="btn btn-pdf" onclick="document.getElementById('pdfp').classList.toggle('open')">📄 PDF original</button><?php endif; ?>
            <button type="button" id="editToggle" class="btn" style="background:#fff3d6; color:#8a5a00; border:1px solid #f0d089;" onclick="veSetEdit(!window._veEditing)">✏️ Modifier</button>
            <button type="button" class="btn btn-save" onclick="veSubmit()">✅ Valider</button>
        </div>
    </div>

    <div class="intro"><b>Clique sur un texte pour le corriger</b>, directement sur la page. Survole une <b>image</b> pour la pivoter / changer sa taille. Les <b style="color:#c0392b;">doutes de l'IA</b> apparaissent en rouge : <b>Appliquer</b> ou <b>Ignorer</b>. Puis <b>Valider</b>.</div>
    <?php if ($pdfUrl !== ''): ?><div class="pdfp" id="pdfp"><iframe src="<?= htmlspecialchars($pdfUrl) ?>"></iframe></div><?php endif; ?>

    <div class="ve-doc" id="veDoc">
        <?php foreach ($blocks as $b): $type = (string) ($b['type'] ?? ''); ?>
        <?php if ($type === 'hero'): ?>
            <div class="ve-blk ve-hero" data-type="hero">
                <div class="eyebrow">Famiformation</div>
                <h1 contenteditable="true" data-f="title"><?= _veInline($b['title'] ?? '') ?></h1>
                <div class="sub" contenteditable="true" data-f="subtitle"><?= _veInline($b['subtitle'] ?? '') ?></div>
            </div>
        <?php elseif ($type === 'section'): ?>
            <div class="ve-blk" data-type="section">
                <h2 class="ve-sec" contenteditable="true" data-f="title"><?= _veInline($b['title'] ?? '') ?></h2>
            </div>
        <?php elseif ($type === 'text'): ?>
            <div class="ve-blk" data-type="text">
                <div class="ve-text" contenteditable="true" data-f="text"><?= _veInline($b['text'] ?? '') ?></div>
                <?php if (trim((string) ($b['fix'] ?? '')) !== ''): ?>
                <div class="fix" data-fix="<?= htmlspecialchars((string) $b['fix'], ENT_QUOTES) ?>">
                    <span class="lab">⚠ Doute de l'IA :</span>
                    <div class="sug"><?= _veInline($b['fix']) ?></div>
                    <button type="button" class="ap" onclick="veApplyFix(this,'text')">✓ Appliquer</button>
                    <button type="button" class="ig" onclick="veIgnoreFix(this)">✗ Ignorer</button>
                </div>
                <?php endif; ?>
            </div>
        <?php elseif ($type === 'list'): ?>
            <div class="ve-blk" data-type="list">
                <ul class="ve-list" data-list>
                    <?php foreach ((array) ($b['items'] ?? []) as $it): ?>
                    <li class="ve-li"><span contenteditable="true" data-f="item"><?= _veInline($it) ?></span><span class="ve-del" onclick="veDelItem(this)" title="Supprimer">✕</span></li>
                    <?php endforeach; ?>
                </ul>
                <span class="ve-add" onclick="veAddLi(this)">+ Ajouter un point</span>
            </div>
        <?php elseif ($type === 'steps'): ?>
            <div class="ve-blk" data-type="steps">
                <div class="ve-steps" data-steps>
                    <?php foreach ((array) ($b['items'] ?? []) as $it): $t = is_array($it) ? ($it['title'] ?? '') : ''; $d = is_array($it) ? ($it['desc'] ?? '') : (string) $it; ?>
                    <div class="ve-step">
                        <div class="st-t" contenteditable="true" data-f="title"><?= _veInline($t) ?></div>
                        <div class="st-d" contenteditable="true" data-f="desc"><?= _veInline($d) ?></div>
                        <span class="ve-del" onclick="veDelStep(this)" title="Supprimer l'étape">✕</span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <span class="ve-add" onclick="veAddStep(this)">+ Ajouter une étape</span>
            </div>
        <?php elseif ($type === 'callout'): ?>
            <?php $st = in_array(($b['style'] ?? 'info'), ['info', 'tip', 'warning'], true) ? $b['style'] : 'info'; ?>
            <div class="ve-blk ve-callout <?= $st ?>" data-type="callout" data-style="<?= $st ?>">
                <div class="bar"></div>
                <div>
                    <div class="c-t" contenteditable="true" data-f="title"><?= _veInline($b['title'] ?? '') ?></div>
                    <div contenteditable="true" data-f="text"><?= _veInline($b['text'] ?? '') ?></div>
                    <div class="ve-tools" style="justify-content:flex-start; margin-top:6px;">
                        <button type="button" class="<?= $st === 'info' ? 'on' : '' ?>" onclick="veStyle(this,'info')">Info</button>
                        <button type="button" class="<?= $st === 'tip' ? 'on' : '' ?>" onclick="veStyle(this,'tip')">Astuce</button>
                        <button type="button" class="<?= $st === 'warning' ? 'on' : '' ?>" onclick="veStyle(this,'warning')">Attention</button>
                    </div>
                    <?php if (trim((string) ($b['fix'] ?? '')) !== ''): ?>
                    <div class="fix" data-fix="<?= htmlspecialchars((string) $b['fix'], ENT_QUOTES) ?>">
                        <span class="lab">⚠ Doute de l'IA :</span>
                        <div class="sug"><?= _veInline($b['fix']) ?></div>
                        <button type="button" class="ap" onclick="veApplyFix(this,'callout')">✓ Appliquer</button>
                        <button type="button" class="ig" onclick="veIgnoreFix(this)">✗ Ignorer</button>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php elseif ($type === 'keyfigures'): ?>
            <div class="ve-blk" data-type="keyfigures">
                <div class="ve-kf" data-kf>
                    <?php foreach ((array) ($b['items'] ?? []) as $it): ?>
                    <div class="kf">
                        <div class="v" contenteditable="true" data-f="value"><?= _veInline(is_array($it) ? ($it['value'] ?? '') : '') ?></div>
                        <div class="l" contenteditable="true" data-f="label"><?= _veInline(is_array($it) ? ($it['label'] ?? '') : '') ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php elseif ($type === 'image'): ?>
            <?php $n = (int) ($b['n'] ?? 0); $imgUrl = isset($images[$n - 1]) ? moduleFileUrl($images[$n - 1]) : ''; $sz = in_array(($b['size'] ?? 'm'), ['s', 'm', 'l'], true) ? $b['size'] : 'm'; ?>
            <div class="ve-blk" data-type="image" data-n="<?= $n ?>" data-rotate="0" data-size="<?= $sz ?>">
                <?php if ($imgUrl !== ''): ?>
                <figure class="ve-img">
                    <img class="ve-imgel" src="<?= htmlspecialchars($imgUrl) ?>" alt="" style="max-width:<?= (int) ($sizePx[$sz]) ?>px;">
                    <figcaption contenteditable="true" data-f="caption"><?= _veInline($b['caption'] ?? '') ?></figcaption>
                    <div class="ve-tools">
                        <button type="button" onclick="veRot(this)">↻ Pivoter</button>
                        <span class="sep"></span>
                        <button type="button" class="<?= $sz === 's' ? 'on' : '' ?>" onclick="veSize(this,'s')">Petite</button>
                        <button type="button" class="<?= $sz === 'm' ? 'on' : '' ?>" onclick="veSize(this,'m')">Moyenne</button>
                        <button type="button" class="<?= $sz === 'l' ? 'on' : '' ?>" onclick="veSize(this,'l')">Grande</button>
                    </div>
                </figure>
                <?php else: ?>
                <div class="intro" style="margin:0; color:#b06a00;">Image indisponible (elle sera ignorée).</div>
                <?php endif; ?>
            </div>
        <?php elseif ($type === 'quote'): ?>
            <div class="ve-blk" data-type="quote">
                <div class="ve-quote" contenteditable="true" data-f="text"><?= _veInline($b['text'] ?? '') ?></div>
            </div>
        <?php endif; ?>
        <?php endforeach; ?>
    </div>

    <div class="savebar">
        <a href="module.php?id=<?= (int) $id ?>" class="btn btn-back" style="text-decoration:none;">Annuler</a>
        <button type="button" class="btn btn-save" onclick="veSubmit()">✅ Valider la relecture</button>
    </div>

    <form id="veForm" method="POST" action="module_review.php" style="display:none;">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save_review">
        <input type="hidden" name="id" value="<?= (int) $id ?>">
        <input type="hidden" name="blocks_json" id="veJson" value="">
    </form>

<script>
// Lit un élément éditable et retranscrit le gras (**...**).
function veMd(el) {
    var out = '';
    el.childNodes.forEach(function (node) {
        if (node.nodeType === 3) { out += node.nodeValue; }
        else if (node.nodeType === 1) {
            var tag = node.tagName.toLowerCase();
            var inner = veMd(node);
            if (tag === 'strong' || tag === 'b') { out += '**' + inner + '**'; }
            else if (tag === 'br') { out += '\n'; }
            else if (tag === 'div' || tag === 'p') { out += (out ? '\n' : '') + inner; }
            else { out += inner; }
        }
    });
    return out.replace(/ /g, ' ').trim();
}
function fld(blk, f) { var e = blk.querySelector('[data-f="' + f + '"]'); return e ? veMd(e) : ''; }

function veApplyFix(btn, kind) {
    var fix = btn.closest('.fix');
    var blk = btn.closest('.ve-blk');
    var target = blk.querySelector('[data-f="text"]');
    if (target && fix) { target.innerHTML = fix.getAttribute('data-fix').replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>'); }
    if (fix) { fix.style.display = 'none'; }
}
function veIgnoreFix(btn) { var f = btn.closest('.fix'); if (f) { f.style.display = 'none'; } }
function veStyle(btn, s) {
    var blk = btn.closest('.ve-blk');
    blk.setAttribute('data-style', s);
    blk.classList.remove('info', 'tip', 'warning'); blk.classList.add(s);
    btn.parentNode.querySelectorAll('button').forEach(function (b) { b.classList.remove('on'); });
    btn.classList.add('on');
}
function veRot(btn) {
    var blk = btn.closest('.ve-blk');
    var cur = (parseInt(blk.getAttribute('data-rotate'), 10) || 0 + 0);
    cur = ((parseInt(blk.getAttribute('data-rotate'), 10) || 0) + 90) % 360;
    blk.setAttribute('data-rotate', cur);
    var img = blk.querySelector('.ve-imgel'); if (img) { img.style.transform = 'rotate(' + cur + 'deg)'; }
}
function veSize(btn, s) {
    var blk = btn.closest('.ve-blk');
    blk.setAttribute('data-size', s);
    var w = { s: 200, m: 320, l: 460 }[s] || 320;
    var img = blk.querySelector('.ve-imgel'); if (img) { img.style.maxWidth = w + 'px'; }
    btn.parentNode.querySelectorAll('button').forEach(function (b) { if (b.textContent === 'Petite' || b.textContent === 'Moyenne' || b.textContent === 'Grande') { b.classList.remove('on'); } });
    btn.classList.add('on');
}
function veAddLi(a) {
    var ul = a.previousElementSibling;
    var li = document.createElement('li'); li.className = 've-li';
    li.innerHTML = '<span contenteditable="true" data-f="item"></span><span class="ve-del" onclick="veDelItem(this)" title="Supprimer">✕</span>';
    ul.appendChild(li); li.querySelector('span').focus();
}
function veDelItem(x) { if (!confirm('Supprimer ce point ?')) { return; } var li = x.closest('.ve-li'); if (li) { li.remove(); } }
function veAddStep(a) {
    var box = a.previousElementSibling;
    var d = document.createElement('div'); d.className = 've-step';
    d.innerHTML = '<div class="st-t" contenteditable="true" data-f="title"></div><div class="st-d" contenteditable="true" data-f="desc"></div><span class="ve-del" onclick="veDelStep(this)">✕</span>';
    box.appendChild(d); d.querySelector('.st-t').focus();
}
function veDelStep(x) { if (!confirm('Supprimer cette étape ?')) { return; } var s = x.closest('.ve-step'); if (s) { s.remove(); } }

function veBuild() {
    var blocks = [];
    document.querySelectorAll('#veDoc > .ve-blk').forEach(function (blk) {
        var t = blk.getAttribute('data-type');
        if (t === 'hero') { blocks.push({ type: 'hero', title: fld(blk, 'title'), subtitle: fld(blk, 'subtitle') }); }
        else if (t === 'section') { var x = fld(blk, 'title'); if (x) { blocks.push({ type: 'section', title: x }); } }
        else if (t === 'text') { var x = fld(blk, 'text'); if (x) { blocks.push({ type: 'text', text: x }); } }
        else if (t === 'quote') { var x = fld(blk, 'text'); if (x) { blocks.push({ type: 'quote', text: x }); } }
        else if (t === 'list') {
            var items = []; blk.querySelectorAll('[data-f="item"]').forEach(function (e) { var v = veMd(e); if (v) { items.push(v); } });
            if (items.length) { blocks.push({ type: 'list', items: items }); }
        } else if (t === 'steps') {
            var items = []; blk.querySelectorAll('.ve-step').forEach(function (s) {
                var ti = veMd(s.querySelector('[data-f="title"]')); var de = veMd(s.querySelector('[data-f="desc"]'));
                if (ti || de) { items.push({ title: ti, desc: de }); }
            });
            if (items.length) { blocks.push({ type: 'steps', items: items }); }
        } else if (t === 'callout') {
            var tx = fld(blk, 'text'); var ti = fld(blk, 'title');
            if (tx || ti) { blocks.push({ type: 'callout', style: blk.getAttribute('data-style') || 'info', title: ti, text: tx }); }
        } else if (t === 'keyfigures') {
            var items = []; blk.querySelectorAll('.kf').forEach(function (k) {
                var v = veMd(k.querySelector('[data-f="value"]')); var l = veMd(k.querySelector('[data-f="label"]'));
                if (v) { items.push({ value: v, label: l }); }
            });
            if (items.length) { blocks.push({ type: 'keyfigures', items: items }); }
        } else if (t === 'image') {
            var capEl = blk.querySelector('[data-f="caption"]');
            blocks.push({ type: 'image', n: parseInt(blk.getAttribute('data-n'), 10) || 0, caption: capEl ? veMd(capEl) : '', rotate: parseInt(blk.getAttribute('data-rotate'), 10) || 0, size: blk.getAttribute('data-size') || 'm' });
        }
    });
    return blocks;
}
function veSubmit() {
    document.getElementById('veJson').value = JSON.stringify({ blocks: veBuild() });
    document.getElementById('veForm').submit();
}
// Verrou d'édition : la page démarre en LECTURE SEULE ; « Modifier » déverrouille.
function veSetEdit(on) {
    window._veEditing = !!on;
    document.querySelectorAll('#veDoc [data-f]').forEach(function (e) { e.setAttribute('contenteditable', on ? 'true' : 'false'); });
    document.body.classList.toggle('editing', !!on);
    var b = document.getElementById('editToggle');
    if (b) {
        b.textContent = on ? '🔒 Terminer' : '✏️ Modifier';
        b.style.background = on ? '#e8f5e9' : '#fff3d6';
        b.style.color = on ? '#2d5a37' : '#8a5a00';
    }
}
veSetEdit(false);
</script>
</body>
</html>
