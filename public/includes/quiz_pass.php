<?php
// ============================================================
// quiz_pass.php — RÉSULTATS des quiz : qui a réussi quoi.
//
//   Le quiz modulaire n'enregistrait RIEN : le score s'affichait puis disparaissait.
//   On mémorise donc chaque passage (score, réussite), pour deux raisons :
//     • savoir si un apprenant a VALIDÉ une formation ;
//     • conditionner le téléchargement (vidéo / guide) à cette réussite.
//
//   « Validé » = score ≥ note de réussite (réglable dans Paramètres → Préférences,
//   défaut 70 %). Un module SANS quiz n'a rien à valider : il ne bloque rien.
// ============================================================

if (!function_exists('quizPassEnsureTable')) {
    function quizPassEnsureTable(PDO $db)
    {
        static $done = false;
        if ($done) { return; }
        $done = true;
        try {
            $db->exec("CREATE TABLE IF NOT EXISTS quiz_passes (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                module_id INT NOT NULL,
                score INT NOT NULL,
                total INT NOT NULL,
                pct INT NOT NULL,
                passed TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                INDEX (user_id, module_id)
            ) DEFAULT CHARSET=utf8mb4");
        } catch (Exception $e) { /* non bloquant */ }
    }
}

if (!function_exists('quizPassMark')) {
    /** Note de réussite, en % (réglable ; défaut 70). */
    function quizPassMark(PDO $db)
    {
        $p = (int) (function_exists('widgetGet') ? widgetGet($db, 'quiz_pass_mark', '70') : 70);
        return max(1, min(100, $p ?: 70));
    }

    /** Enregistre un passage de quiz et renvoie true si c'est une réussite. */
    function quizRecordResult(PDO $db, $userId, $moduleId, $score, $total)
    {
        quizPassEnsureTable($db);
        $userId = (int) $userId;
        $moduleId = (int) $moduleId;
        if ($userId <= 0 || $moduleId <= 0 || $total <= 0) { return false; }
        $pct = (int) round($score * 100 / $total);
        $passed = $pct >= quizPassMark($db) ? 1 : 0;
        try {
            $db->prepare("INSERT INTO quiz_passes (user_id, module_id, score, total, pct, passed, created_at)
                          VALUES (?, ?, ?, ?, ?, ?, ?)")
               ->execute([$userId, $moduleId, (int) $score, (int) $total, $pct, $passed, date('Y-m-d H:i:s')]);
        } catch (Exception $e) {}
        return (bool) $passed;
    }

    /**
     * L'utilisateur a-t-il VALIDÉ le quiz de cette formation ?
     * On cherche le quiz sur le module lui-même ET sur ses sous-modules (le quiz vit sur le guide).
     * Vrai s'il n'y a AUCUN quiz (rien à valider).
     */
    function quizUserPassed(PDO $db, $parentModuleId, $userId)
    {
        quizPassEnsureTable($db);
        $userId = (int) $userId;
        $parentModuleId = (int) $parentModuleId;
        if ($userId <= 0 || $parentModuleId <= 0) { return true; }

        // Les modules concernés : la formation + ses sous-modules (guide, vidéo).
        $ids = [$parentModuleId];
        try {
            $st = $db->prepare("SELECT id FROM modules WHERE parent_id = ?");
            $st->execute([$parentModuleId]);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $cid) { $ids[] = (int) $cid; }
        } catch (Exception $e) {}

        // Y a-t-il seulement un quiz à valider ?
        $ph = implode(',', array_fill(0, count($ids), '?'));
        try {
            $st = $db->prepare("SELECT COUNT(*) FROM modules WHERE id IN ($ph) AND quiz_json IS NOT NULL AND quiz_json <> ''");
            $st->execute($ids);
            if ((int) $st->fetchColumn() === 0) { return true; } // pas de quiz → rien à valider
        } catch (Exception $e) { return true; }

        // Une réussite enregistrée sur l'un de ces modules ?
        try {
            $st = $db->prepare("SELECT COUNT(*) FROM quiz_passes WHERE user_id = ? AND passed = 1 AND module_id IN ($ph)");
            $st->execute(array_merge([$userId], $ids));
            return (int) $st->fetchColumn() > 0;
        } catch (Exception $e) { return false; }
    }
}
