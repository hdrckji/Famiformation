<?php
require __DIR__ . '/../includes/bootstrap.php';

session_destroy();
header('Location: login.php');
exit;
