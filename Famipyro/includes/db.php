<?php

declare(strict_types=1);

function get_pdo(array $config): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host = getenv('DB_HOST') ?: $config['db_host'];
    $port = getenv('DB_PORT') ?: ($config['db_port'] ?? '3306');
    $name = getenv('DB_NAME') ?: $config['db_name'];
    $user = getenv('DB_USER') ?: $config['db_user'];
    $pass = getenv('DB_PASS') ?: $config['db_pass'];

    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $name);

    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    return $pdo;
}
