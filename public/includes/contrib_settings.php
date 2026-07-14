<?php
// ============================================================
// contrib_settings.php — DROITS DE CONTRIBUTION (création de module / ajout de contenu).
//   L'admin choisit : activé ou non, quels PROFILS peuvent contribuer, et dans quelles
//   ZONES (modules-conteneurs). Les contributeurs ne peuvent créer/ajouter QUE dans ces
//   zones (ou leurs sous-modules), jamais à l'accueil. L'admin, lui, n'est jamais limité.
// ============================================================

if (!function_exists('contribGet')) {
    function contribGet($db)
    {
        $enabled = (widgetGet($db, 'contrib_enabled', '0') === '1');
        $roles = array_values(array_filter(array_map('trim', explode(',', (string) widgetGet($db, 'contrib_roles', '')))));
        $zones = array_values(array_filter(array_map('intval', explode(',', (string) widgetGet($db, 'contrib_zones', ''))), function ($z) { return $z > 0; }));
        return ['enabled' => $enabled, 'roles' => $roles, 'zones' => $zones];
    }
}

if (!function_exists('contribRoleAllowed')) {
    /** Ce rôle (profil) a-t-il le droit de contribuer ? (l'admin est géré à part par l'appelant) */
    function contribRoleAllowed($db, $role)
    {
        $c = contribGet($db);
        return $c['enabled'] && $role !== '' && in_array($role, $c['roles'], true);
    }
}

if (!function_exists('contribModuleInZone')) {
    /** Le module (ou l'un de ses parents) est-il une zone de contribution autorisée ? */
    function contribModuleInZone($db, $moduleId)
    {
        $c = contribGet($db);
        if (empty($c['zones'])) { return false; }
        $cur = (int) $moduleId;
        $guard = 0;
        while ($cur > 0 && $guard++ < 60) {
            if (in_array($cur, $c['zones'], true)) { return true; }
            try {
                $st = $db->prepare("SELECT parent_id FROM modules WHERE id = ? LIMIT 1");
                $st->execute([$cur]);
                $p = $st->fetchColumn();
            } catch (Exception $e) { return false; }
            $cur = ($p === false || $p === null) ? 0 : (int) $p;
        }
        return false;
    }
}

if (!function_exists('contribCanCreateIn')) {
    /** Ce contributeur peut-il créer un (sous-)module sous ce parent ? (parent dans une zone) */
    function contribCanCreateIn($db, $parentId, $role)
    {
        if (!contribRoleAllowed($db, $role)) { return false; }
        $parentId = (int) $parentId;
        if ($parentId <= 0) { return false; } // jamais à la racine / accueil
        return contribModuleInZone($db, $parentId);
    }
}

if (!function_exists('contribCanAddContent')) {
    /** Ce contributeur peut-il ajouter du contenu à ce module ? (module dans une zone) */
    function contribCanAddContent($db, $module, $role)
    {
        if (!contribRoleAllowed($db, $role)) { return false; }
        return contribModuleInZone($db, (int) ($module['id'] ?? 0));
    }
}

if (!function_exists('contribHandlePost')) {
    function contribHandlePost($db)
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_POST['action'] ?? '') !== 'save_contrib') {
            return;
        }
        requireValidCSRF();
        if (($_SESSION['role'] ?? '') !== 'admin') { header('Location: index.php'); exit(); }

        // Bascule seule (interrupteur du titre) : on n'écrase NI les profils NI les zones.
        if (!empty($_POST['toggle_only'])) {
            widgetSet($db, 'contrib_enabled', !empty($_POST['contrib_enabled']) ? '1' : '0');
            $_SESSION['module_flash'] = !empty($_POST['contrib_enabled'])
                ? '🤝 Contribution activée.'
                : '🤝 Contribution coupée : seuls les admins peuvent ajouter du contenu.';
            header('Location: parametres.php#prefs');
            exit();
        }

        widgetSet($db, 'contrib_enabled', !empty($_POST['contrib_enabled']) ? '1' : '0');

        $validRoles = array_keys(moduleProfiles($db));
        $roles = is_array($_POST['contrib_roles'] ?? null) ? $_POST['contrib_roles'] : [];
        $roles = array_values(array_intersect($validRoles, array_map('strval', $roles)));
        $roles = array_values(array_diff($roles, ['admin'])); // admin toujours autorisé, inutile de le lister
        widgetSet($db, 'contrib_roles', implode(',', $roles));

        $zones = is_array($_POST['contrib_zones'] ?? null) ? $_POST['contrib_zones'] : [];
        $zones = array_values(array_unique(array_filter(array_map('intval', $zones), function ($z) { return $z > 0; })));
        widgetSet($db, 'contrib_zones', implode(',', $zones));

        $_SESSION['module_flash'] = "✅ Droits de contribution enregistrés.";
        header('Location: parametres.php#prefs');
        exit();
    }
}

if (!function_exists('contribSettingsCard')) {
    function contribSettingsCard($db)
    {
        $c = contribGet($db);
        $profiles = moduleProfiles($db);
        // Zones candidates = modules conteneurs.
        $containers = [];
        try {
            $containers = $db->query("SELECT id, nom, parent_id FROM modules WHERE is_container = 1 ORDER BY nom ASC")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {}
        ?>
        <div class="pref-block">
            <?php
                require_once __DIR__ . '/ui_switch.php';
                famiPrefHead('🤝 Droits de contribution', 'save_contrib', 'contrib_enabled', $c['enabled'],
                    "Coupé : seuls les admins peuvent créer un module ou ajouter du contenu.");
            ?>
            <p class="muted" style="margin-top:-6px;">Qui peut <strong>créer un module</strong> ou <strong>ajouter du contenu</strong>, et <strong>où</strong>. L'admin n'est jamais limité.</p>
            <form method="POST" action="parametres.php">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="save_contrib">
                <input type="hidden" name="contrib_enabled" value="<?= $c['enabled'] ? '1' : '0' ?>">
                <div class="pref-body<?= $c['enabled'] ? '' : ' pref-off' ?>">

                <div style="font-weight:700; color:#244230; margin:4px 0 6px;">Profils autorisés</div>
                <div style="display:flex; flex-wrap:wrap; gap:12px; margin-bottom:16px;">
                    <?php foreach ($profiles as $key => $label): if ($key === 'admin') { continue; } ?>
                    <label style="display:flex; align-items:center; gap:6px; font-weight:600;">
                        <input type="checkbox" name="contrib_roles[]" value="<?= htmlspecialchars($key) ?>" <?= in_array($key, $c['roles'], true) ? 'checked' : '' ?>>
                        <?= htmlspecialchars($label) ?>
                    </label>
                    <?php endforeach; ?>
                </div>

                <?php if (empty($containers)): ?>
                    <div style="font-weight:700; color:#244230; margin:4px 0 6px;">Zones autorisées</div>
                    <p class="muted">Aucun module-conteneur pour l'instant. Crée d'abord un conteneur pour en faire une zone.</p>
                <?php else: ?>
                <!-- Les zones sont nombreuses : repliées par défaut, on n'ouvre que si on veut les changer. -->
                <details class="zonefold"<?= !empty($c['zones']) ? '' : ' open' ?>>
                    <summary>
                        Zones autorisées
                        <span class="muted" style="font-weight:400;">— <?= count($c['zones']) ?> sélectionnée<?= count($c['zones']) > 1 ? 's' : '' ?> sur <?= count($containers) ?></span>
                    </summary>
                    <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:8px; padding:12px 14px 14px;">
                        <?php foreach ($containers as $ct): ?>
                        <label style="display:flex; align-items:center; gap:8px; background:#f4f7f6; border:1px solid #e1e8e3; border-radius:10px; padding:8px 12px;">
                            <input type="checkbox" name="contrib_zones[]" value="<?= (int) $ct['id'] ?>" <?= in_array((int) $ct['id'], $c['zones'], true) ? 'checked' : '' ?>>
                            📁 <?= htmlspecialchars((string) $ct['nom']) ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </details>
                <style>
                .zonefold { border:1px solid #dde7e1; border-radius:12px; background:#fbfdfc; margin-bottom:16px; }
                .zonefold > summary { cursor:pointer; padding:11px 14px; font-weight:700; color:#244230; list-style:none; }
                .zonefold > summary::-webkit-details-marker { display:none; }
                .zonefold > summary::before { content:'▸'; display:inline-block; margin-right:8px; color:#3E8E4E; transition:transform .15s; }
                .zonefold[open] > summary::before { transform:rotate(90deg); }
                .zonefold > summary:hover { background:#f2f8f4; border-radius:12px; }
                </style>
                <?php endif; ?>

                </div>
                <button type="submit" class="btn btn-primary">💾 Enregistrer les droits</button>
            </form>
        </div>
        <?php
    }
}
