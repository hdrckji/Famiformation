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

if (!function_exists('eventsCategory')) {
    /**
     * Trois boîtes distinctes dans les notifications :
     *   'todo'   → à contrôler (admin) : contenus proposés, en attente de publication ;
     *   'error'  → ce qui a mal tourné : doutes de l'IA, compression ratée, API en panne ;
     *   'event'  → la vie du site : modules créés, contenus ajoutés/publiés.
     */
    function eventsCategory($type)
    {
        $type = (string) $type;
        if ($type === 'content_submitted') { return 'todo'; }
        if ($type === 'ai_doubt' || $type === 'error') { return 'error'; }
        return 'event';
    }
}

if (!function_exists('logSiteError')) {
    /**
     * Enregistre une ERREUR TECHNIQUE, écrite POUR UN HUMAIN.
     * Le message brut (« cURL error 28 », « HTTP 429 ») ne doit jamais arriver tel quel
     * dans la boîte : on le traduit ici en une phrase compréhensible + la marche à suivre.
     *
     * @param string $what  'video' | 'subtitles' | 'quiz' | 'guide' | 'api'
     * @param string $raw   le message technique d'origine (analysé, jamais affiché brut)
     */
    function logSiteError($db, $moduleId, $actorId, $what, $raw = '')
    {
        $raw = (string) $raw;
        $low = mb_strtolower($raw);

        // On traduit les causes techniques connues en langage humain.
        $cause = '';
        if (strpos($low, 'clé') !== false || strpos($low, 'api_key') !== false || strpos($low, 'absente') !== false) {
            $cause = "la clé de l'API n'est pas configurée (Railway → Variables).";
        } elseif (strpos($low, 'timed out') !== false || strpos($low, 'timeout') !== false || strpos($low, 'délai') !== false) {
            $cause = "le service a mis trop de temps à répondre. Réessaie dans quelques minutes.";
        } elseif (strpos($low, '429') !== false || strpos($low, 'rate limit') !== false || strpos($low, 'quota') !== false) {
            $cause = "le quota de l'API est atteint. Réessaie plus tard, ou vérifie le crédit du compte.";
        } elseif (strpos($low, 'ffmpeg') !== false) {
            $cause = "la conversion de la vidéo a échoué. La vidéo d'origine reste lisible.";
        } elseif ($raw !== '') {
            $cause = "cause : " . mb_substr($raw, 0, 90);
        }

        $lbl = [
            'video'     => "La compression de la vidéo a échoué",
            'subtitles' => "Les sous-titres n'ont pas pu être générés",
            'quiz'      => "Le quiz n'a pas pu être généré",
            'guide'     => "La mise en forme du guide a échoué",
            'api'       => "Un service externe n'a pas répondu",
        ];
        $msg = ($lbl[$what] ?? "Une opération a échoué");
        if ($cause !== '') { $msg .= ' — ' . $cause; }

        logEvent($db, 'error', $actorId, $moduleId, '❌ ' . $msg);
    }
}

if (!function_exists('logAiDoubts')) {
    /**
     * L'IA a signalé des doutes (champ "fix") : on le dit dans les notifications.
     * L'auteur du contenu ET les admins le verront dans le fil (et le rond rouge s'allume).
     *
     * @param string $what 'guide' ou 'quiz'
     */
    function logAiDoubts($db, $moduleId, $actorId, $nb, $what = 'guide')
    {
        $nb = (int) $nb;
        if ($nb <= 0) { return; }
        $nom = '';
        try {
            $st = $db->prepare("SELECT nom FROM modules WHERE id = ?");
            $st->execute([(int) $moduleId]);
            $nom = (string) $st->fetchColumn();
        } catch (Exception $e) {}
        $lbl = ($what === 'quiz')
            ? ($nb . ' question' . ($nb > 1 ? 's' : '') . ' douteuse' . ($nb > 1 ? 's' : '') . ' dans le quiz')
            : ($nb . ' point' . ($nb > 1 ? 's' : '') . ' douteux dans le guide');
        logEvent($db, 'ai_doubt', $actorId, $moduleId, '⚠️ ' . $lbl . ' — à vérifier : ' . $nom);
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
    /**
     * Nb de notifications NON VUES par cet utilisateur — quelle que soit leur nature
     * (contenu publié, contenu proposé, rejet…). Le rond rouge doit s'allumer pour TOUT.
     * Seul filtre : un non-admin ne compte pas un événement portant sur un module
     * qu'il n'a pas le droit de voir (contenu encore caché, profil non autorisé).
     */
    function eventsUnseenCount($db, $userId, $role)
    {
        eventsEnsureUserSeen($db);
        $isAdmin = ($role === 'admin');
        $seen = '2000-01-01 00:00:00';
        try {
            $st = $db->prepare("SELECT events_seen_at FROM utilisateurs WHERE id = ?");
            $st->execute([(int) $userId]);
            $s = $st->fetchColumn();
            if ($s) { $seen = (string) $s; }
        } catch (Exception $e) {}

        try {
            $st = $db->prepare("SELECT id, module_id FROM site_events WHERE created_at > ? ORDER BY created_at DESC LIMIT 100");
            $st->execute([$seen]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) { return 0; }

        if ($isAdmin) { return count($rows); }

        $n = 0;
        $cache = [];
        foreach ($rows as $r) {
            $mid = (int) ($r['module_id'] ?? 0);
            if ($mid <= 0) { $n++; continue; } // événement général : visible par tous
            if (!array_key_exists($mid, $cache)) {
                $ok = false;
                try {
                    $m = getModuleById($db, $mid);
                    $ok = ($m && (int) ($m['is_active'] ?? 0) === 1
                        && (!function_exists('userCanSeeModule') || userCanSeeModule($m, $role)));
                } catch (Exception $e) {}
                $cache[$mid] = $ok;
            }
            if ($cache[$mid]) { $n++; }
        }
        return $n;
    }
}

if (!function_exists('eventsDelete')) {
    /** Supprime les notifications dont l'id est dans $ids. */
    function eventsDelete($db, array $ids)
    {
        $ids = array_values(array_filter(array_map('intval', $ids), function ($n) { return $n > 0; }));
        if (empty($ids)) { return 0; }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        try {
            $st = $db->prepare("DELETE FROM site_events WHERE id IN ($ph)");
            $st->execute($ids);
            return $st->rowCount();
        } catch (Exception $e) { return 0; }
    }

    /** Vide entièrement le fil de notifications. */
    function eventsDeleteAll($db)
    {
        try {
            $st = $db->query("DELETE FROM site_events");
            return $st ? $st->rowCount() : 0;
        } catch (Exception $e) { return 0; }
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
