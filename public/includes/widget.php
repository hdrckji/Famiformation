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

            // Phrases qui défilent (blagues / infos jardinerie)
            $db->exec(
                "CREATE TABLE IF NOT EXISTS widget_phrases (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    texte VARCHAR(500) NOT NULL,
                    categorie VARCHAR(30) NOT NULL DEFAULT 'info',
                    actif TINYINT(1) NOT NULL DEFAULT 1,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
            );
            $cph = (int) $db->query("SELECT COUNT(*) FROM widget_phrases")->fetchColumn();
            if ($cph === 0) {
                $seed = [
                    ["Arrosez vos plantes tôt le matin ou en soirée pour limiter l'évaporation.", 'info'],
                    ['Le paillage garde l\'humidité du sol et limite les mauvaises herbes.', 'info'],
                    ['Un sol vivant = un jardin en bonne santé : préservez les vers de terre ! 🪱', 'info'],
                    ['Pourquoi les jardiniers sont-ils si zen ? Ils savent cultiver la patience. 🌱', 'blague'],
                    ['Que dit une fleur à une abeille ? « Butine-moi tant que je suis en fleur ! » 🐝', 'blague'],
                ];
                $insP = $db->prepare("INSERT INTO widget_phrases (texte, categorie) VALUES (?, ?)");
                foreach ($seed as $s) {
                    $insP->execute($s);
                }
            }
        } catch (Exception $e) {
            // base indisponible : on ignore
        }
    }

    /**
     * Phrases du widget. $onlyActive = true -> uniquement celles affichées.
     */
    function widgetPhrases(PDO $db, $onlyActive = true)
    {
        try {
            ensureWidgetTables($db);
            $sql = "SELECT id, texte, categorie, actif FROM widget_phrases";
            if ($onlyActive) {
                $sql .= " WHERE actif = 1";
            }
            $sql .= " ORDER BY id ASC";
            return $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            return [];
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
        // Phrases à faire défiler au centre (lues EN DIRECT depuis la base)
        $phrases = array_values(array_filter(array_map(function ($p) {
            return trim((string) $p['texte']);
        }, widgetPhrases($db, true))));
        if (empty($phrases)) {
            $phrases = [$tt('Bienvenue chez Famiflora 🌿', 'Welkom bij Famiflora 🌿')];
        }
        $phrasesAttr = htmlspecialchars(json_encode($phrases, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
        ob_start();
        ?>
        <div class="home-widget">
            <div class="hw-weather">🌤️ <span class="hw-soon"><?= htmlspecialchars($tt('Météo à venir', 'Weer binnenkort')) ?></span></div>
            <div class="hw-center" id="hwCenter" data-phrases="<?= $phrasesAttr ?>"><span class="hw-phrase"><?= htmlspecialchars($phrases[0]) ?></span></div>
            <div class="hw-date"><?= htmlspecialchars(widgetDate()) ?></div>
        </div>
        <style>
        /* Bande horizontale intégrée dans le ruban du haut (météo à gauche, date à droite, infos au centre) */
        .home-widget { flex: 1 1 auto; min-width: 0; max-width: 860px; display: flex; align-items: center; justify-content: space-between; gap: 16px; height: 52px; background: rgba(255,255,255,0.95); border-radius: 14px; box-shadow: 0 4px 14px rgba(0,0,0,0.12); padding: 6px 16px; box-sizing: border-box; }
        .hw-weather { font-weight: 700; color: #2d5a37; white-space: nowrap; flex-shrink: 0; }
        .hw-soon { color: #9bb3a3; font-weight: 600; font-size: 0.82rem; }
        .hw-center { flex: 1 1 auto; min-width: 0; display: flex; align-items: center; justify-content: center; text-align: center; }
        .hw-phrase { color: #2d5a37; font-weight: 700; font-size: 0.95rem; line-height: 1.15; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; transition: opacity 0.35s ease; }
        .hw-date { font-weight: 600; color: #666; font-size: 0.82rem; white-space: nowrap; flex-shrink: 0; }
        @media (max-width: 780px) { .home-widget { display: none; } }
        </style>
        <script>
        (function () {
            var box = document.getElementById('hwCenter');
            if (!box) { return; }
            var list;
            try { list = JSON.parse(box.getAttribute('data-phrases') || '[]'); } catch (e) { list = []; }
            if (list.length < 2) { return; }
            var span = box.querySelector('.hw-phrase');
            var i = 0;
            setInterval(function () {
                i = (i + 1) % list.length;
                if (!span) { return; }
                span.style.opacity = '0';
                setTimeout(function () {
                    span.textContent = list[i];
                    span.style.opacity = '1';
                }, 350);
            }, 7000);
        })();
        </script>
        <?php
        return ob_get_clean();
    }
}
