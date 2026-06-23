<?php
require_once 'config.php';
verifierConnexion($db);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Formation - Les Chrysanthèmes - Famiflora</title>
    <link rel="shortcut icon" type="image/x-icon" href="favicon.ico">
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Open Sans', sans-serif; background: url('background.jpg') no-repeat center center fixed; background-size: cover; margin: 0; display: flex; flex-direction: column; align-items: center; min-height: 100vh; }
        .container { background: rgba(255, 255, 255, 0.95); max-width: 800px; width: 90%; padding: 40px; border-radius: 20px; box-shadow: 0 15px 35px rgba(0,0,0,0.2); text-align: center; margin-top: 50px; }
        h1 { color: #2d5a37; margin-bottom: 20px; }
        .video-wrapper { position: relative; padding-bottom: 56.25%; height: 0; overflow: hidden; border-radius: 15px; background: #000; margin: 25px 0; }
        #player { position: absolute; top: 0; left: 0; width: 100%; height: 100%; border: 0; }
        .video-overlay { position: absolute; top: 0; left: 0; width: 100%; height: 100%; z-index: 10; background: rgba(0,0,0,0); cursor: default; }
        .btn-start { background: #2d5a37; color: white; padding: 15px 30px; border: none; border-radius: 50px; font-weight: bold; cursor: pointer; font-size: 1.1rem; box-shadow: 0 4px 10px rgba(0,0,0,0.2); }
        .btn-start:disabled { background: #ccc; cursor: not-allowed; }
        .instruction { font-style: italic; color: #666; margin-top: 10px; }

        /* Styles Modale */
        .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); z-index: 1000; justify-content: center; align-items: center; }
        .modal-content { background: white; padding: 40px; border-radius: 20px; text-align: center; animation: popIn 0.4s ease; max-width: 400px; }
        @keyframes popIn { 0% { transform: scale(0.5); opacity: 0; } 100% { transform: scale(1); opacity: 1; } }
        .btn-continue { background: #2d5a37; color: white; padding: 12px 25px; border: none; border-radius: 50px; font-weight: bold; cursor: pointer; margin-top: 20px; }
    </style>
</head>
<body>
    <div id="successModal" class="modal-overlay">
        <div class="modal-content">
            <h2 style="color: #2d5a37; margin-top: 0;">Félicitations !</h2>
            <p>Cette formation est désormais validée. Tu peux passer à la suite de ton parcours.</p>
            <button class="btn-continue" onclick="window.location.href='green.php'">Continuer mon parcours</button>
        </div>
    </div>

    <div class="container">
        <h1>Les Chrysanthèmes</h1>
        <div class="video-wrapper">
            <div id="player"></div>
            <div class="video-overlay" id="overlay"></div>
        </div>
        <button id="start-btn" class="btn-start" onclick="startVideo()">▶ Lancer la formation</button>
        <p class="instruction" id="status-msg">La vidéo doit être vue entièrement pour valider l'étape.</p>
    </div>

    <script>
        var tag = document.createElement('script');
        tag.src = "https://www.youtube.com/iframe_api";
        var firstScriptTag = document.getElementsByTagName('script')[0];
        firstScriptTag.parentNode.insertBefore(tag, firstScriptTag);

        var player;
        function onYouTubeIframeAPIReady() {
            player = new YT.Player('player', {
                videoId: 'ZH3yjbMTGOQ',
                playerVars: { 'controls': 1, 'disablekb': 1, 'rel': 0, 'modestbranding': 1 },
                events: { 'onStateChange': onPlayerStateChange }
            });
        }

        function startVideo() {
            player.playVideo();
            document.getElementById('start-btn').disabled = true;
            document.getElementById('start-btn').innerText = "Vidéo en cours...";
        }

        function onPlayerStateChange(event) {
            if (event.data == YT.PlayerState.ENDED) {
                fetch('valider_vue.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'nom_page=chrysanthemes'
                }).then(() => {
                    document.getElementById('successModal').style.display = 'flex';
                });
            }
        }
    </script>
    <script src="/video-lock.js" defer></script>
</body>
</html>