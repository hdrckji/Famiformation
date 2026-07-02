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
                'is_locked'  => "ALTER TABLE modules ADD COLUMN is_locked TINYINT(1) NOT NULL DEFAULT 0",
                'uniformized' => "ALTER TABLE modules ADD COLUMN uniformized TINYINT(1) NOT NULL DEFAULT 0",
                'a_evaluer'  => "ALTER TABLE modules ADD COLUMN a_evaluer TINYINT(1) NOT NULL DEFAULT 0",
            ];
            foreach ($extraColumns as $col => $ddl) {
                $check = $db->query("SHOW COLUMNS FROM modules LIKE " . $db->quote($col));
                if ($check && !$check->fetch()) {
                    $db->exec($ddl);
                }
            }

            runModuleMigrations($db);
        } catch (Exception $e) {
            // table déjà présente ou base indisponible : on ignore
        }
    }

    /**
     * Migrations de données jouées une seule fois (drapeaux dans `modules_meta`).
     * - Crée le module « Aide » et ses sous-modules.
     * - Verrouille tous les modules déjà présents.
     */
    function runModuleMigrations(PDO $db)
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        try {
            $db->exec("CREATE TABLE IF NOT EXISTS modules_meta (mkey VARCHAR(64) PRIMARY KEY, mval VARCHAR(255) NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $hasFlag = function ($k) use ($db) {
                $s = $db->prepare("SELECT 1 FROM modules_meta WHERE mkey = ?");
                $s->execute([$k]);
                return (bool) $s->fetchColumn();
            };
            $setFlag = function ($k) use ($db) {
                $db->prepare("INSERT IGNORE INTO modules_meta (mkey, mval) VALUES (?, '1')")->execute([$k]);
            };

            // 1) Module « Aide » (une seule fois)
            if (!$hasFlag('seed_aide_v1')) {
                $roles = 'employe_magasin,etudiant,mentor,employe_logistique';
                $db->prepare("INSERT INTO modules (nom, description, is_container, parent_id, icon, roles, is_active) VALUES (?, ?, 1, NULL, ?, ?, 1)")
                   ->execute([
                       'Aide',
                       "Bloqué ? Vous avez besoin d'un renseignement ? Ce module est là pour ça : vous y trouverez les réponses à vos questions.",
                       '🆘',
                       $roles,
                   ]);
                $aideId = (int) $db->lastInsertId();
                if ($aideId > 0) {
                    $insChild = $db->prepare("INSERT INTO modules (nom, description, is_container, parent_id, icon, roles, is_active) VALUES (?, '', 0, ?, ?, ?, 1)");
                    foreach ([['Becosoft', '💻'], ['Logistique', '🚚'], ['Magasin', '🛒']] as $c) {
                        $insChild->execute([$c[0], $aideId, $c[1], $roles]);
                    }
                }
                $setFlag('seed_aide_v1');
            }

            // 2) Verrouiller tous les modules déjà présents (une seule fois)
            if (!$hasFlag('lock_existing_v1')) {
                $db->exec("UPDATE modules SET is_locked = 1");
                $setFlag('lock_existing_v1');
            }

            // 3) Verrouiller les profils de base (une seule fois)
            if (!$hasFlag('profiles_lock_base_v1')) {
                ensureProfilesTable($db);
                $db->exec("UPDATE profils SET is_locked = 1 WHERE is_core = 1");
                $setFlag('profiles_lock_base_v1');
            }
        } catch (Exception $e) {
            // migration non critique : on ignore
        }
    }

    /**
     * Crée la table des profils si besoin et amorce les profils de base.
     */
    function ensureProfilesTable(PDO $db)
    {
        try {
            $db->exec(
                "CREATE TABLE IF NOT EXISTS profils (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    cle VARCHAR(50) NOT NULL UNIQUE,
                    libelle VARCHAR(100) NOT NULL,
                    is_core TINYINT(1) NOT NULL DEFAULT 0,
                    is_locked TINYINT(1) NOT NULL DEFAULT 0,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
            );
            // Colonne is_locked ajoutée après coup (installations existantes)
            $chk = $db->query("SHOW COLUMNS FROM profils LIKE 'is_locked'");
            if ($chk && !$chk->fetch()) {
                $db->exec("ALTER TABLE profils ADD COLUMN is_locked TINYINT(1) NOT NULL DEFAULT 0");
            }
            $count = (int) $db->query("SELECT COUNT(*) FROM profils")->fetchColumn();
            if ($count === 0) {
                $seed = [
                    ['etudiant', 'Étudiant'], ['employe_magasin', 'Magasin'], ['teamcoach', 'Teamcoach'],
                    ['mentor', 'Mentor'], ['employe_logistique', 'Logistique'], ['admin', 'Admin'], ['evaluateur', 'Évaluateur'],
                ];
                $ins = $db->prepare("INSERT INTO profils (cle, libelle, is_core, is_locked) VALUES (?, ?, 1, 1)");
                foreach ($seed as $s) {
                    $ins->execute($s);
                }
            }
        } catch (Exception $e) {
            // base indisponible : on ignore
        }
    }

    /**
     * Liste des profils (clé => libellé). Sert au ciblage d'accès.
     * Profils connus + tout rôle réellement présent dans `utilisateurs`,
     * pour que la liste s'actualise automatiquement quand un profil est ajouté.
     */
    function moduleProfiles(PDO $db = null)
    {
        $fallback = [
            'etudiant'           => 'Étudiant',
            'employe_magasin'    => 'Magasin',
            'teamcoach'          => 'Teamcoach',
            'mentor'             => 'Mentor',
            'employe_logistique' => 'Logistique',
            'admin'              => 'Admin',
            'evaluateur'         => 'Évaluateur',
        ];
        if (!($db instanceof PDO)) {
            return $fallback;
        }
        ensureProfilesTable($db);
        $profiles = [];
        try {
            foreach ($db->query("SELECT cle, libelle FROM profils ORDER BY libelle ASC")->fetchAll(PDO::FETCH_ASSOC) as $p) {
                $profiles[$p['cle']] = $p['libelle'];
            }
        } catch (Exception $e) {
            $profiles = [];
        }
        if (empty($profiles)) {
            $profiles = $fallback;
        }
        // Ajoute tout rôle présent dans `utilisateurs` mais absent de la table profils
        try {
            $rows = $db->query("SELECT DISTINCT role FROM utilisateurs WHERE role IS NOT NULL AND role <> ''")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($rows as $r) {
                $r = trim((string) $r);
                if ($r !== '' && !isset($profiles[$r])) {
                    $profiles[$r] = ucwords(str_replace('_', ' ', $r));
                }
            }
        } catch (Exception $e) {
            // on garde la liste courante
        }
        return $profiles;
    }

    /**
     * Un utilisateur de rôle $role peut-il voir ce module ?
     * roles vide / NULL = visible par tous.
     */
    function userCanSeeModule(array $module, $role)
    {
        // Rôles gestionnaires : voient tous les modules (pour gérer le contenu)
        if ($role === 'admin' || $role === 'teamcoach') {
            return true;
        }
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
     * Tous les modules (racine + sous-modules), pour l'écran de gestion.
     */
    function getAllModules(PDO $db)
    {
        ensureModulesTable($db);
        return $db->query("SELECT * FROM modules ORDER BY sort_order ASC, nom ASC")->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Mot de passe UNIQUE pour verrouiller/déverrouiller ou supprimer un module
     * verrouillé. Volontairement distinct du mot de passe personnel de chaque
     * admin, pour qu'un module ne puisse pas être supprimé trop facilement.
     * Surchargeable via la variable d'environnement MODULE_LOCK_PASSWORD.
     */
    function moduleLockPassword()
    {
        $env = getenv('MODULE_LOCK_PASSWORD');
        return ($env !== false && $env !== '') ? $env : 'Admin+formation2026!';
    }

    /**
     * Vérifie le mot de passe unique de verrouillage des modules.
     * ($db conservé pour compatibilité des appels existants.)
     */
    function adminPasswordOk(PDO $db, $password)
    {
        return is_string($password) && $password !== '' && hash_equals(moduleLockPassword(), $password);
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

        // Styles + JS de la zone d'upload et des options avancées (émis 1x par page)
        static $assetsDone = false;
        if (!$assetsDone) {
            $assetsDone = true;
            echo moduleFieldsAssets();
        }
        ?>
        <?php if (!empty($module['is_locked'])): ?>
            <div class="lock-note">🔒 Module verrouillé — saisissez le mot de passe de verrouillage pour enregistrer une modification.</div>
            <label>Mot de passe de verrouillage</label>
            <input type="password" name="admin_password" placeholder="Mot de passe de verrouillage" required autocomplete="off" style="width:100%; box-sizing:border-box; padding:10px; border:1px solid #ccc; border-radius:8px; font:inherit;">
        <?php endif; ?>
        <label>Nom du module</label>
        <input type="text" name="nom" required maxlength="150" value="<?= $nom ?>">
        <label>Description (quelques mots)</label>
        <textarea name="description" rows="2" maxlength="500"><?= $desc ?></textarea>
        <label class="chk"><input type="checkbox" name="is_container" value="1" <?= $isContainer ? 'checked' : '' ?>> Ce module contient d'autres modules</label>

        <label>Accès <small>(rien de coché = visible par tous)</small></label>
        <details class="access-drop">
            <summary>Choisir les profils…</summary>
            <div class="roles-wrap">
                <?php foreach ($profiles as $key => $lbl): ?>
                    <label class="role-chk"><input type="checkbox" name="roles[]" value="<?= htmlspecialchars($key) ?>" <?= in_array($key, $curRoles, true) ? 'checked' : '' ?>> <?= htmlspecialchars($lbl) ?></label>
                <?php endforeach; ?>
            </div>
        </details>

        <details class="adv-options">
            <summary>Options avancées — icône &amp; image</summary>
            <div class="adv-body">
                <p class="adv-note">Sans choix ici, l'icône par défaut est utilisée : 📄 pour un contenu, 📂 pour un module qui en contient d'autres.</p>

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
                <div class="dzm">
                    <input type="file" name="icon_image" accept="image/png,image/jpeg,image/gif,image/webp,image/svg+xml" class="dzm-input">
                    <div class="dzm-icon">🖼️</div>
                    <div class="dzm-text">Glissez une image ici ou cliquez pour parcourir</div>
                    <div class="dzm-file" hidden></div>
                </div>
            </div>
        </details>
        <?php
    }

    /**
     * Styles + JS communs des champs de module (zone d'upload d'icône, options avancées).
     * Auto-contenu pour fonctionner sur toutes les pages (accueil, module, paramètres).
     */
    function moduleFieldsAssets()
    {
        ob_start();
        ?>
        <style>
        .adv-options { margin-top: 16px; border: 1px solid #e0e6e2; border-radius: 10px; padding: 2px 12px; background: #fafcfb; }
        .adv-options > summary { cursor: pointer; font-weight: 700; color: #2d5a37; padding: 10px 2px; list-style: none; }
        .adv-options > summary::-webkit-details-marker { display: none; }
        .adv-options > summary::before { content: '▸ '; }
        .adv-options[open] > summary::before { content: '▾ '; }
        .adv-body { padding: 4px 2px 12px; }
        .adv-note { font-size: 0.82rem; color: #777; margin: 0 0 10px; }
        .access-drop { margin-top: 4px; border: 1px solid #cdd8d0; border-radius: 10px; padding: 2px 12px; background: #fff; }
        .access-drop > summary { cursor: pointer; font-weight: 600; color: #2d5a37; padding: 10px 2px; list-style: none; }
        .access-drop > summary::-webkit-details-marker { display: none; }
        .access-drop > summary::before { content: '▸ '; }
        .access-drop[open] > summary::before { content: '▾ '; }
        .access-drop .roles-wrap { display: flex; flex-wrap: wrap; gap: 10px 16px; padding: 6px 2px 12px; }
        .access-drop .role-chk { font-weight: 600; display: flex; align-items: center; gap: 6px; }
        .lock-note { background: #fff8e1; border: 1px solid #ffe082; color: #6a5400; padding: 10px 12px; border-radius: 10px; font-weight: 700; font-size: 0.86rem; margin-top: 6px; }
        .dzm { position: relative; border: 2px dashed #b9cdbf; border-radius: 12px; background: #f6faf7; padding: 16px; text-align: center; cursor: pointer; margin-top: 4px; transition: all .15s ease; }
        .dzm:hover { border-color: #2d5a37; background: #eef7f0; }
        .dzm.over { border-color: #2d5a37; background: #e3f2e7; }
        .dzm.has-file { border-style: solid; border-color: #2d5a37; background: #e8f5e9; }
        .dzm-input { position: absolute; top: 0; left: 0; width: 100%; height: 100%; opacity: 0; cursor: pointer; }
        .dzm-icon { font-size: 1.8rem; line-height: 1; }
        .dzm-text { color: #6c7a70; font-size: 0.84rem; margin-top: 2px; }
        .dzm-file { margin-top: 6px; font-weight: 700; color: #244230; word-break: break-all; }
        </style>
        <script>
        (function () {
            document.addEventListener('change', function (e) {
                var input = e.target;
                if (!input.classList || !input.classList.contains('dzm-input')) { return; }
                var dz = input.closest('.dzm'); if (!dz) { return; }
                var label = dz.querySelector('.dzm-file');
                if (input.files && input.files.length) {
                    label.textContent = '✓ ' + input.files[0].name; label.hidden = false; dz.classList.add('has-file');
                } else {
                    label.hidden = true; dz.classList.remove('has-file');
                }
            });
            function overToggle(add) {
                return function (e) {
                    var dz = e.target && e.target.closest ? e.target.closest('.dzm') : null;
                    if (dz) { dz.classList[add ? 'add' : 'remove']('over'); }
                };
            }
            document.addEventListener('dragenter', overToggle(true), true);
            document.addEventListener('dragover', overToggle(true), true);
            document.addEventListener('dragleave', overToggle(false), true);
            document.addEventListener('drop', overToggle(false), true);
        })();
        </script>
        <?php
        return ob_get_clean();
    }

    /**
     * Script JS du sélecteur d'icône (à inclure une fois par page).
     */
    function moduleFormScript()
    {
        return "<script>function pickIcon(formId, emoji, btn){var f=document.getElementById(formId+'_icon');if(f)f.value=emoji;var w=document.getElementById(formId+'_iconwrap');if(w){var b=w.querySelectorAll('.icon-opt');for(var i=0;i<b.length;i++){b[i].classList.remove('sel');}}btn.classList.add('sel');}</script>";
    }
}
