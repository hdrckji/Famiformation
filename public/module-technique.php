<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Module technique - FamiFormation</title>
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
        }
        .header { text-align: center; padding: 40px 20px; }
        .logo { max-width: 150px; margin-bottom: 15px; filter: drop-shadow(0 2px 4px rgba(0,0,0,0.1)); }
        h1 {
            color: #2d5a37;
            background: rgba(255, 255, 255, 0.8);
            padding: 10px 25px;
            border-radius: 30px;
            font-size: 1.5rem;
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
        }
        .video-container {
            width: 90vw;
            max-width: 800px;
            margin: 40px auto;
            background: #fff;
            border-radius: 18px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.12);
            padding: 30px;
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        iframe {
            width: 100%;
            height: 450px;
            border: none;
            border-radius: 12px;
        }
        .back-link {
            margin-top: 40px;
            color: #2d5a37;
            text-decoration: none;
            font-weight: bold;
            background: white;
            padding: 12px 25px;
            border-radius: 25px;
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
        }
    </style>
</head>
<body>
    <div class="header">
        <img src="logo.png" alt="FamiFormation" class="logo">
        <h1>Module technique</h1>
    </div>
    <div class="video-container">
        <div style="position:relative;width:100%;height:450px;">
            <iframe id="moduleVideo" src="https://www.youtube.com/embed/NTDf_0lNJks?enablejsapi=1" allowfullscreen style="width:100%;height:100%;border-radius:12px;"></iframe>
            <div id="videoOverlay" style="position:absolute;top:0;left:0;width:100%;height:100%;z-index:2;background:transparent;cursor:not-allowed;"></div>
            <button id="playBtn" style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);z-index:3;padding:18px 40px;border-radius:50px;background:#2d5a37;color:white;font-size:1.2rem;font-weight:bold;border:none;box-shadow:0 8px 20px rgba(45,90,55,0.3);cursor:pointer;">▶ Lancer la vidéo</button>
        </div>
    </div>
    <a href="formation-caisse.php" class="back-link">← Retour à la formation caisse</a>
    <script>
    // Bouton pour lancer la vidéo
    document.addEventListener('DOMContentLoaded', function() {
        var btn = document.getElementById('playBtn');
        var overlay = document.getElementById('videoOverlay');
        var iframe = document.getElementById('moduleVideo');
        btn.addEventListener('click', function() {
            overlay.style.display = 'none';
            btn.style.display = 'none';
            // Démarrer la vidéo via l'API YouTube
            iframe.contentWindow.postMessage('{"event":"command","func":"playVideo","args":""}', '*');
        });
    });
    </script>
    <script src="/video-lock.js" defer></script>
</body>
</html>