<?php
require_once 'config.php';
// On passe la variable $db pour enregistrer la statistique de visite
verifierConnexion($db); 
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Formation Caisse - FamiFormation</title>
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

        nav {
            width: 100%;
            background: rgba(45, 90, 55, 0.9);
            padding: 15px 30px;
            box-sizing: border-box;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        nav a {
            color: white;
            text-decoration: none;
            font-weight: bold;
            font-size: 0.9rem;
            transition: opacity 0.2s;
        }

        nav a:hover { opacity: 0.8; }

        .container {
            max-width: 900px;
            width: 90%;
            margin: 40px auto;
            padding: 40px;
            background: rgba(255, 255, 255, 0.95);
            border-radius: 20px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.15);
            text-align: center;
        }

        h1 {
            color: #2d5a37;
            margin-bottom: 10px;
            font-weight: 700;
        }

        .instructions {
            color: #666;
            margin-bottom: 30px;
            font-style: italic;
            font-size: 1rem;
        }

        /* Conteneur Vidéo */
        .video-wrapper {
            position: relative;
            padding-bottom: 56.25%; /* Ratio 16:9 */
            height: 0;
            overflow: hidden;
            border-radius: 15px;
            background: #000;
            box-shadow: 0 8px 25px rgba(0,0,0,0.2);
            margin-bottom: 20px;
        }

        #player {
            position: absolute;
            top: 0; left: 0; width: 100%; height: 100%;
            border: 0;
        }

        /* Bouton Quiz - Design assorti au reste du site */
        #quiz-button {
            display: none; /* Changé par le JS à la fin de la vidéo */
            margin: 30px auto 0 auto;
            background: linear-gradient(145deg, #2d5a37, #214429);
            color: white;
            padding: 18px 40px;
            text-decoration: none;
            border-radius: 50px;
            font-weight: bold;
            font-size: 1.2rem;
            transition: all 0.3s ease;
            box-shadow: 0 8px 20px rgba(45, 90, 55, 0.3);
            border: none;
            cursor: pointer;
        }

        #quiz-button:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 25px rgba(45, 90, 55, 0.4);
            background: #214429;
        }
    </style>
</head>
<body>

<nav>
    <?php
    require_once 'includes/functions.php';
    $role = getCurrentRole();
    ?>
    <a href="<?php echo ($role === 'etudiant') ? 'index.php' : 'magasin.php'; ?>">← <?php echo ($role === 'etudiant') ? 'Retour à l\'accueil' : 'Retour au menu Magasin'; ?></a>
</nav>

<div class="container">
    <img src="logo.png" alt="FamiFormation" style="max-width: 150px; margin-bottom: 20px;">
    <h1>Module : Formation Caisse</h1>
    <p class="instructions">Regardez la vidéo jusqu'au bout pour débloquer votre évaluation.</p>
    <button id="playBtn" style="margin-bottom: 25px;padding:18px 40px;border-radius:50px;background:#2d5a37;color:white;font-size:1.2rem;font-weight:bold;border:none;box-shadow:0 8px 20px rgba(45,90,55,0.3);cursor:pointer;">▶ Lancer la vidéo</button>
    <div class="video-wrapper">
        <div id="player"></div>
        <div id="videoOverlay" style="position:absolute;top:0;left:0;width:100%;height:100%;z-index:2;background:transparent;cursor:not-allowed;"></div>
    </div>

    <a href="quiz_engine.php?theme=quiz_caisse" id="quiz-button">
        📝 Passer le Quiz de Validation
    </a>
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
            videoId: '0v6VW-TlFfs', 
            playerVars: { 
                'rel': 0, 
                'modestbranding': 1,
                'autoplay': 0,
                'controls': 1, // Désactive les contrôles
                'disablekb': 1, // Désactive les raccourcis clavier
                'fs': 0, // Désactive le plein écran
                'showinfo': 0,
                'iv_load_policy': 3
            },
            events: {
                'onStateChange': onPlayerStateChange
            }
        });
    }

    // Bouton pour lancer la vidéo
    document.addEventListener('DOMContentLoaded', function() {
        var btn = document.getElementById('playBtn');
        var overlay = document.getElementById('videoOverlay');
        btn.addEventListener('click', function() {
            overlay.style.display = 'none';
            btn.style.display = 'none';
            if (typeof player !== 'undefined') {
                player.playVideo();
            }
        });
    });

    function onPlayerStateChange(event) {
        if (event.data == YT.PlayerState.ENDED) {
            var btn = document.getElementById('quiz-button');
            btn.style.display = 'inline-block';
            window.scrollTo({ top: document.body.scrollHeight, behavior: 'smooth' });
        }
    }
</script>

    <script src="/video-lock.js" defer></script>
</body>
</html>