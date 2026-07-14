<?php
require_once 'config.php';
verifierConnexion($db);
require_once 'includes/quizz_status.php';

if (getCurrentRole() === 'etudiant' && !hasUnlockedOnboarding(getCurrentUserId())) {
    header('Location: index.php');
    exit();
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Onboarding — FamiFormation</title>
  <link rel="shortcut icon" type="image/x-icon" href="favicon.ico">
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Open Sans', sans-serif;
            background: url('background.jpg') no-repeat center center fixed;
            background-size: cover;
            margin: 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            min-height: 100vh;
            color: #333;
        }

        .header {
            text-align: center;
            padding: 40px 20px;
        }

        .logo {
            max-width: 150px;
            margin-bottom: 15px;
            filter: drop-shadow(0 2px 4px rgba(0,0,0,0.1));
        }

        h1 {
            color: #2d5a37;
            background: rgba(255, 255, 255, 0.8);
            padding: 10px 25px;
            border-radius: 30px;
            font-size: 1.5rem;
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
        }

        .tiles-container {
            display: flex;
            justify-content: center;
            gap: 30px;
            width: 90%;
            max-width: 800px;
            padding: 20px;
            flex-wrap: wrap;
        }

        .tile {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 20px;
            padding: 50px 30px;
            text-align: center;
            text-decoration: none;
            color: #333;
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
            flex: 1;
            min-width: 280px;
            display: flex;
            flex-direction: column;
            align-items: center;
            border: 2px solid transparent;
        }

        .tile:hover {
            transform: translateY(-10px);
            border-color: #7fa563;
            box-shadow: 0 15px 35px rgba(0,0,0,0.2);
        }

        .tile-icon { font-size: 60px; margin-bottom: 20px; }
        .tile-title { font-size: 1.4rem; font-weight: 700; color: #2d5a37; }
        .tile-desc { margin-top: 10px; font-size: 0.9rem; color: #666; }

        .back-link {
            margin-top: 50px;
            color: #2d5a37;
            text-decoration: none;
            font-weight: bold;
            background: white;
            padding: 12px 25px;
            border-radius: 25px;
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
            transition: all 0.3s;
        }

        .back-link:hover { background: #2d5a37; color: white; }
    </style>
</head>
<body>
<?php
    require_once __DIR__ . '/includes/topbar.php';
    famiTopbar($db, ['back' => 'index.php', 'title' => 'Onboarding']);
?>

    <div class="header">
        <img src="logo.png" alt="FamiFormation" class="logo">
        <h1><?= t('Intégration : Bienvenue chez Famiflora', 'Onthaal: welkom bij Famiflora') ?></h1>
    </div>

    <div class="tiles-container">
        <a href="view-pdf-onboarding.php" class="tile">
            <span class="tile-icon">📖</span>
            <div class="tile-title"><?= t("Livret d'accueil", 'Onthaalbrochure') ?></div>
            <div class="tile-desc"><?= t('Consulter le guide de bienvenue (PDF)', 'De welkomstgids bekijken (PDF)') ?></div>
        </a>

        <a href="video-onboarding.php" class="tile">
            <span class="tile-icon">🎥</span>
            <div class="tile-title"><?= t('Vidéo', 'Video') ?></div>
            <div class="tile-desc"><?= t("Découvrir l'entreprise", 'Het bedrijf ontdekken') ?></div>
        </a>
    </div>


</body>
</html>