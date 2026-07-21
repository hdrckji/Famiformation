<?php
// ========================================
// CONFIGURATION CENTRALE - FamiJob
// ========================================

// 1. CHARGEMENT DES FONCTIONS ET CSRF
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/csrf.php';

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
$localEnvPath = __DIR__ . '/.env';
$sharedEnvPath = dirname(__DIR__) . '/public/.env';

loadEnv($localEnvPath);

// Fallback: si le .env local est absent/incomplet, reutilise celui de public.
if (famiGetEnv('DB_HOST', '') === '' || famiGetEnv('DB_NAME', '') === '' || famiGetEnv('DB_USER', '') === '') {
    loadEnv($sharedEnvPath);
}

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

if (!function_exists('famiSupportedLanguages')) {
    function famiSupportedLanguages()
    {
        return ['fr', 'nl'];
    }
}

if (!function_exists('famiSelectDefaultLanguage')) {
    function famiSelectDefaultLanguage()
    {
        $header = strtolower((string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''));
        return (strpos($header, 'nl') === 0 || strpos($header, ',nl') !== false) ? 'nl' : 'fr';
    }
}

if (!function_exists('famiNormalizeLanguage')) {
    function famiNormalizeLanguage($value)
    {
        $lang = strtolower(trim((string) $value));
        return in_array($lang, famiSupportedLanguages(), true) ? $lang : 'fr';
    }
}

if (!function_exists('famiApplyLanguageSelection')) {
    function famiApplyLanguageSelection()
    {
        if (isset($_GET['lang'])) {
            $_SESSION['fami_lang'] = famiNormalizeLanguage($_GET['lang']);
            return;
        }

        if (!isset($_SESSION['fami_lang']) || !in_array($_SESSION['fami_lang'], famiSupportedLanguages(), true)) {
            $_SESSION['fami_lang'] = famiSelectDefaultLanguage();
        }
    }
}

if (!function_exists('famiLang')) {
    function famiLang()
    {
        $lang = $_SESSION['fami_lang'] ?? 'fr';
        return famiNormalizeLanguage($lang);
    }
}

if (!function_exists('famiTranslations')) {
    function famiTranslations()
    {
        static $translations = [
            'fr' => [
                'lang.fr' => 'Français',
                'lang.nl' => 'Néerlandais',
                'login.page_title' => 'Connexion FamiJob',
                'login.title' => 'Connexion FamiJob',
                'login.timeout' => 'Votre session a expiré pour inactivité. Merci de vous reconnecter.',
                'login.error.invalid' => 'Identifiant ou mot de passe incorrect.',
                'login.username' => 'Identifiant',
                'login.password' => 'Mot de passe',
                'login.submit' => 'Se connecter',
                'login.help' => 'Identifiant ou mot de passe oublié ?',
                'index.workspace' => 'FamiJob Workspace',
                'index.logout' => 'Déconnexion',
                'index.kicker' => 'Espace de pilotage',
                'index.title' => 'FamiJob',
                'index.subtitle' => 'Gestion des horaires et des disponibilités intérim',
                'index.role_prefix' => 'Profil',
                'role.admin' => 'Administrateur',
                'role.teamcoach' => 'TeamCoach',
                'tile.open' => 'Ouvrir',
                'tile.demands.title' => 'Demandes Horaires',
                'tile.demands.desc.admin' => 'Créer, modifier ou supprimer les demandes d\'horaires pour les agences intérim.',
                'tile.demands.desc.teamcoach' => 'Consulter les demandes d\'horaires pour les agences intérim.',
                'tile.matching.title' => 'Matching',
                'tile.matching.desc.admin' => 'Assigner les étudiants aux créneaux intérim, matching manuel ou automatique.',
                'tile.matching.desc.teamcoach' => 'Assigner les étudiants aux créneaux intérim.',
                'tile.validation.title' => 'Validation demandes',
                'tile.validation.desc' => 'Valider ou refuser les demandes horaires avant leur publication dans le matching.',
                'tile.schedule.title' => 'Vue horaire',
                'tile.schedule.desc' => 'Consulter le planning hebdomadaire en lecture seule, par jours, plages horaires et départements.',
                'tile.relaunch.title' => 'Relance étudiant',
                'tile.relaunch.desc' => 'Envoyer des mails groupés selon la disponibilité et le rayon des étudiants.',
                'tile.availability.title' => 'Disponibilités Étudiants',
                'tile.availability.desc' => 'Vue par semaine et par secteur des disponibilités étudiantes.',
                'tile.interim_fixes.title' => 'Horaires Fixes Intérimaires',
                'tile.interim_fixes.desc' => 'Configurer les horaires fixes (semaine A/B) des intérimaires par département. Validation automatique, sans approbation.',
                'interim_fixes.page_title' => 'Horaires Fixes Intérimaires',
                'interim_fixes.workspace' => 'FamiJob Workspace',
                'interim_fixes.back' => 'Retour',
                'interim_fixes.sidebar.title' => 'Intérimaires',
                'interim_fixes.week_prefix' => 'Semaine',
                'interim_fixes.week.label_a' => 'Semaine A - Paire',
                'interim_fixes.week.label_b' => 'Semaine B - Impaire',
                'interim_fixes.search.placeholder' => 'Rechercher...',
                'interim_fixes.filter.agency.all' => 'Toutes les agences',
                'interim_fixes.filter.rayon.all' => 'Tous les rayons',
                'interim_fixes.empty.none' => 'Aucun intérimaire trouvé.',
                'interim_fixes.empty.help' => 'Créez d\'abord un compte via admin.php avec un rôle non-étudiant et une agence renseignée.',
                'interim_fixes.intro.select' => 'Sélectionnez un intérimaire pour encoder son horaire fixe en semaine A/B et son département.',
                'interim_fixes.user.badge' => 'Intérimaire',
                'interim_fixes.hours.week_a' => 'Semaine A',
                'interim_fixes.hours.week_b' => 'Semaine B',
                'interim_fixes.hours.total' => 'Total A + B',
                'interim_fixes.hours.encoded_suffix' => 'h encodées',
                'interim_fixes.hours.unit' => 'h',
                'interim_fixes.department.label' => 'Département',
                'interim_fixes.department.undefined' => '- Non défini -',
                'interim_fixes.department.custom_suffix' => '(personnalisé)',
                'interim_fixes.week.current_hint' => '<- semaine en cours',
                'interim_fixes.week.current_dot' => '● ',
                'interim_fixes.copy.button' => 'Copier Semaine A -> B',
                'interim_fixes.table.day' => 'Jour',
                'interim_fixes.table.active' => 'Actif',
                'interim_fixes.table.start' => 'Heure début',
                'interim_fixes.table.end' => 'Heure fin',
                'interim_fixes.status.active' => 'Actif',
                'interim_fixes.status.inactive' => 'Inactif',
                'interim_fixes.save' => 'Enregistrer l\'horaire',
                'interim_fixes.confirm.copy_a_to_b' => 'Copier tous les horaires de la Semaine A vers la Semaine B ?',
                'interim_fixes.message.saved' => 'Horaire fixe enregistré avec succès.',
                'interim_fixes.day.1' => 'Lundi',
                'interim_fixes.day.2' => 'Mardi',
                'interim_fixes.day.3' => 'Mercredi',
                'interim_fixes.day.4' => 'Jeudi',
                'interim_fixes.day.5' => 'Vendredi',
                'interim_fixes.day.6' => 'Samedi',
                'interim_fixes.day.7' => 'Dimanche',
            ],
            'nl' => [
                'lang.fr' => 'Frans',
                'lang.nl' => 'Nederlands',
                'login.page_title' => 'Aanmelden FamiJob',
                'login.title' => 'Aanmelden FamiJob',
                'login.timeout' => 'Je sessie is verlopen door inactiviteit. Meld je opnieuw aan.',
                'login.error.invalid' => 'Gebruikersnaam of wachtwoord is onjuist.',
                'login.username' => 'Gebruikersnaam',
                'login.password' => 'Wachtwoord',
                'login.submit' => 'Aanmelden',
                'login.help' => 'Gebruikersnaam of wachtwoord vergeten?',
                'index.workspace' => 'FamiJob Workspace',
                'index.logout' => 'Afmelden',
                'index.kicker' => 'Werkruimtebeheer',
                'index.title' => 'FamiJob',
                'index.subtitle' => 'Beheer van interimroosters en beschikbaarheden',
                'index.role_prefix' => 'Profiel',
                'role.admin' => 'Beheerder',
                'role.teamcoach' => 'TeamCoach',
                'tile.open' => 'Openen',
                'tile.demands.title' => 'Uurroosteraanvragen',
                'tile.demands.desc.admin' => 'Aanvragen voor interimkantoren aanmaken, wijzigen of verwijderen.',
                'tile.demands.desc.teamcoach' => 'Aanvragen voor interimkantoren raadplegen.',
                'tile.matching.title' => 'Matching',
                'tile.matching.desc.admin' => 'Studenten toewijzen aan interimslots, manueel of automatisch.',
                'tile.matching.desc.teamcoach' => 'Studenten toewijzen aan interimslots.',
                'tile.validation.title' => 'Aanvragen valideren',
                'tile.validation.desc' => 'Aanvragen goedkeuren of weigeren voor publicatie in de matching.',
                'tile.schedule.title' => 'Roosteroverzicht',
                'tile.schedule.desc' => 'Wekelijkse planning in alleen-lezen met dagen, tijdsloten en afdelingen.',
                'tile.relaunch.title' => 'Studenten relance',
                'tile.relaunch.desc' => 'Groepe-mails versturen op basis van beschikbaarheid en radius.',
                'tile.availability.title' => 'Beschikbaarheid studenten',
                'tile.availability.desc' => 'Weekoverzicht per sector van studentbeschikbaarheid.',
                'tile.interim_fixes.title' => 'Vaste roosters interimaires',
                'tile.interim_fixes.desc' => 'Vaste roosters (week A/B) per afdeling configureren voor interimaires. Automatisch goedgekeurd, geen validatie vereist.',
                'interim_fixes.page_title' => 'Vaste roosters interimaires',
                'interim_fixes.workspace' => 'FamiJob Workspace',
                'interim_fixes.back' => 'Terug',
                'interim_fixes.sidebar.title' => 'Interimaires',
                'interim_fixes.week_prefix' => 'Week',
                'interim_fixes.week.label_a' => 'Week A - Even',
                'interim_fixes.week.label_b' => 'Week B - Oneven',
                'interim_fixes.search.placeholder' => 'Zoeken...',
                'interim_fixes.filter.agency.all' => 'Alle agentschappen',
                'interim_fixes.filter.rayon.all' => 'Alle afdelingen',
                'interim_fixes.empty.none' => 'Geen interimair gevonden.',
                'interim_fixes.empty.help' => 'Maak eerst een account aan via admin.php met een niet-student rol en een ingevuld agentschap.',
                'interim_fixes.intro.select' => 'Selecteer een interimair om het vaste rooster (week A/B) en de afdeling in te stellen.',
                'interim_fixes.user.badge' => 'Interimair',
                'interim_fixes.hours.week_a' => 'Week A',
                'interim_fixes.hours.week_b' => 'Week B',
                'interim_fixes.hours.total' => 'Totaal A + B',
                'interim_fixes.hours.encoded_suffix' => 'u ingevoerd',
                'interim_fixes.hours.unit' => 'u',
                'interim_fixes.department.label' => 'Afdeling',
                'interim_fixes.department.undefined' => '- Niet ingesteld -',
                'interim_fixes.department.custom_suffix' => '(aangepast)',
                'interim_fixes.week.current_hint' => '<- huidige week',
                'interim_fixes.week.current_dot' => '● ',
                'interim_fixes.copy.button' => 'Kopieer Week A -> B',
                'interim_fixes.table.day' => 'Dag',
                'interim_fixes.table.active' => 'Actief',
                'interim_fixes.table.start' => 'Startuur',
                'interim_fixes.table.end' => 'Einduur',
                'interim_fixes.status.active' => 'Actief',
                'interim_fixes.status.inactive' => 'Inactief',
                'interim_fixes.save' => 'Rooster opslaan',
                'interim_fixes.confirm.copy_a_to_b' => 'Alle roosters van Week A naar Week B kopieren?',
                'interim_fixes.message.saved' => 'Vast rooster succesvol opgeslagen.',
                'interim_fixes.day.1' => 'Maandag',
                'interim_fixes.day.2' => 'Dinsdag',
                'interim_fixes.day.3' => 'Woensdag',
                'interim_fixes.day.4' => 'Donderdag',
                'interim_fixes.day.5' => 'Vrijdag',
                'interim_fixes.day.6' => 'Zaterdag',
                'interim_fixes.day.7' => 'Zondag',
            ],
        ];

        return $translations;
    }
}

if (!function_exists('famiT')) {
    function famiT($key, $fallback = '')
    {
        $lang = famiLang();
        $translations = famiTranslations();

        if (isset($translations[$lang][$key])) {
            return $translations[$lang][$key];
        }

        if (isset($translations['fr'][$key])) {
            return $translations['fr'][$key];
        }

        return $fallback !== '' ? $fallback : $key;
    }
}

if (!function_exists('famiLanguageSwitchUrl')) {
    function famiLanguageSwitchUrl($targetLang)
    {
        $targetLang = famiNormalizeLanguage($targetLang);
        $requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        if ($requestUri === '') {
            $requestUri = (string) ($_SERVER['PHP_SELF'] ?? 'index.php');
        }

        $path = parse_url($requestUri, PHP_URL_PATH) ?: '';
        $query = parse_url($requestUri, PHP_URL_QUERY) ?: '';
        $params = [];
        if ($query !== '') {
            parse_str($query, $params);
        }

        $params['lang'] = $targetLang;
        $queryString = http_build_query($params);

        return $path . ($queryString !== '' ? ('?' . $queryString) : '');
    }
}

if (!function_exists('famiRenderLanguageSwitcher')) {
    function famiRenderLanguageSwitcher($extraClass = '')
    {
        $current = famiLang();
        $frClass = $current === 'fr' ? ' is-active' : '';
        $nlClass = $current === 'nl' ? ' is-active' : '';

        return '<div class="fami-lang-switcher ' . e(trim((string) $extraClass)) . '">'
            . '<a class="fami-lang-option' . $frClass . '" href="' . e(famiLanguageSwitchUrl('fr')) . '">FR</a>'
            . '<a class="fami-lang-option' . $nlClass . '" href="' . e(famiLanguageSwitchUrl('nl')) . '">NL</a>'
            . '</div>';
    }
}

famiApplyLanguageSelection();

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
