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

// 🔑 Codes bonus à usage unique (les mêmes que sur tes QR codes en magasin).
// 20 codes, chacun rapporte $CODE_GRAINES graines à la PREMIÈRE personne qui le
// récupère. Chaque joueur peut en cumuler au maximum $MAX_CODES.
$BONUS_CODES = [
  "FAMI-A7K2", "FAMI-B3X9", "FAMI-C5M1", "FAMI-D8R4", "FAMI-E2T7",
  "FAMI-F6H8", "FAMI-G1J3", "FAMI-K9L2", "FAMI-M4N7", "FAMI-P5Q8",
  "FAMI-R3S6", "FAMI-T2U9", "FAMI-V7W1", "FAMI-X8Y4", "FAMI-Z5A2",
  "FAMI-B9C6", "FAMI-D1E3", "FAMI-F4G7", "FAMI-H8J5", "FAMI-K2L9",
];
$CODE_GRAINES = 15;   // graines par code bonus (comptent dans le classement)
$MAX_CODES    = 2;    // combien de codes une même personne peut cumuler

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
$jardinFile    = $dataDir . '/jardin.json';

/* ============================================================
   🌼 LE JARDIN COLLECTIF
   Les joueurs dépensent leurs graines (= leurs points, qu'ils gardent au
   classement : planter ne fait PAS reculer au classement) pour poser des
   plantes sur une grille commune. Chaque case ne se plante qu'une fois.
   ============================================================ */

// Catalogue : clé => [emoji, nom affiché, coût en graines].
// Les coûts sont pensés pour un score max d'environ 340 graines :
// un bon joueur plante 3-5 fois, un joueur moyen 2-3 fois.
$PLANTES = [
  'trefle'     => ['emoji' => '🍀', 'nom' => 'Trèfle',      'cout' => 1],
  'brin'       => ['emoji' => '🌱', 'nom' => 'Brin d\'herbe', 'cout' => 1],
  'paquerette' => ['emoji' => '🌼', 'nom' => 'Pâquerette',  'cout' => 20],
  'tulipe'     => ['emoji' => '🌷', 'nom' => 'Tulipe',      'cout' => 35],
  'lavande'    => ['emoji' => '💜', 'nom' => 'Lavande',     'cout' => 50],
  'tournesol'  => ['emoji' => '🌻', 'nom' => 'Tournesol',   'cout' => 80],
  'rosier'     => ['emoji' => '🌹', 'nom' => 'Rosier',      'cout' => 120],
  'arbre'      => ['emoji' => '🌳', 'nom' => 'Petit arbre', 'cout' => 200],
];

// Taille de la grille (8 colonnes × 6 lignes = 48 cases).
$JARDIN_CASES = 48;

// 🌿 MINI-JEU « chasse aux mauvaises herbes » : combien rapporte chaque herbe.
// Le serveur ne fait JAMAIS confiance au total envoyé par la page : il recalcule
// les graines avec CETTE table, à partir du nombre d'herbes de chaque sorte, et
// plafonne le gain par partie (anti-triche raisonnable pour un jeu bon enfant).
$HERBE_GAIN = ['normale' => 1, 'bronze' => 3, 'argent' => 6, 'or' => 12];
$HERBE_MAX_PAR_HERBE = 300;   // borne le nombre d'herbes d'une sorte par partie
$HERBE_MAX_GAIN = 80;         // gain maximum crédité en une partie

/**
 * Solde de graines DISPONIBLES pour planter :
 *   récoltées au quiz (score) + gagnées au mini-jeu (bonus) − déjà dépensées.
 * Le « bonus » n'entre PAS dans le classement (score) : le classement, et donc
 * les prix, restent basés sur le quiz uniquement.
 */
function soldeDe($p) {
  return max(0, intval($p['score'] ?? 0) + intval($p['bonus'] ?? 0) - intval($p['depensees'] ?? 0));
}

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
    // « code » = le code jardinier à 4 chiffres (secret rigolo qui sert à
    // récupérer son compte sur un autre téléphone). « nom » = Nom Prénom, saisi
    // facultativement, utile pour remettre les prix aux vrais gagnants.
    $codeJard = preg_replace('/\D/', '', (string)($input['code'] ?? ''));
    $entree = [
      'name'      => $name,                                   // le pseudo (nom de jardinier)
      'code'      => substr($codeJard, 0, 4),                 // code secret à 4 chiffres
      'nom'       => trim(mb_substr((string)($input['nom'] ?? ''), 0, 60)),
      'score'     => max(0, intval($input['score'] ?? 0)),   // graines récoltées, définitives
      'bonus'     => 0,                                       // graines gagnées au mini-jeu
      'depensees' => 0,                                       // graines déjà plantées au jardin
      'correct'   => max(0, intval($input['correct'] ?? 0)),
      'codes'     => 0,                                       // nombre de codes bonus récupérés
      'codes_pris' => [],                                     // quels codes bonus ont été pris
      'time'      => max(0, intval($input['time'] ?? 0)),
      'date'      => date('c'),
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

  // 🌼 Le catalogue des plantes (public : affiché sur la page du jardin).
  case 'plantes': {
    echo json_encode(['plantes' => $PLANTES, 'cases' => $JARDIN_CASES], JSON_UNESCAPED_UNICODE);
    break;
  }

  // 🌳 La grille du jardin collectif (lecture seule).
  case 'jardin': {
    $j = readJson($jardinFile);
    echo json_encode(['cases' => (object)($j['cases'] ?? [])], JSON_UNESCAPED_UNICODE);
    break;
  }

  // 💰 Le solde d'un joueur qui revient (rechargement de page, autre appareil).
  case 'solde': {
    $name = mb_strtolower(trim($input['name'] ?? ''));
    $board = readJson($scoresFile);
    foreach ($board as $p) {
      if (mb_strtolower($p['name'] ?? '') === $name) {
        echo json_encode([
          'exists'    => true,
          'name'      => $p['name'],
          'recoltees' => intval($p['score'] ?? 0),
          'depensees' => intval($p['depensees'] ?? 0),
          'solde'     => soldeDe($p),
        ], JSON_UNESCAPED_UNICODE);
        exit;
      }
    }
    echo json_encode(['exists' => false]);
    break;
  }

  // 🌱 Planter : débiter les graines PUIS poser la plante.
  // Deux fichiers sont touchés (scores + jardin) : on prend les verrous l'un
  // APRÈS l'autre, jamais imbriqués (deux verrous imbriqués pris dans des ordres
  // différents par deux requêtes = blocage mutuel). Si la case est prise entre
  // les deux étapes, on rembourse — le joueur ne perd jamais de graines pour rien.
  case 'planter': {
    $name   = trim($input['name'] ?? '');
    $idx    = intval($input['case'] ?? -1);
    $plante = trim($input['plante'] ?? '');

    if (!isset($PLANTES[$plante])) { echo json_encode(['ok' => false, 'reason' => 'plante_inconnue']); break; }
    if ($idx < 0 || $idx >= $JARDIN_CASES) { echo json_encode(['ok' => false, 'reason' => 'case_invalide']); break; }
    $cout = $PLANTES[$plante]['cout'];

    // Étape 1 : débit des graines, sous verrou des scores.
    $debit = withLock($scoresFile, function (&$board, &$write) use ($name, $cout) {
      foreach ($board as &$p) {
        if (mb_strtolower($p['name'] ?? '') === mb_strtolower($name)) {
          $solde = soldeDe($p);
          if ($solde < $cout) { return ['ok' => false, 'reason' => 'solde_insuffisant', 'solde' => $solde]; }
          $p['depensees'] = intval($p['depensees'] ?? 0) + $cout;
          $write = true;
          return ['ok' => true, 'solde' => $solde - $cout];
        }
      }
      return ['ok' => false, 'reason' => 'joueur_inconnu'];
    });
    if (empty($debit['ok'])) { echo json_encode($debit, JSON_UNESCAPED_UNICODE); break; }

    // Étape 2 : pose de la plante, sous verrou du jardin.
    $pose = withLock($jardinFile, function (&$j, &$write) use ($idx, $plante, $name) {
      if (!isset($j['cases']) || !is_array($j['cases'])) { $j['cases'] = []; }
      if (isset($j['cases'][$idx])) { return ['ok' => false, 'reason' => 'case_prise']; }
      $j['cases'][$idx] = ['plante' => $plante, 'par' => $name, 'date' => date('c')];
      $write = true;
      return ['ok' => true];
    });

    if (empty($pose['ok'])) {
      // La case a été prise entre-temps : on rembourse le débit de l'étape 1.
      withLock($scoresFile, function (&$board, &$write) use ($name, $cout) {
        foreach ($board as &$p) {
          if (mb_strtolower($p['name'] ?? '') === mb_strtolower($name)) {
            $p['depensees'] = max(0, intval($p['depensees'] ?? 0) - $cout);
            $write = true;
            break;
          }
        }
        return null;
      });
      echo json_encode($pose, JSON_UNESCAPED_UNICODE);
      break;
    }
    echo json_encode(['ok' => true, 'solde' => $debit['solde']], JSON_UNESCAPED_UNICODE);
    break;
  }

  // 💸 REVENDRE une plante que J'AI plantée : la case se libère et je récupère
  // exactement ce que j'avais payé. On ne peut revendre QUE ses propres plantes
  // (vérifié par le prénom), pas celles des autres.
  case 'revendre': {
    $name = trim($input['name'] ?? '');
    $idx  = intval($input['case'] ?? -1);

    // Étape 1 : retirer la plante SI elle m'appartient, sous verrou du jardin.
    $retiree = withLock($jardinFile, function (&$j, &$write) use ($idx, $name) {
      if (!isset($j['cases'][$idx])) { return ['ok' => false, 'reason' => 'case_vide']; }
      $c = $j['cases'][$idx];
      if (mb_strtolower($c['par'] ?? '') !== mb_strtolower($name)) {
        return ['ok' => false, 'reason' => 'pas_la_mienne'];
      }
      unset($j['cases'][$idx]);
      $write = true;
      return ['ok' => true, 'plante' => $c['plante']];
    });
    if (empty($retiree['ok'])) { echo json_encode($retiree, JSON_UNESCAPED_UNICODE); break; }

    // Étape 2 : rembourser le coût (on diminue les graines dépensées), sous
    // verrou des scores. On renvoie le nouveau solde disponible.
    $cout = $PLANTES[$retiree['plante']]['cout'] ?? 0;
    $res = withLock($scoresFile, function (&$board, &$write) use ($name, $cout) {
      foreach ($board as &$p) {
        if (mb_strtolower($p['name'] ?? '') === mb_strtolower($name)) {
          $p['depensees'] = max(0, intval($p['depensees'] ?? 0) - $cout);
          $write = true;
          return ['ok' => true, 'solde' => soldeDe($p), 'rendu' => $cout];
        }
      }
      return ['ok' => true, 'solde' => null, 'rendu' => $cout];  // plante retirée quand même
    });
    echo json_encode($res, JSON_UNESCAPED_UNICODE);
    break;
  }

  // 🌿 RÉCOLTE DES MAUVAISES HERBES (mini-jeu) : la page envoie combien d'herbes
  // de chaque sorte ont été tapées ; le serveur RECALCULE les graines avec sa
  // propre table et plafonne le total, puis les crédite au « bonus » du joueur
  // (graines à planter, sans impact sur le classement).
  case 'recolte_herbes': {
    $name = trim($input['name'] ?? '');
    $h = is_array($input['herbes'] ?? null) ? $input['herbes'] : [];

    $gain = 0;
    foreach ($HERBE_GAIN as $sorte => $valeur) {
      $n = max(0, min($HERBE_MAX_PAR_HERBE, intval($h[$sorte] ?? 0)));
      $gain += $n * $valeur;
    }
    $gain = min($gain, $HERBE_MAX_GAIN);
    if ($gain <= 0) { echo json_encode(['ok' => false, 'reason' => 'rien']); break; }

    $res = withLock($scoresFile, function (&$board, &$write) use ($name, $gain) {
      foreach ($board as &$p) {
        if (mb_strtolower($p['name'] ?? '') === mb_strtolower($name)) {
          $p['bonus'] = intval($p['bonus'] ?? 0) + $gain;
          $write = true;
          return ['ok' => true, 'gain' => $gain, 'solde' => soldeDe($p)];
        }
      }
      return ['ok' => false, 'reason' => 'joueur_inconnu'];
    });
    echo json_encode($res, JSON_UNESCAPED_UNICODE);
    break;
  }

  // 🌱 RÉCUPÉRER SON COMPTE sur un autre téléphone : pseudo + code à 4 chiffres.
  // (Au quotidien, le téléphone reconnaît le joueur tout seul via son stockage
  // local ; cette action ne sert qu'au rattrapage.)
  case 'login_joueur': {
    $name  = trim($input['name'] ?? '');
    $code4 = preg_replace('/\D/', '', (string)($input['code'] ?? ''));
    $board = readJson($scoresFile);
    foreach ($board as $p) {
      if (mb_strtolower($p['name'] ?? '') === mb_strtolower($name)) {
        if ((string)($p['code'] ?? '') !== $code4) {
          echo json_encode(['exists' => true, 'mauvais_code' => true]);
          exit;
        }
        echo json_encode([
          'exists'    => true,
          'name'      => $p['name'],
          'recoltees' => intval($p['score'] ?? 0),
          'solde'     => soldeDe($p),
          'nbCodes'   => intval($p['codes'] ?? 0),
        ], JSON_UNESCAPED_UNICODE);
        exit;
      }
    }
    echo json_encode(['exists' => false]);
    break;
  }

  // 🎁 STATUT d'un code bonus (quand on scanne son QR) : existe-t-il, est-il pris,
  // et — si on donne le pseudo — est-ce MOI qui l'ai déjà (pour un message adapté) ?
  case 'code_status': {
    $bonus = strtoupper(trim($input['bonuscode'] ?? ''));
    $name  = trim($input['name'] ?? '');
    $connu = in_array($bonus, $BONUS_CODES, true);
    $pris  = false; $parMoi = false;
    if ($connu) {
      $claimed = readJson($codesFile);
      if (isset($claimed[$bonus])) {
        $pris = true;
        $parMoi = ($name !== '' && mb_strtolower($claimed[$bonus]['par'] ?? '') === mb_strtolower($name));
      }
    }
    echo json_encode(['connu' => $connu, 'pris' => $pris, 'parMoi' => $parMoi], JSON_UNESCAPED_UNICODE);
    break;
  }

  // 🎁 RÉCUPÉRER un code bonus et l'associer à son compte (+ graines).
  // Authentifié par pseudo + code à 4 chiffres. Usage unique (premier servi),
  // et maximum $MAX_CODES par personne. Les graines comptent dans le classement.
  case 'code_claim': {
    $name  = trim($input['name'] ?? '');
    $code4 = preg_replace('/\D/', '', (string)($input['code'] ?? ''));
    $bonus = strtoupper(trim($input['bonuscode'] ?? ''));

    if (!in_array($bonus, $BONUS_CODES, true)) { echo json_encode(['ok' => false, 'reason' => 'inconnu']); break; }

    // Étape 1 : authentifier + vérifier qu'il peut encore prendre un code.
    $chk = withLock($scoresFile, function (&$board, &$write) use ($name, $code4, $bonus, $MAX_CODES) {
      foreach ($board as $p) {
        if (mb_strtolower($p['name'] ?? '') === mb_strtolower($name)) {
          if ((string)($p['code'] ?? '') !== $code4) { return ['ok' => false, 'reason' => 'auth']; }
          $pris = $p['codes_pris'] ?? [];
          if (in_array($bonus, $pris, true)) { return ['ok' => false, 'reason' => 'deja_a_toi']; }
          if (count($pris) >= $MAX_CODES) { return ['ok' => false, 'reason' => 'max_atteint', 'max' => $MAX_CODES]; }
          return ['ok' => true];
        }
      }
      return ['ok' => false, 'reason' => 'joueur_inconnu'];
    });
    if (empty($chk['ok'])) {
      if (($chk['reason'] ?? '') === 'auth' || ($chk['reason'] ?? '') === 'joueur_inconnu') { http_response_code(401); }
      echo json_encode($chk, JSON_UNESCAPED_UNICODE);
      break;
    }

    // Étape 2 : réserver le code globalement (premier arrivé, premier servi).
    $prise = withLock($codesFile, function (&$claimed, &$write) use ($bonus, $name) {
      if (isset($claimed[$bonus])) { return ['ok' => false, 'reason' => 'deja_pris']; }
      $claimed[$bonus] = ['par' => $name, 'date' => date('c')];
      $write = true;
      return ['ok' => true];
    });
    if (empty($prise['ok'])) { echo json_encode($prise, JSON_UNESCAPED_UNICODE); break; }

    // Étape 3 : créditer les graines (elles comptent dans le classement).
    $res = withLock($scoresFile, function (&$board, &$write) use ($name, $bonus, $CODE_GRAINES) {
      foreach ($board as &$p) {
        if (mb_strtolower($p['name'] ?? '') === mb_strtolower($name)) {
          $p['score'] = intval($p['score'] ?? 0) + $CODE_GRAINES;
          $p['codes_pris'] = array_values(array_merge($p['codes_pris'] ?? [], [$bonus]));
          $p['codes'] = count($p['codes_pris']);
          $write = true;
          return ['ok' => true, 'gagne' => $CODE_GRAINES, 'recoltees' => intval($p['score']), 'solde' => soldeDe($p), 'nbCodes' => $p['codes']];
        }
      }
      return ['ok' => true, 'gagne' => $CODE_GRAINES];
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
    $j = readJson($jardinFile);
    echo json_encode([
      'board'     => $board,
      'codes'     => $codes,
      'questions' => lesQuestions($questionsFile, $QUESTIONS_DEFAUT),
      'jardin'    => ['cases' => (object)($j['cases'] ?? []), 'total' => $JARDIN_CASES],
      'plantes'   => $PLANTES,
    ], JSON_UNESCAPED_UNICODE);
    break;
  }

  // 🧹 Vider une case du jardin (admin) : la plante disparaît, le planteur est
  // remboursé de son coût — c'est une correction, pas une punition.
  case 'jardin_vider': {
    exigeAdmin($input);
    global $PLANTES;
    $idx = intval($input['case'] ?? -1);

    $retiree = withLock($jardinFile, function (&$j, &$write) use ($idx) {
      if (!isset($j['cases'][$idx])) { return null; }
      $c = $j['cases'][$idx];
      unset($j['cases'][$idx]);
      $write = true;
      return $c;
    });

    if (!$retiree) { echo json_encode(['ok' => false, 'reason' => 'case_vide']); break; }
    $cout = $PLANTES[$retiree['plante']]['cout'] ?? 0;
    withLock($scoresFile, function (&$board, &$write) use ($retiree, $cout) {
      foreach ($board as &$p) {
        if (mb_strtolower($p['name'] ?? '') === mb_strtolower($retiree['par'] ?? '')) {
          $p['depensees'] = max(0, intval($p['depensees'] ?? 0) - $cout);
          $write = true;
          break;
        }
      }
      return null;
    });
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    break;
  }

  // 🧹 Réinitialiser tout le jardin (admin) : grille vidée, tout le monde
  // récupère l'intégralité de ses graines (depensees remis à zéro).
  case 'jardin_reset': {
    exigeAdmin($input);
    writeJson($jardinFile, ['cases' => (object)[]]);
    withLock($scoresFile, function (&$board, &$write) {
      foreach ($board as &$p) { $p['depensees'] = 0; }
      $write = count($board) > 0;
      return null;
    });
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
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
    writeJson($jardinFile, ['cases' => (object)[]]);
    echo json_encode(['ok' => true, 'message' => 'Scores, codes et jardin remis à zéro']);
    break;
  }

  default: {
    http_response_code(400);
    echo json_encode(['error' => 'Action inconnue']);
  }
}
