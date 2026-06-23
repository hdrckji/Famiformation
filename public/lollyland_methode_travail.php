<?php
require_once 'config.php';
verifierConnexion($db);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Méthode de travail - Lollyland</title>
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
        .header { text-align: center; padding: 40px 20px 20px; }
        .logo { max-width: 180px; margin-bottom: 20px; filter: drop-shadow(0 3px 6px rgba(0,0,0,0.15)); }
        h1 {
            color: #2d5a37;
            background: rgba(255, 255, 255, 0.9);
            padding: 12px 30px;
            border-radius: 30px;
            font-size: 1.6rem;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            display: inline-block;
        }
        .content-box {
            width: 90%;
            max-width: 900px;
            background: rgba(255, 255, 255, 0.94);
            border-radius: 20px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.12);
            padding: 30px;
            margin-top: 20px;
            text-align: center;
        }
        .videos-container {
            display: flex;
            flex-wrap: wrap;
            gap: 50px;
            justify-content: center;
            margin-top: 0;
        }
        .video-wrapper {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 12px;
        }
        .video-wrapper span {
            font-weight: 600;
            color: #2d5a37;
            font-size: 1rem;
        }
        .video-wrapper iframe {
            width: 600px;
            height: 1070px;
            border-radius: 16px;
            box-shadow: 0 8px 24px rgba(0,0,0,0.15);
            border: none;
        }
        @media (max-width: 1400px) {
            .video-wrapper iframe {
                width: 500px;
                height: 890px;
            }
        }
        @media (max-width: 1100px) {
            .video-wrapper iframe {
                width: 420px;
                height: 750px;
            }
        }
        @media (max-width: 600px) {
            .videos-container {
                flex-direction: column;
                gap: 30px;
            }
            .video-wrapper iframe {
                width: 100%;
                max-width: 450px;
                height: 800px;
            }
        }
        .back-link {
            margin-top: 35px;
            margin-bottom: 30px;
            color: #2d5a37;
            text-decoration: none;
            font-weight: bold;
            background: rgba(255, 255, 255, 0.9);
            padding: 12px 25px;
            border-radius: 25px;
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
            display: inline-block;
        }
        .back-link:hover { background: #2d5a37; color: white; }
    </style>
</head>
<body>

    <div class="header">
        <img src="logo.png" alt="Famiflora" class="logo">
        <br>
        <h1>Méthode de travail</h1>
    </div>

    <div class="videos-container">
        <div class="video-wrapper">
            <span>Vidéo 1</span>
            <iframe
                src="https://www.youtube.com/embed/iqxs2hie510"
                allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                allowfullscreen>
            </iframe>
        </div>
        <div class="video-wrapper">
            <span>Vidéo 2</span>
            <iframe
                src="https://www.youtube.com/embed/xDIs21sERCg"
                allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                allowfullscreen>
            </iframe>
        </div>
    </div>

    <a href="lollyland_menu.php" class="back-link">← Retour à Lollyland</a>

    <script src="/video-lock.js" defer></script>
</body>
</html>
