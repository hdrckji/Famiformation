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
        if (!validateCSRF()) {
            http_response_code(403);
            exit('Jeton CSRF invalide.');
        }
    }
}