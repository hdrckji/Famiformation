<?php
// ============================================================
// storage_stats.php — mesure du STOCKAGE (fichiers du volume) et de l'EGRESS
// (octets réellement envoyés par media.php), pour estimer le coût.
//
// Stockage : calcul direct sur les fichiers → exact, immédiat.
// Egress   : compteur mensuel alimenté à chaque envoi de fichier. Ne compte
//            QUE ce qui sort vraiment du serveur (donc le cache navigateur,
//            qui n'est pas facturé, n'est pas compté). Démarre à l'installation.
// ============================================================

if (!function_exists('famiStorageBase')) {
    function famiStorageBase()
    {
        return defined('FAMI_STORAGE_BASE') ? FAMI_STORAGE_BASE : (__DIR__ . '/../uploads');
    }
}

if (!function_exists('famiStorageUsage')) {
    /** Parcourt le volume : total, nombre de fichiers, et répartition par catégorie. */
    function famiStorageUsage()
    {
        $base = famiStorageBase();
        $out = ['total' => 0, 'files' => 0, 'by' => []];
        if (!is_dir($base)) {
            return $out;
        }
        try {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($it as $f) {
                if (!$f->isFile()) {
                    continue;
                }
                $size = (int) $f->getSize();
                $out['total'] += $size;
                $out['files']++;

                // Catégorie = sous-dossier de modules/ (video, pdf, video_raw...)
                $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($base) + 1));
                $parts = explode('/', $rel);
                $cat = 'autres';
                if (isset($parts[0]) && $parts[0] === 'modules' && isset($parts[1])) {
                    $cat = $parts[1];
                } elseif (isset($parts[0]) && $parts[0] !== '') {
                    $cat = $parts[0];
                }
                if (!isset($out['by'][$cat])) {
                    $out['by'][$cat] = 0;
                }
                $out['by'][$cat] += $size;
            }
        } catch (Exception $e) {
            // volume illisible : on renvoie ce qu'on a
        }
        arsort($out['by']);
        return $out;
    }
}

if (!function_exists('ensureEgressTable')) {
    function ensureEgressTable(PDO $db)
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        try {
            $db->exec(
                "CREATE TABLE IF NOT EXISTS egress_stats (
                    ym VARCHAR(7) NOT NULL PRIMARY KEY,
                    bytes BIGINT UNSIGNED NOT NULL DEFAULT 0
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
        } catch (Exception $e) {
            // table indisponible : le suivi est non critique
        }
    }
}

if (!function_exists('egressAdd')) {
    /** Ajoute des octets envoyés au compteur du mois courant. */
    function egressAdd(PDO $db, $bytes)
    {
        $bytes = (int) $bytes;
        if ($bytes <= 0) {
            return;
        }
        try {
            ensureEgressTable($db);
            $st = $db->prepare(
                "INSERT INTO egress_stats (ym, bytes) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE bytes = bytes + VALUES(bytes)"
            );
            $st->execute([date('Y-m'), $bytes]);
        } catch (Exception $e) {
            // non critique : ne jamais bloquer la diffusion d'un fichier
        }
    }
}

if (!function_exists('egressMonth')) {
    /** Octets envoyés sur un mois (YYYY-MM), par défaut le mois courant. */
    function egressMonth(PDO $db, $ym = null)
    {
        try {
            ensureEgressTable($db);
            $st = $db->prepare("SELECT bytes FROM egress_stats WHERE ym = ?");
            $st->execute([$ym !== null ? $ym : date('Y-m')]);
            return (int) $st->fetchColumn();
        } catch (Exception $e) {
            return 0;
        }
    }
}

if (!function_exists('famiBytesToGo')) {
    function famiBytesToGo($bytes)
    {
        return ((float) $bytes) / (1024 * 1024 * 1024);
    }
}

if (!function_exists('famiFormatSize')) {
    /** Affichage lisible : Ko / Mo / Go. */
    function famiFormatSize($bytes)
    {
        $bytes = (float) $bytes;
        if ($bytes >= 1024 * 1024 * 1024) {
            return number_format($bytes / (1024 * 1024 * 1024), 2, ',', ' ') . ' Go';
        }
        if ($bytes >= 1024 * 1024) {
            return number_format($bytes / (1024 * 1024), 1, ',', ' ') . ' Mo';
        }
        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 0, ',', ' ') . ' Ko';
        }
        return (int) $bytes . ' o';
    }
}
