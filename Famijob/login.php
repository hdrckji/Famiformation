<?php
require_once 'config.php';
initCSRF();

if (function_exists('ensureUserAccountAccessColumns')) {
    ensureUserAccountAccessColumns($db);
}

$erreur = '';
$timeout = isset($_GET['timeout']) && (string) $_GET['timeout'] === '1';

function resolveFamijobBackgroundUrl(): string {
    $fallbackUrl = '/background-famijob.png';
    $docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? realpath((string) $_SERVER['DOCUMENT_ROOT']) : false;
    $candidates = [
        __DIR__ . '/background-famijob.png',
        dirname(__DIR__) . '/background-famijob.png',
        dirname(__DIR__) . '/public/background-famijob.png',
    ];

    foreach ($candidates as $candidate) {
        $realPath = realpath($candidate);
        if ($realPath === false || !is_file($realPath)) {
            continue;
        }

        $version = @filemtime($realPath) ?: time();

        if ($docRoot !== false) {
            $normalizedDocRoot = str_replace('\\', '/', $docRoot);
            $normalizedRealPath = str_replace('\\', '/', $realPath);
            if (strpos($normalizedRealPath, $normalizedDocRoot) === 0) {
                $publicPath = ltrim(substr($normalizedRealPath, strlen($normalizedDocRoot)), '/');
                return '/' . $publicPath . '?v=' . $version;
            }
        }

        return $fallbackUrl . '?v=' . $version;
    }

    return $fallbackUrl . '?v=' . time();
}

$famijobBackgroundUrl = resolveFamijobBackgroundUrl();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCSRF();

    $identifiant = trim((string) ($_POST['username'] ?? ''));
    $mdp = (string) ($_POST['password'] ?? '');

    $stmt = $db->prepare('SELECT * FROM utilisateurs WHERE identifiant = ? LIMIT 1');
    $stmt->execute([$identifiant]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && empty($user['account_activation_pending']) && !empty($user['mot_de_passe']) && password_verify($mdp, $user['mot_de_passe'])) {
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['username'] = (string) ($user['identifiant'] ?? '');
        $_SESSION['role'] = (string) ($user['role'] ?? '');
        $_SESSION['nom'] = (string) ($user['nom'] ?? '');
        $_SESSION['prenom'] = (string) ($user['prenom'] ?? '');
        $_SESSION['photo_profil'] = $user['photo_profil'] ?? null;
        $_SESSION['last_activity'] = time();
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

        $role = (string) ($user['role'] ?? '');
        if (in_array($role, ['admin', 'teamcoach'], true)) {
            header('Location: index.php');
            exit();
        }

        header('Location: ../index.php');
        exit();
    }

    $erreur = famiT('login.error.invalid');
}
?>
<!DOCTYPE html>
<html lang="<?= e(famiLang()) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e(famiT('login.page_title')) ?></title>
    <link rel="shortcut icon" type="image/x-icon" href="../favicon.ico">
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, rgba(27, 60, 38, 0.55), rgba(49, 98, 66, 0.45)), url('<?php echo e($famijobBackgroundUrl); ?>') center/cover no-repeat fixed;
            font-family: 'Open Sans', sans-serif;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
            padding: 16px;
            box-sizing: border-box;
        }

        .lang-wrap {
            position: fixed;
            top: 12px;
            right: 12px;
            z-index: 10;
        }

        .fami-lang-switcher {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: rgba(255, 255, 255, 0.2);
            border: 1px solid rgba(255, 255, 255, 0.32);
            border-radius: 999px;
            padding: 4px;
            backdrop-filter: blur(6px);
        }

        .fami-lang-option {
            display: inline-block;
            text-decoration: none;
            color: #ffffff;
            font-weight: 800;
            font-size: 0.78rem;
            letter-spacing: 0.04em;
            padding: 5px 9px;
            border-radius: 999px;
            transition: background 0.2s ease, color 0.2s ease;
        }

        .fami-lang-option.is-active {
            background: #ffffff;
            color: #163a2a;
        }

        .container {
            background: rgba(255, 255, 255, 0.96);
            border-radius: 16px;
            box-shadow: 0 12px 36px rgba(0, 0, 0, 0.25);
            padding: 34px 30px;
            max-width: 460px;
            width: 100%;
        }

        h2 {
            color: #244a32;
            font-size: 1.45rem;
            margin: 0 0 18px;
            text-align: center;
        }

        .notice {
            background: #eef6f0;
            border: 1px solid #d4e6d9;
            border-radius: 10px;
            color: #2e5d3f;
            font-size: 0.92rem;
            padding: 10px 12px;
            margin-bottom: 14px;
        }

        .error {
            background: #fdeceb;
            border: 1px solid #f7c9c5;
            border-radius: 10px;
            color: #b3362d;
            font-size: 0.92rem;
            padding: 10px 12px;
            margin-bottom: 14px;
        }

        label {
            color: #24332a;
            font-weight: 600;
            margin-bottom: 8px;
            display: block;
            font-size: 0.95rem;
        }

        input[type="text"],
        input[type="password"] {
            width: 100%;
            padding: 11px 12px;
            border: 1px solid #cfd7d2;
            border-radius: 10px;
            margin-bottom: 14px;
            font-size: 1rem;
            box-sizing: border-box;
        }

        button {
            width: 100%;
            background: #2d5a37;
            color: #fff;
            border: none;
            border-radius: 10px;
            padding: 12px;
            font-size: 1rem;
            font-weight: 700;
            cursor: pointer;
        }

        button:hover {
            background: #356f43;
        }

        .links {
            margin-top: 14px;
            text-align: center;
            font-size: 0.9rem;
        }

        .links a {
            color: #2d5a37;
            text-decoration: none;
            font-weight: 600;
        }
    </style>
</head>
<body>
    <div class="lang-wrap"><?= famiRenderLanguageSwitcher() ?></div>
    <div class="container">
        <h2><?= e(famiT('login.title')) ?></h2>

        <?php if ($timeout): ?>
            <div class="notice"><?= e(famiT('login.timeout')) ?></div>
        <?php endif; ?>

        <?php if ($erreur !== ''): ?>
            <div class="error"><?= e($erreur) ?></div>
        <?php endif; ?>

        <form method="post" action="">
            <?= csrfField() ?>
            <label for="username"><?= e(famiT('login.username')) ?></label>
            <input type="text" id="username" name="username" required autofocus>

            <label for="password"><?= e(famiT('login.password')) ?></label>
            <input type="password" id="password" name="password" required>

            <button type="submit"><?= e(famiT('login.submit')) ?></button>
        </form>

        <div class="links">
            <a href="../account_help.php"><?= e(famiT('login.help')) ?></a>
        </div>
    </div>
</body>
</html>
