<?php
// ============================================================
// bulk_action.php — suppression GROUPÉE réutilisable (admin only).
//   POST action=bulk_delete, entity=<module|profile|phrase|user|agence>, ids[]=..., admin_password
//   Protégé par le mot de passe admin. Sauvegardes par type (verrou / profils cœur).
// ============================================================
require_once 'config.php';
verifierConnexion($db);
require_once 'includes/modules.php'; // adminPasswordOk, requireValidCSRF

if (($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: index.php');
    exit();
}

function bulkSafeReturn($value)
{
    $value = (string) $value;
    foreach (['parametres.php', 'index.php', 'admin_agences_interim.php', 'admin.php'] as $allowed) {
        if (strpos($value, $allowed) === 0) { return $value; }
    }
    return 'parametres.php';
}

$return = bulkSafeReturn($_POST['return'] ?? 'parametres.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'bulk_delete') {
    requireValidCSRF();

    $entity = (string) ($_POST['entity'] ?? '');
    $ids = is_array($_POST['ids'] ?? null)
        ? array_values(array_unique(array_filter(array_map('intval', $_POST['ids']), function ($n) { return $n > 0; })))
        : [];

    if (empty($ids)) {
        $_SESSION['module_flash'] = "❌ Aucun élément sélectionné.";
        header('Location: ' . $return); exit();
    }
    // Les notifications sont a faible enjeu : suppression sans mot de passe (admin deja verifie).
    // Tout le reste (modules, utilisateurs, agences...) exige le mot de passe admin.
    if ($entity !== 'event' && !adminPasswordOk($db, (string) ($_POST['admin_password'] ?? ''))) {
        $_SESSION['module_flash'] = "❌ Mot de passe incorrect : rien supprimé.";
        header('Location: ' . $return); exit();
    }

    $ph = implode(',', array_fill(0, count($ids), '?'));
    $done = 0;
    $skipped = 0;

    try {
        if ($entity === 'module') {
            // On protège les modules verrouillés (comme la suppression unitaire).
            $st = $db->prepare("SELECT id FROM modules WHERE id IN ($ph) AND is_locked = 1");
            $st->execute($ids);
            $locked = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
            $del = array_values(array_diff($ids, $locked));
            $skipped = count($locked);
            if ($del) {
                // Descendants récursifs (sous-sous-modules compris).
                $all = $del; $queue = $del; $g = 0;
                while ($queue && $g++ < 10000) {
                    $qp = implode(',', array_fill(0, count($queue), '?'));
                    $cs = $db->prepare("SELECT id FROM modules WHERE parent_id IN ($qp)");
                    $cs->execute(array_values($queue));
                    $kids = array_values(array_diff(array_map('intval', $cs->fetchAll(PDO::FETCH_COLUMN)), $all));
                    $all = array_merge($all, $kids);
                    $queue = $kids;
                }
                $all = array_values(array_unique($all));
                $ap = implode(',', array_fill(0, count($all), '?'));
                // Nettoyage COMPLET du stockage (PDF, vidéo + source, sous-titres .vtt/.srt,
                // images du PDF, images de l'éditeur, icône) — voir famiModuleFileKeys().
                require_once __DIR__ . '/includes/versions.php';
                // 1) On relève les fichiers AVANT de perdre les lignes.
                $keysToKill = famiCollectModulesFileKeys($db, $all);
                $db->prepare("DELETE FROM modules WHERE id IN ($ap)")->execute($all);
                versionsPurgeForModules($db, $all);
                famiUnlinkKeys($keysToKill);
                $done = count($del);
            }
        } elseif ($entity === 'profile') {
            // On protège les profils « base » (is_core) et verrouillés.
            $st = $db->prepare("SELECT id FROM profils WHERE id IN ($ph) AND (is_core = 1 OR is_locked = 1)");
            $st->execute($ids);
            $prot = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
            $del = array_values(array_diff($ids, $prot));
            $skipped = count($prot);
            if ($del) {
                $ph2 = implode(',', array_fill(0, count($del), '?'));
                $db->prepare("DELETE FROM profils WHERE id IN ($ph2)")->execute($del);
                $done = count($del);
            }
        } elseif ($entity === 'phrase') {
            $db->prepare("DELETE FROM widget_phrases WHERE id IN ($ph)")->execute($ids);
            $done = count($ids);
        } elseif ($entity === 'event') {
            $db->prepare("DELETE FROM site_events WHERE id IN ($ph)")->execute($ids);
            $done = count($ids);
        } elseif ($entity === 'user') {
            // Utilisateurs : on ne supprime JAMAIS le compte « admin » (protection).
            $st = $db->prepare("SELECT id FROM utilisateurs WHERE id IN ($ph) AND identifiant <> 'admin'");
            $st->execute($ids);
            $del = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
            $skipped = count($ids) - count($del);
            if ($del) {
                $p2 = implode(',', array_fill(0, count($del), '?'));
                $db->prepare("DELETE FROM utilisateurs WHERE id IN ($p2)")->execute($del);
                $done = count($del);
            }
        } elseif ($entity === 'agence') {
            // Agences interim (table interim_agences). On PROTEGE celles qui ont encore des
            // comptes utilisateurs lies (il faut retirer les comptes d'abord).
            $keep = [];
            $st = $db->prepare("SELECT DISTINCT agence_id FROM interim_agence_users WHERE agence_id IN ($ph)");
            $st->execute($ids);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $aid) { $keep[(int) $aid] = true; }
            $del = array_values(array_filter($ids, function ($x) use ($keep) { return empty($keep[$x]); }));
            $skipped = count($ids) - count($del);
            if ($del) {
                $p2 = implode(',', array_fill(0, count($del), '?'));
                $db->prepare("DELETE FROM interim_agences WHERE id IN ($p2)")->execute($del);
                $done = count($del);
            }
        } else {
            $_SESSION['module_flash'] = "❌ Type d'élément inconnu.";
            header('Location: ' . $return); exit();
        }
    } catch (Exception $e) {
        $_SESSION['module_flash'] = "❌ Erreur pendant la suppression : rien garanti.";
        header('Location: ' . $return); exit();
    }

    $msg = "✅ " . $done . " élément" . ($done > 1 ? 's' : '') . " supprimé" . ($done > 1 ? 's' : '') . ".";
    if ($skipped > 0) {
        $msg .= " (" . $skipped . " protégé" . ($skipped > 1 ? 's' : '') . " ou verrouillé" . ($skipped > 1 ? 's' : '') . " ignoré" . ($skipped > 1 ? 's' : '') . ")";
    }
    $_SESSION['module_flash'] = $msg;
    header('Location: ' . $return); exit();
}

header('Location: ' . $return);
exit();
