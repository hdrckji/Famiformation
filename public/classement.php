<?php
// Empêcher le cache pour voir les points en temps réel
header("Cache-Control: no-cache, must-revalidate");
header("Expires: Sat, 26 Jul 1997 05:00:00 GMT");

require_once 'config.php';
verifierConnexion($db);

// On ne récupère que ceux qui ont PLUS de 0 points

$role = isset($_SESSION['role']) ? $_SESSION['role'] : 'etudiant';
$stmt = $db->prepare("SELECT nom, prenom, points FROM utilisateurs WHERE points > 0 AND role = ? ORDER BY points DESC, nom ASC LIMIT 10");
$stmt->execute([$role]);
$users = $stmt->fetchAll();

// Fonction modifiée pour n'afficher QUE Nom et Prénom
function formatNom($u) {
    if (!empty($u['nom']) || !empty($u['prenom'])) {
        return htmlspecialchars(trim($u['prenom'] . ' ' . $u['nom']));
    }
    // Si vide, on peut mettre un texte par défaut ou rien
    return ""; 
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title><?= t('Classement', 'Klassement') ?> — FamiFormation</title>
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Open Sans', sans-serif; background: url('background.jpg') no-repeat center center fixed; background-size: cover; margin: 0; padding: 20px; display: flex; flex-direction: column; align-items: center; min-height: 100vh; }
        .container { background: rgba(255, 255, 255, 0.95); max-width: 600px; width: 100%; padding: 30px; border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.2); text-align: center; }
        h1 { color: #2d5a37; margin-bottom: 10px; }
        .subtitle { color: #666; margin-bottom: 30px; }
        
        .podium { display: flex; align-items: flex-end; justify-content: center; margin: 60px 0 20px 0; height: 180px; }
        .step { width: 120px; margin: 0 5px; position: relative; color: white; font-weight: bold; display: flex; flex-direction: column; justify-content: flex-end; padding-bottom: 10px; border-radius: 10px 10px 0 0; }
        
        .first { height: 150px; background: #FFD700; order: 2; box-shadow: 0 0 20px rgba(255, 215, 0, 0.4); }
        .second { height: 110px; background: #C0C0C0; order: 1; }
        .third { height: 80px; background: #CD7F32; order: 3; }
        
        /* Ajustement des labels pour les noms longs */
        .name-label { color: #333; position: absolute; top: -50px; left: -10px; width: 140px; font-size: 0.85rem; line-height: 1.2; font-weight: 700; text-transform: capitalize; }
        .pts-label { color: #2d5a37; position: absolute; top: -22px; left: 0; width: 100%; font-size: 0.8rem; font-weight: 600; }
        
        .ranking-list { text-align: left; margin-top: 30px; border-collapse: collapse; width: 100%; }
        .ranking-list tr:hover { background: #f9f9f9; }
        .ranking-list td { padding: 12px; border-bottom: 1px solid #eee; }
        .pts { font-weight: bold; color: #2d5a37; text-align: right; }
        .btn-back { display: inline-block; margin-top: 30px; padding: 12px 30px; background: #2d5a37; color: white; text-decoration: none; border-radius: 50px; font-weight: bold; }
        .empty-msg { padding: 40px; color: #888; font-style: italic; }
    </style>
</head>
<body>
<?php
    require_once __DIR__ . '/includes/topbar.php';
    famiTopbar($db, ['back' => 'index.php', 'title' => '🏆 ' . t('Classement', 'Klassement')]);
?>
    <div class="container">
        <h1>🏆 <?= t('Classement FamiFlora', 'FamiFlora-klassement') ?></h1>
        <p class="subtitle"><?= t('Seuls les collaborateurs ayant des points apparaissent ici.', 'Alleen medewerkers met punten verschijnen hier.') ?></p>
        
        <?php if (count($users) > 0): ?>
            <div class="podium">
                <?php if(isset($users[1])): ?>
                    <div class="step second">
                        <div class="name-label"><?php echo formatNom($users[1]); ?></div>
                        <div class="pts-label"><?php echo $users[1]['points']; ?> pts</div>
                        2ème
                    </div>
                <?php endif; ?>

                <?php if(isset($users[0])): ?>
                    <div class="step first">
                        <div class="name-label" style="font-size: 1rem; color: #d9a406;">🥇 <?php echo formatNom($users[0]); ?></div>
                        <div class="pts-label" style="top: -25px; font-weight: bold;"><?php echo $users[0]['points']; ?> pts</div>
                        1er
                    </div>
                <?php endif; ?>

                <?php if(isset($users[2])): ?>
                    <div class="step third">
                        <div class="name-label"><?php echo formatNom($users[2]); ?></div>
                        <div class="pts-label"><?php echo $users[2]['points']; ?> pts</div>
                        3ème
                    </div>
                <?php endif; ?>
            </div>

            <?php if (count($users) > 3): ?>
            <table class="ranking-list">
                <?php for($i = 3; $i < count($users); $i++): ?>
                <tr>
                    <td style="width: 40px; color: #999;">#<?php echo $i+1; ?></td>
                    <td><?php echo formatNom($users[$i]); ?></td>
                    <td class="pts"><?php echo $users[$i]['points']; ?> pts</td>
                </tr>
                <?php endfor; ?>
            </table>
            <?php endif; ?>

        <?php else: ?>
            <div class="empty-msg">
                <p><?= t('Le classement est vide pour le moment.', 'Het klassement is voorlopig leeg.') ?><br><?= t('Revenez plus tard !', 'Kom later terug!') ?></p>
            </div>
        <?php endif; ?>

        <a href="index.php" class="btn-back"><?= t("Retour à l'accueil", 'Terug naar de startpagina') ?></a>
    </div>
</body>
</html>