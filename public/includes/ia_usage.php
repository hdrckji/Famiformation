<?php
// ============================================================
// ia_usage.php — journal des appels API IA (coûts).
//   Enregistre chaque appel (uniformisation, quiz) : date, qui, quoi, modèle, coût.
//   Affiche le total + le détail dans l'onglet « API » des paramètres (admin).
// ============================================================

if (!function_exists('iaUsageEnsureTable')) {
    function iaUsageEnsureTable($db)
    {
        try {
            $db->exec("CREATE TABLE IF NOT EXISTS ia_usage (
                id INT AUTO_INCREMENT PRIMARY KEY,
                created_at DATETIME NOT NULL,
                user_id INT NULL,
                kind VARCHAR(20) NOT NULL,
                model VARCHAR(50) NULL,
                in_tokens INT DEFAULT 0,
                out_tokens INT DEFAULT 0,
                cost_eur DECIMAL(10,4) DEFAULT 0,
                module_id INT NULL
            ) DEFAULT CHARSET=utf8mb4");
        } catch (Exception $e) { /* non bloquant */ }
    }
}

if (!function_exists('iaLogUsage')) {
    /** Enregistre un appel API IA. $kind : 'uniformise' | 'quiz'. */
    function iaLogUsage($db, $userId, $kind, $model, $in, $out, $costEur, $moduleId = null)
    {
        iaUsageEnsureTable($db);
        try {
            $db->prepare("INSERT INTO ia_usage (created_at, user_id, kind, model, in_tokens, out_tokens, cost_eur, module_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
               ->execute([
                   date('Y-m-d H:i:s'),
                   ((int) $userId) ?: null,
                   (string) $kind,
                   (string) $model,
                   (int) $in,
                   (int) $out,
                   round((float) $costEur, 4),
                   ((int) $moduleId) ?: null,
               ]);
        } catch (Exception $e) { /* non bloquant */ }
    }
}

if (!function_exists('renderApiUsageTab')) {
    function renderApiUsageTab($db)
    {
        iaUsageEnsureTable($db);
        $total = 0.0; $count = 0;
        try {
            $r = $db->query("SELECT COUNT(*) c, COALESCE(SUM(cost_eur),0) s FROM ia_usage")->fetch(PDO::FETCH_ASSOC);
            $total = (float) ($r['s'] ?? 0);
            $count = (int) ($r['c'] ?? 0);
        } catch (Exception $e) {}

        $rows = [];
        try {
            $rows = $db->query("SELECT iu.*, u.prenom, u.nom, m.nom AS module_nom
                                FROM ia_usage iu
                                LEFT JOIN utilisateurs u ON u.id = iu.user_id
                                LEFT JOIN modules m ON m.id = iu.module_id
                                ORDER BY iu.created_at DESC LIMIT 300")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {}

        $kindLabel = function ($k) {
            if ($k === 'uniformise') { return '🪄 Mise en forme (fiche)'; }
            if ($k === 'quiz') { return '📝 Génération du quiz'; }
            return htmlspecialchars((string) $k);
        };
        $eur = function ($v) { return number_format((float) $v, 4, ',', ' ') . ' €'; };
        ?>
        <div class="card">
            <h2 style="margin-top:0; color:#2d5a37;">💶 Coûts API — IA</h2>
            <p class="muted" style="margin-top:-6px;">Chaque appel à l'IA (mise en forme, quiz) est enregistré ici.</p>
            <div style="display:flex; flex-wrap:wrap; gap:16px; margin:14px 0 4px;">
                <div style="flex:1; min-width:220px; background:#eef7f0; border:1px solid #cfe3d5; border-radius:14px; padding:20px 22px;">
                    <div style="font-size:0.8rem; letter-spacing:.08em; text-transform:uppercase; color:#5a6b60; font-weight:700;">Total dépensé</div>
                    <div style="font-size:2.2rem; font-weight:800; color:#1E4D2B; line-height:1.1; margin-top:4px;"><?= htmlspecialchars($eur($total)) ?></div>
                    <div class="muted" style="margin-top:2px;"><?= (int) $count ?> appel<?= $count > 1 ? 's' : '' ?> au total</div>
                </div>
            </div>
        </div>

        <div class="card" style="margin-top:18px;">
            <h2 style="margin-top:0; color:#2d5a37;">🧾 Détail des appels</h2>
            <?php if (empty($rows)): ?>
                <p class="muted">Aucun appel API pour l'instant.</p>
            <?php else: ?>
            <div style="overflow-x:auto;">
            <table>
                <thead>
                    <tr>
                        <th style="text-align:left;">Date</th>
                        <th style="text-align:left;">Par</th>
                        <th style="text-align:left;">Pour quoi</th>
                        <th style="text-align:left;">Module</th>
                        <th style="text-align:left;">Modèle</th>
                        <th style="text-align:right;">Coût</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <?php
                        $who = trim((string) (($r['prenom'] ?? '') . ' ' . ($r['nom'] ?? '')));
                        if ($who === '') { $who = $r['user_id'] ? ('#' . (int) $r['user_id']) : '—'; }
                        $when = !empty($r['created_at']) ? date('d/m/Y H:i', strtotime((string) $r['created_at'])) : '—';
                    ?>
                    <tr>
                        <td style="white-space:nowrap;"><?= htmlspecialchars($when) ?></td>
                        <td><?= htmlspecialchars($who) ?></td>
                        <td><?= $kindLabel($r['kind'] ?? '') ?></td>
                        <td><?= htmlspecialchars((string) ($r['module_nom'] ?? '—')) ?></td>
                        <td class="muted"><?= htmlspecialchars((string) ($r['model'] ?? '—')) ?></td>
                        <td style="text-align:right; white-space:nowrap; font-weight:700; color:#2d5a37;"><?= htmlspecialchars($eur($r['cost_eur'] ?? 0)) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }
}
