<?php
// ============================================================
// Gestion des modules dynamiques (créés par l'admin depuis le site)
// ============================================================

if (!function_exists('ensureModulesTable')) {
    /**
     * Crée la table `modules` si elle n'existe pas + colonnes ajoutées après coup.
     */
    function ensureModulesTable(PDO $db)
    {
        try {
            $db->exec(
                "CREATE TABLE IF NOT EXISTS modules (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    nom VARCHAR(150) NOT NULL,
                    description VARCHAR(500) NULL,
                    is_container TINYINT(1) NOT NULL DEFAULT 0,
                    parent_id INT NULL,
                    pdf_path VARCHAR(255) NULL,
                    video_path VARCHAR(255) NULL,
                    is_active TINYINT(1) NOT NULL DEFAULT 1,
                    sort_order INT NOT NULL DEFAULT 0,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_modules_parent (parent_id),
                    INDEX idx_modules_active (is_active)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
            );

            // Colonnes ajoutées après coup (icône, profils ayant accès)
            $extraColumns = [
                'icon'  => "ALTER TABLE modules ADD COLUMN icon VARCHAR(16) NULL",
                'roles' => "ALTER TABLE modules ADD COLUMN roles VARCHAR(255) NULL",
            ];
            foreach ($extraColumns as $col => $ddl) {
                $check = $db->query("SHOW COLUMNS FROM modules LIKE " . $db->quote($col));
                if ($check && !$check->fetch()) {
                    $db->exec($ddl);
                }
            }
        } catch (Exception $e) {
            // table déjà présente ou base indisponible : on ignore
        }
    }

    /**
     * Liste des profils existants (clé => libellé). Sert au ciblage d'accès.
     * NB: deviendra dynamique quand on fera "Gestion des profils".
     */
    function moduleProfiles()
    {
        return [
            'etudiant'           => 'Étudiant',
            'employe_magasin'    => 'Magasin',
            'teamcoach'          => 'Teamcoach',
            'mentor'             => 'Mentor',
            'employe_logistique' => 'Logistique',
            'admin'              => 'Admin',
            'evaluateur'         => 'Évaluateur',
        ];
    }

    /**
     * Un utilisateur de rôle $role peut-il voir ce module ?
     * roles vide / NULL = visible par tous.
     */
    function userCanSeeModule(array $module, $role)
    {
        $roles = trim((string) ($module['roles'] ?? ''));
        if ($roles === '') {
            return true; // tous
        }
        $allowed = array_filter(array_map('trim', explode(',', $roles)));
        return in_array($role, $allowed, true);
    }

    /**
     * Récupère les modules d'un niveau donné (NULL = niveau racine).
     */
    function getModules(PDO $db, $parentId = null, $onlyActive = true)
    {
        ensureModulesTable($db);
        $sql = "SELECT * FROM modules WHERE ";
        $sql .= $parentId === null ? "parent_id IS NULL" : "parent_id = :pid";
        if ($onlyActive) {
            $sql .= " AND is_active = 1";
        }
        $sql .= " ORDER BY sort_order ASC, nom ASC";
        $stmt = $db->prepare($sql);
        if ($parentId !== null) {
            $stmt->bindValue(':pid', (int) $parentId, PDO::PARAM_INT);
        }
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Récupère un module par son id.
     */
    function getModuleById(PDO $db, $id)
    {
        ensureModulesTable($db);
        $stmt = $db->prepare("SELECT * FROM modules WHERE id = ? LIMIT 1");
        $stmt->execute([(int) $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Icône à afficher : celle choisie, sinon une par défaut.
     */
    function moduleIcon(array $module)
    {
        $icon = trim((string) ($module['icon'] ?? ''));
        if ($icon !== '') {
            return $icon;
        }
        return !empty($module['is_container']) ? '📂' : '📄';
    }

    /**
     * Jeu d'icônes proposées dans le sélecteur.
     */
    function moduleIconChoices()
    {
        return ['📄','📂','📦','🎓','🛒','🧑‍💼','📊','🦺','🚀','🏆','🗓️','🔧','📚','💡','⭐','🎯','🎬','📁','🧩','🔒'];
    }
}
