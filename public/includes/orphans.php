<?php
// ============================================================
// orphans.php — FICHIERS ORPHELINS : ceux que plus aucune ligne de la base ne réclame.
//
//   Un fichier peut survivre à son module : suppression interrompue, ancien code, remplacement
//   avant l'archivage des versions… Il reste alors sur le volume, invisible, et il est FACTURÉ.
//   Ici, on compare ce que contient le disque avec TOUT ce que la base référence encore
//   (modules : pdf, vidéo, source, sous-titres, icône, images extraites, images de l'éditeur ;
//    versions archivées ; réglages du Créateur : habillage, intro, outro ; photos de profil).
//
//   ⚠️ Ce qui n'est réclamé par PERSONNE est proposé à la suppression — jamais supprimé tout seul.
// ============================================================

if (!function_exists('orphansReferenced')) {
    /** TOUTES les clés de fichiers encore référencées quelque part en base. */
    function orphansReferenced(PDO $db)
    {
        $ref = [];
        $add = function ($k) use (&$ref) {
            $k = trim((string) $k);
            if ($k !== '') { $ref[ltrim($k, '/')] = true; }
        };

        // 1) Modules (contenu courant)
        try {
            require_once __DIR__ . '/modules.php';
            $rows = $db->query("SELECT pdf_path, video_path, video_src_path, sub_fr_path, sub_nl_path, sub_src_path,
                                       icon_image, contenu_images, contenu_ia, contenu_ia_nl
                                FROM modules")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                foreach (famiModuleFileKeys($r) as $k) { $add($k); }
            }
        } catch (Exception $e) {}

        // 2) Versions archivées
        try {
            require_once __DIR__ . '/versions.php';
            versionsEnsureTable($db);
            foreach ($db->query("SELECT * FROM content_versions")->fetchAll(PDO::FETCH_ASSOC) as $v) {
                foreach (versionFileKeys($v) as $k) { $add($k); }
            }
        } catch (Exception $e) {}

        // 3) Créateur : habillage, intro, outro (les 4 langues × 3 réglages)
        try {
            foreach (['video_backdrop', 'video_backdrop_nl', 'video_intro', 'video_intro_nl', 'video_outro', 'video_outro_nl'] as $k) {
                $add(widgetGet($db, $k, ''));
            }
        } catch (Exception $e) {}

        // 4) Photos de profil
        try {
            foreach ($db->query("SELECT photo_profil FROM utilisateurs WHERE photo_profil IS NOT NULL AND photo_profil <> ''")->fetchAll(PDO::FETCH_COLUMN) as $p) {
                $add($p);
            }
        } catch (Exception $e) {}

        return $ref;
    }
}

if (!function_exists('orphansScan')) {
    /**
     * Les fichiers du volume que plus personne ne réclame.
     * @return array ['files' => [['key','size','mtime'], ...], 'bytes' => int]
     */
    function orphansScan(PDO $db)
    {
        require_once __DIR__ . '/storage_stats.php';
        $base = famiStorageBase();
        $out = ['files' => [], 'bytes' => 0];
        if (!is_dir($base)) { return $out; }

        $ref = orphansReferenced($db);

        try {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($it as $f) {
                if (!$f->isFile()) { continue; }
                $key = str_replace('\\', '/', substr($f->getPathname(), strlen($base) + 1));
                if ($key === '' || isset($ref[$key])) { continue; }

                // Les anciens chemins étaient stockés avec le préfixe « uploads/ ».
                if (isset($ref['uploads/' . $key])) { continue; }

                $out['files'][] = [
                    'key'   => $key,
                    'size'  => (int) $f->getSize(),
                    'mtime' => (int) $f->getMTime(),
                ];
                $out['bytes'] += (int) $f->getSize();
            }
        } catch (Exception $e) {}

        usort($out['files'], function ($a, $b) { return $b['size'] <=> $a['size']; });
        return $out;
    }
}

if (!function_exists('orphansHandlePost')) {
    function orphansHandlePost(PDO $db)
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { return; }
        if (($_POST['action'] ?? '') !== 'purge_orphans') { return; }
        requireValidCSRF();
        if (($_SESSION['role'] ?? '') !== 'admin') { header('Location: index.php'); exit(); }

        if (!adminPasswordOk($db, (string) ($_POST['admin_password'] ?? ''))) {
            $_SESSION['module_flash'] = "❌ Mot de passe incorrect : aucun fichier supprimé.";
            header('Location: parametres.php#contenu');
            exit();
        }

        // On RESCANNE au moment de supprimer : entre l'affichage et le clic, un fichier a pu
        // redevenir utile (un import en cours). On ne supprime jamais sur une liste périmée.
        $scan = orphansScan($db);
        $n = 0;
        $bytes = 0;
        foreach ($scan['files'] as $f) {
            if (function_exists('famiUnlinkStorageKey')) {
                famiUnlinkStorageKey($f['key']);
                $n++;
                $bytes += (int) $f['size'];
            }
        }
        $_SESSION['module_flash'] = $n > 0
            ? "🧹 " . $n . " fichier" . ($n > 1 ? 's' : '') . " orphelin" . ($n > 1 ? 's' : '') . " supprimé" . ($n > 1 ? 's' : '')
              . " (" . famiFormatSize($bytes) . " libérés)."
            : "✅ Aucun fichier orphelin : le stockage est propre.";
        header('Location: parametres.php#contenu');
        exit();
    }
}

if (!function_exists('orphansCard')) {
    function orphansCard(PDO $db)
    {
        $scan = orphansScan($db);
        $nb = count($scan['files']);
        ?>
        <div class="pref-block" style="<?= $nb > 0 ? 'border-left-color:#e8a13a;' : '' ?>">
            <h3 style="margin:0 0 6px; color:#244230;">🧹 Fichiers orphelins</h3>
            <p class="muted">Des fichiers que <strong>plus aucune ligne de la base ne réclame</strong> : restes d'une suppression interrompue, d'un ancien code, d'un remplacement. Ils sont invisibles dans le site — mais ils occupent le volume et ils sont <strong>facturés</strong>.</p>

            <?php if ($nb === 0): ?>
                <div style="background:#eef7f0; border:1px solid #cfe3d5; color:#1d6a39; border-radius:10px; padding:12px 14px; font-weight:700;">
                    ✅ Aucun fichier orphelin — le stockage est propre.
                </div>
            <?php else: ?>
                <div style="background:#fffaf2; border:1px solid #f0d089; color:#8a5a00; border-radius:10px; padding:12px 14px; font-weight:700; margin-bottom:12px;">
                    ⚠️ <?= (int) $nb ?> fichier<?= $nb > 1 ? 's' : '' ?> orphelin<?= $nb > 1 ? 's' : '' ?> — <?= famiFormatSize($scan['bytes']) ?> occupés pour rien.
                </div>

                <details class="fold">
                    <summary>Voir le détail</summary>
                    <div style="padding:6px 14px 14px; max-height:320px; overflow:auto;">
                        <table style="margin:0; width:100%;">
                            <tbody>
                            <?php foreach (array_slice($scan['files'], 0, 200) as $f): ?>
                                <tr>
                                    <td style="font-size:.82rem; word-break:break-all;"><?= htmlspecialchars($f['key']) ?></td>
                                    <td style="text-align:right; white-space:nowrap; font-weight:700;"><?= famiFormatSize($f['size']) ?></td>
                                    <td style="text-align:right; white-space:nowrap; color:#7a8a80; font-size:.8rem;"><?= date('d/m/Y', $f['mtime']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php if ($nb > 200): ?><p class="muted" style="font-size:.8rem;">… et <?= $nb - 200 ?> autres.</p><?php endif; ?>
                    </div>
                </details>

                <form method="POST" action="parametres.php#contenu" style="margin-top:12px; display:flex; gap:10px; flex-wrap:wrap; align-items:center;"
                      onsubmit="return confirm('Supprimer définitivement <?= (int) $nb ?> fichier(s) orphelin(s) ? Cette action est irréversible.');">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="purge_orphans">
                    <input type="password" name="admin_password" placeholder="Mot de passe admin" required
                           style="padding:9px 11px; border:1px solid #ccd6cf; border-radius:9px; font:inherit;">
                    <button type="submit" class="btn btn-danger">🧹 Supprimer les orphelins</button>
                </form>
                <p class="muted" style="font-size:.8rem; margin-top:8px;">La liste est <strong>recalculée au moment de la suppression</strong> : un fichier redevenu utile entre-temps (import en cours) ne sera pas touché.</p>
            <?php endif; ?>
        </div>
        <?php
    }
}
