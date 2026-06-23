<?php
require_once 'config.php';
verifierConnexion($db);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Lutte contre les escargots - Famiflora</title>
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Open Sans', sans-serif; background: #f4f7f6; display: flex; justify-content: center; padding-top: 50px; }
        .container { background: white; max-width: 800px; width: 90%; padding: 40px; border-radius: 20px; box-shadow: 0 10px 25px rgba(0,0,0,0.1); text-align: center; }
        .video-wrapper { position: relative; padding-bottom: 56.25%; height: 0; overflow: hidden; border-radius: 15px; background: #000; margin: 20px 0; }
        #player { position: absolute; top: 0; left: 0; width: 100%; height: 100%; }
        .video-overlay { position: absolute; top: 0; left: 0; width: 100%; height: 100%; z-index: 10; }
        .btn-start { background: #2d5a37; color: white; padding: 15px 30px; border: none; border-radius: 50px; font-weight: bold; cursor: pointer; font-size: 1.1rem; }
        .btn-start:disabled { background: #ccc; }
  
.modal-overlay {
    display: none;
    position: fixed;
    top: 0; left: 0; width: 100%; height: 100%;
    background: rgba(0,0,0,0.7);
    z-index: 1000;
    justify-content: center;
    align-items: center;
}

.modal-content {
    background: white;
    padding: 40px;
    border-radius: 20px;
    text-align: center;
    animation: popIn 0.4s cubic-bezier(0.68, -0.55, 0.27, 1.55);
    max-width: 400px;
}

.modal-icon {
    font-size: 50px;
    color: #2d5a37;
    margin-bottom: 20px;
}

@keyframes popIn {
    0% { transform: scale(0.5); opacity: 0; }
    100% { transform: scale(1); opacity: 1; }
}

.btn-continue {
    background: #2d5a37;
    color: white;
    padding: 12px 25px;
    border: none;
    border-radius: 50px;
    font-weight: bold;
    cursor: pointer;
    margin-top: 20px;
}    
    </style>
</head>
<body>
  <div id="successModal" class="modal-overlay">
    <div class="modal-content">
        <div class="modal-icon">🌿</div>
        <h2 style="color: #2d5a37; margin-top: 0;">Félicitations !</h2>
        <p>Tu as terminé cette formation avec succès. Elle est désormais validée dans ton parcours.</p>
        <button class="btn-continue" onclick="window.location.href='garden.php'">Continuer mon parcours</button>
    </div>
</div>
    <div class="container">
        <h1>Lutte contre les escargots et limaces</h1>
        <div class="video-wrapper">
            <div id="player"></div>
            <div class="video-overlay"></div>
        </div>
        <button id="start-btn" class="btn-start" onclick="startVideo()">▶ Lancer la formation</button>
        <p id="msg">La vidéo doit être vue entièrement pour valider l'étape.</p>
    </div>

    <script>
        var tag = document.createElement('script');
        tag.src = "https://www.youtube.com/iframe_api";
        var firstScriptTag = document.getElementsByTagName('script')[0];
        firstScriptTag.parentNode.insertBefore(tag, firstScriptTag);

        var player;
        function onYouTubeIframeAPIReady() {
            player = new YT.Player('player', {
                videoId: '7Qbj3L_CFMQ', 
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
        // Enregistrement en base de données
        fetch('valider_vue.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'nom_page=lutte_limaces' 
        }).then(() => {
            // Affiche la belle modale au lieu de l'alert()
            document.getElementById('successModal').style.display = 'flex';
        });
    }
}
    </script>
    <script src="/video-lock.js" defer></script>
</body>
</html>