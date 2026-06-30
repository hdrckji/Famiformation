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
                'icon'       => "ALTER TABLE modules ADD COLUMN icon VARCHAR(16) NULL",
                'roles'      => "ALTER TABLE modules ADD COLUMN roles VARCHAR(255) NULL",
                'icon_image' => "ALTER TABLE modules ADD COLUMN icon_image VARCHAR(255) NULL",
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

    /**
     * Rendu HTML de l'icône d'un module : image uploadée si présente, sinon emoji.
     */
    function moduleIconHtml(array $module, $size = '3.5rem')
    {
        $img = trim((string) ($module['icon_image'] ?? ''));
        if ($img !== '') {
            return '<img src="' . htmlspecialchars($img) . '" alt="" style="width:' . $size . ';height:' . $size . ';object-fit:contain;display:inline-block;">';
        }
        return '<span class="tile-icon" style="font-size:' . $size . ';">' . moduleIcon($module) . '</span>';
    }

    /**
     * Libellé des profils ayant accès (pour les tableaux).
     */
    function rolesLabel($module, $profiles)
    {
        $roles = array_filter(array_map('trim', explode(',', (string) ($module['roles'] ?? ''))));
        if (empty($roles)) {
            return 'Tous';
        }
        $labels = [];
        foreach ($roles as $r) {
            $labels[] = $profiles[$r] ?? $r;
        }
        return implode(', ', $labels);
    }

    /**
     * Champs communs d'un module (création/édition), réutilisés sur l'accueil et les Paramètres.
     */
    function renderModuleFields($formId, $module, $profiles, $icons)
    {
        $nom         = htmlspecialchars($module['nom'] ?? '');
        $desc        = htmlspecialchars($module['description'] ?? '');
        $isContainer = !empty($module['is_container']);
        $curIcon     = trim((string) ($module['icon'] ?? ''));
        $curImage    = trim((string) ($module['icon_image'] ?? ''));
        $curRoles    = array_filter(array_map('trim', explode(',', (string) ($module['roles'] ?? ''))));
        ?>
        <label>Nom du module</label>
        <input type="text" name="nom" required maxlength="150" value="<?= $nom ?>">
        <label>Description (quelques mots)</label>
        <textarea name="description" rows="2" maxlength="500"><?= $desc ?></textarea>
        <label class="chk"><input type="checkbox" name="is_container" value="1" <?= $isContainer ? 'checked' : '' ?>> Ce module contient d'autres modules</label>

        <label>Icône (emoji)</label>
        <input type="hidden" name="icon" id="<?= $formId ?>_icon" value="<?= htmlspecialchars($curIcon) ?>">
        <div class="icon-wrap" id="<?= $formId ?>_iconwrap">
            <?php foreach ($icons as $em): ?>
                <button type="button" class="icon-opt <?= ($em === $curIcon) ? 'sel' : '' ?>" onclick="pickIcon('<?= $formId ?>','<?= $em ?>',this)"><?= $em ?></button>
            <?php endforeach; ?>
        </div>

        <label>…ou une image d'icône (remplace l'emoji)</label>
        <?php if ($curImage !== ''): ?>
            <div style="margin-bottom:6px;"><img src="<?= htmlspecialchars($curImage) ?>" alt="" style="width:48px;height:48px;object-fit:contain;vertical-align:middle;border:1px solid #ddd;border-radius:8px;">
            <label class="chk" style="display:inline-flex;margin-left:10px;"><input type="checkbox" name="remove_icon_image" value="1"> Retirer l'image</label></div>
        <?php endif; ?>
        <input type="file" name="icon_image" accept="image/png,image/jpeg,image/gif,image/webp,image/svg+xml">

        <label>Accès — quels profils voient ce module ? <small>(aucun coché = visible par tous)</small></label>
        <div class="roles-wrap">
            <?php foreach ($profiles as $key => $lbl): ?>
                <label class="role-chk"><input type="checkbox" name="roles[]" value="<?= $key ?>" <?= in_array($key, $curRoles, true) ? 'checked' : '' ?>> <?= htmlspecialchars($lbl) ?></label>
            <?php endforeach; ?>
        </div>
        <?php
    }

    /**
     * Script JS du sélecteur d'icône (à inclure une fois par page).
     */
    function moduleFormScript()
    {
        return "<script>function pickIcon(formId, emoji, btn){var f=document.getElementById(formId+'_icon');if(f)f.value=emoji;var w=document.getElementById(formId+'_iconwrap');if(w){var b=w.querySelectorAll('.icon-opt');for(var i=0;i<b.length;i++){b[i].classList.remove('sel');}}btn.classList.add('sel');}</script>";
    }
}
