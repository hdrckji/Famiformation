<?php
// ============================================================
// image_upload.php — upload d'une image DEPUIS l'éditeur visuel (AJAX).
//   Réservé à l'admin ou à l'auteur du contenu. Renvoie la clé + l'URL (JSON).
// ============================================================
require_once 'config.php';
verifierConnexion($db);
require_once 'includes/modules.php';

header('Content-Type: application/json; charset=utf-8');

$fail = function ($msg) { echo json_encode(['ok' => false, 'error' => $msg]); exit(); };

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { $fail('Méthode invalide'); }
if (!validateCSRF()) { $fail('Session expirée, rechargez la page'); }

$isAdmin = (($_SESSION['role'] ?? '') === 'admin');
$uid = (int) ($_SESSION['user_id'] ?? 0);
$id = (int) ($_POST['id'] ?? 0);
$module = $id > 0 ? getModuleById($db, $id) : null;
if (!$module || !($isAdmin || (int) ($module['contenu_by'] ?? 0) === $uid)) { $fail('Accès refusé'); }

if (empty($_FILES['image']) || ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) { $fail('Aucun fichier reçu'); }
$f = $_FILES['image'];
if ($f['size'] <= 0 || $f['size'] > 8 * 1024 * 1024) { $fail('Image trop lourde (8 Mo max)'); }

$mime = function_exists('mime_content_type') ? @mime_content_type($f['tmp_name']) : '';
$map = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
$ext = $map[$mime] ?? '';
if ($ext === '') { $fail('Format non supporté (JPG, PNG, WEBP, GIF)'); }

$base = defined('FAMI_STORAGE_BASE') ? rtrim(FAMI_STORAGE_BASE, '/') : (__DIR__ . '/uploads');
$dir = $base . '/modules/editor_img';
if (!is_dir($dir)) { @mkdir($dir, 0775, true); }

$slug = function_exists('moduleFileSlug') ? moduleFileSlug($module['nom'] ?? 'image') : 'image';
$name = $slug . '-img_' . date('Ymd_His') . '_' . substr(bin2hex(random_bytes(4)), 0, 6) . '.' . $ext;
if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) { $fail('Enregistrement impossible'); }

$key = 'modules/editor_img/' . $name;
echo json_encode(['ok' => true, 'key' => $key, 'url' => moduleFileUrl($key)]);
