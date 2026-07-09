<?php
// ========================================
// CONFIGURATION CENTRALE - FamiFormation
// ========================================

// 1. CHARGEMENT DES FONCTIONS ET CSRF
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/lang.php';

// Fallback défensif: évite le crash si le serveur charge une version incomplète de includes/functions.php
if (!function_exists('loadEnv')) {
    function loadEnv($filePath)
    {
        if (!is_string($filePath) || $filePath === '' || !is_file($filePath)) {
            return;
        }

        $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '#') === 0 || strpos($line, '=') === false) {
                continue;
            }

            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            if ($key === '') {
                continue;
            }

            if ((substr($value, 0, 1) === '"' && substr($value, -1) === '"') || (substr($value, 0, 1) === "'" && substr($value, -1) === "'")) {
                $value = substr($value, 1, -1);
            }

            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
            putenv($key . '=' . $value);
        }
    }
}

if (!function_exists('famiGetEnv')) {
    function famiGetEnv($key, $default = null)
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        return ($value === false || $value === null || $value === '') ? $default : $value;
    }
}

if (!function_exists('famiEnvFlag')) {
    function famiEnvFlag($key, $default = false)
    {
        $value = famiGetEnv($key, $default ? '1' : '0');
        $normalized = strtolower(trim((string) $value));
        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }
}

if (!function_exists('e')) {
    function e($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

// 2. CHARGEMENT DES VARIABLES D'ENVIRONNEMENT
loadEnv(__DIR__ . '/.env');

$appDebug = famiEnvFlag('APP_DEBUG', false);
ini_set('display_errors', $appDebug ? '1' : '0');
ini_set('display_startup_errors', $appDebug ? '1' : '0');
error_reporting($appDebug ? E_ALL : 0);

// 3. CONFIGURATION STRICTE DES SESSIONS (AVANT session_start)
$session_timeout = (int) famiGetEnv('SESSION_TIMEOUT', 7200); // 7200 secondes = 2 heures d'inactivité
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.gc_maxlifetime', $session_timeout);
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443)
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    // Configure le cookie pour qu'il s'efface à la fermeture du navigateur
    session_set_cookie_params([
        'lifetime' => 0, 
        'path' => '/',
        'domain' => '', 
        'secure' => $isHttps,
        'httponly' => true, // Empêche l'accès au cookie via JavaScript
        'samesite' => 'Lax'
    ]);
    session_start();
}

// 3bis. LANGUE (FR / NL)
initLang();

// 4. INITIALISATION CSRF
initCSRF();

// 5. VÉRIFICATION DE L'INACTIVITÉ
if (isset($_SESSION['user_id'])) {
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $session_timeout)) {
        // Session expirée : on vide et on détruit
        session_unset();
        session_destroy();
        header('Location: login.php?timeout=1');
        exit();
    }
    // Mise à jour du marqueur de temps
    $_SESSION['last_activity'] = time();
}

// Restriction forte : les comptes agence_interim peuvent uniquement acceder
// aux pages de planning interim/disponibilites et se deconnecter.
if (isset($_SESSION['user_id']) && (($_SESSION['role'] ?? '') === 'agence_interim')) {
    $requestedPath = (string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    $currentScript = basename($requestedPath !== '' ? $requestedPath : ($_SERVER['SCRIPT_NAME'] ?? ''));
    $allowedScripts = ['interim_horaires.php', 'admin_disponibilites_etudiants.php', 'logout.php', 'deco.php'];

    if (!in_array($currentScript, $allowedScripts, true)) {
        header('Location: interim_horaires.php');
        exit();
    }
}

// 6. CONNEXION À LA BASE DE DONNÉES (depuis variables d'environnement)
$host   = famiGetEnv('DB_HOST', 'localhost');
$dbname = famiGetEnv('DB_NAME', 'test');
$user   = famiGetEnv('DB_USER', 'root');
$pass   = famiGetEnv('DB_PASSWORD', '');
$fallbackHostsRaw = (string) famiGetEnv('DB_HOST_FALLBACK', '');

// Helper pour construire un DSN propre
function _makeDsn($host, $dbname) {
    return "mysql:host=$host;dbname=$dbname;charset=utf8mb4";
}

function _buildDbHostCandidates($primaryHost, $fallbackHostsRaw)
{
    $candidates = [];
    $seen = [];

    $push = static function ($value) use (&$candidates, &$seen) {
        $value = trim((string) $value);
        if ($value === '') {
            return;
        }
        $key = strtolower($value);
        if (isset($seen[$key])) {
            return;
        }
        $seen[$key] = true;
        $candidates[] = $value;
    };

    $push($primaryHost);

    if ($fallbackHostsRaw !== '') {
        foreach (explode(',', $fallbackHostsRaw) as $fallbackHost) {
            $push($fallbackHost);
        }
    }

    if (strtolower(trim((string) $primaryHost)) === 'localhost') {
        $push('127.0.0.1');
    }

    return $candidates;
}

$connectionException = null;
$dbHostCandidates = _buildDbHostCandidates($host, $fallbackHostsRaw);

foreach ($dbHostCandidates as $candidateHost) {
    try {
        $db = new PDO(_makeDsn($candidateHost, $dbname), $user, $pass);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $connectionException = null;
        break;
    } catch (Exception $e) {
        $connectionException = $e;
    }
}

if (isset($connectionException)) {
    if ($appDebug) {
        die('Erreur de connexion à la base de données : ' . e($connectionException->getMessage()));
    }

    http_response_code(500);
    die('Erreur de connexion à la base de données.');
}

// 7. SCRIPT DE SUIVI DU TEMPS
if (isset($_SESSION['user_id'])) {
    // ...fin du bloc PHP, suppression du script HTML injecté...
}

// 8. THÈME (événement / anniversaire) : fond appliqué sur TOUTES les pages HTML.
if (isset($db) && isset($_SESSION['user_id'])) {
    require_once __DIR__ . '/includes/widget.php';
    require_once __DIR__ . '/includes/theme.php';

    // Vérifie l'anniversaire une seule fois par jour et par session (léger).
    $__todayD = date('Y-m-d');
    if (($_SESSION['bday_cache_date'] ?? '') !== $__todayD) {
        $_SESSION['bday_cache_date'] = $__todayD;
        $_SESSION['is_birthday_today'] = '0';
        try {
            if (function_exists('ensureUserProfileColumns')) {
                ensureUserProfileColumns($db);
            }
            $__bstmt = $db->prepare("SELECT date_naissance FROM utilisateurs WHERE id = ? LIMIT 1");
            $__bstmt->execute([(int) $_SESSION['user_id']]);
            $__dn = (string) $__bstmt->fetchColumn();
            if ($__dn !== '' && $__dn !== '0000-00-00' && substr($__dn, 5, 5) === date('m-d')) {
                $_SESSION['is_birthday_today'] = '1';
            }
        } catch (Exception $e) {
            // pas critique
        }
    }

    // Aperçu admin persistant : ?theme=noel (ou ?theme=off pour revenir au normal).
    if (isset($_GET['theme']) && (($_SESSION['role'] ?? '') === 'admin')) {
        $__tp = (string) $_GET['theme'];
        if ($__tp === 'off' || $__tp === '') {
            unset($_SESSION['theme_preview']);
        } else {
            $_SESSION['theme_preview'] = $__tp;
        }
    }

    if (function_exists('activePageTheme')) {
        $__pageTheme = activePageTheme($db);
        if (!empty($__pageTheme)) {
            $GLOBALS['__fami_page_theme'] = $__pageTheme;
            ob_start('famiInjectPageTheme');
        }
    }
}
