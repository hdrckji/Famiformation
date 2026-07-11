<?php
// ============================================================
// ai_test.php — page de TEST (admin) du moteur IA d'uniformisation.
// Dépose un PDF -> l'IA le réécrit + affiche coût, images extraites, et un
// DIAGNOSTIC (poppler dispo ? stockage persistant ?).
// ⚠️ PDF UNIQUEMENT (petit).
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
        $err = 'Fichier trop lourd. Dépose un PDF plus petit (30 Mo max), pas la vidéo.';
    } elseif (($f['error'] ?? 0) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
        $err = 'Échec de l\'upload (code ' . (int) ($f['error'] ?? -1) . ').';
    } elseif (strtolower(pathinfo((string) $f['name'], PATHINFO_EXTENSION)) !== 'pdf') {
        $err = 'Merci de déposer un fichier .pdf.';
    } elseif ((int) ($f['size'] ?? 0) > 30 * 1024 * 1024) {
        $err = 'PDF trop lourd (max 30 Mo).';
    } else {
        $stableName = 'test_' . preg_replace('/[^A-Za-z0-9]/', '_', pathinfo((string) $f['name'], PATHINFO_FILENAME));
        $result = aiUniformisePdf($db, $f['tmp_name'], $stableName);
    }
}

$cur = iaSelectedModel($db);
$catalog = iaModelCatalog();
$curLabel = $catalog[$cur]['label'] ?? $cur;

// Diagnostic
$diagBin     = function_exists('shell_exec') ? trim((string) @shell_exec('command -v pdfimages 2>/dev/null')) : '';
$diagBase    = defined('FAMI_STORAGE_BASE') ? FAMI_STORAGE_BASE : (__DIR__ . '/uploads');
$diagPersist = (defined('FAMI_STORAGE_BASE') && FAMI_STORAGE_BASE !== (__DIR__ . '/uploads'));
$diagShell   = function_exists('shell_exec');
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
        .diag { font-size: .92rem; line-height: 1.9; }
        .ok { color: #1d6f42; font-weight: 700; } .ko { color: #a83232; font-weight: 700; }
        textarea { width: 100%; box-sizing: border-box; min-height: 320px; font-family: ui-monospace, Consolas, monospace; font-size: .85rem; padding: 12px; border: 1px solid #cfdad3; border-radius: 10px; }
        .thumbs { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 10px; }
        .thumbs img { height: 100px; border-radius: 8px; border: 1px solid #cfdad3; background:#fff; }
    </style>
</head>
<body>
<div class="container">
    <a class="back" href="parametres.php#prefs">⬅ Retour aux paramètres</a>
    <h1>🧪 Test IA — Uniformisation d'un PDF</h1>
    <p class="muted">Modèle actuel : <strong><?= htmlspecialchars($curLabel) ?></strong>.</p>

    <div class="card">
        <div class="diag">
            🔧 <strong>Diagnostic images</strong><br>
            • <code>pdfimages</code> (extraction photos) : <?= $diagBin !== '' ? '<span class="ok">✅ présent</span> <span class="muted">(' . htmlspecialchars($diagBin) . ')</span>' : '<span class="ko">❌ absent</span> — poppler-utils pas (encore) installé (attends le rebuild Docker)'; ?><br>
            • <code>shell_exec</code> : <?= $diagShell ? '<span class="ok">✅ actif</span>' : '<span class="ko">❌ désactivé</span> (extraction impossible)'; ?><br>
            • Stockage : <?= $diagPersist ? '<span class="ok">✅ volume persistant</span>' : '<span class="ko">⚠️ local — NON persistant</span> (les fichiers sont perdus au redéploiement : attache un volume Railway)'; ?>
            <span class="muted">(<?= htmlspecialchars($diagBase) ?>)</span>
        </div>
    </div>

    <div class="warn">📄 <strong>PDF uniquement, 30 Mo max.</strong> Ne dépose pas la vidéo ici.</div>

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
                    <span class="stat"><?= number_format($result['in']) ?> tok. entrée</span>
                    <span class="stat"><?= number_format($result['out']) ?> tok. sortie</span>
                    <span class="stat">≈ <?= number_format($result['cost_eur'], 3) ?> €</span>
                    <span class="stat"><?= count($result['images'] ?? []) ?> image(s) extraite(s)</span>
                </div>

                <?php if (!empty($result['images'])): ?>
                    <p class="muted" style="margin:12px 0 0;">Images extraites (servies depuis le stockage) :</p>
                    <div class="thumbs">
                        <?php foreach ($result['images'] as $imk): ?>
                            <img src="<?= htmlspecialchars(moduleFileUrl($imk)) ?>" alt="">
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="muted" style="margin-top:12px;">Aucune image extraite (PDF sans photo, poppler absent, ou petites images/logos filtrés).</p>
                <?php endif; ?>

                <p class="muted" style="margin-top:14px;">Contenu généré (JSON de blocs de design) :</p>
                <textarea readonly><?= htmlspecialchars($result['text']) ?></textarea>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
<script>
function checkPdf(form) {
    var f = form.pdf.files[0];
    if (!f) { alert('Choisis un PDF.'); return false; }
    if (!/\.pdf$/i.test(f.name)) { alert('Ce doit être un fichier .pdf.'); return false; }
    if (f.size > 30 * 1024 * 1024) { alert('PDF trop lourd (max 30 Mo). Ne dépose pas la vidéo ici.'); return false; }
    return true;
}
</script>
</body>
</html>
