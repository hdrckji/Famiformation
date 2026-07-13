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

            // Sites (lieux de travail) pour la météo
            $db->exec(
                "CREATE TABLE IF NOT EXISTS widget_sites (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    nom VARCHAR(100) NOT NULL,
                    ville VARCHAR(100) NULL,
                    latitude DECIMAL(9,5) NULL,
                    longitude DECIMAL(9,5) NULL,
                    weather_json TEXT NULL,
                    weather_at DATETIME NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
            );
            $cs = (int) $db->query("SELECT COUNT(*) FROM widget_sites")->fetchColumn();
            if ($cs === 0) {
                $insS = $db->prepare("INSERT INTO widget_sites (nom, ville, latitude, longitude) VALUES (?, ?, ?, ?)");
                $insS->execute(['Famiflora Mouscron', 'Mouscron', 50.73330, 3.21670]);
                $insS->execute(['Famiflora La Panne', 'La Panne', 51.09750, 2.59360]);
            }

            // Colonne site_id sur les utilisateurs (lieu de travail affilié)
            $chkSite = $db->query("SHOW COLUMNS FROM utilisateurs LIKE 'site_id'");
            if ($chkSite && !$chkSite->fetch()) {
                $db->exec("ALTER TABLE utilisateurs ADD COLUMN site_id INT NULL");
            }

            // Traduction NL : colonne texte_nl sur les phrases
            $chkPnl = $db->query("SHOW COLUMNS FROM widget_phrases LIKE 'texte_nl'");
            if ($chkPnl && !$chkPnl->fetch()) {
                $db->exec("ALTER TABLE widget_phrases ADD COLUMN texte_nl VARCHAR(500) NULL");
            }

            // Traduction NL : colonnes sur quiz_questions (si la table existe)
            $chkQt = $db->query("SHOW TABLES LIKE 'quiz_questions'");
            if ($chkQt && $chkQt->fetch()) {
                foreach (['question_text_nl', 'option_a_nl', 'option_b_nl', 'option_c_nl'] as $col) {
                    $cc = $db->query("SHOW COLUMNS FROM quiz_questions LIKE '" . $col . "'");
                    if ($cc && !$cc->fetch()) {
                        $db->exec("ALTER TABLE quiz_questions ADD COLUMN " . $col . " VARCHAR(500) NULL");
                    }
                }
            }

            // Pack de 50 infos jardinerie + 50 blagues (une seule fois)
            $flag = $db->query("SELECT sval FROM widget_settings WHERE skey = 'phrases_pack_v1'")->fetchColumn();
            if ($flag !== '1') {
                $infos = array_filter(array_map('trim', explode("\n", <<<'INFOS'
Le paillage garde l'humidité du sol et limite les mauvaises herbes.
Arrosez tôt le matin ou le soir pour limiter l'évaporation.
Les tomates aiment le soleil : au moins 6 heures par jour.
Ne jetez pas le marc de café : il enrichit le compost.
Les coccinelles dévorent les pucerons : ce sont vos alliées.
Taillez les rosiers en fin d'hiver, avant le redémarrage de la végétation.
Un sol bien drainé évite le pourrissement des racines.
Beaucoup de plantes aromatiques éloignent naturellement certains insectes.
Le basilic protège les tomates et parfume vos plats.
Récoltez les courgettes jeunes : elles sont plus tendres.
Les feuilles mortes font un excellent paillis gratuit.
Arrosez au pied des plantes, pas sur le feuillage, pour éviter les maladies.
La lavande attire les abeilles et repousse les moustiques.
Tournez votre compost régulièrement pour bien l'aérer.
Plantez les bulbes de printemps à l'automne.
Retirez les fleurs fanées des géraniums pour relancer la floraison.
Les orties font un purin riche en azote pour vos légumes.
Le voile d'hivernage protège les plantes fragiles du gel.
Les capucines attirent les pucerons loin de vos légumes.
Rempotez vos plantes au printemps, quand elles reprennent leur croissance.
Une plante qui jaunit manque souvent d'azote ou d'eau.
Les semis d'intérieur démarrent dès février sur un rebord lumineux.
Espacez bien vos plants pour une bonne circulation de l'air.
Le buis se taille deux fois par an, au printemps et en été.
Récupérez l'eau de pluie : gratuite et sans calcaire pour vos plantes.
Les œillets d'Inde protègent le potager des nématodes.
Un rosier aime le soleil et un sol riche et frais.
Pincez les gourmands des tomates pour concentrer la sève sur les fruits.
La menthe est envahissante : cultivez-la de préférence en pot.
Griffez la terre après l'arrosage pour éviter la croûte de surface.
Les fraisiers se multiplient facilement grâce à leurs stolons.
Nettoyez vos outils après usage pour éviter de propager les maladies.
Un hôtel à insectes favorise la biodiversité au jardin.
Les hortensias bleuissent en sol acide et rosissent en sol calcaire.
À la Sainte-Catherine, tout bois prend racine : plantez à l'automne.
Arrosez moins souvent mais abondamment pour des racines profondes.
Le compost mûr sent la bonne terre de forêt, pas la pourriture.
Les limaces détestent le marc de café et les coquilles d'œufs.
Coupez les fleurs fanées des vivaces pour prolonger la floraison.
Le purin de consoude booste la floraison et la fructification.
Aérez votre serre les jours de beau temps pour éviter l'excès d'humidité.
Rentrez les agrumes à l'abri dès les premières gelées.
Les haies libres offrent gîte et nourriture aux oiseaux.
Pratiquez la rotation des cultures : ne replantez pas la même famille au même endroit.
Les graines de tournesol régalent les oiseaux en hiver.
Un sol couvert est un sol protégé : évitez de laisser la terre nue.
La cendre de bois apporte de la potasse, mais à utiliser avec modération.
Ne tondez pas trop court : une pelouse un peu haute résiste mieux à la sécheresse.
Un bon terreau, c'est la moitié du travail pour une plante en pot.
Observez votre jardin : la meilleure façon d'apprendre, c'est de regarder.
INFOS
                )));

                $blagues = array_filter(array_map('trim', explode("\n", <<<'BLAGUES'
Pourquoi les jardiniers sont-ils zen ? Parce qu'ils savent que tout finit par pousser.
Que dit une fraise à une autre ? « C'est de ta faute si on est dans la confiture ! »
Quel est le comble pour un jardinier ? Ne pas savoir planter un clou.
Pourquoi les plantes détestent les maths ? Ça leur donne des racines carrées.
Que fait une tomate qui a peur ? Elle rougit.
Pourquoi l'arbre est-il allé à la banque ? Pour ouvrir un compte en tronc.
Comment un jardinier dit-il au revoir ? « À la salade prochaine ! »
Pourquoi les carottes sont-elles bonnes pour les yeux ? Avez-vous déjà vu un lapin avec des lunettes ?
Que dit un jardinier à ses plantes le matin ? « Alors, on pousse un peu ? »
Quel arbre trouve-t-on dans les mains ? Le palmier.
Pourquoi l'oignon est-il si triste ? Parce que tout le monde le fait pleurer.
Que dit une graine à un jardinier impatient ? « Laisse-moi le temps de pousser ! »
Quel est le comble pour un arrosoir ? D'avoir le cœur sec.
Comment fait un jardinier pour ranger ses idées ? Il les met en pot.
Que dit une pelle à un râteau ? « Toi, tu me ratisses toujours large ! »
Pourquoi les fleurs vont-elles à l'école ? Pour cultiver leur savoir.
Pourquoi le ver de terre est-il champion de yoga ? Parce qu'il est très souple.
Comment appelle-t-on un jardinier très calme ? Un philosophe du potager.
Que dit un cactus à un autre ? « Pique pas ma place ! »
Pourquoi le maïs a-t-il des oreilles ? Pour écouter pousser le blé.
Pourquoi le jardinier a-t-il mis un réveil dans le potager ? Pour que ses légumes soient à l'heure.
Pourquoi le tournesol est-il si sympa ? Il a toujours la tête tournée vers les autres.
Que dit une rose à une épine ? « Arrête de me piquer mes compliments ! »
Quel est le fruit le plus paresseux ? La poire, toujours avachie dans le compotier.
Pourquoi les haricots ne se disputent jamais ? Parce qu'ils restent dans les rangs.
Pourquoi la courgette est-elle modeste ? Elle ne se prend pas pour un potiron.
Que dit le jardinier épuisé ? « Je suis complètement à plat… -bande. »
Pourquoi le gazon est-il de bonne humeur ? Parce qu'il voit la vie en vert.
Comment appelle-t-on un chat tombé dans un massif ? Un chat-mite… de jardin.
Pourquoi la salade reste-t-elle calme ? Elle garde son sang-froid dans le frigo.
Que se disent deux escargots pressés ? Rien, ils sont à la traîne.
Pourquoi le champignon est-il invité partout ? Parce que c'est un mec sympa.
Pourquoi le radis rougit-il ? Parce qu'il a vu la salade se déshabiller.
Comment un poireau reste-t-il discret ? Il ne fait jamais de plat.
Pourquoi la betterave a-t-elle toujours bonne mine ? Parce qu'elle est rouge de santé.
Quel légume est le plus poli ? Le chou : il dit toujours « à tes souhaits ».
Pourquoi le persil pousse-t-il lentement ? Il prend son persil… son temps.
Que fait une abeille coiffée ? Un brushing-bzzz.
Pourquoi les pommes de terre ont-elles des yeux ? Pour surveiller la cave.
Comment appelle-t-on une vieille théière ? Une antiquithé.
Pourquoi le concombre a-t-il appelé le médecin ? Il était dans le vinaigre.
Pourquoi le potiron est-il un bon copain ? Parce qu'il est toujours bien rond.
Que dit un mur à un autre mur au jardin ? « On se retrouve au coin. »
Pourquoi les abeilles bourdonnent-elles ? Parce qu'elles ne connaissent pas les paroles.
Quel est le sport préféré du cresson ? Le cross… -cresson.
Pourquoi la citrouille ne ment jamais ? Parce qu'elle a le cœur sur la main… -tenant.
Comment un chou fait-il du sport ? Il devient un chou-fleur… de forme.
Que dit le jardinier optimiste sous la pluie ? « Au moins, je n'arrose pas ! »
Pourquoi le sapin est-il toujours à la mode ? Parce qu'il garde toujours ses aiguilles.
Pourquoi le jardinier parle-t-il à ses plantes ? Parce qu'elles ne le contredisent jamais.
BLAGUES
                )));

                $insPack = $db->prepare("INSERT INTO widget_phrases (texte, categorie) VALUES (?, ?)");
                foreach ($infos as $t) {
                    $insPack->execute([mb_substr($t, 0, 500), 'info']);
                }
                foreach ($blagues as $t) {
                    $insPack->execute([mb_substr($t, 0, 500), 'blague']);
                }
                $db->prepare("INSERT INTO widget_settings (skey, sval) VALUES ('phrases_pack_v1', '1') ON DUPLICATE KEY UPDATE sval = '1'")->execute();
            }
        } catch (Exception $e) {
            // base indisponible : on ignore
        }
    }

    /**
     * Liste des sites (lieux de travail).
     */
    function widgetSites(PDO $db)
    {
        try {
            ensureWidgetTables($db);
            return $db->query("SELECT * FROM widget_sites ORDER BY nom ASC")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Géocodage gratuit (Open-Meteo) : ville -> ['lat','lon'] ou null.
     */
    function widgetGeocode($ville)
    {
        $ville = trim((string) $ville);
        if ($ville === '' || !function_exists('curl_init')) {
            return null;
        }
        $url = 'https://geocoding-api.open-meteo.com/v1/search?count=1&language=fr&format=json&name=' . rawurlencode($ville);
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_USERAGENT => 'Famiformation/1.0']);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($resp === false || $code < 200 || $code >= 300) {
            return null;
        }
        $d = json_decode($resp, true);
        if (empty($d['results'][0]['latitude'])) {
            return null;
        }
        return ['lat' => $d['results'][0]['latitude'], 'lon' => $d['results'][0]['longitude']];
    }

    /**
     * Émoji météo depuis un code WMO (Open-Meteo).
     */
    function weatherEmoji($code)
    {
        $code = (int) $code;
        if ($code === 0) { return '☀️'; }
        if ($code === 1 || $code === 2) { return '🌤️'; }
        if ($code === 3) { return '☁️'; }
        if ($code === 45 || $code === 48) { return '🌫️'; }
        if ($code >= 51 && $code <= 67) { return '🌦️'; }
        if ($code >= 71 && $code <= 77) { return '❄️'; }
        if ($code >= 80 && $code <= 82) { return '🌧️'; }
        if ($code >= 85 && $code <= 86) { return '🌨️'; }
        if ($code >= 95) { return '⛈️'; }
        return '🌡️';
    }

    /**
     * Météo actuelle d'un site via Open-Meteo, mise en cache 30 min dans widget_sites.
     * Renvoie ['emoji','temp'] ou null.
     */
    function widgetWeather(PDO $db, array $site)
    {
        $lat = $site['latitude'] ?? null;
        $lon = $site['longitude'] ?? null;
        if ($lat === null || $lon === null) {
            return null;
        }
        // Cache 30 min
        $at = $site['weather_at'] ?? null;
        if ($at && (time() - strtotime((string) $at)) < 1800 && !empty($site['weather_json'])) {
            $cached = json_decode((string) $site['weather_json'], true);
            if (is_array($cached)) {
                return $cached;
            }
        }
        if (!function_exists('curl_init')) {
            return null;
        }
        $url = 'https://api.open-meteo.com/v1/forecast?latitude=' . rawurlencode((string) $lat)
            . '&longitude=' . rawurlencode((string) $lon)
            . '&current=temperature_2m,weather_code&timezone=Europe%2FBrussels';
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_USERAGENT => 'Famiformation/1.0']);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($resp === false || $code < 200 || $code >= 300) {
            // Échec : on renvoie le cache périmé s'il existe
            $stale = json_decode((string) ($site['weather_json'] ?? ''), true);
            return is_array($stale) ? $stale : null;
        }
        $data = json_decode($resp, true);
        $temp = $data['current']['temperature_2m'] ?? null;
        if ($temp === null) {
            return null;
        }
        $result = ['emoji' => weatherEmoji($data['current']['weather_code'] ?? 0), 'temp' => (int) round((float) $temp)];
        try {
            $db->prepare("UPDATE widget_sites SET weather_json = ?, weather_at = NOW() WHERE id = ?")
               ->execute([json_encode($result), (int) $site['id']]);
        } catch (Exception $e) {
        }
        return $result;
    }

    /**
     * Site (lieu de travail) d'un utilisateur, sinon le premier site en secours.
     */
    function userSite(PDO $db, $userId)
    {
        try {
            ensureWidgetTables($db);
            if ($userId) {
                $st = $db->prepare("SELECT site_id FROM utilisateurs WHERE id = ? LIMIT 1");
                $st->execute([(int) $userId]);
                $sid = $st->fetchColumn();
                if ($sid) {
                    $s = $db->prepare("SELECT * FROM widget_sites WHERE id = ? LIMIT 1");
                    $s->execute([(int) $sid]);
                    $row = $s->fetch(PDO::FETCH_ASSOC);
                    if ($row) {
                        return $row;
                    }
                }
            }
            $row = $db->query("SELECT * FROM widget_sites ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Questions de quiz (avec la bonne réponse) à afficher dans le widget.
     * Lues EN DIRECT depuis quiz_questions, uniquement pour les quiz que la
     * personne a déjà réalisés (statistiques.nom_page = quiz_questions.theme).
     * Renvoie un tableau de chaînes "❓ question   ✅ réponse".
     */
    function widgetQuizItems(PDO $db, $userId, $limit = 40)
    {
        if (!$userId) {
            return [];
        }
        try {
            $stThemes = $db->prepare("SELECT DISTINCT nom_page FROM statistiques WHERE utilisateur_id = ? AND score IS NOT NULL");
            $stThemes->execute([(int) $userId]);
            $themes = array_filter($stThemes->fetchAll(PDO::FETCH_COLUMN));
            if (empty($themes)) {
                return [];
            }
            $placeholders = implode(',', array_fill(0, count($themes), '?'));
            $q = $db->prepare(
                "SELECT question_text, question_text_nl, option_a, option_b, option_c,
                        option_a_nl, option_b_nl, option_c_nl, reponse_correcte
                 FROM quiz_questions WHERE theme IN ($placeholders) ORDER BY RAND() LIMIT " . (int) $limit
            );
            $q->execute(array_values($themes));
            $isNl = (function_exists('currentLang') && currentLang() === 'nl');
            $pick = function ($fr, $nl) use ($isNl) {
                $fr = trim((string) $fr);
                $nl = trim((string) $nl);
                return ($isNl && $nl !== '') ? $nl : $fr;
            };
            $items = [];
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $letter = strtoupper(trim((string) ($row['reponse_correcte'] ?? 'A')));
                $mapFr = ['A' => $row['option_a'] ?? '', 'B' => $row['option_b'] ?? '', 'C' => $row['option_c'] ?? ''];
                $mapNl = ['A' => $row['option_a_nl'] ?? '', 'B' => $row['option_b_nl'] ?? '', 'C' => $row['option_c_nl'] ?? ''];
                $qt = $pick($row['question_text'] ?? '', $row['question_text_nl'] ?? '');
                $rep = $pick($mapFr[$letter] ?? ($row['option_a'] ?? ''), $mapNl[$letter] ?? '');
                if ($qt === '' || $rep === '') {
                    continue;
                }
                $items[] = '❓ ' . $qt . '   ✅ ' . $rep;
            }
            return $items;
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Statistiques de synchronisation widget <-> module Quiz (pour l'onglet Widget).
     * Renvoie ['total' => questions en base, 'themes' => nb thèmes, 'user' => questions
     * visibles pour cet utilisateur selon ses quiz réalisés, 'ok' => quiz_questions accessible].
     */
    function widgetQuizStats(PDO $db, $userId)
    {
        $out = ['total' => 0, 'themes' => 0, 'user' => 0, 'ok' => false];
        try {
            $out['total'] = (int) $db->query("SELECT COUNT(*) FROM quiz_questions")->fetchColumn();
            $out['themes'] = (int) $db->query("SELECT COUNT(DISTINCT theme) FROM quiz_questions")->fetchColumn();
            $out['ok'] = true;
            $out['user'] = count(widgetQuizItems($db, $userId, 1000));
        } catch (Exception $e) {
            $out['ok'] = false;
        }
        return $out;
    }

    /**
     * Horaires attribués de l'utilisateur (issus de Famijob, base partagée).
     * Renvoie ['today' => [lignes du jour], 'next' => prochaine ligne future|null].
     * Lecture EN DIRECT des tables interim_shift_assignments / interim_shift_requests.
     */
    function widgetSchedule(PDO $db, $userId)
    {
        $out = ['today' => [], 'next' => null];
        if (!$userId) {
            return $out;
        }
        try {
            $chk = $db->query("SHOW TABLES LIKE 'interim_shift_assignments'");
            if (!$chk || !$chk->fetch()) {
                return $out;
            }
            $today = date('Y-m-d');
            $st = $db->prepare(
                "SELECT r.shift_date, r.department_name, r.time_slot, r.comment, a.agency_name
                 FROM interim_shift_assignments a
                 INNER JOIN interim_shift_requests r ON r.id = a.request_id
                 WHERE a.student_id = ? AND r.shift_date >= ?
                 ORDER BY r.shift_date ASC, r.time_slot ASC"
            );
            $st->execute([(int) $userId, $today]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if ((string) $row['shift_date'] === $today) {
                    $out['today'][] = $row;
                } elseif ($out['next'] === null) {
                    $out['next'] = $row;
                }
            }
            return $out;
        } catch (Exception $e) {
            return $out;
        }
    }

    /**
     * Libellé d'un créneau pour le défilement du widget.
     * $mode = 'today' (créneau du jour) ou 'next' (prochain créneau, avec la date).
     */
    function widgetShiftLabel(array $row, $mode)
    {
        $tt = function ($fr, $nl) {
            return function_exists('t') ? t($fr, $nl) : $fr;
        };
        $slot = trim((string) ($row['time_slot'] ?? ''));
        $dept = trim((string) ($row['department_name'] ?? ''));
        $parts = [];
        if ($mode === 'today') {
            $parts[] = '🕒 ' . $tt("Aujourd'hui", 'Vandaag');
        } else {
            $joursFr = ['Monday' => 'Lun', 'Tuesday' => 'Mar', 'Wednesday' => 'Mer', 'Thursday' => 'Jeu', 'Friday' => 'Ven', 'Saturday' => 'Sam', 'Sunday' => 'Dim'];
            $joursNl = ['Monday' => 'Ma', 'Tuesday' => 'Di', 'Wednesday' => 'Wo', 'Thursday' => 'Do', 'Friday' => 'Vr', 'Saturday' => 'Za', 'Sunday' => 'Zo'];
            $dt = DateTimeImmutable::createFromFormat('Y-m-d', (string) ($row['shift_date'] ?? ''));
            if ($dt) {
                $en = $dt->format('l');
                $jour = (function_exists('currentLang') && currentLang() === 'nl') ? ($joursNl[$en] ?? $en) : ($joursFr[$en] ?? $en);
                $dateStr = $jour . ' ' . $dt->format('d/m');
            } else {
                $dateStr = (string) ($row['shift_date'] ?? '');
            }
            $parts[] = '📅 ' . $tt('Prochain', 'Volgende') . ' : ' . $dateStr;
        }
        if ($slot !== '') {
            $parts[] = $slot;
        }
        if ($dept !== '') {
            $parts[] = $dept;
        }
        return implode(' · ', $parts);
    }

    /**
     * Phrases du widget. $onlyActive = true -> uniquement celles affichées.
     */
    function widgetPhrases(PDO $db, $onlyActive = true)
    {
        try {
            ensureWidgetTables($db);
            $sql = "SELECT id, texte, texte_nl, categorie, actif FROM widget_phrases";
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
    function renderWidget(PDO $db, $birthdayName = null, $festiveMessage = null)
    {
        $tt = function ($fr, $nl) {
            return function_exists('t') ? t($fr, $nl) : $fr;
        };
        // Contenu du centre, lu EN DIRECT et affiché selon la langue (FR/NL)
        $isNl = (function_exists('currentLang') && currentLang() === 'nl');
        $infoList = [];
        $blagueList = [];
        foreach (widgetPhrases($db, true) as $p) {
            $txt = ($isNl && !empty($p['texte_nl'])) ? $p['texte_nl'] : $p['texte'];
            $txt = trim((string) $txt);
            if ($txt === '') {
                continue;
            }
            if (($p['categorie'] ?? 'info') === 'blague') {
                $blagueList[] = $txt;
            } else {
                $infoList[] = $txt;
            }
        }
        // Questions de quiz déjà réalisées (déjà traduites en NL si disponible)
        $quizItems = widgetQuizItems($db, $_SESSION['user_id'] ?? null, 40);
        shuffle($infoList);
        shuffle($blagueList);
        shuffle($quizItems);
        // Défilement dans l'ordre : info / blague / quiz / info / blague / quiz…
        $pool = [];
        $rounds = min(30, max(count($infoList), count($blagueList), count($quizItems)));
        for ($i = 0; $i < $rounds; $i++) {
            if (!empty($infoList)) {
                $pool[] = $infoList[$i % count($infoList)];
            }
            if (!empty($blagueList)) {
                $pool[] = $blagueList[$i % count($blagueList)];
            }
            if (!empty($quizItems)) {
                $pool[] = $quizItems[$i % count($quizItems)];
            }
        }

        // Horaires attribués (Famijob, base partagée) — lus EN DIRECT
        $schedule = widgetSchedule($db, $_SESSION['user_id'] ?? null);
        $scheduleItems = [];
        if (!empty($schedule['today'])) {
            foreach ($schedule['today'] as $s) {
                $scheduleItems[] = widgetShiftLabel($s, 'today');
            }
        } elseif ($schedule['next']) {
            $scheduleItems[] = widgetShiftLabel($schedule['next'], 'next');
        }

        if (!empty($scheduleItems) && !empty($schedule['today'])) {
            // Travaille aujourd'hui : l'horaire du jour d'abord, puis le reste défile
            $items = array_merge($scheduleItems, $pool);
        } elseif (!empty($scheduleItems)) {
            // Horaire à venir mais pas aujourd'hui : on alterne horaire / phrase / horaire / phrase…
            $items = [];
            $k = 0;
            foreach ($pool as $p) {
                $items[] = $scheduleItems[$k % count($scheduleItems)];
                $items[] = $p;
                $k++;
            }
            if (empty($pool)) {
                $items = $scheduleItems;
            }
        } else {
            // Pas d'horaire : phrases + quiz uniquement
            $items = $pool;
        }

        if (empty($items)) {
            $items = [$tt('Bienvenue chez Famiflora 🌿', 'Welkom bij Famiflora 🌿')];
        }
        $phrases = $items;
        // Message de fête (thème événementiel) : alterne avec les phrases habituelles.
        if (is_string($festiveMessage) && trim($festiveMessage) !== '') {
            array_unshift($phrases, '🎉 ' . trim($festiveMessage));
        }
        $phrasesAttr = htmlspecialchars(json_encode($phrases, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
        // Météo du site (lieu de travail) de l'utilisateur — Open-Meteo, mise en cache
        $site = userSite($db, $_SESSION['user_id'] ?? null);
        $weather = $site ? widgetWeather($db, $site) : null;
        $siteLabel = $site ? ((string) ($site['ville'] ?: $site['nom'])) : '';
        ob_start();
        $bdName = is_string($birthdayName) ? trim($birthdayName) : '';
        if ($bdName !== '') {
            ?>
            <div class="home-widget hw-birthday"><div class="hw-bd-text">🎉 <?= htmlspecialchars($tt('Joyeux anniversaire', 'Gelukkige verjaardag')) ?> <?= htmlspecialchars($bdName) ?> ! 🎂</div></div>
            <style>
            .home-widget.hw-birthday { background: linear-gradient(120deg,#0b0b0b,#1c1c1c,#0b0b0b); border:1px solid #d4af37; box-shadow:0 4px 20px rgba(212,175,55,0.45); justify-content:center; overflow:hidden; }
            .hw-bd-text { font-weight:800; font-size:1rem; letter-spacing:.4px; white-space:nowrap; background:linear-gradient(90deg,#b8860b,#d4af37,#fff6cf,#d4af37,#b8860b); background-size:200% auto; -webkit-background-clip:text; background-clip:text; -webkit-text-fill-color:transparent; color:transparent; animation:hwGold 3s linear infinite; }
            @keyframes hwGold { to { background-position:200% center; } }
            </style>
            <?php
            return ob_get_clean();
        }
        ?>
        <?php
            // Composants activables individuellement (Paramètres → Widget).
            $showMeteo = widgetGet($db, 'show_meteo', '1') === '1';
            $showPhrases = widgetGet($db, 'show_phrases', '1') === '1';
            $showDate = widgetGet($db, 'show_date', '1') === '1';
        ?>
        <div class="home-widget">
            <?php if ($showMeteo): ?>
            <div class="hw-weather">
                <?php if ($weather): ?>
                    <?= $weather['emoji'] ?> <?= (int) $weather['temp'] ?>°C
                    <?php if ($siteLabel !== ''): ?><span class="hw-soon"><?= htmlspecialchars($siteLabel) ?></span><?php endif; ?>
                <?php else: ?>
                    🌤️ <span class="hw-soon"><?= htmlspecialchars($tt('Météo indisponible', 'Weer onbeschikbaar')) ?></span>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <?php if ($showPhrases): ?>
            <div class="hw-center" id="hwCenter" data-phrases="<?= $phrasesAttr ?>"><span class="hw-phrase"><?= htmlspecialchars($phrases[0]) ?></span></div>
            <?php endif; ?>
            <?php if ($showDate): ?>
            <div class="hw-date"><?= htmlspecialchars(widgetDate()) ?></div>
            <?php endif; ?>
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
