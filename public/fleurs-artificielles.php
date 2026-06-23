<?php
require_once 'config.php';
verifierConnexion($db);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fleurs Artificielles - FamiFormation</title>
    <link rel="shortcut icon" type="image/x-icon" href="favicon.ico">
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Open Sans', sans-serif; background: url('background.jpg') no-repeat center center fixed; background-size: cover; margin: 0; display: flex; flex-direction: column; align-items: center; min-height: 100vh; }
        .header { text-align: center; padding: 20px; }
        .logo { max-width: 120px; margin-bottom: 10px; }
        .container { background: rgba(255, 255, 255, 0.95); max-width: 800px; width: 90%; padding: 40px; border-radius: 20px; box-shadow: 0 15px 35px rgba(0,0,0,0.2); text-align: center; margin-bottom: 30px; }
        h1 { color: #2d5a37; font-weight: 700; margin-top: 0; }
        .video-wrapper { position: relative; padding-bottom: 56.25%; height: 0; overflow: hidden; border-radius: 15px; background: #000; box-shadow: 0 8px 20px rgba(0,0,0,0.2); margin: 25px 0; }
        #player { position: absolute; top: 0; left: 0; width: 100%; height: 100%; border: 0; }
        .back-link { color: #2d5a37; text-decoration: none; font-weight: bold; margin-bottom: 20px; display: block; }
        .instruction { font-style: italic; color: #666; margin-bottom: 15px; }
    </style>
</head>
<body>
    <div class="header">
        <img src="logo.png" alt="Famiflora" class="logo">
    </div>
    <div class="container">
        <a href="deco.php" class="back-link">⬅ Retour</a>
        <h1>Fleurs Artificielles</h1>
        <p class="instruction">Veuillez regarder la vidéo ci-dessous.</p>
        <div class="video-wrapper">
            <iframe id="player" src="https://www.youtube.com/embed/aFCwkdqXcyg?rel=0&modestbranding=1&controls=1" allowfullscreen></iframe>
        </div>
        <div style="margin: 40px auto; text-align: center;">
            <a href="quiz_engine.php?theme=quiz_fleurs_artificielles" style="display:inline-block; background:#2d5a37; color:#fff; font-weight:bold; padding:18px 40px; border-radius:30px; font-size:1.2rem; text-decoration:none; box-shadow:0 4px 16px rgba(45,90,55,0.12); transition:background 0.3s;">Lancer le quizz Fleurs Artificielles (Video)</a>
        </div>
    </div>
    <script src="/video-lock.js" defer></script>
</body>
</html>
