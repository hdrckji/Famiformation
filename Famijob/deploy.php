<?php
/**
 * Script de déploiement automatique FamiJob
 * Exécute ce script UNE FOIS sur l'hébergeur depuis ton navigateur:
 * https://famijob.be/deploy.php
 * ou depuis le terminal: php deploy.php
 */

$hebergeurRoot = __DIR__ . '/..';
$publicDir = $hebergeurRoot . '/public';
$famijobDir = __DIR__;

function adaptFileForFamiJob($sourceFile, $destFile) {
    if (!file_exists($sourceFile)) {
        throw new Exception("Fichier source non trouvé: $sourceFile");
    }

    $content = file_get_contents($sourceFile);
    
    // Adapter le contrôle de rôle pour FamiJob (admin + teamcoach)
    if (strpos($destFile, 'interim_horaires.php') !== false) {
        // Remplacer le check de rôle
        $content = preg_replace(
            '/(\$role = getCurrentRole\(\);)\s*\n\s*if \(!in_array\(\$role.*?\n\s*\}/',
            '$1' . "\n" . 'if (!in_array($role, [\'admin\', \'teamcoach\'], true)) {' . "\n" .
            '    header(\'Location: ../public/index.php\');' . "\n" .
            '    exit();' . "\n" .
            '}' . "\n",
            $content
        );
    } elseif (strpos($destFile, 'admin_disponibilites_etudiants.php') !== false) {
        // Adapter pour admin_disponibilites_etudiants.php
        $content = preg_replace(
            '/(\$currentRole = getCurrentRole\(\);)\s*\n\s*if \(!in_array\(\$currentRole.*?\n\s*\}/',
            '$1' . "\n" . 'if (!in_array($currentRole, [\'admin\', \'teamcoach\'], true)) {' . "\n" .
            '    header(\'Location: ../public/index.php\');' . "\n" .
            '    exit();' . "\n" .
            '}' . "\n",
            $content
        );
    }

    // Écrire le fichier
    if (file_put_contents($destFile, $content) === false) {
        throw new Exception("Impossible d'écrire: $destFile");
    }

    return true;
}

try {
    echo "<h1>🚀 Déploiement FamiJob</h1>";
    echo "<pre style='background:#f4f4f4;padding:12px;border-radius:6px;'>";

    // 1. Copier interim_horaires.php
    echo "✓ Copie interim_horaires.php...";
    adaptFileForFamiJob(
        $publicDir . '/interim_horaires.php',
        $famijobDir . '/interim_horaires.php'
    );
    echo " OK\n";

    // 2. Copier admin_disponibilites_etudiants.php
    echo "✓ Copie admin_disponibilites_etudiants.php...";
    adaptFileForFamiJob(
        $publicDir . '/admin_disponibilites_etudiants.php',
        $famijobDir . '/admin_disponibilites_etudiants.php'
    );
    echo " OK\n";

    // 3. Copier includes/
    echo "✓ Copie du dossier includes/...";
    $includesSource = $publicDir . '/includes';
    $includesDest = $famijobDir . '/includes';
    
    if (is_dir($includesSource)) {
        if (!is_dir($includesDest)) {
            mkdir($includesDest, 0755, true);
        }
        
        $files = array_diff(scandir($includesSource), ['.', '..']);
        foreach ($files as $file) {
            if (is_file($includesSource . '/' . $file)) {
                copy($includesSource . '/' . $file, $includesDest . '/' . $file);
            }
        }
    }
    echo " OK\n";

    // 4. Copier .env
    echo "✓ Copie .env...";
    if (file_exists($publicDir . '/.env')) {
        copy($publicDir . '/.env', $famijobDir . '/.env');
    } else {
        echo " (fichier non trouvé, à créer manuellement)";
    }
    echo " OK\n";

    // 5. Créer le dossier uploads
    echo "✓ Création du dossier uploads/...";
    @mkdir($famijobDir . '/uploads', 0755, true);
    @mkdir($famijobDir . '/uploads/profils', 0755, true);
    echo " OK\n";

    echo "\n";
    echo "=== ✅ DÉPLOIEMENT RÉUSSI ===\n";
    echo "\nFichiers créés:\n";
    echo "  ✓ famijob/config.php\n";
    echo "  ✓ famijob/index.php\n";
    echo "  ✓ famijob/interim_horaires_demandes.php\n";
    echo "  ✓ famijob/interim_horaires.php\n";
    echo "  ✓ famijob/admin_disponibilites_etudiants.php\n";
    echo "  ✓ famijob/includes/\n";
    echo "  ✓ famijob/.env\n";
    echo "  ✓ famijob/uploads/profils/\n";
    echo "\nÉtapes suivantes:\n";
    echo "  1. Configurer votre DNS: famijob.be → dossier famijob/\n";
    echo "  2. Tester l'accès: https://famijob.be\n";
    echo "  3. Supprimer ce fichier deploy.php pour des raisons de sécurité\n";
    echo "\n";

} catch (Exception $e) {
    echo "❌ ERREUR: " . htmlspecialchars($e->getMessage()) . "\n";
}

echo "</pre>";
?>
