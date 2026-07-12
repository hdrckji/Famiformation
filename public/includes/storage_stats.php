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

// ------------------------------------------------------------
// HISTORIQUE DU STOCKAGE (facturation au PRO RATA : Go × durée)
// Le fournisseur facture l'intégrale du volume dans le temps (« Go-mois »).
// Une simple photo du volume actuel serait FAUSSE : si on a stocké 10 Go
// pendant 20 jours puis tout supprimé, ces 20 jours sont bel et bien facturés.
// On enregistre donc des échantillons (horodatés) et on intègre dans le temps.
// ------------------------------------------------------------

if (!function_exists('ensureStorageSamplesTable')) {
    function ensureStorageSamplesTable(PDO $db)
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        try {
            $db->exec(
                "CREATE TABLE IF NOT EXISTS storage_samples (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    ts DATETIME NOT NULL,
                    bytes BIGINT UNSIGNED NOT NULL,
                    INDEX idx_ts (ts)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
        } catch (Exception $e) {
            // non critique
        }
    }
}

if (!function_exists('storageRecordSample')) {
    /**
     * Enregistre le volume courant. À appeler à chaque CHANGEMENT (upload, suppression)
     * et au moins une fois par jour. On évite de spammer : on n'écrit que si le volume
     * a changé, ou si le dernier point date de plus d'une heure.
     */
    function storageRecordSample(PDO $db, $bytes = null)
    {
        try {
            ensureStorageSamplesTable($db);
            if ($bytes === null) {
                $u = famiStorageUsage();
                $bytes = (int) $u['total'];
            }
            $bytes = max(0, (int) $bytes);

            $last = $db->query("SELECT ts, bytes FROM storage_samples ORDER BY ts DESC, id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            if ($last) {
                $sameSize = ((int) $last['bytes'] === $bytes);
                $recent = (strtotime($last['ts']) > time() - 3600);
                if ($sameSize && $recent) {
                    return; // rien de neuf et point récent : inutile d'écrire
                }
            }
            $db->prepare("INSERT INTO storage_samples (ts, bytes) VALUES (NOW(), ?)")->execute([$bytes]);
        } catch (Exception $e) {
            // non critique
        }
    }
}

if (!function_exists('storageMonthUsage')) {
    /**
     * Intègre le volume dans le temps sur le mois courant.
     * Renvoie :
     *  - avg_bytes  : volume MOYEN sur la période écoulée du mois
     *  - gb_month   : Go-mois réellement consommés depuis le 1er (c'est ça qu'on facture)
     *  - elapsed    : fraction du mois écoulée (0..1)
     */
    function storageMonthUsage(PDO $db, $ym = null)
    {
        $out = ['avg_bytes' => 0.0, 'gb_month' => 0.0, 'elapsed' => 0.0];
        try {
            ensureStorageSamplesTable($db);
            $ym = ($ym !== null) ? $ym : date('Y-m');
            $startTs = strtotime($ym . '-01 00:00:00');
            $monthSecs = (int) date('t', $startTs) * 86400;
            $nowTs = min(time(), $startTs + $monthSecs);
            $elapsedSecs = max(1, $nowTs - $startTs);

            // Niveau au début du mois = dernier échantillon AVANT le 1er.
            $p = $db->prepare("SELECT bytes FROM storage_samples WHERE ts < ? ORDER BY ts DESC, id DESC LIMIT 1");
            $p->execute([date('Y-m-d H:i:s', $startTs)]);
            $level = (int) $p->fetchColumn();

            $s = $db->prepare("SELECT ts, bytes FROM storage_samples WHERE ts >= ? AND ts <= ? ORDER BY ts ASC, id ASC");
            $s->execute([date('Y-m-d H:i:s', $startTs), date('Y-m-d H:i:s', $nowTs)]);

            $cursor = $startTs;
            $byteSecs = 0.0;
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $t = strtotime($r['ts']);
                if ($t > $cursor) {
                    $byteSecs += $level * ($t - $cursor); // le volume précédent a duré jusqu'ici
                    $cursor = $t;
                }
                $level = (int) $r['bytes'];
            }
            if ($nowTs > $cursor) {
                $byteSecs += $level * ($nowTs - $cursor);
            }

            $out['avg_bytes'] = $byteSecs / $elapsedSecs;
            // Go-mois consommés = (moyenne en Go) × (part du mois écoulée)
            $out['elapsed'] = $elapsedSecs / $monthSecs;
            $out['gb_month'] = famiBytesToGo($out['avg_bytes']) * $out['elapsed'];
        } catch (Exception $e) {
            // on renvoie des zéros
        }
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
