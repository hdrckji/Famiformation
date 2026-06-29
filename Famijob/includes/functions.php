<?php

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

if (!function_exists('getEnv')) {
    function getEnv($key, $default = null)
    {
        return famiGetEnv($key, $default);
    }
}

if (!function_exists('famiEnvFlag')) {
    function famiEnvFlag($key, $default = false)
    {
        $value = famiGetEnv($key, $default ? '1' : '0');
        if (is_bool($value)) {
            return $value;
        }

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

if (!function_exists('getCurrentUserId')) {
    function getCurrentUserId()
    {
        return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
    }
}

if (!function_exists('getCurrentRole')) {
    function getCurrentRole()
    {
        return $_SESSION['role'] ?? 'guest';
    }
}

if (!function_exists('verifierConnexion')) {
    function verifierConnexion($db = null)
    {
        if (!isset($_SESSION['user_id'])) {
            header('Location: login.php');
            exit();
        }
    }
}

if (!function_exists('isAdmin')) {
    function isAdmin()
    {
        return getCurrentRole() === 'admin';
    }
}

if (!function_exists('isAdminOrTeamcoach')) {
    function isAdminOrTeamcoach()
    {
        return in_array(getCurrentRole(), ['admin', 'teamcoach'], true);
    }
}

if (!function_exists('requireAdmin')) {
    function requireAdmin()
    {
        verifierConnexion();
        if (!isAdmin()) {
            header('Location: index.php');
            exit();
        }
    }
}

if (!function_exists('requireAdminOrTeamcoach')) {
    function requireAdminOrTeamcoach()
    {
        verifierConnexion();
        if (!isAdminOrTeamcoach()) {
            header('Location: index.php');
            exit();
        }
    }
}

if (!function_exists('setLastMailError')) {
    function setLastMailError($message)
    {
        $message = trim((string) $message);
        $GLOBALS['fami_last_mail_error'] = $message;
    }
}

if (!function_exists('getLastMailError')) {
    function getLastMailError()
    {
        return $GLOBALS['fami_last_mail_error'] ?? '';
    }
}

if (!function_exists('famiReadSmtpResponse')) {
    function famiReadSmtpResponse($stream)
    {
        $response = '';

        while (($line = fgets($stream, 515)) !== false) {
            $response .= $line;
            if (preg_match('/^\d{3} /', $line)) {
                break;
            }
        }

        return trim($response);
    }
}

if (!function_exists('famiSendSmtpCommand')) {
    function famiSendSmtpCommand($stream, $command, $expectedCodes)
    {
        fwrite($stream, $command . "\r\n");
        $response = famiReadSmtpResponse($stream);

        foreach ((array) $expectedCodes as $code) {
            if (strpos($response, (string) $code) === 0) {
                return $response;
            }
        }

        throw new RuntimeException($response !== '' ? $response : 'Réponse SMTP vide.');
    }
}

if (!function_exists('formatFormationDuration')) {
    function formatFormationDuration($duration)
    {
        if ($duration === null || $duration === '') {
            return 'Non précisée';
        }

        $value = trim((string) $duration);
        if ($value === '') {
            return 'Non précisée';
        }

        return $value . ' h';
    }
}

if (!function_exists('ensureStudentAvailabilityTable')) {
    function ensureStudentAvailabilityTable(PDO $db)
    {
        static $tableReady = false;

        if ($tableReady) {
            return true;
        }

        $db->exec(
            "CREATE TABLE IF NOT EXISTS student_availabilities (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                availability_date DATE NOT NULL,
                availability_status VARCHAR(30) NOT NULL DEFAULT 'non_renseigne',
                note VARCHAR(255) DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_student_availability (user_id, availability_date),
                INDEX idx_student_availability_date (availability_date),
                INDEX idx_student_availability_user (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $tableReady = true;
        return true;
    }
}

if (!function_exists('ensureStudentProfileLinksTable')) {
    function ensureStudentProfileLinksTable(PDO $db)
    {
        static $tableReady = false;

        if ($tableReady) {
            return true;
        }

        $db->exec(
            "CREATE TABLE IF NOT EXISTS student_profile_links (
                id INT AUTO_INCREMENT PRIMARY KEY,
                student_id INT NOT NULL,
                linked_student_id INT NOT NULL,
                priority_rank INT NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_student_link (student_id, linked_student_id),
                INDEX idx_student_profile_links_student (student_id),
                INDEX idx_student_profile_links_linked (linked_student_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $tableReady = true;
        return true;
    }
}

if (!function_exists('ensureUserAccountAccessColumns')) {
    function ensureUserAccountAccessColumns(PDO $db)
    {
        static $columnsReady = false;

        if ($columnsReady) {
            return true;
        }

        $tableStmt = $db->query("SHOW TABLES LIKE 'utilisateurs'");
        if (!$tableStmt->fetch()) {
            return false;
        }

        $requiredColumns = [
            'account_activation_pending' => "ALTER TABLE utilisateurs ADD COLUMN account_activation_pending TINYINT(1) NOT NULL DEFAULT 0 AFTER mot_de_passe",
            'account_access_token_hash' => "ALTER TABLE utilisateurs ADD COLUMN account_access_token_hash CHAR(64) NULL AFTER account_activation_pending",
            'account_access_expires_at' => "ALTER TABLE utilisateurs ADD COLUMN account_access_expires_at DATETIME NULL AFTER account_access_token_hash",
            'account_access_type' => "ALTER TABLE utilisateurs ADD COLUMN account_access_type VARCHAR(20) NULL AFTER account_access_expires_at",
            'photo_profil' => "ALTER TABLE utilisateurs ADD COLUMN photo_profil VARCHAR(255) NULL",
        ];

        foreach ($requiredColumns as $columnName => $alterSql) {
            $columnStmt = $db->query("SHOW COLUMNS FROM utilisateurs LIKE " . $db->quote($columnName));
            if (!$columnStmt->fetch()) {
                $db->exec($alterSql);
            }
        }

        $indexStmt = $db->query("SHOW INDEX FROM utilisateurs WHERE Key_name = 'idx_account_access_token_hash'");
        if (!$indexStmt->fetch()) {
            $db->exec("CREATE INDEX idx_account_access_token_hash ON utilisateurs (account_access_token_hash)");
        }

        $columnsReady = true;
        return true;
    }
}

if (!function_exists('famiCreateMysqlPdo')) {
    function famiCreateMysqlPdo($host, $dbname, $user, $pass, $port = null)
    {
        $dsn = sprintf(
            'mysql:host=%s;%sdbname=%s;charset=utf8mb4',
            $host,
            ($port !== null && $port !== '') ? 'port=' . (int) $port . ';' : '',
            $dbname
        );
        $pdo = new PDO($dsn, $user, $pass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        return $pdo;
    }
}

if (!function_exists('getPlanningDbConnection')) {
    function hasPlanningDbConfig()
    {
        return famiGetEnv('PLANNING_DB_NAME', '') !== ''
            && famiGetEnv('PLANNING_DB_USER', '') !== '';
    }
}

if (!function_exists('getPlanningDbConnection')) {
    function getPlanningDbConnection()
    {
        static $planningDb = false;

        if ($planningDb instanceof PDO) {
            return $planningDb;
        }

        if ($planningDb === null) {
            return null;
        }

        if (!hasPlanningDbConfig()) {
            $planningDb = null;
            return null;
        }

        $host = famiGetEnv('PLANNING_DB_HOST', famiGetEnv('DB_HOST', 'localhost'));
        $port = famiGetEnv('PLANNING_DB_PORT', '3306');
        $dbname = famiGetEnv('PLANNING_DB_NAME', '');
        $user = famiGetEnv('PLANNING_DB_USER', '');
        $pass = famiGetEnv('PLANNING_DB_PASSWORD', '');

        if ($dbname === '' || $user === '') {
            $planningDb = null;
            return null;
        }

        try {
            $planningDb = famiCreateMysqlPdo($host, $dbname, $user, $pass, $port);
        } catch (Exception $e) {
            if ($host === 'localhost') {
                try {
                    $planningDb = famiCreateMysqlPdo('127.0.0.1', $dbname, $user, $pass, $port);
                } catch (Exception $e2) {
                    $planningDb = null;
                    return null;
                }
            } else {
                $planningDb = null;
                return null;
            }
        }

        return $planningDb;
    }
}

if (!function_exists('fetchPlanningDepartmentNames')) {
    function getExcludedDepartmentNames()
    {
        $rawValue = (string) famiGetEnv('PLANNING_DEPARTMENTS_EXCLUDED', 'tezr,voiture');
        $parts = array_map('trim', explode(',', $rawValue));
        $parts = array_filter($parts, static function ($value) {
            return $value !== '';
        });

        $excluded = [];
        foreach ($parts as $value) {
            $normalized = function_exists('mb_strtolower')
                ? mb_strtolower($value, 'UTF-8')
                : strtolower($value);
            $excluded[$normalized] = $value;
        }

        return array_values($excluded);
    }
}

if (!function_exists('fetchPlanningDepartmentNames')) {
    function fetchPlanningDepartmentNames(PDO $planningDb)
    {
        $tableName = trim((string) famiGetEnv('PLANNING_DEPARTMENTS_TABLE', 'planning_lignes'));
        $columnName = trim((string) famiGetEnv('PLANNING_DEPARTMENTS_COLUMN', 'departement'));

        if ($tableName === '' || $columnName === '') {
            return [];
        }

        $safeTableName = preg_replace('/[^a-zA-Z0-9_]/', '', $tableName);
        $safeColumnName = preg_replace('/[^a-zA-Z0-9_]/', '', $columnName);

        if ($safeTableName === '' || $safeColumnName === '') {
            return [];
        }

        $stmt = $planningDb->query(
            "SELECT DISTINCT TRIM($safeColumnName) AS department_name
             FROM $safeTableName
             WHERE $safeColumnName IS NOT NULL
               AND TRIM($safeColumnName) <> ''
             ORDER BY department_name ASC"
        );

        $departmentNames = [];
        $excludedNames = getExcludedDepartmentNames();
        $excludedIndex = [];
        foreach ($excludedNames as $excludedName) {
            $excludedIndex[function_exists('mb_strtolower') ? mb_strtolower($excludedName, 'UTF-8') : strtolower($excludedName)] = true;
        }

        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $departmentName) {
            $departmentName = trim((string) $departmentName);
            $normalizedDepartmentName = function_exists('mb_strtolower')
                ? mb_strtolower($departmentName, 'UTF-8')
                : strtolower($departmentName);

            if ($departmentName !== '' && !isset($excludedIndex[$normalizedDepartmentName])) {
                $departmentNames[] = $departmentName;
            }
        }

        return array_values(array_unique($departmentNames));
    }
}

if (!function_exists('purgeExcludedDepartments')) {
    function purgeExcludedDepartments(PDO $db, array $excludedDepartmentNames)
    {
        if (empty($excludedDepartmentNames)) {
            return;
        }

        $placeholders = implode(', ', array_fill(0, count($excludedDepartmentNames), '?'));

        $deleteLinksStmt = $db->prepare(
            "DELETE sdl
             FROM student_department_links sdl
             INNER JOIN departments d ON d.id = sdl.department_id
             WHERE d.department_name IN ($placeholders)"
        );
        $deleteLinksStmt->execute($excludedDepartmentNames);

        $deleteDepartmentsStmt = $db->prepare(
            "DELETE FROM departments
             WHERE department_name IN ($placeholders)"
        );
        $deleteDepartmentsStmt->execute($excludedDepartmentNames);
    }
}

if (!function_exists('syncDepartmentsFromPlanningDb')) {
    function syncDepartmentsFromPlanningDb(PDO $db)
    {
        $planningDb = getPlanningDbConnection();
        if (!$planningDb instanceof PDO) {
            return [];
        }

        $departmentNames = fetchPlanningDepartmentNames($planningDb);
        if (empty($departmentNames)) {
            return [];
        }

        ensureDepartmentsTable($db);
                purgeExcludedDepartments($db, getExcludedDepartmentNames());

        $upsertStmt = $db->prepare(
            "INSERT INTO departments (department_name, is_active)
             VALUES (?, 1)
               ON DUPLICATE KEY UPDATE updated_at = CURRENT_TIMESTAMP"
        );

        foreach ($departmentNames as $departmentName) {
            $upsertStmt->execute([$departmentName]);
        }

        return $departmentNames;
    }
}

if (!function_exists('ensureDepartmentsTable')) {
    function ensureDepartmentsTable(PDO $db)
    {
        static $tableReady = false;

        if ($tableReady) {
            return true;
        }

        $db->exec(
            "CREATE TABLE IF NOT EXISTS departments (
                id INT AUTO_INCREMENT PRIMARY KEY,
                department_name VARCHAR(120) NOT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_department_name (department_name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $tableReady = true;
        return true;
    }
}

if (!function_exists('ensureStudentDepartmentLinksTable')) {
    function ensureStudentDepartmentLinksTable(PDO $db)
    {
        static $tableReady = false;

        if ($tableReady) {
            return true;
        }

        $db->exec(
            "CREATE TABLE IF NOT EXISTS student_department_links (
                id INT AUTO_INCREMENT PRIMARY KEY,
                student_id INT NOT NULL,
                department_id INT NOT NULL,
                priority_rank INT NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_student_department (student_id, department_id),
                INDEX idx_student_department_links_student (student_id),
                INDEX idx_student_department_links_department (department_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $tableReady = true;
        return true;
    }
}

if (!function_exists('sendMailViaSmtpSocket')) {
    function sendMailViaSmtpSocket($to, $subject, $body, $isHtml = true)
    {
        $host = famiGetEnv('SMTP_HOST', '');
        $port = (int) famiGetEnv('SMTP_PORT', 465);
        $user = famiGetEnv('SMTP_USER', '');
        $pass = famiGetEnv('SMTP_PASS', '');
        $from = famiGetEnv('MAIL_FROM', 'admin@famiformation.com');
        $fromName = famiGetEnv('MAIL_FROM_NAME', 'FamiFormation');
        $secure = strtolower((string) famiGetEnv('SMTP_SECURE', 'ssl'));

        if ($host === '' || $user === '' || $pass === '') {
            setLastMailError('Configuration SMTP incomplète.');
            return false;
        }

        $transport = ($secure === 'ssl' || $secure === 'smtps') ? 'ssl://' : 'tcp://';
        $stream = @stream_socket_client(
            $transport . $host . ':' . $port,
            $errorNumber,
            $errorMessage,
            20,
            STREAM_CLIENT_CONNECT
        );

        if (!is_resource($stream)) {
            setLastMailError('Connexion SMTP impossible : ' . trim($errorMessage ?: 'erreur inconnue'));
            return false;
        }

        stream_set_timeout($stream, 20);

        try {
            $greeting = famiReadSmtpResponse($stream);
            if (strpos($greeting, '220') !== 0) {
                throw new RuntimeException($greeting !== '' ? $greeting : 'Accueil SMTP invalide.');
            }

            famiSendSmtpCommand($stream, 'EHLO famiformation.com', [250]);

            if ($secure !== 'ssl' && $secure !== 'smtps') {
                famiSendSmtpCommand($stream, 'STARTTLS', [220]);
                if (!stream_socket_enable_crypto($stream, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new RuntimeException('Impossible d\'activer STARTTLS.');
                }
                famiSendSmtpCommand($stream, 'EHLO famiformation.com', [250]);
            }

            famiSendSmtpCommand($stream, 'AUTH LOGIN', [334]);
            famiSendSmtpCommand($stream, base64_encode($user), [334]);
            famiSendSmtpCommand($stream, base64_encode($pass), [235]);
            famiSendSmtpCommand($stream, 'MAIL FROM:<' . $from . '>', [250]);
            famiSendSmtpCommand($stream, 'RCPT TO:<' . $to . '>', [250, 251]);
            famiSendSmtpCommand($stream, 'DATA', [354]);

            $encodedSubject = '=?UTF-8?B?' . base64_encode((string) $subject) . '?=';
            $headers = [
                'Date: ' . date(DATE_RFC2822),
                'From: ' . $fromName . ' <' . $from . '>',
                'Reply-To: ' . $from,
                'Sender: ' . $from,
                'To: ' . $to,
                'Subject: ' . $encodedSubject,
                'MIME-Version: 1.0',
                'Content-Type: ' . ($isHtml ? 'text/html' : 'text/plain') . '; charset=UTF-8',
                'Content-Transfer-Encoding: 8bit',
            ];

            $messageBody = str_replace(["\r\n", "\r"], "\n", (string) $body);
            $messageBody = preg_replace('/^\./m', '..', $messageBody);
            $payload = implode("\r\n", $headers) . "\r\n\r\n" . str_replace("\n", "\r\n", $messageBody) . "\r\n.\r\n";
            fwrite($stream, $payload);

            $dataResponse = famiReadSmtpResponse($stream);
            if (strpos($dataResponse, '250') !== 0) {
                throw new RuntimeException($dataResponse !== '' ? $dataResponse : 'Envoi SMTP refusé.');
            }

            @fwrite($stream, "QUIT\r\n");
            @fclose($stream);
            setLastMailError('');
            return true;
        } catch (Throwable $e) {
            @fwrite($stream, "QUIT\r\n");
            @fclose($stream);
            setLastMailError(trim((string) $e->getMessage()));
            error_log('[FamiFormation] sendMail socket SMTP failed: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('sendMail')) {
    function sendMail($to, $subject, $body, $isHtml = true)
    {
        setLastMailError('');

        $to = trim((string) $to);
        if ($to === '') {
            setLastMailError('Aucune adresse destinataire fournie.');
            return false;
        }

        $from = famiGetEnv('MAIL_FROM', 'admin@famiformation.com');
        $fromName = famiGetEnv('MAIL_FROM_NAME', 'FamiFormation');
        $smtpHost = famiGetEnv('SMTP_HOST', '');
        $smtpUser = famiGetEnv('SMTP_USER', $from);
        $smtpPass = famiGetEnv('SMTP_PASS', '');
        $smtpConfigured = $smtpHost !== '' && $smtpUser !== '' && $smtpPass !== '';

        if ($smtpConfigured && is_file(__DIR__ . '/../vendor/autoload.php')) {
            require_once __DIR__ . '/../vendor/autoload.php';

            try {
                $mail = new PHPMailer\PHPMailer\PHPMailer(true);
                $mail->isSMTP();
                $mail->Host = $smtpHost;
                $mail->SMTPAuth = true;
                $mail->Username = $smtpUser;
                $mail->Password = $smtpPass;
                $mail->Timeout = 20;
                $mail->SMTPAutoTLS = true;

                $secure = strtolower((string) famiGetEnv('SMTP_SECURE', 'tls'));
                if ($secure === 'ssl' || $secure === 'smtps') {
                    $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
                } else {
                    $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
                }

                $mail->Port = (int) famiGetEnv('SMTP_PORT', $mail->SMTPSecure === PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS ? 465 : 587);
                $mail->setFrom($from, $fromName, false);
                $mail->Sender = $from;
                $mail->addReplyTo($from, $fromName);
                $mail->addAddress($to);
                $mail->CharSet = 'UTF-8';
                $mail->isHTML($isHtml);
                $mail->Subject = $subject;
                $mail->Body = $body;
                if ($isHtml) {
                    $mail->AltBody = trim(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $body)));
                }
                return $mail->send();
            } catch (Throwable $e) {
                $smtpError = trim((string) $e->getMessage());
                if ($smtpError === '') {
                    $smtpError = 'Erreur SMTP inconnue.';
                }
                setLastMailError($smtpError);
                error_log('[FamiFormation] sendMail SMTP failed: ' . $smtpError);
                if (sendMailViaSmtpSocket($to, $subject, $body, $isHtml)) {
                    return true;
                }

                return false;
            }
        }

        if ($smtpConfigured) {
            if (sendMailViaSmtpSocket($to, $subject, $body, $isHtml)) {
                return true;
            }

            if (getLastMailError() === '') {
                setLastMailError('SMTP configuré mais indisponible, aucun fallback natif utilisé pour préserver l’adresse d’expédition.');
            }
            error_log('[FamiFormation] sendMail SMTP configured but unavailable; native mail() fallback skipped to preserve sender identity.');
            return false;
        }

        $headers = "From: {$fromName} <{$from}>\r\n";
        $headers .= "Sender: {$from}\r\n";
        $headers .= "Reply-To: {$from}\r\n";
        $headers .= 'MIME-Version: 1.0' . "\r\n";
        if ($isHtml) {
            $headers .= 'Content-Type: text/html; charset=UTF-8' . "\r\n";
        } else {
            $headers .= 'Content-Type: text/plain; charset=UTF-8' . "\r\n";
        }

        $mailSent = mail($to, $subject, $body, $headers, '-f' . $from);
        if (!$mailSent) {
            setLastMailError('La fonction mail() a échoué.');
        }

        return $mailSent;
    }
}

if (!function_exists('sendEnrollmentEmail')) {
    function sendEnrollmentEmail(PDO $db, $userId, $formationId, $creneauId = null)
    {
        $stmt = $db->prepare('SELECT prenom, nom, email, role FROM utilisateurs WHERE id = ? LIMIT 1');
        $stmt->execute([(int) $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        $stmt = $db->prepare('SELECT titre, description FROM formations_sessions WHERE id = ? LIMIT 1');
        $stmt->execute([(int) $formationId]);
        $formation = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || !$formation || empty($user['email']) || ($user['role'] ?? '') !== 'etudiant') {
            return false;
        }

        $dateLine = 'Date à venir communiquée ultérieurement';
        if ($creneauId !== null) {
            $stmt = $db->prepare('SELECT date_heure, duree FROM formations_creneaux WHERE id = ? LIMIT 1');
            $stmt->execute([(int) $creneauId]);
            $creneau = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($creneau && !empty($creneau['date_heure'])) {
                $dateLine = date('d/m/Y H:i', strtotime($creneau['date_heure']));
                if (!empty($creneau['duree'])) {
                    $dateLine .= ' (' . e(formatFormationDuration($creneau['duree'])) . ')';
                }
            }
        }

        $subject = 'Confirmation d\'inscription - ' . $formation['titre'];
        $body = '<div style="font-family:Open Sans,Arial,sans-serif;background:#f4f7f6;padding:24px;color:#2d5a37">'
            . '<div style="max-width:560px;margin:auto;background:#fff;border-radius:16px;padding:28px">'
            . '<h2 style="margin-top:0;color:#2d5a37">Inscription enregistrée</h2>'
            . '<p>Bonjour ' . e($user['prenom']) . ' ' . e($user['nom']) . ',</p>'
            . '<p>Votre inscription pour la formation <strong>' . e($formation['titre']) . '</strong> a bien été prise en compte.</p>'
            . '<p><strong>Date :</strong> ' . e($dateLine) . '</p>'
            . '<p><strong>Description :</strong> ' . e($formation['description'] ?? '') . '</p>'
            . '<p>Vous pouvez retrouver ces informations dans votre espace FamiFormation.</p>'
            . '</div></div>';

        return sendMail($user['email'], $subject, $body, true);
    }
}

if (!function_exists('getStudentCaisseQuizAverageOn20')) {
    function getStudentCaisseQuizAverageOn20(PDO $db, $userId)
    {
        $stmt = $db->prepare("SELECT nom_page, MAX(score) AS best_score FROM statistiques WHERE utilisateur_id = ? AND nom_page IN ('quiz_caisse', 'quiz_pdf') AND score IS NOT NULL GROUP BY nom_page");
        $stmt->execute([(int) $userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($rows)) {
            return 0.0;
        }

        $total = 0.0;
        foreach ($rows as $row) {
            $total += (float) ($row['best_score'] ?? 0);
        }

        $averageOn10 = $total / count($rows);
        return round($averageOn10 * 2, 1);
    }
}

if (!function_exists('sendStudentTrainingEnrollmentEmail')) {
    function sendStudentTrainingEnrollmentEmail(PDO $db, $userId, $formationId, $creneauId)
    {
        $userStmt = $db->prepare('SELECT prenom, nom, email FROM utilisateurs WHERE id = ? LIMIT 1');
        $userStmt->execute([(int) $userId]);
        $user = $userStmt->fetch(PDO::FETCH_ASSOC);

        $formationStmt = $db->prepare('SELECT titre, description FROM formations_sessions WHERE id = ? LIMIT 1');
        $formationStmt->execute([(int) $formationId]);
        $formation = $formationStmt->fetch(PDO::FETCH_ASSOC);

        $creneauStmt = $db->prepare('SELECT date_heure, duree FROM formations_creneaux WHERE id = ? LIMIT 1');
        $creneauStmt->execute([(int) $creneauId]);
        $creneau = $creneauStmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || !$formation || !$creneau || empty($user['email']) || empty($creneau['date_heure'])) {
            return false;
        }

        $averageOn20 = getStudentCaisseQuizAverageOn20($db, $userId);
        $isAboveTwelve = $averageOn20 > 12;
        $scheduledAt = strtotime($creneau['date_heure']);
        $formattedDate = date('d/m/Y', $scheduledAt);
        $formattedTime = date('H:i', $scheduledAt);
        $duration = formatFormationDuration($creneau['duree'] ?? null);

        if ($isAboveTwelve) {
            $subject = 'Bravo, ton rendez-vous en magasin est confirmé';
            $headline = 'Bravo pour ton travail';
            $intro = 'Tu as obtenu une moyenne supérieure à 12/20 à tes quiz caisse. Félicitations pour cette belle étape franchie.';
            $advice = 'Nous te rappelons ci-dessous l’horaire choisi pour ta formation en magasin. Pense à arriver un peu en avance pour démarrer sereinement.';
            $highlight = 'Tu avances très bien dans ton parcours. Continue sur cette lancée.';
        } else {
            $subject = 'Rappel de ton rendez-vous en magasin';
            $headline = 'Ton rendez-vous est bien planifié';
            $intro = 'Ta formation en magasin est maintenant programmée. Nous te rappelons la date et l’horaire choisis.';
            $advice = 'Avant cette date, prends bien le temps de relire la formation sur le site afin d’arriver prêt et en confiance le jour du rendez-vous.';
            $highlight = 'Un petit rafraîchissement sur le contenu caisse avant le test peut vraiment faire la différence.';
        }

        $body = '<div style="margin:0;padding:32px;background:#eef4ef;font-family:Open Sans,Arial,sans-serif;color:#244230;">'
            . '<div style="max-width:680px;margin:0 auto;background:#ffffff;border-radius:24px;overflow:hidden;box-shadow:0 18px 38px rgba(27,54,36,0.12);">'
            . '<div style="padding:28px 32px;background:linear-gradient(135deg,#2d5a37 0%,#4a7b55 100%);color:#ffffff;">'
            . '<div style="font-size:12px;letter-spacing:.08em;text-transform:uppercase;opacity:.85;">FamiFormation</div>'
            . '<h1 style="margin:10px 0 8px;font-size:30px;line-height:1.2;">' . e($headline) . '</h1>'
            . '<p style="margin:0;font-size:15px;line-height:1.6;opacity:.95;">' . e($highlight) . '</p>'
            . '</div>'
            . '<div style="padding:32px;">'
            . '<p style="margin:0 0 18px;font-size:16px;line-height:1.7;">Bonjour ' . e($user['prenom']) . ',</p>'
            . '<p style="margin:0 0 18px;font-size:16px;line-height:1.7;">' . e($intro) . '</p>'
            . '<div style="margin:24px 0;padding:22px;border-radius:18px;background:#f6faf7;border:1px solid #dde9df;">'
            . '<div style="font-size:13px;text-transform:uppercase;letter-spacing:.08em;color:#6a7d72;margin-bottom:12px;">Rappel de ton rendez-vous</div>'
            . '<p style="margin:0 0 8px;font-size:16px;"><strong>Formation :</strong> ' . e($formation['titre']) . '</p>'
            . '<p style="margin:0 0 8px;font-size:16px;"><strong>Date :</strong> ' . e($formattedDate) . '</p>'
            . '<p style="margin:0 0 8px;font-size:16px;"><strong>Heure :</strong> ' . e($formattedTime) . '</p>'
            . '<p style="margin:0 0 8px;font-size:16px;"><strong>Durée prévue :</strong> ' . e($duration) . '</p>'
            . '<p style="margin:0;font-size:16px;"><strong>Moyenne quiz caisse :</strong> ' . e(number_format($averageOn20, 1, ',', ' ')) . ' / 20</p>'
            . '</div>'
            . '<p style="margin:0 0 18px;font-size:16px;line-height:1.7;">' . e($advice) . '</p>'
            . '<p style="margin:0 0 18px;font-size:15px;line-height:1.7;color:#617268;">Si tu as une question avant le rendez-vous, rapproche-toi de ton contact habituel.</p>'
            . '<p style="margin:24px 0 0;font-size:15px;line-height:1.7;">À bientôt en magasin,<br><strong>L’équipe Famiflora</strong></p>'
            . '</div>'
            . '<div style="padding:18px 32px;background:#f5f8f6;color:#617268;font-size:13px;">Message automatique envoyé par FamiFormation.</div>'
            . '</div></div>';

        return sendMail($user['email'], $subject, $body, true);
    }
}

if (!function_exists('sendSchedulingEmail')) {
    function sendSchedulingEmail(PDO $db, $formationId, $creneauId)
    {
        return false;
    }
}

if (!function_exists('sendAccueilTrainingEnrollmentEmail')) {
    function sendAccueilTrainingEnrollmentEmail(PDO $db, $userId, $formationId, $creneauId)
    {
        $recipient = 'accueil@famiflora.be';

        $userStmt = $db->prepare('SELECT prenom, nom, identifiant, interim FROM utilisateurs WHERE id = ? LIMIT 1');
        $userStmt->execute([(int) $userId]);
        $user = $userStmt->fetch(PDO::FETCH_ASSOC);

        $formationStmt = $db->prepare('SELECT titre FROM formations_sessions WHERE id = ? LIMIT 1');
        $formationStmt->execute([(int) $formationId]);
        $formation = $formationStmt->fetch(PDO::FETCH_ASSOC);

        $creneauStmt = $db->prepare('SELECT date_heure, duree FROM formations_creneaux WHERE id = ? LIMIT 1');
        $creneauStmt->execute([(int) $creneauId]);
        $creneau = $creneauStmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || !$formation || !$creneau || empty($creneau['date_heure'])) {
            return false;
        }

        $scheduledAt = strtotime($creneau['date_heure']);
        if ($scheduledAt === false) {
            return false;
        }

        $studentFullName = trim((string) (($user['prenom'] ?? '') . ' ' . ($user['nom'] ?? '')));
        $subject = 'Notification accueil - rendez-vous etudiant - ' . $studentFullName;
        $body = '<div style="margin:0;padding:28px;background:#eef4ef;font-family:Open Sans,Arial,sans-serif;color:#244230;">'
            . '<div style="max-width:640px;margin:0 auto;background:#ffffff;border-radius:18px;overflow:hidden;box-shadow:0 14px 28px rgba(27,54,36,0.12);">'
            . '<div style="padding:22px 26px;background:linear-gradient(135deg,#2d5a37 0%,#416e4b 100%);color:#ffffff;">'
            . '<div style="font-size:12px;letter-spacing:.08em;text-transform:uppercase;opacity:.85;">FamiFormation</div>'
            . '<h2 style="margin:8px 0 0;font-size:24px;line-height:1.25;">Nouvelle inscription etudiant</h2>'
            . '</div>'
            . '<div style="padding:24px 26px;">'
            . '<p style="margin:0 0 14px;font-size:15px;line-height:1.6;">Un etudiant vient de s\'inscrire a une formation presentielle.</p>'
            . '<div style="margin:18px 0;padding:16px;border-radius:14px;background:#f6faf7;border:1px solid #dde9df;">'
            . '<p style="margin:0 0 8px;font-size:15px;"><strong>Etudiant :</strong> ' . e($studentFullName) . '</p>'
            . '<p style="margin:0 0 8px;font-size:15px;"><strong>Identifiant :</strong> ' . e((string) ($user['identifiant'] ?? '')) . '</p>'
            . '<p style="margin:0 0 8px;font-size:15px;"><strong>Agence interim :</strong> ' . e((string) ($user['interim'] ?? '')) . '</p>'
            . '<p style="margin:0 0 8px;font-size:15px;"><strong>Formation :</strong> ' . e((string) ($formation['titre'] ?? '')) . '</p>'
            . '<p style="margin:0 0 8px;font-size:15px;"><strong>Date :</strong> ' . e(date('d/m/Y', $scheduledAt)) . '</p>'
            . '<p style="margin:0 0 8px;font-size:15px;"><strong>Heure :</strong> ' . e(date('H:i', $scheduledAt)) . '</p>'
            . '<p style="margin:0;font-size:15px;"><strong>Duree :</strong> ' . e(formatFormationDuration($creneau['duree'] ?? null)) . '</p>'
            . '</div>'
            . '<p style="margin:0;font-size:13px;color:#617268;">Message automatique envoye par FamiFormation.</p>'
            . '</div></div></div>';

        return sendMail($recipient, $subject, $body, true);
    }
}

if (!function_exists('sendInterimAgencyEnrollmentEmail')) {
    function sendInterimAgencyEnrollmentEmail(PDO $db, $userId, $formationId, $creneauId)
    {
        try {
            $tableStmt = $db->query("SHOW TABLES LIKE 'interim_agences'");
            if (!$tableStmt->fetch()) {
                return false;
            }

            $userStmt = $db->prepare('SELECT prenom, nom, interim FROM utilisateurs WHERE id = ? LIMIT 1');
            $userStmt->execute([(int) $userId]);
            $user = $userStmt->fetch(PDO::FETCH_ASSOC);

            if (!$user || empty($user['interim'])) {
                return false;
            }

            $agencyStmt = $db->prepare('SELECT nom_agence, nom_contact, email_1, email_2 FROM interim_agences WHERE nom_agence = ? LIMIT 1');
            $agencyStmt->execute([$user['interim']]);
            $agency = $agencyStmt->fetch(PDO::FETCH_ASSOC);

            if (!$agency) {
                return false;
            }

            $formationStmt = $db->prepare('SELECT titre, description FROM formations_sessions WHERE id = ? LIMIT 1');
            $formationStmt->execute([(int) $formationId]);
            $formation = $formationStmt->fetch(PDO::FETCH_ASSOC);

            $creneauStmt = $db->prepare('SELECT date_heure, duree FROM formations_creneaux WHERE id = ? LIMIT 1');
            $creneauStmt->execute([(int) $creneauId]);
            $creneau = $creneauStmt->fetch(PDO::FETCH_ASSOC);

            if (!$formation || !$creneau || empty($creneau['date_heure'])) {
                return false;
            }

            $recipients = [];
            foreach (['email_1', 'email_2'] as $emailKey) {
                $email = trim((string) ($agency[$emailKey] ?? ''));
                if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $recipients[] = $email;
                }
            }

            if (empty($recipients)) {
                return false;
            }

            $scheduledAt = strtotime($creneau['date_heure']);
            $duration = formatFormationDuration($creneau['duree'] ?? null);
            $subject = 'Nouvelle convocation test magasin - ' . $user['prenom'] . ' ' . $user['nom'];
            $body = '<div style="margin:0;padding:32px;background:#eef4ef;font-family:Open Sans,Arial,sans-serif;color:#244230;">'
                . '<div style="max-width:640px;margin:0 auto;background:#ffffff;border-radius:24px;overflow:hidden;box-shadow:0 18px 38px rgba(27,54,36,0.12);">'
                . '<div style="padding:28px 32px;background:linear-gradient(135deg,#2d5a37 0%,#416e4b 100%);color:#ffffff;">'
                . '<div style="font-size:12px;letter-spacing:.08em;text-transform:uppercase;opacity:.8;">FamiFormation</div>'
                . '<h1 style="margin:10px 0 0;font-size:28px;line-height:1.2;">Convocation à une évaluation en point de vente</h1>'
                . '</div>'
                . '<div style="padding:30px 32px;">'
                . '<p style="margin:0 0 18px;font-size:16px;line-height:1.6;">Bonjour ' . e($agency['nom_contact']) . ',</p>'
                . '<p style="margin:0 0 18px;font-size:16px;line-height:1.6;">Nous vous informons que <strong>' . e($user['prenom'] . ' ' . $user['nom']) . '</strong>, rattaché à votre agence <strong>' . e($agency['nom_agence']) . '</strong>, est inscrit à un test en point de vente.</p>'
                . '<div style="margin:24px 0;padding:22px;border-radius:18px;background:#f6faf7;border:1px solid #dde9df;">'
                . '<div style="font-size:13px;text-transform:uppercase;letter-spacing:.08em;color:#6a7d72;margin-bottom:12px;">Détails du rendez-vous</div>'
                . '<p style="margin:0 0 8px;font-size:16px;"><strong>Collaborateur :</strong> ' . e($user['prenom'] . ' ' . $user['nom']) . '</p>'
                . '<p style="margin:0 0 8px;font-size:16px;"><strong>Formation / test :</strong> ' . e($formation['titre']) . '</p>'
                . '<p style="margin:0 0 8px;font-size:16px;"><strong>Date :</strong> ' . e(date('d/m/Y', $scheduledAt)) . '</p>'
                . '<p style="margin:0 0 8px;font-size:16px;"><strong>Heure :</strong> ' . e(date('H:i', $scheduledAt)) . '</p>'
                . '<p style="margin:0;font-size:16px;"><strong>Durée prévue :</strong> ' . e($duration) . '</p>'
                . '</div>'
                . '<p style="margin:0 0 18px;font-size:15px;line-height:1.6;">Cette session correspond à une évaluation en magasin. Les résultats détermineront la suite du parcours de formation sur la plateforme.</p>'
                . '<p style="margin:0;font-size:15px;line-height:1.6;color:#617268;">Pour toute question, vous pouvez répondre directement à cet email ou contacter l\'équipe FamiFormation.</p>'
                . '</div>'
                . '<div style="padding:18px 32px;background:#f5f8f6;color:#617268;font-size:13px;">Message automatique envoyé par FamiFormation.</div>'
                . '</div></div>';

            $sent = false;
            foreach ($recipients as $recipient) {
                $sent = sendMail($recipient, $subject, $body, true) || $sent;
            }

            return $sent;
        } catch (Throwable $e) {
            error_log('[FamiFormation] agency enrollment email failed: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('sendInterimAgencyCancellationEmail')) {
    function sendInterimAgencyCancellationEmail(PDO $db, $userId, $formationId, $creneauId)
    {
        try {
            $tableStmt = $db->query("SHOW TABLES LIKE 'interim_agences'");
            if (!$tableStmt->fetch()) {
                return false;
            }

            $userStmt = $db->prepare('SELECT prenom, nom, interim FROM utilisateurs WHERE id = ? LIMIT 1');
            $userStmt->execute([(int) $userId]);
            $user = $userStmt->fetch(PDO::FETCH_ASSOC);

            if (!$user || empty($user['interim'])) {
                return false;
            }

            $agencyStmt = $db->prepare('SELECT nom_agence, nom_contact, email_1, email_2 FROM interim_agences WHERE nom_agence = ? LIMIT 1');
            $agencyStmt->execute([$user['interim']]);
            $agency = $agencyStmt->fetch(PDO::FETCH_ASSOC);

            if (!$agency) {
                return false;
            }

            $formationStmt = $db->prepare('SELECT titre FROM formations_sessions WHERE id = ? LIMIT 1');
            $formationStmt->execute([(int) $formationId]);
            $formation = $formationStmt->fetch(PDO::FETCH_ASSOC);

            $creneauStmt = $db->prepare('SELECT date_heure, duree FROM formations_creneaux WHERE id = ? LIMIT 1');
            $creneauStmt->execute([(int) $creneauId]);
            $creneau = $creneauStmt->fetch(PDO::FETCH_ASSOC);

            if (!$formation || !$creneau || empty($creneau['date_heure'])) {
                return false;
            }

            $recipients = [];
            foreach (['email_1', 'email_2'] as $emailKey) {
                $email = trim((string) ($agency[$emailKey] ?? ''));
                if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $recipients[] = $email;
                }
            }

            if (empty($recipients)) {
                return false;
            }

            $scheduledAt = strtotime($creneau['date_heure']);
            $duration = formatFormationDuration($creneau['duree'] ?? null);
            $subject = 'Changement de planning - annulation de participation - ' . $user['prenom'] . ' ' . $user['nom'];
            $body = '<div style="margin:0;padding:32px;background:#eef4ef;font-family:Open Sans,Arial,sans-serif;color:#244230;">'
                . '<div style="max-width:640px;margin:0 auto;background:#ffffff;border-radius:24px;overflow:hidden;box-shadow:0 18px 38px rgba(27,54,36,0.12);">'
                . '<div style="padding:28px 32px;background:linear-gradient(135deg,#8f2d2d 0%,#b44848 100%);color:#ffffff;">'
                . '<div style="font-size:12px;letter-spacing:.08em;text-transform:uppercase;opacity:.8;">FamiFormation</div>'
                . '<h1 style="margin:10px 0 0;font-size:28px;line-height:1.2;">Changement de planning</h1>'
                . '</div>'
                . '<div style="padding:30px 32px;">'
                . '<p style="margin:0 0 18px;font-size:16px;line-height:1.6;">Bonjour ' . e($agency['nom_contact']) . ',</p>'
                . '<p style="margin:0 0 18px;font-size:16px;line-height:1.6;">Nous vous informons qu\'un changement de planning a eu lieu pour <strong>' . e($user['prenom'] . ' ' . $user['nom']) . '</strong>, rattaché à votre agence <strong>' . e($agency['nom_agence']) . '</strong>.</p>'
                . '<p style="margin:0 0 18px;font-size:16px;line-height:1.6;">La participation de cet utilisateur à la formation <strong>' . e($formation['titre']) . '</strong> a été annulée.</p>'
                . '<div style="margin:24px 0;padding:22px;border-radius:18px;background:#fdf7f7;border:1px solid #efd3d3;">'
                . '<div style="font-size:13px;text-transform:uppercase;letter-spacing:.08em;color:#8e5f5f;margin-bottom:12px;">Session annulée</div>'
                . '<p style="margin:0 0 8px;font-size:16px;"><strong>Collaborateur :</strong> ' . e($user['prenom'] . ' ' . $user['nom']) . '</p>'
                . '<p style="margin:0 0 8px;font-size:16px;"><strong>Formation :</strong> ' . e($formation['titre']) . '</p>'
                . '<p style="margin:0 0 8px;font-size:16px;"><strong>Date initiale :</strong> ' . e(date('d/m/Y', $scheduledAt)) . '</p>'
                . '<p style="margin:0 0 8px;font-size:16px;"><strong>Heure initiale :</strong> ' . e(date('H:i', $scheduledAt)) . '</p>'
                . '<p style="margin:0;font-size:16px;"><strong>Durée prévue :</strong> ' . e($duration) . '</p>'
                . '</div>'
                . '<p style="margin:0;font-size:15px;line-height:1.6;color:#617268;">Pour toute question, vous pouvez répondre directement à cet email ou contacter l\'équipe FamiFormation.</p>'
                . '</div>'
                . '<div style="padding:18px 32px;background:#f5f8f6;color:#617268;font-size:13px;">Message automatique envoyé par FamiFormation.</div>'
                . '</div></div>';

            $sent = false;
            foreach ($recipients as $recipient) {
                $sent = sendMail($recipient, $subject, $body, true) || $sent;
            }

            return $sent;
        } catch (Throwable $e) {
            error_log('[FamiFormation] agency cancellation email failed: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('sendStudentWelcomeEmail')) {
    function sendStudentWelcomeEmail($email, $username, $passwordSetupUrl = null)
    {
        $email = trim((string) $email);
        $username = trim((string) $username);
        $passwordSetupUrl = trim((string) $passwordSetupUrl);

        if ($email === '' || $username === '') {
            return false;
        }

        $loginUrl = famiGetEnv('APP_URL', 'https://famiformation.com/login.php');
        $passwordBlock = '';
        $passwordMessage = '<p style="margin:0 0 18px;font-size:16px;line-height:1.7;">Si ton mot de passe ne t\'a pas encore été remis, tu peux toujours prendre contact avec <strong>Honorine</strong>.</p>';
        $nextStepText = 'connecte-toi, termine les formations caisse, puis réserve ton premier rendez-vous en point de vente.';

        if ($passwordSetupUrl !== '') {
            $passwordBlock = '<div style="margin:24px 0;padding:22px;border-radius:18px;background:#fff7e8;border:1px solid #f0dbac;">'
                . '<div style="font-size:13px;text-transform:uppercase;letter-spacing:.08em;color:#7a5a11;margin-bottom:12px;">Création de ton mot de passe</div>'
                . '<p style="margin:0 0 16px;font-size:16px;line-height:1.7;color:#7a5a11;">Comme aucun mot de passe n\'a été défini lors de la création de ton compte, utilise le lien ci-dessous pour en créer un. Ce lien est valable pendant 72 heures.</p>'
                . '<p style="margin:0;"><a href="' . e($passwordSetupUrl) . '" style="display:inline-block;padding:14px 22px;border-radius:999px;background:#d6a21a;color:#ffffff;font-weight:700;text-decoration:none;">Créer mon mot de passe</a></p>'
                . '</div>';
            $passwordMessage = '<p style="margin:0 0 18px;font-size:16px;line-height:1.7;">Commence par créer ton mot de passe via le bouton ci-dessous, puis connecte-toi à la plateforme pour suivre tes formations caisse.</p>';
            $nextStepText = 'crée ton mot de passe, connecte-toi, termine les formations caisse, puis réserve ton premier rendez-vous en point de vente.';
        }

        $subject = 'Bienvenue dans l\'aventure FamiFormation';
        $body = '<div style="margin:0;padding:32px;background:#eef4ef;font-family:Open Sans,Arial,sans-serif;color:#244230;">'
            . '<div style="max-width:680px;margin:0 auto;background:#ffffff;border-radius:24px;overflow:hidden;box-shadow:0 18px 38px rgba(27,54,36,0.12);">'
            . '<div style="padding:28px 32px;background:linear-gradient(135deg,#2d5a37 0%,#4a7b55 100%);color:#ffffff;">'
            . '<div style="font-size:12px;letter-spacing:.08em;text-transform:uppercase;opacity:.85;">Bienvenue chez Famiflora</div>'
            . '<h1 style="margin:10px 0 8px;font-size:30px;line-height:1.2;">Félicitations pour cette première étape</h1>'
            . '<p style="margin:0;font-size:15px;line-height:1.6;opacity:.95;">Ton parcours commence maintenant sur la plateforme FamiFormation.</p>'
            . '</div>'
            . '<div style="padding:32px;">'
            . '<p style="margin:0 0 18px;font-size:16px;line-height:1.7;">Bonjour,</p>'
            . '<p style="margin:0 0 18px;font-size:16px;line-height:1.7;">Ton compte étudiant a bien été créé. Pour continuer l\'aventure, tu vas devoir te connecter au site et suivre les formations caisse disponibles sur la plateforme.</p>'
            . '<div style="margin:24px 0;padding:22px;border-radius:18px;background:#f6faf7;border:1px solid #dde9df;">'
            . '<div style="font-size:13px;text-transform:uppercase;letter-spacing:.08em;color:#6a7d72;margin-bottom:12px;">Tes informations de connexion</div>'
            . '<p style="margin:0 0 10px;font-size:16px;"><strong>Lien du site :</strong> <a href="' . e($loginUrl) . '" style="color:#2d5a37;font-weight:700;">' . e($loginUrl) . '</a></p>'
            . '<p style="margin:0;font-size:16px;"><strong>Nom d\'utilisateur :</strong> ' . e($username) . '</p>'
            . '</div>'
            . $passwordBlock
            . '<p style="margin:0 0 18px;font-size:16px;line-height:1.7;">Une fois les formations caisse terminées et les quiz validés, tu auras accès au planning directement depuis le site. Tu pourras alors prendre rendez-vous pour suivre ta première formation en magasin.</p>'
            . $passwordMessage
            . '<div style="margin-top:26px;padding:18px 20px;border-radius:18px;background:#fff7e8;border:1px solid #f0dbac;color:#7a5a11;">'
            . '<strong>Prochaine étape :</strong> ' . $nextStepText
            . '</div>'
            . '<p style="margin:24px 0 0;font-size:15px;line-height:1.7;">À très bientôt sur FamiFormation,<br><strong>L\'équipe Famiflora</strong></p>'
            . '</div>'
            . '<div style="padding:18px 32px;background:#f5f8f6;color:#617268;font-size:13px;">Message automatique envoyé par FamiFormation.</div>'
            . '</div></div>';

        return sendMail($email, $subject, $body, true);
    }
}

if (!function_exists('sendStudentEvaluationSuccessEmail')) {
    function sendStudentEvaluationSuccessEmail($email, $firstName = '')
    {
        $email = trim((string) $email);
        $firstName = trim((string) $firstName);

        if ($email === '') {
            return false;
        }

        $onboardingUrl = rtrim((string) famiGetEnv('APP_URL', 'https://famiformation.com'), '/');
        if (substr($onboardingUrl, -9) === 'login.php') {
            $onboardingUrl = substr($onboardingUrl, 0, -9);
        }
        $onboardingUrl .= '/onboarding.php';

        $greetingName = $firstName !== '' ? $firstName : 'à toi';
        $subject = 'Bienvenue dans la famille Famiflora';
        $body = '<div style="margin:0;padding:32px;background:#eef4ef;font-family:Open Sans,Arial,sans-serif;color:#244230;">'
            . '<div style="max-width:680px;margin:0 auto;background:#ffffff;border-radius:24px;overflow:hidden;box-shadow:0 18px 38px rgba(27,54,36,0.12);">'
            . '<div style="padding:28px 32px;background:linear-gradient(135deg,#2d5a37 0%,#4a7b55 100%);color:#ffffff;">'
            . '<div style="font-size:12px;letter-spacing:.08em;text-transform:uppercase;opacity:.85;">Famiflora</div>'
            . '<h1 style="margin:10px 0 8px;font-size:30px;line-height:1.2;">Félicitations</h1>'
            . '<p style="margin:0;font-size:15px;line-height:1.6;opacity:.95;">Tu fais maintenant officiellement partie de la famille Famiflora.</p>'
            . '</div>'
            . '<div style="padding:32px;">'
            . '<p style="margin:0 0 18px;font-size:16px;line-height:1.7;">Bonjour ' . e($greetingName) . ',</p>'
            . '<p style="margin:0 0 18px;font-size:16px;line-height:1.7;">Félicitations pour la réussite de ton évaluation en magasin. Tu fais enfin partie de la famille Famiflora.</p>'
            . '<p style="margin:0 0 18px;font-size:16px;line-height:1.7;">Pour continuer ton parcours avec nous, tu dois maintenant compléter la formation onboarding sur la plateforme.</p>'
            . '<div style="margin:24px 0;padding:22px;border-radius:18px;background:#f6faf7;border:1px solid #dde9df;">'
            . '<div style="font-size:13px;text-transform:uppercase;letter-spacing:.08em;color:#6a7d72;margin-bottom:12px;">Prochaine étape</div>'
            . '<p style="margin:0 0 10px;font-size:16px;"><strong>Formation à compléter :</strong> Onboarding</p>'
            . '<p style="margin:0;font-size:16px;"><strong>Lien direct :</strong> <a href="' . e($onboardingUrl) . '" style="color:#2d5a37;font-weight:700;">' . e($onboardingUrl) . '</a></p>'
            . '</div>'
            . '<p style="margin:0 0 18px;font-size:16px;line-height:1.7;">Une fois cet onboarding terminé, tu auras accès à la suite de ton parcours sur le site.</p>'
            . '<p style="margin:24px 0 0;font-size:15px;line-height:1.7;">À bientôt,<br><strong>L’équipe Famiflora</strong></p>'
            . '</div>'
            . '<div style="padding:18px 32px;background:#f5f8f6;color:#617268;font-size:13px;">Message automatique envoyé par FamiFormation.</div>'
            . '</div></div>';

        return sendMail($email, $subject, $body, true);
    }
}

if (!function_exists('sendStudentEvaluationFailureEmail')) {
    function sendStudentEvaluationFailureEmail($email, $firstName = '')
    {
        $email = trim((string) $email);
        $firstName = trim((string) $firstName);

        if ($email === '') {
            return false;
        }

        $greetingName = $firstName !== '' ? $firstName : 'à toi';
        $subject = 'Suite à ton évaluation en magasin';
        $body = '<div style="margin:0;padding:32px;background:#f5f7f8;font-family:Open Sans,Arial,sans-serif;color:#23323a;">'
            . '<div style="max-width:680px;margin:0 auto;background:#ffffff;border-radius:24px;overflow:hidden;box-shadow:0 16px 34px rgba(27,54,36,0.12);">'
            . '<div style="padding:28px 32px;background:linear-gradient(135deg,#8f2d2d 0%,#b44848 100%);color:#ffffff;">'
            . '<div style="font-size:12px;letter-spacing:.08em;text-transform:uppercase;opacity:.85;">Famiflora</div>'
            . '<h1 style="margin:10px 0 8px;font-size:30px;line-height:1.2;">Résultat de ton évaluation</h1>'
            . '<p style="margin:0;font-size:15px;line-height:1.6;opacity:.95;">Merci pour ton implication et ton passage en magasin.</p>'
            . '</div>'
            . '<div style="padding:32px;">'
            . '<p style="margin:0 0 18px;font-size:16px;line-height:1.7;">Bonjour ' . e($greetingName) . ',</p>'
            . '<p style="margin:0 0 18px;font-size:16px;line-height:1.7;">Ton évaluation en magasin n\'a malheureusement pas été concluante cette fois-ci.</p>'
            . '<p style="margin:0 0 18px;font-size:16px;line-height:1.7;">Nous te remercions pour le temps consacré et te souhaitons bonne chance pour la suite de tes aventures professionnelles.</p>'
            . '<p style="margin:24px 0 0;font-size:15px;line-height:1.7;">L\'équipe Famiflora</p>'
            . '</div>'
            . '<div style="padding:18px 32px;background:#f3f6f7;color:#617268;font-size:13px;">Message automatique envoyé par FamiFormation.</div>'
            . '</div></div>';

        return sendMail($email, $subject, $body, true);
    }
}

if (!function_exists('sendInterimAgencyEvaluationOutcomeEmail')) {
    function sendInterimAgencyEvaluationOutcomeEmail(PDO $db, $userId, $isSuccess = true)
    {
        try {
            $tableStmt = $db->query("SHOW TABLES LIKE 'interim_agences'");
            if (!$tableStmt->fetch()) {
                return false;
            }

            $userStmt = $db->prepare('SELECT prenom, nom, interim FROM utilisateurs WHERE id = ? LIMIT 1');
            $userStmt->execute([(int) $userId]);
            $user = $userStmt->fetch(PDO::FETCH_ASSOC);

            if (!$user || empty($user['interim'])) {
                return false;
            }

            $agencyStmt = $db->prepare('SELECT nom_agence, nom_contact, email_1, email_2 FROM interim_agences WHERE nom_agence = ? LIMIT 1');
            $agencyStmt->execute([$user['interim']]);
            $agency = $agencyStmt->fetch(PDO::FETCH_ASSOC);

            if (!$agency) {
                return false;
            }

            $recipients = [];
            foreach (['email_1', 'email_2'] as $emailKey) {
                $email = trim((string) ($agency[$emailKey] ?? ''));
                if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $recipients[] = $email;
                }
            }

            if (empty($recipients)) {
                return false;
            }

            $isSuccess = (bool) $isSuccess;
            $candidateName = trim((string) (($user['prenom'] ?? '') . ' ' . ($user['nom'] ?? '')));
            $agencyContact = trim((string) ($agency['nom_contact'] ?? ''));
            if ($agencyContact === '') {
                $agencyContact = 'Madame, Monsieur';
            }

            if ($isSuccess) {
                $subject = 'Validation test magasin - ' . $candidateName;
                $headerBg = 'linear-gradient(135deg,#2d5a37 0%,#4a7b55 100%)';
                $title = 'Test validé en magasin';
                $mainMessage = 'Nous vous confirmons que <strong>' . e($candidateName) . '</strong>, rattaché à votre agence <strong>' . e($agency['nom_agence']) . '</strong>, a validé son test en magasin.';
                $detailMessage = 'Le collaborateur peut désormais commencer son aventure chez Famiflora.';
            } else {
                $subject = 'Résultat test magasin non concluant - ' . $candidateName;
                $headerBg = 'linear-gradient(135deg,#8f2d2d 0%,#b44848 100%)';
                $title = 'Test non concluant en magasin';
                $mainMessage = 'Nous vous informons que le test en magasin de <strong>' . e($candidateName) . '</strong>, rattaché à votre agence <strong>' . e($agency['nom_agence']) . '</strong>, n\'a malheureusement pas été concluant.';
                $detailMessage = 'Merci de ne plus prévoir ce profil pour le planning en point de vente chez Famiflora.';
            }

            $body = '<div style="margin:0;padding:32px;background:#eef4ef;font-family:Open Sans,Arial,sans-serif;color:#244230;">'
                . '<div style="max-width:640px;margin:0 auto;background:#ffffff;border-radius:24px;overflow:hidden;box-shadow:0 18px 38px rgba(27,54,36,0.12);">'
                . '<div style="padding:28px 32px;background:' . $headerBg . ';color:#ffffff;">'
                . '<div style="font-size:12px;letter-spacing:.08em;text-transform:uppercase;opacity:.8;">FamiFormation</div>'
                . '<h1 style="margin:10px 0 0;font-size:28px;line-height:1.2;">' . $title . '</h1>'
                . '</div>'
                . '<div style="padding:30px 32px;">'
                . '<p style="margin:0 0 18px;font-size:16px;line-height:1.6;">Bonjour ' . e($agencyContact) . ',</p>'
                . '<p style="margin:0 0 18px;font-size:16px;line-height:1.6;">' . $mainMessage . '</p>'
                . '<div style="margin:24px 0;padding:22px;border-radius:18px;background:#f6faf7;border:1px solid #dde9df;">'
                . '<div style="font-size:13px;text-transform:uppercase;letter-spacing:.08em;color:#6a7d72;margin-bottom:12px;">Information candidat</div>'
                . '<p style="margin:0 0 8px;font-size:16px;"><strong>Collaborateur :</strong> ' . e($candidateName) . '</p>'
                . '<p style="margin:0;font-size:16px;"><strong>Agence :</strong> ' . e($agency['nom_agence']) . '</p>'
                . '</div>'
                . '<p style="margin:0;font-size:15px;line-height:1.6;color:#617268;">' . $detailMessage . '</p>'
                . '</div>'
                . '<div style="padding:18px 32px;background:#f5f8f6;color:#617268;font-size:13px;">Message automatique envoyé par FamiFormation.</div>'
                . '</div></div>';

            $sent = false;
            foreach ($recipients as $recipient) {
                $sent = sendMail($recipient, $subject, $body, true) || $sent;
            }

            return $sent;
        } catch (Throwable $e) {
            error_log('[FamiFormation] agency evaluation outcome email failed: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('famiAppBaseUrl')) {
    function famiAppBaseUrl()
    {
        $configuredUrl = trim((string) famiGetEnv('APP_URL', ''));
        if ($configuredUrl !== '') {
            $configuredUrl = preg_replace('~/login\.php$~', '', $configuredUrl);
            return rtrim((string) $configuredUrl, '/');
        }

        $host = trim((string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''));
        if ($host === '') {
            return 'https://famiformation.com';
        }

        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443)
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

        return ($isHttps ? 'https://' : 'http://') . $host;
    }
}

if (!function_exists('famiBuildAppUrl')) {
    function famiBuildAppUrl($path, array $params = [])
    {
        $url = rtrim(famiAppBaseUrl(), '/') . '/' . ltrim((string) $path, '/');
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        return $url;
    }
}

if (!function_exists('issueUserAccountAccessToken')) {
    function issueUserAccountAccessToken(PDO $db, $userId, $type = 'activation', $expiresInHours = 48)
    {
        ensureUserAccountAccessColumns($db);

        $type = in_array($type, ['activation', 'reset'], true) ? $type : 'activation';
        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $expiresAt = (new DateTimeImmutable('+' . (int) $expiresInHours . ' hours'))->format('Y-m-d H:i:s');

        if ($type === 'activation') {
            $stmt = $db->prepare(
                "UPDATE utilisateurs
                 SET account_activation_pending = 1,
                     account_access_token_hash = ?,
                     account_access_expires_at = ?,
                     account_access_type = ?
                 WHERE id = ?"
            );
            $stmt->execute([$tokenHash, $expiresAt, $type, (int) $userId]);
        } else {
            $stmt = $db->prepare(
                "UPDATE utilisateurs
                 SET account_access_token_hash = ?,
                     account_access_expires_at = ?,
                     account_access_type = ?
                 WHERE id = ?"
            );
            $stmt->execute([$tokenHash, $expiresAt, $type, (int) $userId]);
        }

        return $token;
    }
}

if (!function_exists('findUserByAccountAccessToken')) {
    function findUserByAccountAccessToken(PDO $db, $token, array $allowedTypes = ['activation', 'reset'])
    {
        ensureUserAccountAccessColumns($db);

        $token = trim((string) $token);
        if ($token === '') {
            return null;
        }

        $allowedTypes = array_values(array_filter($allowedTypes, static function ($type) {
            return in_array($type, ['activation', 'reset'], true);
        }));

        if (empty($allowedTypes)) {
            $allowedTypes = ['activation', 'reset'];
        }

        $placeholders = implode(', ', array_fill(0, count($allowedTypes), '?'));
        $params = array_merge([hash('sha256', $token)], $allowedTypes);

        $stmt = $db->prepare(
            "SELECT *
             FROM utilisateurs
             WHERE account_access_token_hash = ?
               AND account_access_expires_at IS NOT NULL
               AND account_access_expires_at >= NOW()
               AND account_access_type IN ($placeholders)
             LIMIT 1"
        );
        $stmt->execute($params);

        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        return $user ?: null;
    }
}

if (!function_exists('clearUserAccountAccessToken')) {
    function clearUserAccountAccessToken(PDO $db, $userId)
    {
        ensureUserAccountAccessColumns($db);

        $stmt = $db->prepare(
            "UPDATE utilisateurs
             SET account_access_token_hash = NULL,
                 account_access_expires_at = NULL,
                 account_access_type = NULL
             WHERE id = ?"
        );

        return $stmt->execute([(int) $userId]);
    }
}

if (!function_exists('sendAccountActivationEmail')) {
    function sendAccountActivationEmail(PDO $db, $userId)
    {
        ensureUserAccountAccessColumns($db);

        $stmt = $db->prepare('SELECT id, identifiant, prenom, nom, email FROM utilisateurs WHERE id = ? LIMIT 1');
        $stmt->execute([(int) $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || empty($user['email'])) {
            return false;
        }

        $token = issueUserAccountAccessToken($db, $user['id'], 'activation', 72);
        $activationUrl = famiBuildAppUrl('set_password.php', ['token' => $token]);
        $loginUrl = famiBuildAppUrl('login.php');
        $firstName = trim((string) ($user['prenom'] ?? ''));
        $greeting = $firstName !== '' ? $firstName : trim((string) ($user['identifiant'] ?? ''));

        $subject = 'Activez votre compte FamiFormation';
        $body = '<div style="margin:0;padding:32px;background:#eef4ef;font-family:Open Sans,Arial,sans-serif;color:#244230;">'
            . '<div style="max-width:680px;margin:0 auto;background:#ffffff;border-radius:24px;overflow:hidden;box-shadow:0 18px 38px rgba(27,54,36,0.12);">'
            . '<div style="padding:28px 32px;background:linear-gradient(135deg,#2d5a37 0%,#4a7b55 100%);color:#ffffff;">'
            . '<div style="font-size:12px;letter-spacing:.08em;text-transform:uppercase;opacity:.85;">FamiFormation</div>'
            . '<h1 style="margin:10px 0 8px;font-size:30px;line-height:1.2;">Activation de votre compte</h1>'
            . '<p style="margin:0;font-size:15px;line-height:1.6;opacity:.95;">Votre accès est prêt. Il ne reste plus qu’à définir votre mot de passe.</p>'
            . '</div>'
            . '<div style="padding:32px;">'
            . '<p style="margin:0 0 18px;font-size:16px;line-height:1.7;">Bonjour ' . e($greeting) . ',</p>'
            . '<p style="margin:0 0 18px;font-size:16px;line-height:1.7;">Un compte a été créé pour vous sur FamiFormation.</p>'
            . '<div style="margin:24px 0;padding:22px;border-radius:18px;background:#f6faf7;border:1px solid #dde9df;">'
            . '<div style="font-size:13px;text-transform:uppercase;letter-spacing:.08em;color:#6a7d72;margin-bottom:12px;">Vos informations</div>'
            . '<p style="margin:0 0 10px;font-size:16px;"><strong>Identifiant :</strong> ' . e($user['identifiant']) . '</p>'
            . '<p style="margin:0;font-size:16px;"><strong>Lien de connexion :</strong> <a href="' . e($loginUrl) . '" style="color:#2d5a37;font-weight:700;">' . e($loginUrl) . '</a></p>'
            . '</div>'
            . '<p style="margin:0 0 24px;font-size:16px;line-height:1.7;">Cliquez sur le bouton ci-dessous pour définir votre mot de passe. Ce lien est valable pendant 72 heures.</p>'
            . '<p style="margin:0 0 24px;"><a href="' . e($activationUrl) . '" style="display:inline-block;padding:14px 22px;border-radius:999px;background:#d6a21a;color:#ffffff;font-weight:700;text-decoration:none;">Définir mon mot de passe</a></p>'
            . '<p style="margin:0;font-size:15px;line-height:1.7;color:#617268;">Si vous n’êtes pas à l’origine de cette demande, vous pouvez ignorer ce message.</p>'
            . '</div>'
            . '<div style="padding:18px 32px;background:#f5f8f6;color:#617268;font-size:13px;">Message automatique envoyé par FamiFormation.</div>'
            . '</div></div>';

        return sendMail($user['email'], $subject, $body, true);
    }
}

if (!function_exists('sendPasswordResetEmail')) {
    function sendPasswordResetEmail(PDO $db, $userId)
    {
        ensureUserAccountAccessColumns($db);

        $stmt = $db->prepare('SELECT id, identifiant, prenom, email FROM utilisateurs WHERE id = ? LIMIT 1');
        $stmt->execute([(int) $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || empty($user['email'])) {
            return false;
        }

        $token = issueUserAccountAccessToken($db, $user['id'], 'reset', 24);
        $resetUrl = famiBuildAppUrl('set_password.php', ['token' => $token]);
        $firstName = trim((string) ($user['prenom'] ?? ''));
        $greeting = $firstName !== '' ? $firstName : trim((string) ($user['identifiant'] ?? ''));

        $subject = 'Réinitialisation de votre mot de passe FamiFormation';
        $body = '<div style="margin:0;padding:32px;background:#eef4ef;font-family:Open Sans,Arial,sans-serif;color:#244230;">'
            . '<div style="max-width:680px;margin:0 auto;background:#ffffff;border-radius:24px;overflow:hidden;box-shadow:0 18px 38px rgba(27,54,36,0.12);">'
            . '<div style="padding:28px 32px;background:linear-gradient(135deg,#2d5a37 0%,#4a7b55 100%);color:#ffffff;">'
            . '<div style="font-size:12px;letter-spacing:.08em;text-transform:uppercase;opacity:.85;">FamiFormation</div>'
            . '<h1 style="margin:10px 0 8px;font-size:30px;line-height:1.2;">Réinitialisation du mot de passe</h1>'
            . '<p style="margin:0;font-size:15px;line-height:1.6;opacity:.95;">Un nouveau mot de passe peut être défini en quelques secondes.</p>'
            . '</div>'
            . '<div style="padding:32px;">'
            . '<p style="margin:0 0 18px;font-size:16px;line-height:1.7;">Bonjour ' . e($greeting) . ',</p>'
            . '<p style="margin:0 0 18px;font-size:16px;line-height:1.7;">Une demande de réinitialisation du mot de passe a été reçue pour votre compte <strong>' . e($user['identifiant']) . '</strong>.</p>'
            . '<p style="margin:0 0 24px;font-size:16px;line-height:1.7;">Cliquez sur le bouton ci-dessous pour choisir un nouveau mot de passe. Ce lien est valable pendant 24 heures.</p>'
            . '<p style="margin:0 0 24px;"><a href="' . e($resetUrl) . '" style="display:inline-block;padding:14px 22px;border-radius:999px;background:#d6a21a;color:#ffffff;font-weight:700;text-decoration:none;">Choisir un nouveau mot de passe</a></p>'
            . '<p style="margin:0;font-size:15px;line-height:1.7;color:#617268;">Si vous n’êtes pas à l’origine de cette demande, vous pouvez ignorer ce message.</p>'
            . '</div>'
            . '<div style="padding:18px 32px;background:#f5f8f6;color:#617268;font-size:13px;">Message automatique envoyé par FamiFormation.</div>'
            . '</div></div>';

        return sendMail($user['email'], $subject, $body, true);
    }
}

if (!function_exists('sendUsernameReminderEmail')) {
    function sendUsernameReminderEmail($email, array $accounts)
    {
        $email = trim((string) $email);
        if ($email === '' || empty($accounts)) {
            return false;
        }

        $lines = '';
        $greeting = 'Bonjour,';
        foreach ($accounts as $index => $account) {
            $identifiant = trim((string) ($account['identifiant'] ?? ''));
            if ($identifiant === '') {
                continue;
            }

            $prenom = trim((string) ($account['prenom'] ?? ''));
            if ($index === 0 && $prenom !== '') {
                $greeting = 'Bonjour ' . e($prenom) . ',';
            }

            $fullName = trim((string) (($account['prenom'] ?? '') . ' ' . ($account['nom'] ?? '')));
            $lines .= '<li style="margin-bottom:8px;"><strong>' . e($identifiant) . '</strong>';
            if ($fullName !== '') {
                $lines .= ' - ' . e($fullName);
            }
            $lines .= '</li>';
        }

        if ($lines === '') {
            return false;
        }

        $loginUrl = famiBuildAppUrl('login.php');
        $subject = 'Rappel de votre identifiant FamiFormation';
        $body = '<div style="margin:0;padding:32px;background:#eef4ef;font-family:Open Sans,Arial,sans-serif;color:#244230;">'
            . '<div style="max-width:680px;margin:0 auto;background:#ffffff;border-radius:24px;overflow:hidden;box-shadow:0 18px 38px rgba(27,54,36,0.12);">'
            . '<div style="padding:28px 32px;background:linear-gradient(135deg,#2d5a37 0%,#4a7b55 100%);color:#ffffff;">'
            . '<div style="font-size:12px;letter-spacing:.08em;text-transform:uppercase;opacity:.85;">FamiFormation</div>'
            . '<h1 style="margin:10px 0 8px;font-size:30px;line-height:1.2;">Rappel de vos identifiants</h1>'
            . '</div>'
            . '<div style="padding:32px;">'
            . '<p style="margin:0 0 18px;font-size:16px;line-height:1.7;">' . $greeting . '</p>'
            . '<p style="margin:0 0 18px;font-size:16px;line-height:1.7;">Voici le ou les identifiants associés à cette adresse email :</p>'
            . '<ul style="padding-left:20px;margin:0 0 24px;font-size:16px;line-height:1.7;">' . $lines . '</ul>'
            . '<p style="margin:0 0 18px;font-size:16px;line-height:1.7;">Vous pouvez vous connecter ici : <a href="' . e($loginUrl) . '" style="color:#2d5a37;font-weight:700;">' . e($loginUrl) . '</a></p>'
            . '<p style="margin:0;font-size:15px;line-height:1.7;color:#617268;">Si vous n’êtes pas à l’origine de cette demande, vous pouvez ignorer ce message.</p>'
            . '</div>'
            . '<div style="padding:18px 32px;background:#f5f8f6;color:#617268;font-size:13px;">Message automatique envoyé par FamiFormation.</div>'
            . '</div></div>';

        return sendMail($email, $subject, $body, true);
    }
}