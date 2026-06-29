<?php

if (!function_exists('getCaisseQuizValidationStatus')) {
    function getCaisseQuizValidationStatus($userId)
    {
        global $db;

        $defaultStatus = [
            'required_score' => 8,
            'video_best_score' => null,
            'pdf_best_score' => null,
            'video_valid' => false,
            'pdf_valid' => false,
        ];

        if (!isset($db) || !$db instanceof PDO || !$userId) {
            return $defaultStatus;
        }

        $userId = (int) $userId;
        $requiredScore = 8;

        $videoStmt = $db->prepare("SELECT MAX(score) FROM statistiques WHERE utilisateur_id = ? AND nom_page = 'quiz_caisse' AND score IS NOT NULL");
        $videoStmt->execute([$userId]);
        $videoBestScore = $videoStmt->fetchColumn();
        $videoBestScore = $videoBestScore !== false && $videoBestScore !== null ? (int) $videoBestScore : null;

        $pdfStmt = $db->prepare("SELECT MAX(score) FROM statistiques WHERE utilisateur_id = ? AND nom_page = 'quiz_pdf' AND score IS NOT NULL");
        $pdfStmt->execute([$userId]);
        $pdfBestScore = $pdfStmt->fetchColumn();
        $pdfBestScore = $pdfBestScore !== false && $pdfBestScore !== null ? (int) $pdfBestScore : null;

        return [
            'required_score' => $requiredScore,
            'video_best_score' => $videoBestScore,
            'pdf_best_score' => $pdfBestScore,
            'video_valid' => $videoBestScore !== null && $videoBestScore >= $requiredScore,
            'pdf_valid' => $pdfBestScore !== null && $pdfBestScore >= $requiredScore,
        ];
    }
}

if (!function_exists('hasCompletedCaisseQuizzes')) {
    function hasCompletedCaisseQuizzes($userId)
    {
        $status = getCaisseQuizValidationStatus($userId);
        return $status['video_valid'] && $status['pdf_valid'];
    }
}

if (!function_exists('areAllQuizzFinished')) {
    function areAllQuizzFinished($userId)
    {
        return hasCompletedCaisseQuizzes($userId);
    }
}

if (!function_exists('hasUnlockedOnboarding')) {
    function hasUnlockedOnboarding($userId)
    {
        global $db;

        if (!isset($db) || !$db instanceof PDO || !$userId) {
            return false;
        }

        try {
            $columnStmt = $db->query("SHOW COLUMNS FROM utilisateurs LIKE 'onboarding_unlocked'");
            if (!$columnStmt->fetch()) {
                $db->exec("ALTER TABLE utilisateurs ADD COLUMN onboarding_unlocked TINYINT(1) NOT NULL DEFAULT 0 AFTER statut");
            }

            $stmt = $db->prepare("SELECT onboarding_unlocked FROM utilisateurs WHERE id = ? LIMIT 1");
            $stmt->execute([(int) $userId]);
            return (int) $stmt->fetchColumn() === 1;
        } catch (Exception $e) {
            return false;
        }
    }
}

if (!function_exists('getOnboardingQuizValidationStatus')) {
    function getOnboardingQuizValidationStatus($userId)
    {
        global $db;

        $defaultStatus = [
            'required_score' => 8,
            'video_best_score' => null,
            'pdf_best_score' => null,
            'video_valid' => false,
            'pdf_valid' => false,
        ];

        if (!isset($db) || !$db instanceof PDO || !$userId) {
            return $defaultStatus;
        }

        $userId = (int) $userId;
        $requiredScore = 8;

        $videoStmt = $db->prepare("SELECT MAX(score) FROM statistiques WHERE utilisateur_id = ? AND nom_page = 'quiz_onboarding_video' AND score IS NOT NULL");
        $videoStmt->execute([$userId]);
        $videoBestScore = $videoStmt->fetchColumn();
        $videoBestScore = $videoBestScore !== false && $videoBestScore !== null ? (int) $videoBestScore : null;

        $pdfStmt = $db->prepare("SELECT MAX(score) FROM statistiques WHERE utilisateur_id = ? AND nom_page = 'quiz_onboarding_pdf' AND score IS NOT NULL");
        $pdfStmt->execute([$userId]);
        $pdfBestScore = $pdfStmt->fetchColumn();
        $pdfBestScore = $pdfBestScore !== false && $pdfBestScore !== null ? (int) $pdfBestScore : null;

        return [
            'required_score' => $requiredScore,
            'video_best_score' => $videoBestScore,
            'pdf_best_score' => $pdfBestScore,
            'video_valid' => $videoBestScore !== null && $videoBestScore >= $requiredScore,
            'pdf_valid' => $pdfBestScore !== null && $pdfBestScore >= $requiredScore,
        ];
    }
}

if (!function_exists('hasCompletedOnboarding')) {
    function hasCompletedOnboarding($userId)
    {
        $status = getOnboardingQuizValidationStatus($userId);
        return $status['video_valid'] && $status['pdf_valid'];
    }
}