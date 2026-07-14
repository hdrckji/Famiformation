<?php
// ============================================================
// gestion_quiz.php — HUB CENTRAL « Gestion Quiz » (admin).
//   Liste tous les quiz générés (un par module, format multi-réponses),
//   avec recherche (module + texte des questions), stats, et accès à l'éditeur
//   complet (module_quiz.php : ajout / suppression / modif / unique-multiple / création).
// ============================================================
require_once 'config.php';
verifierConnexion($db);
require_once 'includes/modules.php';

if (($_SESSION['role'] ?? '') !== 'admin') { header('Location: index.php'); exit(); }

$flash = '';
if (!empty($_SESSION['module_flash'])) { $flash = $_SESSION['module_flash']; unset($_SESSION['module_flash']); }

// Arbre pour le fil d'Ariane.
$tree = [];
try {
    foreach ($db->query("SELECT id, nom, nom_nl, parent_id FROM modules")->fetchAll(PDO::FETCH_ASSOC) as $m) { $tree[(int) $m['id']] = $m; }
} catch (Exception $e) {}
$crumb = function ($id) use ($tree) {
    $parts = []; $cur = (int) $id; $g = 0;
    while ($cur && isset($tree[$cur]) && $g++ < 50) { $parts[] = moduleNom($tree[$cur]); $cur = (int) ($tree[$cur]['parent_id'] ?? 0); }
    return implode(' › ', array_reverse($parts));
};

// Modules ayant un quiz.
$rows = [];
try {
    $rows = $db->query("SELECT id, nom, nom_nl, quiz_json FROM modules WHERE quiz_json IS NOT NULL AND quiz_json <> ''")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$quizzes = [];
$totQ = 0; $totMul = 0; $totSin = 0;
foreach ($rows as $r) {
    $q = json_decode((string) $r['quiz_json'], true);
    $questions = (is_array($q) && !empty($q['questions']) && is_array($q['questions'])) ? $q['questions'] : [];
    if (empty($questions)) { continue; }
    $mul = 0; $sin = 0; $texts = []; $doubts = 0;
    foreach ($questions as $qq) {
        if (($qq['type'] ?? 'single') === 'multiple') { $mul++; } else { $sin++; }
        if (trim((string) ($qq['fix'] ?? '')) !== '') { $doubts++; } // doute non tranche par l'IA
        $texts[] = (string) ($qq['q'] ?? '');
    }
    $quizzes[] = [
        'id' => (int) $r['id'], 'name' => moduleNom($r), 'path' => $crumb((int) $r['id']),
        'nb' => count($questions), 'mul' => $mul, 'sin' => $sin, 'texts' => $texts, 'doubts' => $doubts,
    ];
    $totQ += count($questions); $totMul += $mul; $totSin += $sin;
}
usort($quizzes, function ($a, $b) { return strcasecmp($a['name'], $b['name']); });

// FORMATIONS SANS QUIZ : celles qui ont du contenu (guide et/ou vidéo) mais aucune question.
// Elles n'apparaissaient nulle part : impossible de leur en créer un sans tout réimporter.
require_once __DIR__ . '/includes/evaluation.php';
$sansQuiz = [];
try {
    $rowsP = $db->query("SELECT DISTINCT p.id, p.nom, p.nom_nl
                         FROM modules p
                         JOIN modules c ON c.parent_id = p.id AND c.content_kind IN ('ecrit','video')
                         WHERE (c.contenu_ia IS NOT NULL AND c.contenu_ia <> '')
                            OR (c.video_path IS NOT NULL AND c.video_path <> '')
                            OR (c.video_src_path IS NOT NULL AND c.video_src_path <> '')")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rowsP as $pm) {
        $ev = evalStatus($db, (int) $pm['id']);
        if ($ev['nb'] === 0) {
            $sansQuiz[] = ['id' => (int) $pm['id'], 'name' => moduleNom($pm), 'path' => $crumb((int) $pm['id'])];
        }
    }
    usort($sansQuiz, function ($a, $b) { return strcasecmp($a['name'], $b['name']); });
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Gestion Quiz — FamiFormation</title>
<link rel="shortcut icon" type="image/x-icon" href="favicon.ico">
<style>
    :root { --forest:#1E4D2B; --leaf:#3E8E4E; --line:#d9e3dc; }
    * { box-sizing:border-box; }
    body { font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif; background:#f4f7f6; margin:0; color:#21301F; }
    .topbar { background:#fff; border-bottom:1px solid var(--line); display:flex; align-items:center; justify-content:space-between; padding:14px 18px; }
    .topbar a { color:var(--forest); text-decoration:none; font-weight:700; }
    .wrap { max-width:900px; margin:0 auto; padding:20px 16px 60px; }
    h1 { color:var(--forest); margin:0 0 4px; }
    .flash { background:#dff3e3; border:1px solid #b6e0c2; color:#1d6a39; padding:12px 18px; border-radius:12px; margin-bottom:16px; font-weight:700; }
    .stats { display:flex; gap:12px; flex-wrap:wrap; margin:14px 0; }
    .stat { background:#eef7f0; border:1px solid #cfe3d5; border-radius:12px; padding:12px 18px; }
    .stat .n { font-size:1.6rem; font-weight:800; color:var(--forest); } .stat .l { font-size:.8rem; color:#5a6b60; font-weight:700; text-transform:uppercase; letter-spacing:.06em; }
    .search { width:100%; padding:12px 14px; border:1px solid #cfdad3; border-radius:12px; font-size:1rem; margin-bottom:14px; }
    .qz { background:#fff; border:1px solid var(--line); border-radius:14px; padding:16px 18px; margin-bottom:12px; display:flex; align-items:center; justify-content:space-between; gap:14px; flex-wrap:wrap; }
    /* Quiz contenant au moins une question douteuse : impossible de le rater dans la liste. */
    .qz-doubt { border-color:#e8a13a; border-left:6px solid #e8a13a; background:#fffaf2; }
    .qz-alert { margin-top:8px; background:#fdecec; border:1px solid #f3b4b4; color:#c0392b; border-radius:9px; padding:7px 11px; font-size:.86rem; font-weight:600; }
    .qz .name { font-weight:800; color:var(--forest); font-size:1.1rem; }
    .qz .path { color:#5a6b60; font-size:.82rem; }
    .qz .badges span { display:inline-block; background:#eef7f0; border:1px solid #cfe3d5; color:var(--forest); border-radius:999px; padding:3px 10px; font-size:.8rem; font-weight:700; margin:4px 6px 0 0; }
    .btn { border:none; border-radius:10px; padding:10px 16px; font-weight:700; cursor:pointer; text-decoration:none; font:inherit; background:var(--forest); color:#fff; white-space:nowrap; }
    .muted { color:#7a8a80; }
    #empty { display:none; }
    .noquiz { background:#fffaf2; border:1px solid #f0d089; border-radius:14px; padding:16px 18px; margin-bottom:16px; }
    .nq-row { display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;
              background:#fff; border:1px solid #e6efe9; border-radius:10px; padding:10px 12px; margin-bottom:8px; }
</style>
</head>
<body>
<?php
    require_once __DIR__ . '/includes/topbar.php';
    famiTopbar($db, ['back' => 'index.php', 'title' => '🧩 Gestion Quiz']);
?>
    <div class="wrap">
        <?php if ($flash): ?><div class="flash"><?= htmlspecialchars($flash) ?></div><?php endif; ?>
        <h1>Tous les quiz</h1>
        <p class="muted" style="margin-top:0;">Chaque formation a son quiz (format à réponses multiples). Recherche, puis « Gérer » pour ajouter / modifier / supprimer des questions.</p>

        <div class="stats">
            <div class="stat"><div class="n"><?= count($quizzes) ?></div><div class="l">Quiz</div></div>
            <div class="stat"><div class="n"><?= (int) $totQ ?></div><div class="l">Questions</div></div>
            <div class="stat"><div class="n"><?= (int) $totMul ?></div><div class="l">Multiples</div></div>
            <div class="stat"><div class="n"><?= (int) $totSin ?></div><div class="l">Uniques</div></div>
        </div>

        <?php if (!empty($sansQuiz)): ?>
        <div class="noquiz">
            <h3 style="margin:0 0 4px; color:#8a5a00;">➕ Formations sans quiz (<?= count($sansQuiz) ?>)</h3>
            <p class="muted" style="margin:0 0 10px; font-size:.86rem;">Elles ont du contenu mais ne sont pas évaluées. Le quiz se génère à partir du guide et de la transcription de la vidéo — <strong>rien à réimporter</strong>.</p>
            <?php foreach ($sansQuiz as $nq): ?>
            <div class="nq-row">
                <div>
                    <div style="font-weight:800; color:#244230;"><?= htmlspecialchars($nq['name']) ?></div>
                    <?php if ($nq['path'] !== ''): ?><div class="path">📍 <?= htmlspecialchars($nq['path']) ?></div><?php endif; ?>
                </div>
                <form method="POST" action="module_save.php" style="margin:0;" onsubmit="return confirm('Générer le quiz de cette formation ?');">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="eval_generate">
                    <input type="hidden" name="id" value="<?= (int) $nq['id'] ?>">
                    <button type="submit" class="btn">🤖 Créer le quiz</button>
                </form>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <input type="text" class="search" id="qsearch" placeholder="🔍 Rechercher un quiz ou une question…" onkeyup="filterQ()">

        <div id="qlist">
        <?php if (empty($quizzes)): ?>
            <p class="muted">Aucun quiz pour l'instant. Ils apparaîtront ici dès qu'un contenu « à évaluer » sera importé.</p>
        <?php else: ?>
            <?php foreach ($quizzes as $qz): ?>
            <div class="qz<?= $qz['doubts'] > 0 ? ' qz-doubt' : '' ?>" data-search="<?= htmlspecialchars(strtolower($qz['name'] . ' ' . $qz['path'] . ' ' . implode(' ', $qz['texts'])), ENT_QUOTES) ?>">
                <div>
                    <div class="name"><?= htmlspecialchars($qz['name']) ?></div>
                    <?php if ($qz['path'] !== ''): ?><div class="path">📍 <?= htmlspecialchars($qz['path']) ?></div><?php endif; ?>
                    <div class="badges">
                        <span><?= (int) $qz['nb'] ?> question<?= $qz['nb'] > 1 ? 's' : '' ?></span>
                        <span><?= (int) $qz['mul'] ?> multiple<?= $qz['mul'] > 1 ? 's' : '' ?></span>
                        <span><?= (int) $qz['sin'] ?> unique<?= $qz['sin'] > 1 ? 's' : '' ?></span>
                    </div>
                    <?php if ($qz['doubts'] > 0): ?>
                    <div class="qz-alert">&#9888; <strong><?= (int) $qz['doubts'] ?> question<?= $qz['doubts'] > 1 ? 's' : '' ?></strong> sur laquelle l'IA a un doute — a trancher.</div>
                    <?php endif; ?>
                </div>
                <a class="btn" href="module_quiz.php?id=<?= (int) $qz['id'] ?>">📝 Gérer le quiz</a>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
        </div>
        <p class="muted" id="empty">Aucun quiz ne correspond à ta recherche.</p>
    </div>
<script>
function filterQ() {
    var q = document.getElementById('qsearch').value.toLowerCase().trim();
    var n = 0;
    document.querySelectorAll('#qlist .qz').forEach(function (c) {
        var show = (q === '' || c.getAttribute('data-search').indexOf(q) !== -1);
        c.style.display = show ? '' : 'none';
        if (show) { n++; }
    });
    document.getElementById('empty').style.display = (n === 0) ? 'block' : 'none';
}
</script>
</body>
</html>
