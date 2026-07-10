<?php
require_once 'config.php';
verifierConnexion($db);
require_once 'includes/modules.php';
require_once 'includes/rendezvous.php';
ensureRendezvousTables($db);

$isAdmin = (($_SESSION['role'] ?? '') === 'admin');
$userId = (int) ($_SESSION['user_id'] ?? 0);
$moduleId = (int) ($_POST['module_id'] ?? 0);
$redirect = 'module.php?id=' . $moduleId;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCSRF();
    $action = (string) ($_POST['action'] ?? '');

    // ---------- Actions ADMIN ----------
    if (in_array($action, ['create_slot', 'delete_slot', 'validate_booking', 'refuse_booking'], true)) {
        if (!$isAdmin) {
            header('Location: index.php');
            exit();
        }

        if ($action === 'create_slot') {
            $titre = trim((string) ($_POST['formation_titre'] ?? ''));
            $desc = trim((string) ($_POST['formation_desc'] ?? ''));
            $mode = (($_POST['mode'] ?? 'presentiel') === 'en_ligne') ? 'en_ligne' : 'presentiel';
            $ftype = (($_POST['formateur_type'] ?? 'interne') === 'externe') ? 'externe' : 'interne';
            $fuid = null;
            $fnom = '';
            $femail = null;
            if ($ftype === 'interne') {
                $fuid = (int) ($_POST['formateur_user_id'] ?? 0) ?: null;
                if ($fuid) {
                    $st = $db->prepare("SELECT nom, prenom, email FROM utilisateurs WHERE id = ? LIMIT 1");
                    $st->execute([$fuid]);
                    if ($u = $st->fetch(PDO::FETCH_ASSOC)) {
                        $fnom = trim(($u['prenom'] ?? '') . ' ' . ($u['nom'] ?? ''));
                        $femail = $u['email'] ?? null;
                    }
                }
            } else {
                $fnom = trim((string) ($_POST['formateur_nom'] ?? ''));
                $femail = trim((string) ($_POST['formateur_email'] ?? '')) ?: null;
            }
            $dateDebut = str_replace('T', ' ', trim((string) ($_POST['date_debut'] ?? '')));
            $dateFinRaw = trim((string) ($_POST['date_fin'] ?? ''));
            $dateFin = $dateFinRaw !== '' ? str_replace('T', ' ', $dateFinRaw) : null;
            $places = max(1, min(500, (int) ($_POST['places'] ?? 1)));
            $lieu = trim((string) ($_POST['lieu_ou_lien'] ?? ''));
            $complement = trim((string) ($_POST['complement'] ?? '')) ?: null;

            if ($titre !== '' && $dateDebut !== '' && $lieu !== '') {
                $ins = $db->prepare(
                    "INSERT INTO formation_slots (module_id, formation_titre, formation_desc, mode, formateur_type, formateur_user_id, formateur_nom, formateur_email, date_debut, date_fin, places, lieu_ou_lien, complement, created_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                );
                $ins->execute([
                    $moduleId, mb_substr($titre, 0, 200), $desc ?: null, $mode, $ftype, $fuid,
                    ($fnom !== '' ? mb_substr($fnom, 0, 150) : null), $femail, $dateDebut, $dateFin, $places, $lieu, $complement, $userId,
                ]);
                $_SESSION['module_flash'] = "✅ Créneau créé.";
                // TODO (étape 2) : notifier le formateur (message interne / email externe).
            } else {
                $_SESSION['module_flash'] = "❌ Champs obligatoires manquants (intitulé, date, lieu/lien).";
            }
        } elseif ($action === 'delete_slot') {
            $sid = (int) ($_POST['slot_id'] ?? 0);
            if ($sid > 0) {
                $db->prepare("DELETE FROM formation_bookings WHERE slot_id = ?")->execute([$sid]);
                $db->prepare("DELETE FROM formation_slots WHERE id = ?")->execute([$sid]);
                $_SESSION['module_flash'] = "✅ Créneau supprimé.";
            }
        } elseif ($action === 'validate_booking' || $action === 'refuse_booking') {
            $bid = (int) ($_POST['booking_id'] ?? 0);
            $newStatut = ($action === 'validate_booking') ? 'confirmed' : 'refused';
            if ($bid > 0) {
                $db->prepare("UPDATE formation_bookings SET statut = ? WHERE id = ?")->execute([$newStatut, $bid]);
                $_SESSION['module_flash'] = ($action === 'validate_booking') ? "✅ Inscription validée." : "Inscription refusée.";
                // TODO (étape 2) : notifier l'utilisateur (+ formateur si confirmé).
            }
        }
    }
    // ---------- Actions UTILISATEUR ----------
    elseif ($action === 'register') {
        $sid = (int) ($_POST['slot_id'] ?? 0);
        $note = trim((string) ($_POST['note'] ?? '')) ?: null;
        if ($sid > 0 && $userId > 0) {
            $st = $db->prepare("SELECT places, (SELECT COUNT(*) FROM formation_bookings b WHERE b.slot_id = s.id AND b.statut = 'confirmed') AS conf FROM formation_slots s WHERE s.id = ? LIMIT 1");
            $st->execute([$sid]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row && (int) $row['conf'] < (int) $row['places']) {
                try {
                    $db->prepare("INSERT INTO formation_bookings (slot_id, user_id, note, statut) VALUES (?, ?, ?, 'pending') ON DUPLICATE KEY UPDATE note = VALUES(note), statut = 'pending'")
                       ->execute([$sid, $userId, $note]);
                    $_SESSION['module_flash'] = "✅ Inscription envoyée — en attente de validation.";
                    // TODO (étape 2) : notifier l'admin.
                } catch (Exception $e) {
                    $_SESSION['module_flash'] = "❌ Inscription impossible.";
                }
            } else {
                $_SESSION['module_flash'] = "❌ Ce créneau est complet.";
            }
        }
    } elseif ($action === 'cancel_booking') {
        $sid = (int) ($_POST['slot_id'] ?? 0);
        if ($sid > 0 && $userId > 0) {
            $db->prepare("DELETE FROM formation_bookings WHERE slot_id = ? AND user_id = ?")->execute([$sid, $userId]);
            $_SESSION['module_flash'] = "Inscription annulée.";
        }
    }
}

header('Location: ' . $redirect);
exit();
