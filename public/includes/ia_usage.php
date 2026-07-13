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
                provider VARCHAR(40) NULL,
                kind VARCHAR(20) NOT NULL,
                model VARCHAR(50) NULL,
                in_tokens INT DEFAULT 0,
                out_tokens INT DEFAULT 0,
                cost_eur DECIMAL(10,4) DEFAULT 0,
                module_id INT NULL
            ) DEFAULT CHARSET=utf8mb4");
            // Migration : ajoute la colonne fournisseur si la table existait déjà (préparation multi-API).
            if (!$db->query("SHOW COLUMNS FROM ia_usage LIKE 'provider'")->fetch()) {
                $db->exec("ALTER TABLE ia_usage ADD COLUMN provider VARCHAR(40) NULL AFTER user_id");
            }
        } catch (Exception $e) { /* non bloquant */ }
    }
}

if (!function_exists('iaLogUsage')) {
    /** Enregistre un appel API IA. $kind : 'uniformise' | 'quiz'. */
    function iaLogUsage($db, $userId, $kind, $model, $in, $out, $costEur, $moduleId = null, $provider = 'Claude (Anthropic)')
    {
        iaUsageEnsureTable($db);
        try {
            $db->prepare("INSERT INTO ia_usage (created_at, user_id, provider, kind, model, in_tokens, out_tokens, cost_eur, module_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")
               ->execute([
                   date('Y-m-d H:i:s'),
                   ((int) $userId) ?: null,
                   (string) $provider,
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

if (!function_exists('iaUsageHandlePost')) {
    /** Remise à zéro du compteur API (supprime tout le détail). Mot de passe admin requis. */
    function iaUsageHandlePost($db)
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_POST['action'] ?? '') !== 'ia_usage_reset') {
            return;
        }
        requireValidCSRF();
        if (($_SESSION['role'] ?? '') !== 'admin') { header('Location: index.php'); exit(); }
        if (!adminPasswordOk($db, (string) ($_POST['admin_password'] ?? ''))) {
            $_SESSION['module_flash'] = "❌ Mot de passe incorrect : compteur inchangé.";
            header('Location: parametres.php#api'); exit();
        }
        try { $db->exec("DELETE FROM ia_usage"); } catch (Exception $e) { /* non bloquant */ }
        $_SESSION['module_flash'] = "✅ Compteur API remis à zéro (détail effacé).";
        header('Location: parametres.php#api'); exit();
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

        // Sous-totaux par fournisseur (préparation multi-API).
        $byProvider = [];
        try {
            $byProvider = $db->query("SELECT COALESCE(NULLIF(provider,''),'—') p, COUNT(*) c, COALESCE(SUM(cost_eur),0) s FROM ia_usage GROUP BY p ORDER BY s DESC")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {}

        $kindLabel = function ($k) {
            if ($k === 'uniformise') { return '🪄 Mise en forme (fiche)'; }
            if ($k === 'quiz') { return '📝 Génération du quiz'; }
            return htmlspecialchars((string) $k);
        };
        $eur = function ($v) { return number_format((float) $v, 4, ',', ' ') . ' €'; };
        ?>
        <div class="card">
            <div style="display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap;">
                <h2 style="margin:0; color:#2d5a37;">💶 Coûts API (<?= (int) $count ?> appel<?= $count > 1 ? 's' : '' ?>)</h2>
                <button type="button" class="btn btn-danger" onclick="document.getElementById('iaResetModal').classList.add('open')">↺ Remise à zéro</button>
            </div>
            <p class="muted" style="margin-top:6px;">Chaque appel à une API (mise en forme, quiz) est enregistré ici. Le total est cumulé par fournisseur.</p>
            <div style="display:flex; flex-wrap:wrap; gap:16px; margin:14px 0 4px;">
                <div style="flex:1; min-width:220px; background:#eef7f0; border:1px solid #cfe3d5; border-radius:14px; padding:20px 22px;">
                    <div style="font-size:0.8rem; letter-spacing:.08em; text-transform:uppercase; color:#5a6b60; font-weight:700;">Total dépensé (toutes API)</div>
                    <div style="font-size:2.2rem; font-weight:800; color:#1E4D2B; line-height:1.1; margin-top:4px;"><?= htmlspecialchars($eur($total)) ?></div>
                    <div class="muted" style="margin-top:2px;"><?= (int) $count ?> appel<?= $count > 1 ? 's' : '' ?> au total</div>
                </div>
                <?php foreach ($byProvider as $bp): ?>
                <div style="flex:1; min-width:180px; background:#fff; border:1px solid #e1e8e3; border-radius:12px; padding:16px 18px;">
                    <div style="font-size:0.82rem; color:#5a6b60; font-weight:700;"><?= htmlspecialchars((string) $bp['p']) ?></div>
                    <div style="font-weight:800; color:#2d5a37; font-size:1.3rem; margin-top:2px;"><?= htmlspecialchars($eur($bp['s'])) ?></div>
                    <div class="muted" style="font-size:0.8rem;"><?= (int) $bp['c'] ?> appel<?= $bp['c'] > 1 ? 's' : '' ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Modale : remise à zéro du compteur API -->
        <div id="iaResetModal" class="bulk-modal">
            <div class="bulk-modal-box">
                <div style="font-size:2.6rem;">↺</div>
                <h3 style="color:#c0392b; margin:8px 0 6px;">Remettre le compteur API à zéro ?</h3>
                <p class="muted" style="margin:0 0 12px;">Cela efface <strong>tout le détail</strong> des appels et remet le total à <strong>0 €</strong>. Irréversible.</p>
                <form method="POST" action="parametres.php">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="ia_usage_reset">
                    <label style="display:block; font-weight:700; color:#244230; margin:0 0 4px; text-align:left;">Mot de passe administrateur</label>
                    <input type="password" name="admin_password" required autocomplete="off" placeholder="Mot de passe de verrouillage"
                        style="width:100%; box-sizing:border-box; padding:10px; border:1px solid #ccc; border-radius:8px;">
                    <div style="display:flex; gap:10px; justify-content:flex-end; margin-top:18px;">
                        <button type="button" class="btn btn-light" onclick="document.getElementById('iaResetModal').classList.remove('open')">Annuler</button>
                        <button type="submit" class="btn btn-danger">Oui, tout remettre à zéro</button>
                    </div>
                </form>
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
                        <th style="text-align:left;">API</th>
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
                        <td><?= htmlspecialchars((string) ($r['provider'] ?? '—')) ?></td>
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
