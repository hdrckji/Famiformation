<?php
/* ============================================================
   ⚙️ API DU QUIZ — côté serveur (IONOS)
   Stocke les scores et les codes bonus dans /data (fichiers JSON)
   ============================================================ */

header('Content-Type: application/json; charset=utf-8');

// 🔑 Codes bonus à usage unique (les mêmes que sur tes QR codes)
$BONUS_CODES = [
  "FAMI-A7K2",
  "FAMI-B3X9",
  "FAMI-C5M1",
  "FAMI-D8R4",
  "FAMI-E2T7",
];

// 🔐 PIN admin (sert à réinitialiser les scores pendant tes tests)
$ADMIN_PIN = "2907";

$dataDir = __DIR__ . '/data';
if (!is_dir($dataDir)) { mkdir($dataDir, 0755, true); }
$scoresFile = $dataDir . '/scores.json';
$codesFile  = $dataDir . '/codes.json';

function readJson($file) {
  if (!file_exists($file)) return [];
  $fp = fopen($file, 'r');
  if (!$fp) return [];
  flock($fp, LOCK_SH);
  $content = stream_get_contents($fp);
  flock($fp, LOCK_UN);
  fclose($fp);
  $d = json_decode($content, true);
  return is_array($d) ? $d : [];
}

function writeJson($file, $data) {
  $fp = fopen($file, 'c');
  if (!$fp) return false;
  flock($fp, LOCK_EX);
  ftruncate($fp, 0);
  rewind($fp);
  fwrite($fp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
  fflush($fp);
  flock($fp, LOCK_UN);
  fclose($fp);
  return true;
}

function sortBoard(&$board) {
  usort($board, function ($a, $b) {
    if ($b['score'] !== $a['score']) return $b['score'] - $a['score'];
    return $a['time'] - $b['time']; // égalité : le plus rapide devant
  });
}

$action = $_GET['action'] ?? '';
$input  = json_decode(file_get_contents('php://input'), true) ?: [];

switch ($action) {

  // 📊 Récupérer le classement
  case 'board': {
    $board = readJson($scoresFile);
    sortBoard($board);
    echo json_encode($board, JSON_UNESCAPED_UNICODE);
    break;
  }

  // 🏁 Enregistrer un score
  case 'submit': {
    $name = trim($input['name'] ?? '');
    if (mb_strlen($name) < 2 || mb_strlen($name) > 24) {
      http_response_code(400);
      echo json_encode(['error' => 'Prénom invalide']);
      break;
    }
    $board = readJson($scoresFile);
    foreach ($board as $p) {
      if (mb_strtolower($p['name']) === mb_strtolower($name)) {
        http_response_code(409);
        echo json_encode(['error' => 'deja_joue']);
        exit;
      }
    }
    $board[] = [
      'name'    => $name,
      'score'   => max(0, intval($input['score'] ?? 0)),
      'correct' => max(0, intval($input['correct'] ?? 0)),
      'codes'   => max(0, intval($input['codes'] ?? 0)),
      'time'    => max(0, intval($input['time'] ?? 0)),
      'date'    => date('c'),
    ];
    sortBoard($board);
    writeJson($scoresFile, $board);
    echo json_encode($board, JSON_UNESCAPED_UNICODE);
    break;
  }

  // 👤 Vérifier si un prénom a déjà joué (avant de démarrer le quiz)
  case 'check': {
    $name = mb_strtolower(trim($input['name'] ?? ''));
    $board = readJson($scoresFile);
    foreach ($board as $p) {
      if (mb_strtolower($p['name']) === $name) {
        echo json_encode(['exists' => true]);
        exit;
      }
    }
    echo json_encode(['exists' => false]);
    break;
  }

  // 🎁 Valider un code bonus (usage unique, premier arrivé premier servi)
  case 'claim': {
    $code = strtoupper(trim($input['code'] ?? ''));
    $name = trim($input['name'] ?? '');
    if (!in_array($code, $BONUS_CODES, true)) {
      echo json_encode(['ok' => false, 'reason' => 'inconnu']);
      break;
    }
    $claimed = readJson($codesFile);
    if (isset($claimed[$code])) {
      echo json_encode(['ok' => false, 'reason' => 'deja_pris']);
      break;
    }
    $claimed[$code] = ['par' => $name, 'date' => date('c')];
    writeJson($codesFile, $claimed);
    echo json_encode(['ok' => true]);
    break;
  }

  // 🧹 Réinitialiser (tests) : api.php?action=reset&pin=XXXX
  case 'reset': {
    if (($_GET['pin'] ?? '') !== $ADMIN_PIN) {
      http_response_code(403);
      echo json_encode(['error' => 'PIN incorrect']);
      break;
    }
    writeJson($scoresFile, []);
    writeJson($codesFile, (object)[]);
    echo json_encode(['ok' => true, 'message' => 'Scores et codes remis à zéro']);
    break;
  }

  default: {
    http_response_code(400);
    echo json_encode(['error' => 'Action inconnue']);
  }
}
