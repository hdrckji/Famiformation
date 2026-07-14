<?php
// ============================================================
// evaluation.php — « Cette formation est évaluée » : l'interrupteur et le quiz.
//
//   POURQUOI : le drapeau « à évaluer » et le quiz vivaient sur le GUIDE (le
//   sous-module), pas sur la formation. Conséquences : impossible de rendre une
//   formation évaluable après coup sans tout réimporter, et le quiz disparaissait
//   au moindre remplacement de contenu.
//
//   ICI : la formation (le module PARENT) porte la décision. Le quiz reste stocké
//   sur le guide (c'est lui qui le sert), mais on l'active, on le génère et on le
//   supprime depuis la formation. Couper l'interrupteur NE DÉTRUIT PAS le quiz :
//   il est simplement mis de côté et peut être réactivé.
// ============================================================

if (!function_exists('evalGuideOf')) {
    /** Le sous-module « guide » d'une formation (celui qui porte le quiz), ou null. */
    function evalGuideOf(PDO $db, $parentId)
    {
        try {
            $st = $db->prepare("SELECT * FROM modules WHERE parent_id = ? AND content_kind = 'ecrit' LIMIT 1");
            $st->execute([(int) $parentId]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if ($r) { return $r; }
            // Ancien contenu non structuré : le module porte lui-même le guide.
            $st = $db->prepare("SELECT * FROM modules WHERE id = ? AND contenu_ia IS NOT NULL AND contenu_ia <> '' LIMIT 1");
            $st->execute([(int) $parentId]);
            return $st->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Exception $e) { return null; }
    }

    /** Le quiz d'une formation : [id du module qui le porte, nb de questions, évaluée ?]. */
    function evalStatus(PDO $db, $parentId)
    {
        $g = evalGuideOf($db, $parentId);
        if (!$g) { return ['module_id' => 0, 'nb' => 0, 'on' => false, 'has_content' => false]; }
        $q = json_decode((string) ($g['quiz_json'] ?? ''), true);
        return [
            'module_id'   => (int) $g['id'],
            'nb'          => (is_array($q) && !empty($q['questions'])) ? count($q['questions']) : 0,
            'on'          => !empty($g['a_evaluer']),
            'has_content' => trim((string) ($g['contenu_ia'] ?? '')) !== '',
        ];
    }

    /** Active / désactive l'évaluation. Le quiz existant est CONSERVÉ dans tous les cas. */
    function evalSetOn(PDO $db, $parentId, $on)
    {
        $g = evalGuideOf($db, $parentId);
        if (!$g) { return false; }
        try {
            $db->prepare("UPDATE modules SET a_evaluer = ? WHERE id = ? OR (parent_id = ? AND content_kind = 'video')")
               ->execute([$on ? 1 : 0, (int) $g['id'], (int) $parentId]);
            return true;
        } catch (Exception $e) { return false; }
    }

    /** Supprime le quiz d'une formation (action explicite, jamais automatique). */
    function evalDeleteQuiz(PDO $db, $parentId)
    {
        $g = evalGuideOf($db, $parentId);
        if (!$g) { return false; }
        try {
            $db->prepare("UPDATE modules SET quiz_json = NULL, quiz_json_nl = NULL WHERE id = ?")->execute([(int) $g['id']]);
            return true;
        } catch (Exception $e) { return false; }
    }

    /**
     * Génère (ou régénère) le quiz d'une formation, à partir du guide ET de la
     * transcription de la vidéo — sans rien réimporter.
     *
     * @return string message pour l'utilisateur
     */
    function evalGenerateQuiz(PDO $db, $parentId, $actorId = 0)
    {
        require_once __DIR__ . '/ai_uniformise.php';
        require_once __DIR__ . '/events.php';
        require_once __DIR__ . '/modules.php';

        $g = evalGuideOf($db, $parentId);
        if (!$g) { return "❌ Cette formation n'a pas de guide : impossible de générer un quiz."; }
        $guideJson = trim((string) ($g['contenu_ia'] ?? ''));

        $transcript = '';
        try {
            $ts = $db->prepare("SELECT transcript FROM modules WHERE parent_id = ? AND content_kind = 'video' LIMIT 1");
            $ts->execute([(int) $parentId]);
            $transcript = trim((string) $ts->fetchColumn());
        } catch (Exception $e) {}

        if ($guideJson === '' && $transcript === '') {
            return "❌ Ni guide ni transcription : il n'y a rien sur quoi interroger.";
        }

        $src = '';
        if ($guideJson !== '')  { $src .= "CONTENU ÉCRIT (le guide) :\n" . $guideJson; }
        if ($transcript !== '') { $src .= ($src !== '' ? "\n\n---\n\n" : '') . "CONTENU DE LA VIDÉO (transcription) :\n" . $transcript; }

        @set_time_limit(0);
        $qz = aiGenerateQuiz($db, $src);
        if (empty($qz['ok']) || empty($qz['quiz'])) {
            if (function_exists('logSiteError')) {
                logSiteError($db, (int) $parentId, (int) $actorId, 'quiz', (string) ($qz['error'] ?? ''));
            }
            return "⚠️ Quiz non généré : " . ($qz['error'] ?? 'erreur') . '.';
        }

        $json = json_encode($qz['quiz'], JSON_UNESCAPED_UNICODE);
        try {
            $db->prepare("UPDATE modules SET quiz_json = ?, quiz_json_nl = NULL, a_evaluer = 1 WHERE id = ?")
               ->execute([$json, (int) $g['id']]);
        } catch (Exception $e) { return "❌ Quiz généré mais non enregistré."; }

        $nb = count($qz['quiz']['questions'] ?? []);
        $msg = "✅ Quiz généré (" . $nb . " question" . ($nb > 1 ? 's' : '') . ").";

        if (function_exists('famiCountDoubts') && function_exists('logAiDoubts')) {
            $d = famiCountDoubts($json);
            if ($d > 0) {
                logAiDoubts($db, (int) $g['id'], (int) $actorId, $d, 'quiz');
                $msg .= " ⚠️ " . $d . " question" . ($d > 1 ? 's' : '') . " douteuse" . ($d > 1 ? 's' : '') . " — à trancher.";
            }
        }
        return $msg . " 👉 Relis-le avant de le proposer.";
    }
}
