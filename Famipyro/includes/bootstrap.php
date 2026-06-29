<?php

declare(strict_types=1);

session_start();
date_default_timezone_set('Europe/Brussels');

$config = require __DIR__ . '/config.php';

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
