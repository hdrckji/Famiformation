<?php
// ============================================================
// versions.php — HISTORIQUE des versions d'une formation (contenu + quiz).
//
//   Remplacer le contenu d'une formation ÉCRASE tout : guide, traduction, quiz,
//   sous-titres. Avant, l'ancienne version était perdue à jamais. Ici, on en prend
//   un INSTANTANÉ juste avant de l'écraser — texte ET fichiers (PDF, vidéo, .srt).
//
//   ⚠️ COÛT DE STOCKAGE : garder les fichiers permet une restauration à l'identique,
//   mais chaque version d'une formation avec vidéo pèse plusieurs centaines de Mo.
//   Le poids total de l'historique est affiché dans Paramètres → Contenu.
//
//   Les fichiers archivés ne sont PAS supprimés au remplacement (c'est tout l'intérêt) :
//   ils ne le sont qu'à la suppression du module ou de la version.
// ============================================================

if (!function_exists('versionsEnsureTable')) {
    function versionsEnsureTable(PDO $db)
    {
        static $done = false;
        if ($done) { return; }
        $done = true;
        try {
            $db->exec("CREATE TABLE IF NOT EXISTS content_versions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                module_id INT NOT NULL,
                created_at DATETIME NOT NULL,
                actor_id INT NULL,
                a_evaluer TINYINT(1) NOT NULL DEFAULT 0,
                source_lang VARCHAR(2) NULL,
                pdf_path VARCHAR(255) NULL,
                video_path VARCHAR(255) NULL,
                video_src_path VARCHAR(255) NULL,
                sub_src_path VARCHAR(255) NULL,
                sub_fr_path VARCHAR(255) NULL,
                sub_nl_path VARCHAR(255) NULL,
                contenu_images MEDIUMTEXT NULL,
                contenu_ia MEDIUMTEXT NULL,
                contenu_ia_nl MEDIUMTEXT NULL,
                quiz_json MEDIUMTEXT NULL,
                quiz_json_nl MEDIUMTEXT NULL,
                transcript MEDIUMTEXT NULL,
                INDEX (module_id)
            ) DEFAULT CHARSET=utf8mb4");
        } catch (Exception $e) { /* non bloquant */ }
    }
}

if (!function_exists('versionFileKeys')) {
    /** Fichiers appartenant à UNE version (pour le poids et la suppression). */
    function versionFileKeys(array $v)
    {
        $keys = [];
        foreach (['pdf_path', 'video_path', 'video_src_path', 'sub_src_path', 'sub_fr_path', 'sub_nl_path'] as $c) {
            $k = trim((string) ($v[$c] ?? ''));
            if ($k !== '') { $keys[] = $k; }
        }
        foreach ((array) json_decode((string) ($v['contenu_images'] ?? '[]'), true) as $img) {
            $img = trim((string) $img);
            if ($img !== '') { $keys[] = $img; }
        }
        return array_values(array_unique($keys));
    }
}

if (!function_exists('versionSnapshot')) {
    /**
     * Archive l'état ACTUEL d'une formation (module parent) AVANT de l'écraser.
     * Ne fait rien s'il n'y a rien à archiver (module encore vide).
     *
     * @return int id de la version créée, ou 0
     */
    function versionSnapshot(PDO $db, $parentId, $actorId = 0)
    {
        versionsEnsureTable($db);
        $parentId = (int) $parentId;
        if ($parentId <= 0) { return 0; }

        // Le contenu vit sur les sous-modules (guide / vidéo) — ou sur le module lui-même
        // pour les anciens contenus non structurés.
        $guide = null;
        $video = null;
        try {
            $st = $db->prepare("SELECT * FROM modules WHERE parent_id = ? OR id = ?");
            $st->execute([$parentId, $parentId]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $kind = (string) ($r['content_kind'] ?? '');
                if ($kind === 'ecrit' || (!$guide && ((int) $r['id'] === $parentId) && !empty($r['contenu_ia']))) { $guide = $r; }
                if ($kind === 'video') { $video = $r; }
            }
        } catch (Exception $e) { return 0; }

        $has = ($guide && (!empty($guide['pdf_path']) || !empty($guide['contenu_ia'])))
            || ($video && (!empty($video['video_path']) || !empty($video['video_src_path'])));
        if (!$has) { return 0; } // rien à archiver

        try {
            $db->prepare("INSERT INTO content_versions
                (module_id, created_at, actor_id, a_evaluer, source_lang, pdf_path, video_path, video_src_path,
                 sub_src_path, sub_fr_path, sub_nl_path, contenu_images, contenu_ia, contenu_ia_nl,
                 quiz_json, quiz_json_nl, transcript)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
               ->execute([
                   $parentId,
                   date('Y-m-d H:i:s'),
                   ((int) $actorId) ?: null,
                   (int) ($guide['a_evaluer'] ?? 0),
                   (string) ($guide['source_lang'] ?? 'fr'),
                   $guide['pdf_path'] ?? null,
                   $video['video_path'] ?? null,
                   $video['video_src_path'] ?? null,
                   $video['sub_src_path'] ?? null,
                   $video['sub_fr_path'] ?? null,
                   $video['sub_nl_path'] ?? null,
                   $guide['contenu_images'] ?? null,
                   $guide['contenu_ia'] ?? null,
                   $guide['contenu_ia_nl'] ?? null,
                   $guide['quiz_json'] ?? null,
                   $guide['quiz_json_nl'] ?? null,
                   $video['transcript'] ?? null,
               ]);
            return (int) $db->lastInsertId();
        } catch (Exception $e) {
            return 0;
        }
    }
}

if (!function_exists('versionsList')) {
    /** Les versions archivées d'une formation, de la plus récente à la plus ancienne. */
    function versionsList(PDO $db, $parentId)
    {
        versionsEnsureTable($db);
        try {
            $st = $db->prepare("SELECT v.*, u.prenom, u.nom AS unom
                                FROM content_versions v
                                LEFT JOIN utilisateurs u ON u.id = v.actor_id
                                WHERE v.module_id = ? ORDER BY v.created_at DESC");
            $st->execute([(int) $parentId]);
            return $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) { return []; }
    }

    /** Nombre de questions du quiz archivé dans une version. */
    function versionQuizNb(array $v)
    {
        $q = json_decode((string) ($v['quiz_json'] ?? ''), true);
        return (is_array($q) && !empty($q['questions'])) ? count($q['questions']) : 0;
    }
}

if (!function_exists('versionsDelete')) {
    /** Supprime une version ET ses fichiers archivés. */
    function versionsDelete(PDO $db, $versionId)
    {
        versionsEnsureTable($db);
        try {
            $st = $db->prepare("SELECT * FROM content_versions WHERE id = ? LIMIT 1");
            $st->execute([(int) $versionId]);
            $v = $st->fetch(PDO::FETCH_ASSOC);
            if (!$v) { return false; }

            // On ne supprime QUE les fichiers que plus personne n'utilise : une version peut
            // partager un fichier avec le module courant (remplacement partiel) ou une autre
            // version (le PDF n'a pas changé entre deux remplacements).
            foreach (versionFileKeys($v) as $key) {
                if (!versionKeyStillUsed($db, $key, (int) $versionId)) {
                    if (function_exists('famiUnlinkStorageKey')) { famiUnlinkStorageKey($key); }
                }
            }
            $db->prepare("DELETE FROM content_versions WHERE id = ?")->execute([(int) $versionId]);
            return true;
        } catch (Exception $e) { return false; }
    }

    /** Ce fichier est-il encore référencé ailleurs (module en cours ou autre version) ? */
    function versionKeyStillUsed(PDO $db, $key, $exceptVersionId = 0)
    {
        $key = (string) $key;
        if ($key === '') { return true; }
        try {
            $st = $db->prepare("SELECT COUNT(*) FROM modules
                                WHERE pdf_path = ? OR video_path = ? OR video_src_path = ?
                                   OR sub_src_path = ? OR sub_fr_path = ? OR sub_nl_path = ?
                                   OR contenu_images LIKE ?");
            $st->execute([$key, $key, $key, $key, $key, $key, '%' . $key . '%']);
            if ((int) $st->fetchColumn() > 0) { return true; }

            $st = $db->prepare("SELECT COUNT(*) FROM content_versions
                                WHERE id <> ? AND (pdf_path = ? OR video_path = ? OR video_src_path = ?
                                   OR sub_src_path = ? OR sub_fr_path = ? OR sub_nl_path = ?
                                   OR contenu_images LIKE ?)");
            $st->execute([(int) $exceptVersionId, $key, $key, $key, $key, $key, $key, '%' . $key . '%']);
            return (int) $st->fetchColumn() > 0;
        } catch (Exception $e) {
            return true; // dans le doute, on ne supprime pas
        }
    }
}

if (!function_exists('versionsPurgeForModules')) {
    /** Suppression d'un module : ses versions et leurs fichiers partent avec lui. */
    function versionsPurgeForModules(PDO $db, array $moduleIds)
    {
        versionsEnsureTable($db);
        $ids = array_values(array_filter(array_map('intval', $moduleIds)));
        if (empty($ids)) { return; }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        try {
            $st = $db->prepare("SELECT id FROM content_versions WHERE module_id IN ($ph)");
            $st->execute($ids);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $vid) {
                versionsDelete($db, (int) $vid);
            }
        } catch (Exception $e) { /* non bloquant */ }
    }
}

if (!function_exists('versionRestore')) {
    /**
     * Restaure une version : le contenu archivé redevient le contenu courant.
     * L'état ACTUEL est d'abord archivé (on ne perd jamais rien en restaurant).
     * La formation repasse par la relecture (elle n'est pas republiée d'office).
     */
    function versionRestore(PDO $db, $versionId, $actorId = 0)
    {
        versionsEnsureTable($db);
        try {
            $st = $db->prepare("SELECT * FROM content_versions WHERE id = ? LIMIT 1");
            $st->execute([(int) $versionId]);
            $v = $st->fetch(PDO::FETCH_ASSOC);
            if (!$v) { return "❌ Version introuvable."; }
            $pid = (int) $v['module_id'];

            versionSnapshot($db, $pid, $actorId); // l'état courant devient lui aussi une version

            // Guide
            $g = $db->prepare("SELECT id FROM modules WHERE parent_id = ? AND content_kind = 'ecrit' LIMIT 1");
            $g->execute([$pid]);
            $gid = (int) $g->fetchColumn();
            if ($gid > 0) {
                $db->prepare("UPDATE modules SET pdf_path = ?, contenu_ia = ?, contenu_ia_nl = ?, contenu_images = ?,
                                quiz_json = ?, quiz_json_nl = ?, source_lang = ?, a_evaluer = ?, uniformized = 1,
                                nl_hash = NULL
                              WHERE id = ?")
                   ->execute([
                       $v['pdf_path'], $v['contenu_ia'], $v['contenu_ia_nl'], $v['contenu_images'],
                       $v['quiz_json'], $v['quiz_json_nl'], $v['source_lang'], (int) $v['a_evaluer'], $gid,
                   ]);
            }

            // Vidéo
            $vd = $db->prepare("SELECT id FROM modules WHERE parent_id = ? AND content_kind = 'video' LIMIT 1");
            $vd->execute([$pid]);
            $vid = (int) $vd->fetchColumn();
            if ($vid > 0) {
                $db->prepare("UPDATE modules SET video_path = ?, video_src_path = ?, sub_src_path = ?,
                                sub_fr_path = ?, sub_nl_path = ?, transcript = ?, video_status = NULL, sub_status = 'ready'
                              WHERE id = ?")
                   ->execute([
                       $v['video_path'], $v['video_src_path'], $v['sub_src_path'],
                       $v['sub_fr_path'], $v['sub_nl_path'], $v['transcript'], $vid,
                   ]);
            }

            return "✅ Version du " . date('d/m/Y H:i', strtotime((string) $v['created_at'])) . " restaurée.";
        } catch (Exception $e) {
            return "❌ Restauration impossible.";
        }
    }
}
