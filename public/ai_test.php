<?php
// ============================================================
// ai_test.php — page de TEST (admin) du moteur IA d'uniformisation.
// Dépose un PDF -> l'IA le réécrit en contenu uniformisé + affiche coût réel.
// Sert à valider qualité + prix AVANT de brancher les boutons de contenu.
// ⚠️ PDF UNIQUEMENT (petit). La vidéo se traite ailleurs (.srt / Whisper).
// ============================================================
require_once 'config.php';
verifierConnexion($db);
require_once 'includes/ia_settings.php';
require_once 'includes/ai_uniformise.php';

if (($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: index.php');
    exit();
}

$result = null;
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCSRF();
    $f = $_FILES['pdf'] ?? null;
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        $err = 'Aucun fichier reçu (ou fichier trop lourd rejeté par le serveur).';
    } elseif (($f['error'] ?? 0) === UPLOAD_ERR_INI_SIZE || ($f['error'] ?? 0) === UPLOAD_ERR_FORM_SIZE) {
        $err = 'Fichier trop lourd pour l\'upload PHP. Dépose un PDF plus petit (30 Mo max), pas la vidéo.';
    } elseif (($f['error'] ?? 0) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
        $err = 'Échec de l\'upload (code ' . (int) ($f['error'] ?? -1) . ').';
    } elseif (strtolower(pathinfo((string) $f['name'], PATHINFO_EXTENSION)) !== 'pdf') {
        $err = 'Merci de déposer un fichier .pdf (pas une vidéo ni un autre format).';
    } elseif ((int) ($f['size'] ?? 0) > 30 * 1024 * 1024) {
        $err = 'PDF trop lourd (max 30 Mo).';
    } else {
        $result = aiUniformisePdf($db, $f['tmp_name']);
    }
}

$cur = iaSelectedModel($db);
$catalog = iaModelCatalog();
$curLabel = $catalog[$cur]['label'] ?? $cur;

// Mini-rendu Markdown -> HTML (aperçu de lecture).
function mdMini($md)
{
    $out = [];
    $inList = false;
    foreach (preg_split('/\r\n|\r|\n/', (string) $md) as $line) {
        $t = rtrim($line);
        if ($t === '') { if ($inList) { $out[] = '</ul>'; $inList = false; } continue; }
        $e = htmlspecialchars($t);
        if (strpos($t, '### ') === 0) { if ($inList) { $out[] = '</ul>'; $inList = false; } $out[] = '<h4>' . htmlspecialchars(substr($t, 4)) . '</h4>'; }
        elseif (strpos($t, '## ') === 0) { if ($inList) { $out[] = '</ul>'; $inList = false; } $out[] = '<h3>' . htmlspecialchars(substr($t, 3)) . '</h3>'; }
        elseif (strpos($t, '# ') === 0) { if ($inList) { $out[] = '</ul>'; $inList = false; } $out[] = '<h2>' . htmlspecialchars(substr($t, 2)) . '</h2>'; }
        elseif (strpos($t, '- ') === 0 || strpos($t, '* ') === 0) { if (!$inList) { $out[] = '<ul>'; $inList = true; } $out[] = '<li>' . htmlspecialchars(substr($t, 2)) . '</li>'; }
        else { if ($inList) { $out[] = '</ul>'; $inList = false; } $out[] = '<p>' . $e . '</p>'; }
    }
    if ($inList) { $out[] = '</ul>'; }
    return implode("\n", $out);
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Test IA — Uniformisation PDF</title>
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Open Sans', sans-serif; background: #f4f7f6; margin: 0; padding: 24px; }
        .container { max-width: 980px; margin: 0 auto; }
        h1 { color: #2d5a37; margin: 0 0 6px; }
        a.back { color: #2d5a37; text-decoration: none; font-weight: 700; }
        .card { background: #fff; border-radius: 14px; box-shadow: 0 4px 15px rgba(0,0,0,0.06); padding: 22px; margin-top: 18px; }
        .btn { border: none; border-radius: 10px; padding: 11px 18px; font-weight: 700; cursor: pointer; background: #2d5a37; color: #fff; }
        .muted { color: #7a8a80; }
        .stat { display: inline-block; background: #e8f5e9; color: #1d6f42; border-radius: 999px; padding: 6px 14px; font-weight: 800; margin: 4px 8px 4px 0; }
        .err { background: #f9e1e1; color: #a83232; border-radius: 10px; padding: 12px 16px; font-weight: 700; }
        .warn { background: #fdf6e6; color: #8a6d1a; border: 1px solid #f0d9a8; border-radius: 10px; padding: 10px 14px; font-weight: 600; }
        textarea { width: 100%; box-sizing: border-box; min-height: 320px; font-family: ui-monospace, Consolas, monospace; font-size: .9rem; padding: 12px; border: 1px solid #cfdad3; border-radius: 10px; }
        .preview { border: 1px solid #e3ece5; border-radius: 10px; padding: 6px 18px; background: #fbfdfb; }
        .preview h2 { color: #2d5a37; } .preview h3 { color: #2d5a37; } .preview h4 { color: #33473b; }
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        @media (max-width: 820px) { .grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
<div class="container">
    <a class="back" href="parametres.php#prefs">⬅ Retour aux paramètres</a>
    <h1>🧪 Test IA — Uniformisation d'un PDF</h1>
    <p class="muted">Modèle actuel : <strong><?= htmlspecialchars($curLabel) ?></strong> (modifiable dans Préférences → 🤖 Intelligence artificielle).</p>
    <div class="warn">📄 <strong>PDF uniquement, 30 Mo max.</strong> Ne dépose <strong>pas</strong> la vidéo ici (elle se traite ailleurs, via le sous-titre <code>.srt</code> ou Whisper).</div>

    <?php if ($err): ?><div class="card"><div class="err"><?= htmlspecialchars($err) ?></div></div><?php endif; ?>

    <div class="card">
        <form method="POST" enctype="multipart/form-data" onsubmit="return checkPdf(this);" style="display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
            <?= csrfField() ?>
            <input type="file" name="pdf" accept="application/pdf,.pdf" required>
            <button type="submit" class="btn">⚙️ Uniformiser</button>
        </form>
    </div>

    <?php if ($result !== null): ?>
        <?php if (!$result['ok']): ?>
            <div class="card"><div class="err">Erreur : <?= htmlspecialchars($result['error']) ?></div></div>
        <?php else: ?>
            <div class="card">
                <div>
                    <span class="stat"><?= htmlspecialchars($catalog[$result['model']]['label'] ?? $result['model']) ?></span>
                    <span class="stat"><?= number_format($result['in']) ?> tokens entrée</span>
                    <span class="stat"><?= number_format($result['out']) ?> tokens sortie</span>
                    <span class="stat">≈ <?= number_format($result['cost_eur'], 3) ?> €</span>
                </div>
                <p class="muted" style="margin-top:10px;">Coût réel de <strong>ce</strong> document. Le contenu ci-dessous est éditable (à gauche = texte brut modifiable, à droite = aperçu de lecture).</p>
                <div class="grid" style="margin-top:12px;">
                    <div>
                        <label style="font-weight:700; color:#244230;">Contenu uniformisé (éditable)</label>
                        <textarea><?= htmlspecialchars($result['text']) ?></textarea>
                    </div>
                    <div>
                        <label style="font-weight:700; color:#244230;">Aperçu</label>
                        <div class="preview"><?= mdMini($result['text']) ?></div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
<script>
function checkPdf(form) {
    var f = form.pdf.files[0];
    if (!f) { alert('Choisis un PDF.'); return false; }
    if (!/\.pdf$/i.test(f.name)) {
        alert('Ce doit être un fichier .pdf — pas une vidéo ni un autre format.');
        return false;
    }
    if (f.size > 30 * 1024 * 1024) {
        alert('PDF trop lourd (' + (f.size / 1048576).toFixed(0) + ' Mo). Maximum 30 Mo.\n\nAstuce : ne dépose PAS la vidéo ici, seulement le document PDF de formation.');
        return false;
    }
    return true;
}
</script>
</body>
</html>
