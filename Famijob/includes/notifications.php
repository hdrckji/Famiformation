<?php
// ============================================================
// notifications.php — Boîte à notif PERSONNELLE (par utilisateur) de FamiJob.
//
//   Contrairement au fil d'événements global de FamiFormation, ici chaque
//   notification est ADRESSÉE à un utilisateur précis (recipient_id).
//   Cas d'usage :
//     • un horaire est validé  -> le créateur de la demande est prévenu ;
//     • un horaire est matché   -> le créateur de la demande est prévenu.
//
//   Tout est défensif : jamais d'exception qui casse la page appelante.
// ============================================================

if (!function_exists('famijobNotifEnsureTable')) {
    function famijobNotifEnsureTable(PDO $db)
    {
        static $ready = false;
        if ($ready) {
            return true;
        }
        try {
            $db->exec(
                "CREATE TABLE IF NOT EXISTS interim_notifications (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    recipient_id INT NOT NULL,
                    type VARCHAR(30) NOT NULL DEFAULT 'info',
                    title VARCHAR(180) NOT NULL,
                    body TEXT NULL,
                    link VARCHAR(255) NULL,
                    actor_id INT NULL,
                    actor_name VARCHAR(160) NULL,
                    is_read TINYINT(1) NOT NULL DEFAULT 0,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_notif_recipient (recipient_id, is_read),
                    INDEX idx_notif_created (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
        } catch (Exception $e) {}
        $ready = true;
        return true;
    }
}

if (!function_exists('famijobNotify')) {
    /**
     * Dépose une notification pour un utilisateur.
     * @return bool true si insérée.
     */
    function famijobNotify(PDO $db, $recipientId, $type, $title, $body = '', $link = '', $actorId = null, $actorName = '')
    {
        $recipientId = (int) $recipientId;
        if ($recipientId <= 0) {
            return false;
        }
        $title = trim((string) $title);
        if ($title === '') {
            return false;
        }
        famijobNotifEnsureTable($db);
        try {
            $stmt = $db->prepare(
                "INSERT INTO interim_notifications (recipient_id, type, title, body, link, actor_id, actor_name, is_read, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 0, NOW())"
            );
            $stmt->execute([
                $recipientId,
                mb_substr((string) $type, 0, 30),
                mb_substr($title, 0, 180),
                ($body !== '' ? mb_substr((string) $body, 0, 2000) : null),
                ($link !== '' ? mb_substr((string) $link, 0, 255) : null),
                ((int) $actorId) ?: null,
                ($actorName !== '' ? mb_substr((string) $actorName, 0, 160) : null),
            ]);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
}

if (!function_exists('famijobNotifUnreadCount')) {
    function famijobNotifUnreadCount(PDO $db, $userId)
    {
        $userId = (int) $userId;
        if ($userId <= 0) {
            return 0;
        }
        famijobNotifEnsureTable($db);
        try {
            $stmt = $db->prepare("SELECT COUNT(*) FROM interim_notifications WHERE recipient_id = ? AND is_read = 0");
            $stmt->execute([$userId]);
            return (int) $stmt->fetchColumn();
        } catch (Exception $e) {
            return 0;
        }
    }
}

if (!function_exists('famijobNotifRecent')) {
    function famijobNotifRecent(PDO $db, $userId, $limit = 60)
    {
        $userId = (int) $userId;
        if ($userId <= 0) {
            return [];
        }
        famijobNotifEnsureTable($db);
        try {
            $stmt = $db->prepare(
                "SELECT * FROM interim_notifications WHERE recipient_id = ? ORDER BY created_at DESC, id DESC LIMIT ?"
            );
            $stmt->bindValue(1, $userId, PDO::PARAM_INT);
            $stmt->bindValue(2, (int) $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            return [];
        }
    }
}

if (!function_exists('famijobNotifMarkAllRead')) {
    function famijobNotifMarkAllRead(PDO $db, $userId)
    {
        $userId = (int) $userId;
        if ($userId <= 0) {
            return 0;
        }
        famijobNotifEnsureTable($db);
        try {
            $stmt = $db->prepare("UPDATE interim_notifications SET is_read = 1 WHERE recipient_id = ? AND is_read = 0");
            $stmt->execute([$userId]);
            return $stmt->rowCount();
        } catch (Exception $e) {
            return 0;
        }
    }
}

if (!function_exists('famijobNotifMarkRead')) {
    function famijobNotifMarkRead(PDO $db, $userId, $notifId)
    {
        $userId = (int) $userId;
        $notifId = (int) $notifId;
        if ($userId <= 0 || $notifId <= 0) {
            return 0;
        }
        famijobNotifEnsureTable($db);
        try {
            $stmt = $db->prepare("UPDATE interim_notifications SET is_read = 1 WHERE id = ? AND recipient_id = ?");
            $stmt->execute([$notifId, $userId]);
            return $stmt->rowCount();
        } catch (Exception $e) {
            return 0;
        }
    }
}

if (!function_exists('famijobNotifDelete')) {
    function famijobNotifDelete(PDO $db, $userId, $notifId)
    {
        $userId = (int) $userId;
        $notifId = (int) $notifId;
        if ($userId <= 0 || $notifId <= 0) {
            return 0;
        }
        famijobNotifEnsureTable($db);
        try {
            $stmt = $db->prepare("DELETE FROM interim_notifications WHERE id = ? AND recipient_id = ?");
            $stmt->execute([$notifId, $userId]);
            return $stmt->rowCount();
        } catch (Exception $e) {
            return 0;
        }
    }
}

if (!function_exists('famijobNotifDeleteAll')) {
    function famijobNotifDeleteAll(PDO $db, $userId)
    {
        $userId = (int) $userId;
        if ($userId <= 0) {
            return 0;
        }
        famijobNotifEnsureTable($db);
        try {
            $stmt = $db->prepare("DELETE FROM interim_notifications WHERE recipient_id = ?");
            $stmt->execute([$userId]);
            return $stmt->rowCount();
        } catch (Exception $e) {
            return 0;
        }
    }
}

// ------------------------------------------------------------
// Helpers "métier" : composent le message à partir d'une demande.
// ------------------------------------------------------------

if (!function_exists('famijobFormatShiftLabel')) {
    /** "lundi 21/07 · Épicerie · 08:00-12:00" à partir d'une ligne de demande. */
    function famijobFormatShiftLabel(array $request)
    {
        $dateLabel = (string) ($request['shift_date'] ?? '');
        if ($dateLabel !== '') {
            try {
                $dateLabel = (new DateTimeImmutable($dateLabel))->format('d/m/Y');
            } catch (Exception $e) {}
        }
        $parts = array_filter([
            $dateLabel,
            trim((string) ($request['department_name'] ?? '')),
            trim((string) ($request['time_slot'] ?? '')),
        ], static function ($v) { return $v !== ''; });
        return implode(' · ', $parts);
    }
}

if (!function_exists('famijobActorName')) {
    function famijobActorName(PDO $db, $userId)
    {
        $userId = (int) $userId;
        if ($userId <= 0) {
            return '';
        }
        try {
            $stmt = $db->prepare("SELECT prenom, nom FROM utilisateurs WHERE id = ? LIMIT 1");
            $stmt->execute([$userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $name = trim(trim((string) ($row['prenom'] ?? '')) . ' ' . trim((string) ($row['nom'] ?? '')));
                return $name;
            }
        } catch (Exception $e) {}
        return '';
    }
}

if (!function_exists('famijobNotifyRequestValidated')) {
    /**
     * Prévient le créateur d'une demande que son horaire a été validé.
     * @param string $decision 'approved' | 'rejected'
     */
    function famijobNotifyRequestValidated(PDO $db, $requestId, $validatorUserId, $decision = 'approved')
    {
        $requestId = (int) $requestId;
        if ($requestId <= 0) {
            return false;
        }
        try {
            $stmt = $db->prepare(
                "SELECT id, shift_date, department_name, time_slot, seats_required, created_by_user_id
                 FROM interim_shift_requests WHERE id = ? LIMIT 1"
            );
            $stmt->execute([$requestId]);
            $req = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            return false;
        }
        if (!$req) {
            return false;
        }
        $recipientId = (int) ($req['created_by_user_id'] ?? 0);
        if ($recipientId <= 0 || $recipientId === (int) $validatorUserId) {
            return false; // pas d'auto-notification
        }

        $actorName = famijobActorName($db, $validatorUserId);
        $shiftLabel = famijobFormatShiftLabel($req);
        $when = date('d/m/Y à H:i');

        if ($decision === 'rejected') {
            $title = 'Horaire refusé';
            $body = 'Votre demande d\'horaire (' . $shiftLabel . ') a été refusée'
                . ($actorName !== '' ? ' par ' . $actorName : '') . ' le ' . $when . '.';
            $type = 'validation_rejected';
        } else {
            $title = 'Horaire validé';
            $body = 'Votre demande d\'horaire (' . $shiftLabel . ') a été validée'
                . ($actorName !== '' ? ' par ' . $actorName : '') . ' le ' . $when . '.';
            $type = 'validation_approved';
        }

        return famijobNotify(
            $db,
            $recipientId,
            $type,
            $title,
            $body,
            'interim_horaires_demandes.php',
            (int) $validatorUserId,
            $actorName
        );
    }
}

if (!function_exists('famijobNotifyRequestMatched')) {
    /**
     * Prévient le créateur d'une demande qu'une place a été pourvue (matching).
     * @param string $assignedLabel nom de l'étudiant / de la personne assignée
     */
    function famijobNotifyRequestMatched(PDO $db, $requestId, $assignerUserId, $assignedLabel = '', $count = 1)
    {
        $requestId = (int) $requestId;
        if ($requestId <= 0) {
            return false;
        }
        try {
            $stmt = $db->prepare(
                "SELECT id, shift_date, department_name, time_slot, seats_required, created_by_user_id
                 FROM interim_shift_requests WHERE id = ? LIMIT 1"
            );
            $stmt->execute([$requestId]);
            $req = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            return false;
        }
        if (!$req) {
            return false;
        }
        $recipientId = (int) ($req['created_by_user_id'] ?? 0);
        if ($recipientId <= 0 || $recipientId === (int) $assignerUserId) {
            return false; // pas d'auto-notification
        }

        $actorName = famijobActorName($db, $assignerUserId);
        $shiftLabel = famijobFormatShiftLabel($req);
        $when = date('d/m/Y à H:i');
        $assignedLabel = trim((string) $assignedLabel);
        $count = max(1, (int) $count);

        $title = 'Horaire matché';
        if ($count > 1) {
            $body = $count . ' places de votre demande d\'horaire (' . $shiftLabel . ') ont été pourvues'
                . ($actorName !== '' ? ' par ' . $actorName : '') . ' le ' . $when . '.';
        } else {
            $body = 'Une place de votre demande d\'horaire (' . $shiftLabel . ') a été pourvue'
                . ($assignedLabel !== '' ? ' — ' . $assignedLabel : '')
                . ($actorName !== '' ? ' (par ' . $actorName . ')' : '') . ' le ' . $when . '.';
        }

        return famijobNotify(
            $db,
            $recipientId,
            'match',
            $title,
            $body,
            'interim_horaires.php',
            (int) $assignerUserId,
            $actorName
        );
    }
}
