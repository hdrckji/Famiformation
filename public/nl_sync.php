<?php
// ============================================================
// nl_sync.php — worker CLI : régénère la version NL d'un module.
//   Lancé en tâche de fond après chaque enregistrement de contenu
//   (guide, quiz, titre) → le néerlandais suit automatiquement le français.
//
//   Usage :  php nl_sync.php <idModule> [force]
//
//   Volontairement silencieux et sans effet de bord : si la traduction échoue,
//   le module garde son FR (qui reste affiché) et l'empreinte n'est pas mémorisée,
//   donc une prochaine tentative réessaiera.
// ============================================================

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI uniquement');
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/modules.php';
require_once __DIR__ . '/includes/ia_settings.php';
require_once __DIR__ . '/includes/i18n_nl.php';

$moduleId = isset($argv[1]) ? (int) $argv[1] : 0;
$force = isset($argv[2]) && $argv[2] === 'force';

if ($moduleId <= 0) {
    exit(1);
}

$res = nlSyncModule($db, $moduleId, $force);
exit($res['ok'] ? 0 : 1);
