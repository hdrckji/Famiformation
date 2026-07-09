<?php
// ============================================================
// Widget d'accueil (météo, date, horaires, phrases qui défilent…)
// Construit par étapes. Étape 1 : coque + date + activation/accès.
// ============================================================

if (!function_exists('ensureWidgetTables')) {

    function ensureWidgetTables(PDO $db)
    {
        try {
            $db->exec(
                "CREATE TABLE IF NOT EXISTS widget_settings (
                    skey VARCHAR(64) PRIMARY KEY,
                    sval TEXT NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
            );
            // Réglages par défaut (une seule fois) : activé, visible par l'admin seulement pour l'instant
            $count = (int) $db->query("SELECT COUNT(*) FROM widget_settings WHERE skey IN ('enabled','roles')")->fetchColumn();
            if ($count === 0) {
                $ins = $db->prepare("INSERT IGNORE INTO widget_settings (skey, sval) VALUES (?, ?)");
                $ins->execute(['enabled', '1']);
                $ins->execute(['roles', 'admin']);
            }
        } catch (Exception $e) {
            // base indisponible : on ignore
        }
    }

    function widgetGet(PDO $db, $key, $default = null)
    {
        try {
            ensureWidgetTables($db);
            $st = $db->prepare("SELECT sval FROM widget_settings WHERE skey = ? LIMIT 1");
            $st->execute([$key]);
            $v = $st->fetchColumn();
            return $v === false ? $default : $v;
        } catch (Exception $e) {
            return $default;
        }
    }

    function widgetSet(PDO $db, $key, $val)
    {
        try {
            ensureWidgetTables($db);
            $db->prepare("INSERT INTO widget_settings (skey, sval) VALUES (?, ?) ON DUPLICATE KEY UPDATE sval = VALUES(sval)")
               ->execute([$key, (string) $val]);
        } catch (Exception $e) {
            // on ignore
        }
    }

    function widgetEnabled(PDO $db)
    {
        return widgetGet($db, 'enabled', '1') === '1';
    }

    function widgetRoles(PDO $db)
    {
        $r = (string) widgetGet($db, 'roles', 'admin');
        return array_values(array_filter(array_map('trim', explode(',', $r))));
    }

    /**
     * Le widget doit-il être affiché pour ce rôle ? (activé ET rôle autorisé ; liste vide = tous)
     */
    function userSeesWidget(PDO $db, $role)
    {
        if (!widgetEnabled($db)) {
            return false;
        }
        $roles = widgetRoles($db);
        if (empty($roles)) {
            return true;
        }
        return in_array($role, $roles, true);
    }

    /**
     * Date du jour localisée FR / NL (ex : « Mercredi 9 juillet 2026 »).
     */
    function widgetDate()
    {
        $lang = (function_exists('currentLang') && currentLang() === 'nl') ? 'nl' : 'fr';
        $jours = [
            'fr' => ['Sunday' => 'dimanche', 'Monday' => 'lundi', 'Tuesday' => 'mardi', 'Wednesday' => 'mercredi', 'Thursday' => 'jeudi', 'Friday' => 'vendredi', 'Saturday' => 'samedi'],
            'nl' => ['Sunday' => 'zondag', 'Monday' => 'maandag', 'Tuesday' => 'dinsdag', 'Wednesday' => 'woensdag', 'Thursday' => 'donderdag', 'Friday' => 'vrijdag', 'Saturday' => 'zaterdag'],
        ];
        $mois = [
            'fr' => [1 => 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'],
            'nl' => [1 => 'januari', 'februari', 'maart', 'april', 'mei', 'juni', 'juli', 'augustus', 'september', 'oktober', 'november', 'december'],
        ];
        $jour = $jours[$lang][date('l')] ?? date('l');
        $m = $mois[$lang][(int) date('n')] ?? date('F');
        return ucfirst($jour) . ' ' . date('j') . ' ' . $m . ' ' . date('Y');
    }

    /**
     * Rendu du widget d'accueil.
     * Étape 1 : cadre + date (bas-droite) + emplacements météo (haut-gauche) et centre.
     * La météo, les horaires et les phrases qui défilent viendront aux étapes suivantes.
     */
    function renderWidget(PDO $db)
    {
        $tt = function ($fr, $nl) {
            return function_exists('t') ? t($fr, $nl) : $fr;
        };
        ob_start();
        ?>
        <div class="home-widget">
            <div class="hw-weather">🌤️ <span class="hw-soon"><?= htmlspecialchars($tt('Météo à venir', 'Weer binnenkort')) ?></span></div>
            <div class="hw-center"><?= htmlspecialchars($tt('Bientôt : horaires & infos qui défilent', 'Binnenkort: uren & info')) ?></div>
            <div class="hw-date"><?= htmlspecialchars(widgetDate()) ?></div>
        </div>
        <style>
        .home-widget { position: relative; width: 42%; min-width: 320px; max-width: 640px; min-height: 155px; margin: 12px auto 6px; background: rgba(255,255,255,0.95); border-radius: 18px; box-shadow: 0 10px 25px rgba(0,0,0,0.12); padding: 16px 18px; box-sizing: border-box; }
        .hw-weather { position: absolute; top: 14px; left: 16px; font-weight: 700; color: #2d5a37; }
        .hw-soon { color: #9bb3a3; font-weight: 600; font-size: 0.9rem; }
        .hw-date { position: absolute; bottom: 12px; right: 16px; font-weight: 600; color: #666; font-size: 0.9rem; }
        .hw-center { display: flex; align-items: center; justify-content: center; min-height: 155px; text-align: center; color: #2d5a37; font-weight: 700; font-size: 1.1rem; padding: 34px 60px; box-sizing: border-box; }
        @media (max-width: 900px) { .home-widget { width: 94%; } .hw-center { padding: 40px 14px; } }
        </style>
        <?php
        return ob_get_clean();
    }
}
