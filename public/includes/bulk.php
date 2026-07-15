<?php
// ============================================================
// bulk.php — sélection multiple + suppression groupée (ANCIENNE API, conservée).
//
//   Cette API (bulkAllTh / bulkCheck / bulkBar / bulkModalAndJs) est encore utilisée
//   par la liste des modules, des profils et des phrases du widget. Elle DÉLÈGUE
//   désormais au composant unifié includes/bulkselect.php : cases masquées, bouton
//   « Sélectionner », Maj+clic et CORBEILLE RONDE flottante (au lieu de l'ancienne
//   barre de suppression en haut de page).
//
//   Recette inchangée pour les appelants :
//     1) table class="bulk-table" data-entity="module"
//     2) En-tête : bulkAllTh() en 1re colonne du <tr> de <thead>
//     3) Chaque ligne : bulkCheck($id) en 1re colonne
//     4) Au-dessus de la table : bulkBar('module')
//     5) Une seule fois par page : bulkModalAndJs()
// ============================================================

if (!function_exists('bulkEntityLabel')) {
    /** Libellé lisible d'un type, pour les messages (« 3 modules supprimés »). */
    function bulkEntityLabel($entity)
    {
        $map = ['module' => 'module', 'profile' => 'profil', 'phrase' => 'phrase',
                'user' => 'utilisateur', 'agence' => 'agence'];
        return $map[$entity] ?? 'élément';
    }
}

// L'entité courante, posée par bulkBar() puis lue par bulkAllTh()/bulkCheck().
$GLOBALS['__bulk_entity'] = $GLOBALS['__bulk_entity'] ?? 'module';

if (!function_exists('bulkAllTh')) {
    function bulkAllTh()
    {
        $e = htmlspecialchars((string) ($GLOBALS['__bulk_entity'] ?? 'module'), ENT_QUOTES);
        // « Tout sélectionner » du groupe (masqué tant que le mode sélection n'est pas actif).
        echo '<th class="bulk-col" style="text-align:center; width:34px;">'
            . '<input type="checkbox" class="bulk-all" data-bulk="' . $e . '" title="Tout sélectionner"></th>';
    }
}

if (!function_exists('bulkCheck')) {
    function bulkCheck($id)
    {
        $e = htmlspecialchars((string) ($GLOBALS['__bulk_entity'] ?? 'module'), ENT_QUOTES);
        echo '<td class="bulk-col" style="text-align:center;">'
            . '<input type="checkbox" class="bulk-cb" data-bulk="' . $e . '" value="' . (int) $id . '"></td>';
    }
}

if (!function_exists('bulkBar')) {
    function bulkBar($entity, $label = '')
    {
        $GLOBALS['__bulk_entity'] = (string) $entity;
        $e = htmlspecialchars((string) $entity, ENT_QUOTES);
        $lbl = htmlspecialchars(bulkEntityLabel((string) $entity), ENT_QUOTES);
        // Un simple bouton « Sélectionner » : il fait apparaître les cases. La suppression
        // se fait ensuite via la corbeille flottante (composant unifié).
        echo '<div class="bulk-bar" style="margin:0 0 10px;">'
            . '<button type="button" class="btn bulk-toggle" style="background:#eef7f0; color:#2d5a37; border:1px solid #cfe3d5;"'
            . ' data-bulk-toggle="' . $e . '" data-bulk-entity="' . $e . '" data-bulk-label="' . $lbl . '">☑️ Sélectionner</button>'
            . '</div>';
    }
}

if (!function_exists('bulkModalAndJs')) {
    function bulkModalAndJs($return = 'parametres.php')
    {
        require_once __DIR__ . '/bulkselect.php';
        echo bulkAssets(); // corbeille flottante + fenêtre de confirmation + JS (une fois par page)
    }
}
