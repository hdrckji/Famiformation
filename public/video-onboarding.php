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
    <title>Formation Vidéo - FamiFormation</title>
  <link rel="shortcut icon" type="image/x-icon" href="favicon.ico">
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Open Sans', sans-serif; background: url('background.jpg') no-repeat center center fixed; background-size: cover; margin: 0; display: flex; flex-direction: column; align-items: center; min-height: 100vh; }
        .header { text-align: center; padding: 20px; }
        .logo { max-width: 120px; margin-bottom: 10px; }
        .container { background: rgba(255, 255, 255, 0.95); max-width: 800px; width: 90%; padding: 40px; border-radius: 20px; box-shadow: 0 15px 35px rgba(0,0,0,0.2); text-align: center; margin-bottom: 30px; }
        h1 { color: #2d5a37; font-weight: 700; margin-top: 0; }
        
        /* Conteneur Vidéo */
        .video-wrapper { position: relative; padding-bottom: 56.25%; height: 0; overflow: hidden; border-radius: 15px; background: #000; box-shadow: 0 8px 20px rgba(0,0,0,0.2); margin: 25px 0; }
        #player { position: absolute; top: 0; left: 0; width: 100%; height: 100%; border: 0; }
        
        /* Bouton Quiz masqué au départ */
        #quiz-button { 
            display: none; 
            background: linear-gradient(145deg, #2d5a37, #214429); 
            color: white; 
            padding: 18px 40px; 
            text-decoration: none; 
            border-radius: 50px; 
            font-weight: bold; 
            font-size: 1.1rem; 
            transition: all 0.3s ease; 
            box-shadow: 0 8px 15px rgba(45, 90, 55, 0.3); 
        }
        #quiz-button:hover { transform: translateY(-3px); box-shadow: 0 12px 25px rgba(45, 90, 55, 0.4); }
        
        .back-link { color: #2d5a37; text-decoration: none; font-weight: bold; margin-bottom: 20px; display: block; }
        .instruction { font-style: italic; color: #666; margin-bottom: 15px; }
    </style>
</head>
<body>
    <div class="header">
        <img src="logo.png" alt="Famiflora" class="logo">
    </div>
    <div class="container">
        <a href="onboarding.php" class="back-link">⬅ Retour</a>
        <h1>Culture & Esprit Famiflora</h1>
        <p class="instruction">Veuillez regarder l'intégralité de la vidéo pour débloquer le quiz.</p>
        
        <div class="video-wrapper">
            <div id="player"></div>
        </div>

        <a href="quiz_engine.php?theme=quiz_onboarding_video" id="quiz-button">📝 Commencer le Quiz</a>
    </div>

    <script>
        // Chargement de l'API YouTube
        var tag = document.createElement('script');
        tag.src = "https://www.youtube.com/iframe_api";
        var firstScriptTag = document.getElementsByTagName('script')[0];
        firstScriptTag.parentNode.insertBefore(tag, firstScriptTag);

        var player;
        function onYouTubeIframeAPIReady() {
            player = new YT.Player('player', {
                videoId: 'LDNIwyTxZbE', // Votre ID de vidéo
                playerVars: { 
                    'rel': 0, 
                    'modestbranding': 1,
                    'controls': 1, // Désactive les contrôles
                    'disablekb': 1, // Désactive les raccourcis clavier
                    'fs': 0 // Désactive le plein écran
                },
                events: {
                    'onStateChange': onPlayerStateChange
                }
            });
        }

        // Fonction de détection de fin de vidéo
        function onPlayerStateChange(event) {
            if (event.data == YT.PlayerState.ENDED) {
                document.getElementById('quiz-button').style.display = 'inline-block';
            }
        }
    </script>
    <script src="/video-lock.js" defer></script>
</body>
</html>