<?php
// ============================================================
// events.php — MODÉRATION (boîte de réception) + FIL D'ÉVÉNEMENTS.
//   Un contenu déposé par un contributeur reste « en attente » (is_active=0 -> caché
//   aux apprenants) jusqu'à ce qu'un admin le publie. L'admin publie sans contrôle.
//   Chaque action alimente un fil d'événements (cloche).
// ============================================================

if (!function_exists('eventsEnsureTables')) {
    function eventsEnsureTables($db)
    {
        try {
            $db->exec("CREATE TABLE IF NOT EXISTS site_events (
                id INT AUTO_INCREMENT PRIMARY KEY,
                created_at DATETIME NOT NULL,
                type VARCHAR(30) NOT NULL,
                actor_id INT NULL,
                module_id INT NULL,
                message VARCHAR(255) NULL
            ) DEFAULT CHARSET=utf8mb4");
        } catch (Exception $e) {}
        try {
            if (!$db->query("SHOW COLUMNS FROM modules LIKE 'content_status'")->fetch()) {
                $db->exec("ALTER TABLE modules ADD COLUMN content_status VARCHAR(16) NULL");
            }
        } catch (Exception $e) {}
    }
}

if (!function_exists('logEvent')) {
    function logEvent($db, $type, $actorId, $moduleId, $message)
    {
        eventsEnsureTables($db);
        try {
            $db->prepare("INSERT INTO site_events (created_at, type, actor_id, module_id, message) VALUES (?, ?, ?, ?, ?)")
               ->execute([date('Y-m-d H:i:s'), (string) $type, ((int) $actorId) ?: null, ((int) $moduleId) ?: null, mb_substr((string) $message, 0, 255)]);
        } catch (Exception $e) {}
    }
}

if (!function_exists('eventsPendingSubmissions')) {
    /** Soumissions en attente = modules content_status='pending' dont le parent n'est PAS lui-même en attente. */
    function eventsPendingSubmissions($db)
    {
        eventsEnsureTables($db);
        try {
            $rows = $db->query("SELECT id, nom, nom_nl, parent_id, contenu_by, content_status FROM modules WHERE content_status = 'pending'")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) { return []; }
        $pendingIds = [];
        foreach ($rows as $r) { $pendingIds[(int) $r['id']] = true; }
        $tops = [];
        foreach ($rows as $r) {
            $p = (int) ($r['parent_id'] ?? 0);
            if ($p === 0 || empty($pendingIds[$p])) { $tops[] = $r; }
        }
        return $tops;
    }
}

if (!function_exists('eventsPendingCount')) {
    function eventsPendingCount($db)
    {
        return count(eventsPendingSubmissions($db));
    }
}

if (!function_exists('eventsDescendants')) {
    /** Renvoie [id, ...] du module + tous ses descendants. */
    function eventsDescendants($db, $moduleId)
    {
        $moduleId = (int) $moduleId;
        try { $all = $db->query("SELECT id, parent_id FROM modules")->fetchAll(PDO::FETCH_ASSOC); }
        catch (Exception $e) { return [$moduleId]; }
        $children = [];
        foreach ($all as $m) { $children[(int) ($m['parent_id'] ?? 0)][] = (int) $m['id']; }
        $out = []; $stack = [$moduleId]; $guard = 0;
        while ($stack && $guard++ < 5000) {
            $cur = array_pop($stack);
            $out[] = $cur;
            foreach (($children[$cur] ?? []) as $c) { $stack[] = $c; }
        }
        return $out;
    }
}

if (!function_exists('publishSubmission')) {
    function publishSubmission($db, $moduleId, $actorId)
    {
        $ids = eventsDescendants($db, $moduleId);
        if (empty($ids)) { return; }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        try {
            $db->prepare("UPDATE modules SET is_active = 1, content_status = 'published' WHERE id IN ($ph)")->execute($ids);
        } catch (Exception $e) {}
        $nom = '';
        try { $st = $db->prepare("SELECT nom FROM modules WHERE id = ?"); $st->execute([(int) $moduleId]); $nom = (string) $st->fetchColumn(); } catch (Exception $e) {}
        logEvent($db, 'content_published', $actorId, $moduleId, 'Contenu publié : ' . $nom);
    }
}

if (!function_exists('rejectSubmission')) {
    function rejectSubmission($db, $moduleId, $actorId)
    {
        $ids = eventsDescendants($db, $moduleId);
        if (empty($ids)) { return; }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        try {
            $db->prepare("UPDATE modules SET is_active = 0, content_status = 'draft' WHERE id IN ($ph)")->execute($ids);
        } catch (Exception $e) {}
        $nom = '';
        try { $st = $db->prepare("SELECT nom FROM modules WHERE id = ?"); $st->execute([(int) $moduleId]); $nom = (string) $st->fetchColumn(); } catch (Exception $e) {}
        logEvent($db, 'content_rejected', $actorId, $moduleId, 'Contenu renvoyé en brouillon : ' . $nom);
    }
}

if (!function_exists('eventsEnsureUserSeen')) {
    function eventsEnsureUserSeen($db)
    {
        try {
            if (!$db->query("SHOW COLUMNS FROM utilisateurs LIKE 'events_seen_at'")->fetch()) {
                $db->exec("ALTER TABLE utilisateurs ADD COLUMN events_seen_at DATETIME NULL");
            }
        } catch (Exception $e) {}
    }
}

if (!function_exists('eventsMarkSeen')) {
    function eventsMarkSeen($db, $userId)
    {
        eventsEnsureUserSeen($db);
        try { $db->prepare("UPDATE utilisateurs SET events_seen_at = ? WHERE id = ?")->execute([date('Y-m-d H:i:s'), (int) $userId]); } catch (Exception $e) {}
    }
}

if (!function_exists('eventsUnseenCount')) {
    /** Nb de contenus PUBLIÉS depuis la dernière visite des notifs, que CET utilisateur peut voir. */
    function eventsUnseenCount($db, $userId, $role)
    {
        eventsEnsureUserSeen($db);
        $seen = '2000-01-01 00:00:00';
        try { $st = $db->prepare("SELECT events_seen_at FROM utilisateurs WHERE id = ?"); $st->execute([(int) $userId]); $s = $st->fetchColumn(); if ($s) { $seen = (string) $s; } } catch (Exception $e) {}
        try {
            $st = $db->prepare("SELECT DISTINCT module_id FROM site_events WHERE type = 'content_published' AND created_at > ? ORDER BY created_at DESC LIMIT 100");
            $st->execute([$seen]);
            $mods = $st->fetchAll(PDO::FETCH_COLUMN);
        } catch (Exception $e) { return 0; }
        $n = 0;
        foreach ($mods as $mid) {
            $mid = (int) $mid;
            if ($mid <= 0) { continue; }
            try {
                $m = getModuleById($db, $mid);
                if ($m && (int) ($m['is_active'] ?? 0) === 1 && (!function_exists('userCanSeeModule') || userCanSeeModule($m, $role))) { $n++; }
            } catch (Exception $e) {}
        }
        return $n;
    }
}

if (!function_exists('eventsRecent')) {
    function eventsRecent($db, $limit = 60)
    {
        eventsEnsureTables($db);
        try {
            $st = $db->prepare("SELECT e.*, u.prenom, u.nom AS unom, m.nom AS module_nom
                                FROM site_events e
                                LEFT JOIN utilisateurs u ON u.id = e.actor_id
                                LEFT JOIN modules m ON m.id = e.module_id
                                ORDER BY e.created_at DESC LIMIT ?");
            $st->bindValue(1, (int) $limit, PDO::PARAM_INT);
            $st->execute();
            return $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) { return []; }
    }
}
