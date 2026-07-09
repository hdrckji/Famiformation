<?php

if (!function_exists('initCSRF')) {
    function initCSRF()
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
    }
}

if (!function_exists('getCSRFToken')) {
    function getCSRFToken()
    {
        initCSRF();
        return $_SESSION['csrf_token'] ?? '';
    }
}

if (!function_exists('csrfField')) {
    function csrfField()
    {
        return '<input type="hidden" name="csrf_token" value="' . e(getCSRFToken()) . '">';
    }
}

if (!function_exists('validateCSRF')) {
    function validateCSRF()
    {
        $token = $_POST['csrf_token'] ?? $_GET['csrf'] ?? '';
        $sessionToken = $_SESSION['csrf_token'] ?? '';

        if ($token === '' || $sessionToken === '') {
            return false;
        }

        return hash_equals($sessionToken, $token);
    }
}

if (!function_exists('requireValidCSRF')) {
    function requireValidCSRF()
    {
        // Mode aperçu (admin prévisualisant un profil) : lecture seule.
        // Aucune écriture n'est autorisée tant que l'aperçu est actif.
        if (!empty($_SESSION['apercu_role']) && (($_SESSION['role'] ?? '') === 'admin')) {
            $_SESSION['apercu_flash'] = "Action ignorée : vous êtes en mode aperçu (lecture seule). Rien n'a été enregistré.";
            $back = 'index.php';
            $ref = $_SERVER['HTTP_REFERER'] ?? '';
            $host = $_SERVER['HTTP_HOST'] ?? '';
            if ($ref !== '' && $host !== '' && parse_url($ref, PHP_URL_HOST) === $host) {
                $back = $ref;
            }
            header('Location: ' . $back);
            exit;
        }

        if (!validateCSRF()) {
            http_response_code(403);
            exit('Jeton CSRF invalide.');
        }
    }
}