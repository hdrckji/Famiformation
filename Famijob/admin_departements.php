<?php
// La gestion des départements vit désormais dans le hub Paramètres.
require_once 'config.php';
verifierConnexion($db);
header('Location: parametres.php?section=departements');
exit();
