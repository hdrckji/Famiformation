<?php
// Endpoint leger : renvoie le nombre de notifications non lues de l'utilisateur.
// Utilise par le polling "live" de la cloche (aucun rechargement de page).
require_once 'config.php';
require_once __DIR__ . '/includes/notifications.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$unread = 0;
$role = (string) ($_SESSION['role'] ?? '');
if (!empty($_SESSION['user_id']) && in_array($role, ['admin', 'teamcoach'], true)) {
    $unread = famijobNotifUnreadCount($db, (int) $_SESSION['user_id']);
}

echo json_encode(['unread' => (int) $unread]);
