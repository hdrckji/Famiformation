<?php
/* ============================================================
   ⚙️ API DU QUIZ — côté serveur (IONOS ou Railway)
   Stocke les scores et les codes bonus dans des fichiers JSON.

   SIMULTANÉITÉ : plusieurs personnes jouent en même temps (c'est même le cas
   normal le jour de l'événement). Toute opération qui MODIFIE un fichier garde
   donc UN SEUL verrou exclusif du début à la fin — lecture ET écriture comprises.
   Sinon deux joueurs qui valident à la même seconde liraient la même liste et le
   second écraserait le premier : un score perdu, ou un code bonus donné deux fois.
   ============================================================ */

header('Content-Type: application/json; charset=utf-8');

// 🛟 FILET DE SÉCURITÉ : mbstring est présent sur IONOS comme sur l'image Railway,
// mais s'il venait à manquer, mb_strlen() serait « fonction inconnue » → erreur
// fatale renvoyée en HTTP 200, c'est-à-dire un score perdu SANS que le joueur
// voie la moindre erreur. On préfère un repli ASCII (légèrement moins exact sur
// les accents) plutôt qu'une panne muette le jour de l'événement.
if (!function_exists('mb_strlen')) {
  function mb_strlen($s, $enc = null) { return strlen((string)$s); }
}
if (!function_exists('mb_strtolower')) {
  function mb_strtolower($s, $enc = null) { return strtolower((string)$s); }
}
if (!function_exists('mb_substr')) {
  function mb_substr($s, $debut, $len = null, $enc = null) {
    return $len === null ? substr((string)$s, $debut) : substr((string)$s, $debut, $len);
  }
}

// 🔑 Codes bonus à usage unique (les mêmes que sur tes QR codes)
$BONUS_CODES = [
  "FAMI-A7K2",
  "FAMI-B3X9",
  "FAMI-C5M1",
  "FAMI-D8R4",
  "FAMI-E2T7",
];

// 🔐 Identifiants admin (accès au mode admin + réinitialisation des scores)
$ADMIN_ID  = "admin";
$ADMIN_PWD = "a";
$ADMIN_PIN = $ADMIN_PWD;   // compat : ancien lien api.php?action=reset&pin=...

// 📁 OÙ SONT STOCKÉS LES SCORES.
// Sur Railway, le disque du conteneur est EFFACÉ à chaque déploiement : si on
// écrivait dans quiz/data, tout le classement disparaîtrait au prochain push.
// On utilise donc le volume persistant (le même que les uploads du site).
// En local ou sur IONOS, pas de volume : on retombe sur quiz/data, c'est correct.
$vol = getenv('RAILWAY_VOLUME_MOUNT_PATH') ?: ($_SERVER['RAILWAY_VOLUME_MOUNT_PATH'] ?? '');
$dataDir = ($vol && @is_dir($vol)) ? rtrim($vol, "/\\") . '/quiz' : __DIR__ . '/data';
// @ et re-test : deux visiteurs simultanés peuvent tenter de créer le dossier
// en même temps, le perdant recevrait un warning inutile.
if (!is_dir($dataDir)) { @mkdir($dataDir, 0755, true); }
if (!is_dir($dataDir)) {
  http_response_code(500);
  echo json_encode(['error' => 'Dossier de données inaccessible']);
  exit;
}
$scoresFile    = $dataDir . '/scores.json';
$codesFile     = $dataDir . '/codes.json';
$questionsFile = $dataDir . '/questions.json';

// ❓ QUESTIONS PAR DÉFAUT. Elles ne servent qu'AU TOUT PREMIER lancement : dès que
// tu enregistres tes questions depuis /quiz/admin, c'est questions.json qui fait
// foi et cette liste n'est plus jamais consultée.
$QUESTIONS_DEFAUT = [
  ['q' => "En quelle année l'entreprise a-t-elle été fondée ?", 'options' => ["1995", "2001", "2008", "2015"], 'correct' => 1],
  ['q' => "Combien de collègues travaillent chez nous aujourd'hui ?", 'options' => ["Moins de 30", "Entre 30 et 60", "Entre 60 et 100", "Plus de 100"], 'correct' => 2],
  ['q' => "Quel est le rayon le plus visité du magasin ?", 'options' => ["Rayon A", "Rayon B", "Rayon C", "Rayon D"], 'correct' => 0],
];

/** Nettoie une question venant du navigateur (on ne fait jamais confiance à l'envoi). */
function nettoieQuestion($item) {
  $q = trim((string)($item['q'] ?? ''));
  $opts = [];
  foreach ((array)($item['options'] ?? []) as $o) {
    $o = trim((string)$o);
    if ($o !== '') { $opts[] = mb_substr($o, 0, 120); }
  }
  $correct = (int)($item['correct'] ?? 0);
  if ($q === '' || count($opts) < 2) { return null; }          // inutilisable
  if ($correct < 0 || $correct >= count($opts)) { $correct = 0; } // index hors liste
  return ['q' => mb_substr($q, 0, 300), 'options' => $opts, 'correct' => $correct];
}

/** Les questions en vigueur (fichier si présent, sinon la liste par défaut). */
function lesQuestions($fichier, $defaut) {
  $d = readJson($fichier);
  $out = [];
  foreach ($d as $item) {
    $c = nettoieQuestion($item);
    if ($c) { $out[] = $c; }
  }
  return $out ?: $defaut;
}

/**
 * Porte d'entrée des actions d'administration.
 * Les identifiants sont revérifiés À CHAQUE appel : le « mode admin » du
 * navigateur n'est qu'un affichage, il ne protège rien. Sans cette vérification
 * ici, n'importe qui pourrait appeler l'action directement et vider le classement.
 */
function exigeAdmin($input) {
  global $ADMIN_ID, $ADMIN_PWD;
  $id  = trim($input['id'] ?? '');
  $pwd = (string)($input['pwd'] ?? '');
  if (!hash_equals($ADMIN_ID, $id) || !hash_equals($ADMIN_PWD, $pwd)) {
    http_response_code(401);
    echo json_encode(['error' => 'Acces refuse']);
    exit;
  }
}

/**
 * LECTURE SEULE (verrou partagé : plusieurs lecteurs en même temps, c'est permis).
 * À n'utiliser que quand on ne compte PAS réécrire derrière.
 */
function readJson($file) {
  if (!file_exists($file)) return [];
  $fp = @fopen($file, 'r');
  if (!$fp) return [];
  flock($fp, LOCK_SH);
  $content = stream_get_contents($fp);
  flock($fp, LOCK_UN);
  fclose($fp);
  $d = json_decode($content, true);
  return is_array($d) ? $d : [];
}

/**
 * LECTURE + MODIFICATION + ÉCRITURE, sous UN SEUL verrou exclusif.
 *
 * C'est le cœur de la protection contre les accès simultanés : tant que $fn
 * travaille, personne d'autre ne peut ni lire ni écrire ce fichier. On relit
 * DANS le verrou (pas avant), donc $fn voit toujours l'état le plus à jour.
 *
 * $fn reçoit ($data, $write) PAR RÉFÉRENCE : modifie $data et mets $write = true
 * pour que le fichier soit réécrit. Ce que $fn retourne est renvoyé tel quel.
 */
function withLock($file, callable $fn) {
  $fp = @fopen($file, 'c+');            // 'c+' : crée si absent, ne tronque pas
  if (!$fp) {
    http_response_code(500);
    return ['error' => 'Fichier de données verrouillé'];
  }
  flock($fp, LOCK_EX);                  // ⬅ attente ici si quelqu'un d'autre écrit

  rewind($fp);
  $content = stream_get_contents($fp);
  $data = json_decode($content, true);
  if (!is_array($data)) { $data = []; }

  $write = false;
  $reponse = $fn($data, $write);

  if ($write) {
    rewind($fp);
    ftruncate($fp, 0);
    fwrite($fp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    fflush($fp);
  }
  flock($fp, LOCK_UN);
  fclose($fp);
  return $reponse;
}

/** Écriture simple (sans lecture préalable) : uniquement pour la remise à zéro. */
function writeJson($file, $data) {
  $fp = @fopen($file, 'c');
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

  // 📊 Récupérer le classement (lecture seule)
  case 'board': {
    $board = readJson($scoresFile);
    sortBoard($board);
    echo json_encode($board, JSON_UNESCAPED_UNICODE);
    break;
  }

  // 🏁 Enregistrer un score.
  // La vérification du doublon et l'ajout se font DANS le même verrou : c'est ce
  // qui garantit qu'un seul « Marie » entre dans la liste, même si deux Marie
  // valident exactement au même instant.
  case 'submit': {
    $name = trim($input['name'] ?? '');
    if (mb_strlen($name) < 2 || mb_strlen($name) > 24) {
      http_response_code(400);
      echo json_encode(['error' => 'Prénom invalide']);
      break;
    }
    $entree = [
      'name'    => $name,
      'score'   => max(0, intval($input['score'] ?? 0)),
      'correct' => max(0, intval($input['correct'] ?? 0)),
      'codes'   => max(0, intval($input['codes'] ?? 0)),
      'time'    => max(0, intval($input['time'] ?? 0)),
      'date'    => date('c'),
    ];

    $res = withLock($scoresFile, function (&$board, &$write) use ($name, $entree) {
      foreach ($board as $p) {
        if (mb_strtolower($p['name'] ?? '') === mb_strtolower($name)) {
          return ['doublon' => true];
        }
      }
      $board[] = $entree;
      sortBoard($board);
      $write = true;
      return ['doublon' => false, 'board' => $board];
    });

    if (!empty($res['doublon'])) {
      http_response_code(409);
      echo json_encode(['error' => 'deja_joue']);
      break;
    }
    if (isset($res['error'])) { echo json_encode($res); break; }
    echo json_encode($res['board'], JSON_UNESCAPED_UNICODE);
    break;
  }

  // 👤 Vérifier si un prénom a déjà joué (avant de démarrer le quiz).
  // Simple confort : la vraie garantie est dans 'submit', sous verrou.
  case 'check': {
    $name = mb_strtolower(trim($input['name'] ?? ''));
    $board = readJson($scoresFile);
    foreach ($board as $p) {
      if (mb_strtolower($p['name'] ?? '') === $name) {
        echo json_encode(['exists' => true]);
        exit;
      }
    }
    echo json_encode(['exists' => false]);
    break;
  }

  // 🎁 Valider un code bonus (usage unique, premier arrivé premier servi).
  // « Premier arrivé premier servi » n'a de sens que si le test et la prise du
  // code sont indissociables : deux personnes qui scannent le MÊME QR code au
  // même moment doivent départager, pas gagner toutes les deux.
  case 'claim': {
    $code = strtoupper(trim($input['code'] ?? ''));
    $name = trim($input['name'] ?? '');
    if (!in_array($code, $BONUS_CODES, true)) {
      echo json_encode(['ok' => false, 'reason' => 'inconnu']);
      break;
    }

    $res = withLock($codesFile, function (&$claimed, &$write) use ($code, $name) {
      if (isset($claimed[$code])) {
        return ['ok' => false, 'reason' => 'deja_pris'];
      }
      $claimed[$code] = ['par' => $name, 'date' => date('c')];
      $write = true;
      return ['ok' => true];
    });

    echo json_encode($res, JSON_UNESCAPED_UNICODE);
    break;
  }

  // 🔐 Connexion admin. La vérification se fait ICI, côté serveur : ainsi le mot
  // de passe n'apparaît PAS dans le code source de la page (contrairement à
  // l'ancien PIN, que n'importe qui pouvait lire avec « afficher la source »).
  case 'login': {
    $id  = trim($input['id'] ?? '');
    $pwd = (string)($input['pwd'] ?? '');
    $ok = hash_equals($ADMIN_ID, $id) && hash_equals($ADMIN_PWD, $pwd);
    if (!$ok) {
      http_response_code(401);
      echo json_encode(['ok' => false]);
      break;
    }
    echo json_encode(['ok' => true]);
    break;
  }

  // ❓ Les questions du quiz (appelé par la page joueur au chargement).
  case 'questions': {
    echo json_encode(lesQuestions($questionsFile, $QUESTIONS_DEFAUT), JSON_UNESCAPED_UNICODE);
    break;
  }

  // ✏️ Enregistrer les questions (admin). Remplace la liste entière.
  case 'questions_save': {
    exigeAdmin($input);
    $propres = [];
    foreach ((array)($input['questions'] ?? []) as $item) {
      $c = nettoieQuestion($item);
      if ($c) { $propres[] = $c; }
    }
    if (!$propres) {
      http_response_code(400);
      echo json_encode(['error' => 'Il faut au moins une question valide (un intitulé et deux réponses).']);
      break;
    }
    writeJson($questionsFile, $propres);
    echo json_encode(['ok' => true, 'total' => count($propres)]);
    break;
  }

  // 📋 Tableau de bord admin : classement détaillé + état des codes + questions.
  case 'admin_data': {
    exigeAdmin($input);
    $board = readJson($scoresFile);
    sortBoard($board);
    $pris = readJson($codesFile);
    $codes = [];
    foreach ($BONUS_CODES as $c) {
      $codes[] = [
        'code' => $c,
        'pris' => isset($pris[$c]),
        'par'  => $pris[$c]['par'] ?? null,
        'date' => $pris[$c]['date'] ?? null,
      ];
    }
    echo json_encode([
      'board'     => $board,
      'codes'     => $codes,
      'questions' => lesQuestions($questionsFile, $QUESTIONS_DEFAUT),
    ], JSON_UNESCAPED_UNICODE);
    break;
  }

  // 🗑️ Retirer un participant du classement (erreur de prénom, test, doublon…).
  case 'player_delete': {
    exigeAdmin($input);
    $nom = trim($input['name'] ?? '');
    $res = withLock($scoresFile, function (&$board, &$write) use ($nom) {
      $avant = count($board);
      $board = array_values(array_filter($board, function ($p) use ($nom) {
        return mb_strtolower($p['name'] ?? '') !== mb_strtolower($nom);
      }));
      $write = count($board) !== $avant;
      return ['ok' => $write, 'board' => $board];
    });
    echo json_encode($res, JSON_UNESCAPED_UNICODE);
    break;
  }

  // 🧹 Réinitialiser (tests) : api.php?action=reset&pin=XXXX
  case 'reset': {
    if (!hash_equals($ADMIN_PIN, (string)($_GET['pin'] ?? ''))) {
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
