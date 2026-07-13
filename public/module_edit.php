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

// LANGUE DE TRAVAIL = celle du document importé (source), POINT. Un PDF néerlandais se relit
// en néerlandais, un PDF français en français. Il n'y a PAS d'onglet FR/NL ici : la traduction
// n'existe pas encore à ce stade, elle est produite tout à la fin (après le contrôle du quiz).
require_once 'includes/i18n_nl.php';
$srcLang = moduleSourceLang($module);
$lang = $srcLang;
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
    $t = preg_replace('/\[\[c:[a-z]+\]\](.+?)\[\[\/c\]\]/s', '$1', $t);
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
    .ve-tools, .ve-add, .ve-del { display:none; }
    /* Les DOUTES de l'IA sont visibles MÊME en lecture : on doit les voir AVANT de valider.
       Seuls les boutons Appliquer/Ignorer demandent le mode « Modifier ». */
    .fix { display:block; }
    .fix button { display:none; }
    body.editing .fix button { display:inline-block; }
    .fix .lockhint { display:block; color:#8a5a00; font-size:.8rem; font-weight:700; margin-top:2px; }
    body.editing .fix .lockhint { display:none; }
    body.editing .ve-tools { display:flex; }
    body.editing .ve-add { display:inline-block; }
    body.editing .ve-del { display:inline; }
    body.editing .intro::after { content:" — mode édition ACTIF"; color:#8a5a00; font-weight:700; }
    /* Barre de mise en forme (gras / couleur) + barre d'ajout de bloc + contrôles par bloc. */
    .ve-format, .ve-addbar, .ve-ctrl { display:none; }
    body.editing .ve-format { display:flex; flex-wrap:wrap; gap:8px; align-items:center; position:sticky; top:60px; z-index:15; background:#fff; border:1px solid var(--line); border-radius:12px; padding:8px 12px; max-width:820px; margin:10px auto 0; box-shadow:0 4px 14px rgba(0,0,0,.06); }
    .ve-format button { border:1px solid var(--line); background:#fff; border-radius:8px; padding:6px 12px; cursor:pointer; font:inherit; font-weight:700; }
    .ve-format .sw { width:24px; height:24px; border-radius:50%; padding:0; border:2px solid #fff; box-shadow:0 0 0 1px var(--line); cursor:pointer; }
    .ve-format .sep { width:1px; height:22px; background:var(--line); }
    .ve-format .lbl { color:#5a6b60; font-weight:700; font-size:.85rem; }
    .ve-format select { border:1px solid var(--line); border-radius:8px; padding:6px 10px; font:inherit; font-weight:700; background:#fff; cursor:pointer; }
    .ve-blk[data-align="center"] [data-f] { text-align:center; }
    .ve-blk[data-align="right"] [data-f] { text-align:right; }
    .ve-blk[data-align="left"] [data-f] { text-align:left; }
    body.editing .ve-addbar { display:flex; flex-wrap:wrap; gap:8px; align-items:center; max-width:820px; margin:22px auto; padding:14px 16px; background:#fff; border:1px dashed var(--moss); border-radius:14px; }
    .ve-addbar .lbl { font-weight:800; color:var(--forest); }
    .ve-addbar button { background:#eef7f0; color:var(--forest); border:1px solid #cfe3d5; border-radius:9px; padding:8px 14px; cursor:pointer; font-weight:700; }
    .ve-addbar button:hover { background:#e0efe3; }
    body.editing .ve-ctrl { display:flex; gap:5px; justify-content:flex-end; margin-top:8px; }
    .ve-ctrl button { border:1px solid var(--line); background:#fff; border-radius:7px; padding:3px 9px; cursor:pointer; font-size:.82rem; }
    .ve-ctrl button:last-child { color:#b3261e; }
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
        <strong style="color:#1E4D2B;">✍️ Relecture <span style="color:#6b7f70; font-weight:600;">— <?= $lang === 'nl' ? 'document néerlandais 🇳🇱' : 'document français 🇫🇷' ?></span></strong>
        <div style="display:flex; gap:8px; flex-wrap:wrap;">
            <?php if ($pdfUrl !== ''): ?><button type="button" class="btn btn-pdf" onclick="document.getElementById('pdfp').classList.toggle('open')">📄 PDF original</button><?php endif; ?>
            <button type="button" id="editToggle" class="btn" style="background:#fff3d6; color:#8a5a00; border:1px solid #f0d089;" onclick="veSetEdit(!window._veEditing)">✏️ Modifier</button>
            <button type="button" class="btn btn-save" onclick="veSubmit()">✅ Valider</button>
        </div>
    </div>

    <div class="intro"><b>Clique sur « ✏️ Modifier »</b> pour éditer. Ensuite : clique un texte pour le corriger, sélectionne des mots pour les mettre en <b>gras</b> ou en <b style="color:#c0392b;">couleur</b>, ajoute des blocs en bas, déplace/supprime‑les. Puis <b>Valider</b>.</div>
    <?php
        // Nombre de doutes de l'IA dans ce guide (visibles même en lecture, cf. CSS .fix).
        $nbDoutes = 0;
        foreach ($blocks as $b_) { if (is_array($b_) && trim((string) ($b_['fix'] ?? '')) !== '') { $nbDoutes++; } }
    ?>
    <?php if ($nbDoutes > 0): ?>
    <div class="intro" style="background:#fdecec; border:2px solid #f3b4b4; color:#c0392b; font-weight:700;">
        ⚠ <?= (int) $nbDoutes ?> <?= $nbDoutes > 1 ? 'doutes signalés' : 'doute signalé' ?> par l'IA dans ce guide — ils sont surlignés en rouge ci-dessous.
        <span style="font-weight:400;">Vérifiez-les <strong>avant de valider</strong> : cliquez sur « ✏️ Modifier » pour appliquer ou ignorer chaque correction.</span>
    </div>
    <?php endif; ?>
    <div class="ve-format" id="veFormat">
        <button type="button" onclick="veBold()" title="Gras (sélectionne des mots)"><b>G</b></button>
        <span class="sep"></span>
        <button type="button" onclick="veAlign('left')" title="Aligner à gauche">⬅</button>
        <button type="button" onclick="veAlign('center')" title="Centrer">↔</button>
        <button type="button" onclick="veAlign('right')" title="Aligner à droite">➡</button>
        <span class="sep"></span>
        <select id="veAddSel" onchange="if(this.value){veAddBlk(this.value); this.value='';}">
            <option value="">＋ Ajouter un bloc…</option>
            <option value="section">Titre de section</option>
            <option value="text">Paragraphe</option>
            <option value="list">Liste</option>
            <option value="steps">Étapes</option>
            <option value="callout">Encadré</option>
            <option value="keyfigures">Chiffres clés</option>
            <option value="quote">Citation</option>
            <option value="image">🖼 Image (importer…)</option>
        </select>
        <input type="file" id="veImgFile" accept="image/jpeg,image/png,image/webp,image/gif" style="display:none;">
        <span class="lbl" style="color:#7a8a80; font-weight:400;">Le bloc s'ajoute où ton curseur se trouve.</span>
    </div>
    <?php if ($pdfUrl !== ''): ?><div class="pdfp" id="pdfp"><iframe src="<?= htmlspecialchars($pdfUrl) ?>"></iframe></div><?php endif; ?>

    <div class="ve-doc" id="veDoc">
        <?php foreach ($blocks as $b): $type = (string) ($b['type'] ?? ''); $alAttr = in_array(($b['align'] ?? ''), ['center', 'right', 'left'], true) ? ' data-align="' . $b['align'] . '"' : ''; ?>
        <?php if ($type === 'hero'): ?>
            <div class="ve-blk ve-hero" data-type="hero">
                <div class="eyebrow">Famiformation</div>
                <h1 contenteditable="true" data-f="title"><?= _veInline($b['title'] ?? '') ?></h1>
                <div class="sub" contenteditable="true" data-f="subtitle"><?= _veInline($b['subtitle'] ?? '') ?></div>
            </div>
        <?php elseif ($type === 'section'): ?>
            <div class="ve-blk" data-type="section"<?= $alAttr ?>>
                <h2 class="ve-sec" contenteditable="true" data-f="title"><?= _veInline($b['title'] ?? '') ?></h2>
            </div>
        <?php elseif ($type === 'text'): ?>
            <div class="ve-blk" data-type="text"<?= $alAttr ?>>
                <div class="ve-text" contenteditable="true" data-f="text"><?= _veInline($b['text'] ?? '') ?></div>
                <?php if (trim((string) ($b['fix'] ?? '')) !== ''): ?>
                <div class="fix" data-fix="<?= htmlspecialchars((string) $b['fix'], ENT_QUOTES) ?>">
                    <span class="lab">⚠ Doute de l'IA :</span>
                    <div class="sug"><?= _veInline($b['fix']) ?></div>
                    <button type="button" class="ap" onclick="veApplyFix(this,'text')">✓ Appliquer</button>
                    <button type="button" class="ig" onclick="veIgnoreFix(this)">✗ Ignorer</button>
                    <span class="lockhint">→ Cliquez sur « ✏️ Modifier » en haut pour appliquer ou ignorer.</span>
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
            <div class="ve-blk ve-callout <?= $st ?>" data-type="callout" data-style="<?= $st ?>"<?= $alAttr ?>>
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
                    <span class="lockhint">→ Cliquez sur « ✏️ Modifier » en haut pour appliquer ou ignorer.</span>
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
            <?php $n = (int) ($b['n'] ?? 0); $bsrc = trim((string) ($b['src'] ?? '')); $imgUrl = $bsrc !== '' ? moduleFileUrl($bsrc) : (isset($images[$n - 1]) ? moduleFileUrl($images[$n - 1]) : ''); $sz = in_array(($b['size'] ?? 'm'), ['s', 'm', 'l'], true) ? $b['size'] : 'm'; ?>
            <div class="ve-blk" data-type="image" data-n="<?= $n ?>" data-src="<?= htmlspecialchars($bsrc) ?>" data-rotate="0" data-size="<?= $sz ?>">
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
            <div class="ve-blk" data-type="quote"<?= $alAttr ?>>
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
        <input type="hidden" name="lang" value="<?= htmlspecialchars($lang) ?>">
        <input type="hidden" name="blocks_json" id="veJson" value="">
    </form>

<script>
var VE_MODULE_ID = <?= (int) $id ?>;
var VE_CSRF = <?= json_encode(getCSRFToken()) ?>;
// Lit un élément éditable et retranscrit le gras (**...**).
function veMd(el) {
    var out = '';
    el.childNodes.forEach(function (node) {
        if (node.nodeType === 3) { out += node.nodeValue; }
        else if (node.nodeType === 1) {
            var tag = node.tagName.toLowerCase();
            var inner = veMd(node);
            if (tag === 'strong' || tag === 'b') { out += '**' + inner + '**'; }
            else if (node.getAttribute && node.getAttribute('data-c')) { out += '[[c:' + node.getAttribute('data-c') + ']]' + inner + '[[/c]]'; }
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

// Doute de l'IA encore en suspens sur ce bloc ? On le RENVOIE tel quel : tant que l'humain
// ne l'a pas « appliqué » ou « ignoré », il doit rester visible (guide + éditeur).
function veFix(blk) {
    var f = blk.querySelector('.fix');
    if (!f || f.style.display === 'none') { return ''; }
    return f.getAttribute('data-fix') || '';
}
function veBuild() {
    var blocks = [];
    document.querySelectorAll('#veDoc > .ve-blk').forEach(function (blk) {
        var t = blk.getAttribute('data-type');
        var al = blk.getAttribute('data-align') || '';
        var fx = veFix(blk);
        if (t === 'hero') { blocks.push({ type: 'hero', title: fld(blk, 'title'), subtitle: fld(blk, 'subtitle') }); }
        else if (t === 'section') { var x = fld(blk, 'title'); if (x) { blocks.push({ type: 'section', title: x, align: al }); } }
        else if (t === 'text') { var x = fld(blk, 'text'); if (x) { var b = { type: 'text', text: x, align: al }; if (fx) { b.fix = fx; } blocks.push(b); } }
        else if (t === 'quote') { var x = fld(blk, 'text'); if (x) { blocks.push({ type: 'quote', text: x, align: al }); } }
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
            if (tx || ti) {
                var cb = { type: 'callout', style: blk.getAttribute('data-style') || 'info', title: ti, text: tx, align: al };
                if (fx) { cb.fix = fx; }
                blocks.push(cb);
            }
        } else if (t === 'keyfigures') {
            var items = []; blk.querySelectorAll('.kf').forEach(function (k) {
                var v = veMd(k.querySelector('[data-f="value"]')); var l = veMd(k.querySelector('[data-f="label"]'));
                if (v) { items.push({ value: v, label: l }); }
            });
            if (items.length) { blocks.push({ type: 'keyfigures', items: items }); }
        } else if (t === 'image') {
            var capEl = blk.querySelector('[data-f="caption"]');
            var imgB = { type: 'image', n: parseInt(blk.getAttribute('data-n'), 10) || 0, caption: capEl ? veMd(capEl) : '', rotate: parseInt(blk.getAttribute('data-rotate'), 10) || 0, size: blk.getAttribute('data-size') || 'm' };
            var iSrc = blk.getAttribute('data-src') || '';
            if (iSrc) { imgB.src = iSrc; } // image importée dans l'éditeur
            blocks.push(imgB);
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
// --- Mise en forme : gras + couleur (palette sûre) ---
function veBold() { document.execCommand('bold'); }
var VEPAL = { red: '#c0392b', green: '#1E4D2B', orange: '#C98A1B', blue: '#2c5aa0', gray: '#5a6b60' };
function veColor(name) {
    var sel = window.getSelection();
    if (!sel || !sel.rangeCount || sel.isCollapsed) { return; }
    var range = sel.getRangeAt(0);
    var span = document.createElement('span');
    if (name !== 'none') { span.setAttribute('data-c', name); span.style.color = VEPAL[name] || '#000'; }
    else { span.style.color = 'inherit'; }
    try { span.appendChild(range.extractContents()); range.insertNode(span); sel.removeAllRanges(); } catch (e) {}
}
// --- Ajout / déplacement / suppression de blocs ---
function veCtrlHtml() {
    return '<div class="ve-ctrl"><button type="button" onclick="veMoveBlk(this,-1)" title="Monter">▲</button>'
        + '<button type="button" onclick="veMoveBlk(this,1)" title="Descendre">▼</button>'
        + '<button type="button" onclick="veDelBlk(this)" title="Supprimer le bloc">🗑</button></div>';
}
function veMoveBlk(btn, dir) {
    var b = btn.closest('.ve-blk');
    if (dir < 0) { var p = b.previousElementSibling; if (p && !p.classList.contains('ve-hero')) { b.parentNode.insertBefore(b, p); } }
    else { var nx = b.nextElementSibling; if (nx) { b.parentNode.insertBefore(nx, b); } }
    b.scrollIntoView({ behavior: 'smooth', block: 'center' });
}
function veDelBlk(btn) { if (!confirm('Supprimer ce bloc entier ?')) { return; } var b = btn.closest('.ve-blk'); if (b) { b.remove(); } }
function veNewBlock(type) {
    var d = document.createElement('div'); d.className = 've-blk'; d.setAttribute('data-type', type);
    if (type === 'section') { d.innerHTML = '<h2 class="ve-sec" contenteditable="true" data-f="title">Titre de section</h2>'; }
    else if (type === 'text') { d.innerHTML = '<div class="ve-text" contenteditable="true" data-f="text">Votre texte…</div>'; }
    else if (type === 'quote') { d.innerHTML = '<div class="ve-quote" contenteditable="true" data-f="text">Citation…</div>'; }
    else if (type === 'list') { d.innerHTML = '<ul class="ve-list" data-list><li class="ve-li"><span contenteditable="true" data-f="item">Point…</span><span class="ve-del" onclick="veDelItem(this)">✕</span></li></ul><span class="ve-add" onclick="veAddLi(this)">+ Ajouter un point</span>'; }
    else if (type === 'steps') { d.innerHTML = '<div class="ve-steps" data-steps><div class="ve-step"><div class="st-t" contenteditable="true" data-f="title">Titre de l\'étape</div><div class="st-d" contenteditable="true" data-f="desc">Détail…</div><span class="ve-del" onclick="veDelStep(this)">✕</span></div></div><span class="ve-add" onclick="veAddStep(this)">+ Ajouter une étape</span>'; }
    else if (type === 'callout') { d.className = 've-blk ve-callout info'; d.setAttribute('data-style', 'info'); d.innerHTML = '<div class="bar"></div><div><div class="c-t" contenteditable="true" data-f="title">À noter</div><div contenteditable="true" data-f="text">Information importante…</div><div class="ve-tools" style="justify-content:flex-start; margin-top:6px;"><button type="button" class="on" onclick="veStyle(this,\'info\')">Info</button><button type="button" onclick="veStyle(this,\'tip\')">Astuce</button><button type="button" onclick="veStyle(this,\'warning\')">Attention</button></div></div>'; }
    else if (type === 'keyfigures') { d.innerHTML = '<div class="ve-kf" data-kf><div class="kf"><div class="v" contenteditable="true" data-f="value">100</div><div class="l" contenteditable="true" data-f="label">Libellé</div></div></div>'; }
    else { d.innerHTML = '<div class="ve-text" contenteditable="true" data-f="text">Texte…</div>'; }
    d.insertAdjacentHTML('beforeend', veCtrlHtml());
    return d;
}
function veAddBlk(type) {
    if (type === 'image') { veAddImage(); return; } // l'image passe par un import de fichier
    var blk = veNewBlock(type);
    veInsertBlk(blk);
    var first = blk.querySelector('[data-f]'); if (first) { first.focus(); }
}
// Insère un bloc à l'endroit du curseur (après le dernier bloc cliqué), sinon à la fin.
function veInsertBlk(blk) {
    var doc = document.getElementById('veDoc');
    var ref = window._veLastBlk;
    if (ref && ref.parentNode === doc && !ref.classList.contains('ve-hero')) {
        doc.insertBefore(blk, ref.nextSibling);
    } else {
        doc.appendChild(blk);
    }
    window._veLastBlk = blk;
    blk.scrollIntoView({ behavior: 'smooth', block: 'center' });
}
// Construit le DOM d'un bloc image importé (clé volume directe via data-src).
function veImageBlock(url, key) {
    var d = document.createElement('div');
    d.className = 've-blk'; d.setAttribute('data-type', 'image');
    d.setAttribute('data-n', '0'); d.setAttribute('data-src', key);
    d.setAttribute('data-rotate', '0'); d.setAttribute('data-size', 'm');
    d.innerHTML = '<figure class="ve-img">'
        + '<img class="ve-imgel" src="' + url + '" alt="" style="max-width:320px;">'
        + '<figcaption contenteditable="true" data-f="caption">Légende (facultatif)…</figcaption>'
        + '<div class="ve-tools">'
        + '<button type="button" onclick="veRot(this)">↻ Pivoter</button><span class="sep"></span>'
        + '<button type="button" onclick="veSize(this,\'s\')">Petite</button>'
        + '<button type="button" class="on" onclick="veSize(this,\'m\')">Moyenne</button>'
        + '<button type="button" onclick="veSize(this,\'l\')">Grande</button>'
        + '</div></figure>';
    d.insertAdjacentHTML('beforeend', veCtrlHtml());
    return d;
}
// Ouvre le sélecteur de fichier puis téléverse l'image sur le volume.
function veAddImage() {
    if (!window._veEditing) { veSetEdit(true); }
    window._veImgRef = window._veLastBlk; // on retient l'endroit d'insertion
    var inp = document.getElementById('veImgFile');
    inp.value = '';
    inp.click();
}
(function () {
    var inp = document.getElementById('veImgFile');
    if (!inp) { return; }
    inp.addEventListener('change', function () {
        var file = inp.files && inp.files[0];
        if (!file) { return; }
        if (file.size > 8 * 1024 * 1024) { alert('Image trop lourde (8 Mo maximum).'); return; }
        var fd = new FormData();
        fd.append('image', file);
        fd.append('id', String(VE_MODULE_ID));
        fd.append('csrf_token', VE_CSRF);
        var sel = document.getElementById('veAddSel');
        if (sel) { sel.disabled = true; }
        window._veLastBlk = window._veImgRef || window._veLastBlk;
        fetch('image_upload.php', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (sel) { sel.disabled = false; }
                if (!j || !j.ok) { alert('Import impossible : ' + ((j && j.error) || 'erreur inconnue')); return; }
                var blk = veImageBlock(j.url, j.key);
                veInsertBlk(blk);
            })
            .catch(function () { if (sel) { sel.disabled = false; } alert('Import impossible (réseau).'); });
    });
})();
// Alignement du bloc courant (gauche / centre / droite).
function veAlign(a) {
    var blk = window._veLastBlk || (document.activeElement && document.activeElement.closest ? document.activeElement.closest('.ve-blk') : null);
    if (!blk || blk.classList.contains('ve-hero')) { return; }
    blk.setAttribute('data-align', a);
}
// Mémorise le dernier bloc où on a cliqué (pour insérer / aligner au bon endroit).
document.getElementById('veDoc').addEventListener('focusin', function (e) {
    var b = e.target.closest('.ve-blk');
    if (b) { window._veLastBlk = b; }
});
document.getElementById('veDoc').addEventListener('click', function (e) {
    var b = e.target.closest('.ve-blk');
    if (b) { window._veLastBlk = b; }
});
// Ajoute les contrôles (déplacer/supprimer) à chaque bloc existant (sauf la couverture).
document.querySelectorAll('#veDoc .ve-blk').forEach(function (blk) {
    if (blk.classList.contains('ve-hero')) { return; }
    blk.insertAdjacentHTML('beforeend', veCtrlHtml());
});
veSetEdit(false);
</script>
</body>
</html>
