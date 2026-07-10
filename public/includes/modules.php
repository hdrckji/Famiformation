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
                'nom_nl'         => "ALTER TABLE modules ADD COLUMN nom_nl VARCHAR(150) NULL",
                'description_nl' => "ALTER TABLE modules ADD COLUMN description_nl VARCHAR(500) NULL",
                'link'           => "ALTER TABLE modules ADD COLUMN link VARCHAR(255) NULL",
                'is_booking'     => "ALTER TABLE modules ADD COLUMN is_booking TINYINT(1) NOT NULL DEFAULT 0",
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
                       '🛠️',
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

            // 3) Verrouiller les profils de base (rejoué en v2 pour rattraper l'existant)
            if (!$hasFlag('profiles_lock_base_v2')) {
                ensureProfilesTable($db);
                $db->exec("UPDATE profils SET is_locked = 1 WHERE is_core = 1");
                $setFlag('profiles_lock_base_v2');
            }

            // 4) Recense les tuiles de l'accueil comme modules de base (verrouillés) dans la gestion
            if (!$hasFlag('seed_base_modules_v1')) {
                $nonEtu = 'employe_magasin,teamcoach,mentor,employe_logistique,admin,evaluateur';
                $base = [
                    // [nom, description, icône, rôles (accès), lien]
                    ['Onboarding', 'Bienvenue chez Famiflora — découverte de notre univers.', '🚀', '', 'onboarding.php'],
                    ['Formation', 'Formations en ligne et en présentiel.', '📅', '', 'formation.php'],
                    ['Magasin', 'Procédures de vente et caisses.', '🛒', 'admin,teamcoach,mentor,employe_magasin', 'magasin.php'],
                    ['Management', 'Outils et formations pour managers et mentors.', '🧑‍💼', 'admin,teamcoach,mentor', 'management.php'],
                    ['Becosoft', 'Logiciel de gestion de stock.', '💻', $nonEtu, 'formation_becosoft.php'],
                    ['Formation Caisse', 'Parcours rapide sur l\'utilisation de la caisse.', '💳', 'etudiant', 'formation-caisse.php'],
                    ['Mes disponibilités', 'Jours de disponibilité sur les 30 prochains jours.', '🗓️', 'etudiant', 'student_disponibilites.php'],
                    ['Mes horaires attribués', 'Créneaux passés, du jour et futurs (lecture seule).', '🕒', 'etudiant', 'mon_horaire.php'],
                    ['Logistique', 'Gestion des flux et des stocks.', '📦', 'admin,employe_logistique,teamcoach,mentor', 'logistique.php'],
                    ['Classement', 'Tableau des scores et points.', '🏆', $nonEtu, 'classement.php'],
                    ['Sécurité au travail', 'Chaussures de sécurité & secourisme.', '🦺', $nonEtu, 'securite_travail.php'],
                    ['Famijob', 'Plateforme Famijob (gestion des jobs étudiants).', '💼', 'admin,teamcoach', 'famijob/index.php'],
                    ['Demandes Horaires Intérim', 'Créer/modifier/supprimer les demandes d\'horaires intérim.', '📝', 'admin', 'interim_horaires_demandes.php'],
                    ['Matching Intérim', 'Assigner les étudiants aux créneaux intérim.', '🤝', 'admin', 'interim_horaires.php'],
                    ['Validation demandes horaires', 'Valider ou refuser les demandes d\'horaires.', '✅', 'admin', 'validation_demandes_horaires.php'],
                    ['RH', 'Gestion des comptes et scores.', '👥', 'admin', 'admin.php'],
                    ['Dispos Etudiants', 'Disponibilités étudiantes par semaine et secteur.', '🗓️', 'admin', 'admin_disponibilites_etudiants.php'],
                    ['Gestion Questions', 'Ajouter / modifier les quiz.', '⚙️', 'admin,teamcoach', 'admin_questions.php'],
                ];
                $insBase = $db->prepare("INSERT INTO modules (nom, description, is_container, parent_id, icon, roles, is_active, is_locked, link) VALUES (?, ?, 0, NULL, ?, ?, 1, 1, ?)");
                foreach ($base as $b) {
                    $insBase->execute([$b[0], $b[1], $b[2], $b[3], $b[4]]);
                }
                $setFlag('seed_base_modules_v1');
            }

            // 5) Place les VRAIS modules Becosoft / Logistique / Magasin dans « Aide »
            //    (au lieu des sous-modules vides créés au départ)
            if (!$hasFlag('reorg_aide_v2')) {
                $aideId = (int) $db->query("SELECT id FROM modules WHERE nom = 'Aide' AND parent_id IS NULL ORDER BY id ASC LIMIT 1")->fetchColumn();
                if ($aideId > 0) {
                    // Supprime les sous-modules vides d'Aide (créés initialement)
                    $db->prepare("DELETE FROM modules WHERE parent_id = ? AND (link IS NULL OR link = '') AND nom IN ('Becosoft', 'Logistique', 'Magasin')")->execute([$aideId]);
                    // Rattache les modules de base correspondants sous Aide
                    $db->prepare("UPDATE modules SET parent_id = ? WHERE parent_id IS NULL AND link IS NOT NULL AND link <> '' AND nom IN ('Becosoft', 'Logistique', 'Magasin')")->execute([$aideId]);
                }
                $setFlag('reorg_aide_v2');
            }

            // 6) Icône du module « Aide » : outils au lieu du SOS
            if (!$hasFlag('aide_icon_tools_v1')) {
                $db->prepare("UPDATE modules SET icon = ? WHERE nom = 'Aide' AND parent_id IS NULL")->execute(['🛠️']);
                $setFlag('aide_icon_tools_v1');
            }

            // 7) Sous-modules « Présentiel » et « En ligne » sous « Formation »
            //    (pour les voir et les paramétrer dans la gestion des modules ;
            //     la page Formation garde son fonctionnement actuel)
            if (!$hasFlag('seed_formation_children_v1')) {
                $formationId = (int) $db->query("SELECT id FROM modules WHERE nom = 'Formation' AND parent_id IS NULL ORDER BY id ASC LIMIT 1")->fetchColumn();
                if ($formationId > 0) {
                    $formationChildren = [
                        // [nom, description, icône, lien]
                        ['Présentiel', 'Formations en présentiel (sessions planifiées).', '📅', 'formation.php?vue=presentiel'],
                        ['En ligne', 'Formations en ligne (contenus à évaluer).', '💻', 'formation.php?vue=enligne'],
                    ];
                    $insFc = $db->prepare("INSERT INTO modules (nom, description, is_container, parent_id, icon, roles, is_active, is_locked, link) VALUES (?, ?, 0, ?, ?, '', 1, 0, ?)");
                    $chkFc = $db->prepare("SELECT COUNT(*) FROM modules WHERE nom = ? AND parent_id = ?");
                    foreach ($formationChildren as $fc) {
                        $chkFc->execute([$fc[0], $formationId]);
                        if ((int) $chkFc->fetchColumn() === 0) {
                            $insFc->execute([$fc[0], $fc[1], $formationId, $fc[2], $fc[3]]);
                        }
                    }
                    // « Formation » devient un conteneur (flèche de dépliage en gestion)
                    $db->prepare("UPDATE modules SET is_container = 1 WHERE id = ?")->execute([$formationId]);
                }
                $setFlag('seed_formation_children_v1');
            }

            // 8) Suppression du module « Aide » (inutile). Ses éventuels sous-modules
            //    (ex : Magasin) sont remontés à la racine pour ne pas les perdre.
            if (!$hasFlag('remove_aide_v1')) {
                $aideId = (int) $db->query("SELECT id FROM modules WHERE nom = 'Aide' AND parent_id IS NULL ORDER BY id ASC LIMIT 1")->fetchColumn();
                if ($aideId > 0) {
                    $db->prepare("UPDATE modules SET parent_id = NULL WHERE parent_id = ?")->execute([$aideId]);
                    $db->prepare("DELETE FROM modules WHERE id = ?")->execute([$aideId]);
                }
                $setFlag('remove_aide_v1');
            }

            // 9) Reconstruit l'arborescence complète du site dans la gestion :
            //    chaque conteneur de base reçoit ses sous-modules (tuiles codées en dur
            //    des pages). Purement pour la gestion — n'affecte pas la navigation réelle.
            if (!$hasFlag('seed_full_tree_v1')) {
                // Répare d'abord les orphelins (parent supprimé) -> racine, pour retrouver les conteneurs.
                $db->exec("UPDATE modules m LEFT JOIN modules p ON p.id = m.parent_id SET m.parent_id = NULL WHERE m.parent_id IS NOT NULL AND p.id IS NULL");

                $tree = [
                    'Onboarding' => [
                        ["Livret d'accueil", '📖', 'view-pdf-onboarding.php'],
                        ['Vidéo', '🎥', 'video-onboarding.php'],
                    ],
                    'Magasin' => [
                        ['Caisses', '🛒', 'formation-caisse.php'],
                        ['Formation ressources humaines', '👥', 'ressources_humaines.php'],
                        ['Déco', '🛋️', 'deco.php'],
                        ['Green', '🌿', 'green.php'],
                        ['Animalerie', '🐾', 'animalerie.php'],
                        ['Garden', '🏡', 'garden.php'],
                        ['Food', '🍫', 'food.php'],
                        ['Stock', '📦', 'stock.php'],
                    ],
                    'Management' => [
                        ['Donner du feedback', '💬', 'feedback.php'],
                        ['Formation Parrain/Marraine', '🤝', 'mentor.php'],
                        ['Leadership', '🦸', 'leadership.php'],
                        ['Gestion de la présence', '🗓️', 'presence_view.php'],
                        ['Entretiens de collaboration', '🗣️', 'entretien.php'],
                        ['Judo verbal', '🥋', 'judo.php'],
                    ],
                    'Becosoft' => [
                        ['Recherche Article', '🔍', 'becosoft.php'],
                        ['Commande Gazon', '🌱', 'beco_gazon.php'],
                        ['Bon de Commande', '📜', 'beco_bon.php'],
                        ['Vente Flash', '⚡', 'beco_flash.php'],
                    ],
                    'Logistique' => [
                        ['Chariots Danois', '🪴', 'logistique_chariots.php'],
                        ['Réception', '🚚', 'logistique-reception.php'],
                        ['Transfert', '🔄', 'logistique-transfert.php'],
                        ['Gerbeur', '🏗️', 'gerbeur.php'],
                        ['Empileuse', '📚', 'logistique_empileuse.php'],
                    ],
                    'Sécurité au travail' => [
                        ['Chaussure de sécurité', '👟', 'chaussure_securite_pdf.php'],
                        ['Formation secourisme', '⛑️', 'formation_secourisme_pdf.php'],
                    ],
                ];

                $findParent = $db->prepare("SELECT id FROM modules WHERE nom = ? AND parent_id IS NULL ORDER BY id ASC LIMIT 1");
                $chkChild = $db->prepare("SELECT COUNT(*) FROM modules WHERE nom = ? AND parent_id = ?");
                $insChild = $db->prepare("INSERT INTO modules (nom, description, is_container, parent_id, icon, roles, is_active, is_locked, link) VALUES (?, '', 0, ?, ?, '', 1, 0, ?)");
                $setContainer = $db->prepare("UPDATE modules SET is_container = 1 WHERE id = ?");

                foreach ($tree as $parentName => $children) {
                    $findParent->execute([$parentName]);
                    $parentId = (int) $findParent->fetchColumn();
                    if ($parentId <= 0) {
                        continue;
                    }
                    $setContainer->execute([$parentId]);
                    foreach ($children as $c) {
                        $chkChild->execute([$c[0], $parentId]);
                        if ((int) $chkChild->fetchColumn() === 0) {
                            $insChild->execute([$c[0], $parentId, $c[1], $c[2]]);
                        }
                    }
                }
                $setFlag('seed_full_tree_v1');
            }

            // 10) Niveau 3 : sous-modules de « Formation Caisse » (page = conteneur propre).
            //     PDF et vidéo restent 2 éléments distincts pour l'instant (règle PDF/vidéo à venir).
            if (!$hasFlag('seed_formation_caisse_children_v1')) {
                $fcParents = $db->query("SELECT id FROM modules WHERE nom = 'Formation Caisse'")->fetchAll(PDO::FETCH_COLUMN);
                $fcChildren = [
                    ['Support PDF', '📄', 'view-pdf.php'],
                    ['Vidéo Tutoriel', '🎥', 'caisse.php'],
                    ['Module technique', '🔧', 'module-technique.php'],
                    ['Mes 2 premières semaines en caisse', '🗓️', 'mes-2-premieres-semaines-caisse.php'],
                ];
                $chkFcc = $db->prepare("SELECT COUNT(*) FROM modules WHERE nom = ? AND parent_id = ?");
                $insFcc = $db->prepare("INSERT INTO modules (nom, description, is_container, parent_id, icon, roles, is_active, is_locked, link) VALUES (?, '', 0, ?, ?, '', 1, 0, ?)");
                $setFccContainer = $db->prepare("UPDATE modules SET is_container = 1 WHERE id = ?");
                foreach ($fcParents as $fcParentId) {
                    $fcParentId = (int) $fcParentId;
                    if ($fcParentId <= 0) {
                        continue;
                    }
                    $setFccContainer->execute([$fcParentId]);
                    foreach ($fcChildren as $c) {
                        $chkFcc->execute([$c[0], $fcParentId]);
                        if ((int) $chkFcc->fetchColumn() === 0) {
                            $insFcc->execute([$c[0], $fcParentId, $c[1], $c[2]]);
                        }
                    }
                }
                $setFlag('seed_formation_caisse_children_v1');
            }

            // 11) Arbre profond complet sous « Magasin » (tous les niveaux).
            //     Chaque nœud : [nom, icône, lien, [enfants]]. Un nœud avec enfants = Conteneur.
            if (!$hasFlag('seed_deep_tree_v1')) {
                $deepMagasin = [
                    ['Déco', '🎨', 'deco.php', [
                        ['Barbecue', '🔥', 'barbecue_menu.php', [
                            ["Cook'in Garden et Weber", '🔥', 'barbecue.php', []],
                            ['Barbecook et Napoleon', '🍖', 'barbecue2.php', []],
                        ]],
                        ['Piscine & Spa', '🏊', 'piscine_spa.php', [
                            ['Piscine', '🏊', 'piscine.php', []],
                            ['Spa', '🛁', 'spa.php', []],
                        ]],
                        ['Changement de saison', '🍂', 'changement_saison.php', []],
                        ['Présentation équipe mix', '🧑', 'mix.php', [
                            ['Formation Mix PDF', '📄', 'presentation_mix.php', []],
                            ['Formation Mix Vidéo', '🎬', 'formation_mix_video.php', []],
                        ]],
                        ['Marketing', '📣', 'marketing.php', []],
                        ['Parrain/Marraine', '🤝', 'parrain.php', []],
                        ['Fleurs artificielles', '💐', 'fleurs-artificielles-menu.php', [
                            ['Version vidéo', '🎬', 'fleurs-artificielles.php', []],
                            ['Version guide PDF', '📄', 'fleurs-artificielles-pdf.php', []],
                        ]],
                    ]],
                    ['Green', '🌿', 'green.php', [
                        ['Plantes intérieur Hiver', '🪴', 'plantes-interieur-hiver.php', []],
                        ['Le sapin de Noël', '🎄', 'sapin-noel.php', []],
                        ['Protection des plantes', '❄️', 'protection-hiver.php', []],
                        ['Plantes Automne/Hiver', '🍂', 'plantes-automne-hiver.php', []],
                        ['Les Chrysanthèmes', '🌸', 'chrysanthemes.php', []],
                        ['Cultiver des légumes', '🥦', 'legumes.php', []],
                    ]],
                    ['Animalerie', '🐾', 'animalerie.php', [
                        ['Bien-être animalier', '🐾', 'animalerie_bienetre.php', []],
                        ["Process de base de l'animalerie", '📘', 'process_base_animalerie.php', []],
                        ['Rongeur', '🐭', 'rongeur.php', []],
                        ['Oiseaux', '🐦', 'oiseaux.php', []],
                        ['SAV Animalerie', '🛠️', 'sav_animalerie.php', []],
                    ]],
                    ['Garden', '🌱', 'garden.php', [
                        ['Lutter contre les limaces', '🐌', 'lutte-limaces.php', []],
                        ['Mousse dans le gazon', '🌿', 'mousse-gazon.php', []],
                        ['Aménager votre pelouse', '🚜', 'amenagement-pelouse.php', []],
                        ['Travailler de manière ergonomique', '💪', 'ergonomie-jardin.php', []],
                        ['Faire son compost', '♻️', 'compostage.php', []],
                    ]],
                    ['Food', '🍔', 'food.php', [
                        ['Lollyland', '🍭', 'lollyland_menu.php', [
                            ['Remplir les bonbons (PDF)', '🍬', 'lollyland.php', []],
                            ['Méthode de travail', '🛠️', 'lollyland_methode_travail.php', []],
                        ]],
                    ]],
                    ['Stock', '📦', 'stock.php', [
                        ['Gerbeur', '🏗️', 'gerbeur.php', []],
                        ['Préparation de commande', '📦', 'preparation_commande.php', []],
                    ]],
                    ['Formation ressources humaines', '👥', 'ressources_humaines.php', [
                        ['Judo verbal', '🥋', 'judo.php', []],
                        ['Gestion du stress', '🧘', 'stress.php', []],
                    ]],
                ];

                $upsertNode = function ($nom, $icon, $link, $parentId, $isContainer) use ($db) {
                    $sel = $db->prepare("SELECT id FROM modules WHERE nom = ? AND parent_id = ? LIMIT 1");
                    $sel->execute([$nom, $parentId]);
                    $id = (int) $sel->fetchColumn();
                    if ($id <= 0) {
                        $ins = $db->prepare("INSERT INTO modules (nom, description, is_container, parent_id, icon, roles, is_active, is_locked, link) VALUES (?, '', ?, ?, ?, '', 1, 0, ?)");
                        $ins->execute([$nom, $isContainer, $parentId, $icon, $link]);
                        $id = (int) $db->lastInsertId();
                    } else {
                        $db->prepare("UPDATE modules SET is_container = ? WHERE id = ?")->execute([$isContainer, $id]);
                    }
                    return $id;
                };
                $walk = function ($nodes, $parentId) use (&$walk, $upsertNode) {
                    foreach ($nodes as $node) {
                        $children = $node[3];
                        $isContainer = empty($children) ? 0 : 1;
                        $id = $upsertNode($node[0], $node[1], $node[2], $parentId, $isContainer);
                        if (!empty($children)) {
                            $walk($children, $id);
                        }
                    }
                };

                $magasinId = (int) $db->query("SELECT id FROM modules WHERE nom = 'Magasin' AND parent_id IS NULL ORDER BY id ASC LIMIT 1")->fetchColumn();
                if ($magasinId > 0) {
                    $db->prepare("UPDATE modules SET is_container = 1 WHERE id = ?")->execute([$magasinId]);
                    $walk($deepMagasin, $magasinId);
                }
                $setFlag('seed_deep_tree_v1');
            }

            // 12) « Caisses » (sous Magasin) : même structure que Formation Caisse
            //     (conteneur + 4 éléments).
            if (!$hasFlag('seed_caisses_children_v1')) {
                $magasinId = (int) $db->query("SELECT id FROM modules WHERE nom = 'Magasin' AND parent_id IS NULL ORDER BY id ASC LIMIT 1")->fetchColumn();
                if ($magasinId > 0) {
                    $selCaisses = $db->prepare("SELECT id FROM modules WHERE nom = 'Caisses' AND parent_id = ? ORDER BY id ASC LIMIT 1");
                    $selCaisses->execute([$magasinId]);
                    $caissesId = (int) $selCaisses->fetchColumn();
                    if ($caissesId > 0) {
                        $db->prepare("UPDATE modules SET is_container = 1 WHERE id = ?")->execute([$caissesId]);
                        $caissesChildren = [
                            ['Support PDF', '📄', 'view-pdf.php'],
                            ['Vidéo Tutoriel', '🎥', 'caisse.php'],
                            ['Module technique', '🔧', 'module-technique.php'],
                            ['Mes 2 premières semaines en caisse', '🗓️', 'mes-2-premieres-semaines-caisse.php'],
                        ];
                        $chkCc = $db->prepare("SELECT COUNT(*) FROM modules WHERE nom = ? AND parent_id = ?");
                        $insCc = $db->prepare("INSERT INTO modules (nom, description, is_container, parent_id, icon, roles, is_active, is_locked, link) VALUES (?, '', 0, ?, ?, '', 1, 0, ?)");
                        foreach ($caissesChildren as $c) {
                            $chkCc->execute([$c[0], $caissesId]);
                            if ((int) $chkCc->fetchColumn() === 0) {
                                $insCc->execute([$c[0], $caissesId, $c[1], $c[2]]);
                            }
                        }
                    }
                }
                $setFlag('seed_caisses_children_v1');
            }

            // 13) Ordre des modules racine = ordre réel de l'accueil (pour l'aperçu par profil
            //     et la gestion). N'affecte pas la page d'accueil (codée en dur).
            if (!$hasFlag('set_root_sort_order_v1')) {
                $rootOrder = [
                    'Onboarding', 'Formation', 'Magasin', 'Management', 'Becosoft',
                    'Formation Caisse', 'Mes disponibilités', 'Mes horaires attribués',
                    'Logistique', 'Classement', 'Sécurité au travail', 'Famijob',
                    'Demandes Horaires Intérim', 'Matching Intérim', 'Validation demandes horaires',
                    'RH', 'Dispos Etudiants', 'Gestion Questions',
                ];
                // Tous les modules racine à la fin par défaut, puis on positionne ceux de la liste.
                $db->exec("UPDATE modules SET sort_order = 900 WHERE parent_id IS NULL");
                $updSort = $db->prepare("UPDATE modules SET sort_order = ? WHERE nom = ? AND parent_id IS NULL");
                foreach ($rootOrder as $i => $nom) {
                    $updSort->execute([$i + 1, $nom]);
                }
                $setFlag('set_root_sort_order_v1');
            }

            // 14) Regroupe « Support PDF » + « Vidéo Tutoriel » dans un sous-module
            //     « Formation caisse » (sous "Formation Caisse" racine et "Caisses" de Magasin).
            if (!$hasFlag('group_caisse_pdf_video_v1')) {
                $caisseParents = [];
                $r = (int) $db->query("SELECT id FROM modules WHERE nom = 'Formation Caisse' AND parent_id IS NULL ORDER BY id ASC LIMIT 1")->fetchColumn();
                if ($r > 0) {
                    $caisseParents[] = $r;
                }
                $magasinId = (int) $db->query("SELECT id FROM modules WHERE nom = 'Magasin' AND parent_id IS NULL ORDER BY id ASC LIMIT 1")->fetchColumn();
                if ($magasinId > 0) {
                    $selC = $db->prepare("SELECT id FROM modules WHERE nom = 'Caisses' AND parent_id = ? ORDER BY id ASC LIMIT 1");
                    $selC->execute([$magasinId]);
                    $cid = (int) $selC->fetchColumn();
                    if ($cid > 0) {
                        $caisseParents[] = $cid;
                    }
                }

                $findChild = $db->prepare("SELECT id FROM modules WHERE nom = ? AND parent_id = ? ORDER BY id ASC LIMIT 1");
                $insGroup = $db->prepare("INSERT INTO modules (nom, description, is_container, parent_id, icon, roles, is_active, is_locked, link) VALUES ('Formation caisse', '', 1, ?, '💳', '', 1, 0, NULL)");
                $moveChild = $db->prepare("UPDATE modules SET parent_id = ? WHERE id = ?");

                foreach ($caisseParents as $parentId) {
                    $findChild->execute(['Formation caisse', $parentId]);
                    $groupId = (int) $findChild->fetchColumn();
                    if ($groupId <= 0) {
                        $insGroup->execute([$parentId]);
                        $groupId = (int) $db->lastInsertId();
                    }
                    foreach (['Support PDF', 'Vidéo Tutoriel'] as $childNom) {
                        $findChild->execute([$childNom, $parentId]);
                        $childId = (int) $findChild->fetchColumn();
                        if ($childId > 0) {
                            $moveChild->execute([$groupId, $childId]);
                        }
                    }
                }
                $setFlag('group_caisse_pdf_video_v1');
            }

            // 15) Ordre des SOUS-modules = ordre réel du site.
            //     Les sous-modules ont été créés dans l'ordre d'affichage : on classe donc
            //     les enfants de chaque parent par id croissant (= ordre de création = ordre réel).
            //     Exception : le groupe « Formation caisse » (PDF+vidéo) passe en premier.
            if (!$hasFlag('sync_submodule_order_v2')) {
                $parents = $db->query("SELECT DISTINCT parent_id FROM modules WHERE parent_id IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
                $kidsStmt = $db->prepare("SELECT id, nom FROM modules WHERE parent_id = ? ORDER BY id ASC");
                $updK = $db->prepare("UPDATE modules SET sort_order = ? WHERE id = ?");
                foreach ($parents as $pid) {
                    $kidsStmt->execute([(int) $pid]);
                    $rank = 0;
                    foreach ($kidsStmt->fetchAll(PDO::FETCH_ASSOC) as $k) {
                        $so = (strcasecmp((string) $k['nom'], 'Formation caisse') === 0) ? -1 : $rank;
                        $updK->execute([$so, (int) $k['id']]);
                        $rank++;
                    }
                }
                $setFlag('sync_submodule_order_v2');
            }

            // 16) Site piloté par la base : les modules CONTENEUR ne pointent plus vers une
            //     page codée en dur — ils passent par le moteur générique module.php?id
            //     qui affiche leurs sous-modules depuis la base. Les feuilles gardent leur lien.
            if (!$hasFlag('containers_dbdriven_v1')) {
                $db->exec("UPDATE modules SET link = NULL WHERE is_container = 1");
                $setFlag('containers_dbdriven_v1');
            }

            // 17) Modules « Rendez-vous » : Présentiel (actif) et En ligne (inactif).
            //     Ils affichent l'interface de réservation au lieu d'un contenu.
            if (!$hasFlag('seed_rdv_modules_v1')) {
                // Présentiel : module de rendez-vous, actif, sans lien.
                $db->exec("UPDATE modules SET is_booking = 1, is_container = 0, link = NULL, is_active = 1 WHERE nom = 'Présentiel'");
                // En ligne : module de rendez-vous, INACTIF pour l'instant.
                $db->exec("UPDATE modules SET is_booking = 1, is_container = 0, link = NULL, is_active = 0 WHERE nom = 'En ligne'");
                $setFlag('seed_rdv_modules_v1');
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
                $db->exec("UPDATE profils SET is_locked = 1 WHERE is_core = 1");
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
     * Traduit un texte FR -> NL via l'API gratuite MyMemory (sans clé).
     * Renvoie '' en cas d'échec (le site reste alors en français, sans erreur).
     * Astuce : définir MYMEMORY_EMAIL en variable d'environnement augmente le quota gratuit.
     */
    function mymemoryTranslateFrToNl($text)
    {
        $text = trim((string) $text);
        if ($text === '' || !function_exists('curl_init')) {
            return '';
        }
        // MyMemory limite chaque requête à ~500 caractères
        $text = mb_substr($text, 0, 500);
        $url = 'https://api.mymemory.translated.net/get?langpair=fr|nl&q=' . rawurlencode($text);
        $email = getenv('MYMEMORY_EMAIL');
        if ($email !== false && $email !== '') {
            $url .= '&de=' . rawurlencode($email);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_USERAGENT => 'Famiformation/1.0',
        ]);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($resp === false || $code < 200 || $code >= 300) {
            return '';
        }
        $data = json_decode($resp, true);
        $translated = $data['responseData']['translatedText'] ?? '';
        $status = (int) ($data['responseStatus'] ?? 200);
        if (!is_string($translated) || $translated === '' || $status !== 200) {
            return '';
        }
        // MyMemory glisse parfois un message d'avertissement dans translatedText
        if (stripos($translated, 'MYMEMORY WARNING') !== false || stripos($translated, 'INVALID') !== false) {
            return '';
        }
        return trim(html_entity_decode($translated, ENT_QUOTES, 'UTF-8'));
    }

    /**
     * Traduit le nom + la description d'un module en néerlandais (MyMemory, gratuit, sans clé).
     * Renvoie ['nom' => '', 'desc' => ''] si la traduction échoue.
     */
    function translateModuleToNl($nom, $desc)
    {
        $nom = trim((string) $nom);
        $desc = trim((string) $desc);
        return [
            'nom'  => $nom !== ''  ? mb_substr(mymemoryTranslateFrToNl($nom), 0, 150)  : '',
            'desc' => $desc !== '' ? mb_substr(mymemoryTranslateFrToNl($desc), 0, 500) : '',
        ];
    }

    /**
     * Nom du module dans la langue courante (NL si dispo, sinon FR).
     */
    function moduleNom(array $m)
    {
        if (function_exists('currentLang') && currentLang() === 'nl') {
            $nl = trim((string) ($m['nom_nl'] ?? ''));
            if ($nl !== '') {
                return $nl;
            }
        }
        return (string) ($m['nom'] ?? '');
    }

    /**
     * Description du module dans la langue courante (NL si dispo, sinon FR).
     */
    function moduleDesc(array $m)
    {
        if (function_exists('currentLang') && currentLang() === 'nl') {
            $nl = trim((string) ($m['description_nl'] ?? ''));
            if ($nl !== '') {
                return $nl;
            }
        }
        return (string) ($m['description'] ?? '');
    }

    /**
     * Un aperçu de profil est-il actif ? (réservé à un admin qui prévisualise un autre profil)
     */
    function isApercuActif()
    {
        return !empty($_SESSION['apercu_role']) && (($_SESSION['role'] ?? '') === 'admin');
    }

    /**
     * Rôle à utiliser pour l'AFFICHAGE uniquement (tuiles, visibilité des modules).
     * Ne modifie JAMAIS le rôle réel utilisé pour le contrôle d'accès.
     */
    function currentDisplayRole()
    {
        if (isApercuActif()) {
            return $_SESSION['apercu_role'];
        }
        return $_SESSION['role'] ?? 'etudiant';
    }

    /**
     * Bannière d'aperçu (sticky) affichée en haut des pages quand l'aperçu est actif.
     */
    function apercuBanner(PDO $db = null)
    {
        if (!isApercuActif()) {
            return '';
        }
        $key = (string) $_SESSION['apercu_role'];
        $label = $key;
        if ($db instanceof PDO) {
            $profs = moduleProfiles($db);
            $label = $profs[$key] ?? $key;
        }
        $flash = '';
        if (!empty($_SESSION['apercu_flash'])) {
            $flash = '<div style="position:sticky; top:0; z-index:5001; background:#a13e35; color:#fff; padding:8px 16px; text-align:center; font-weight:700;">'
                . htmlspecialchars((string) $_SESSION['apercu_flash'])
                . '</div>';
            unset($_SESSION['apercu_flash']);
        }
        return $flash
            . '<div style="position:sticky; top:0; z-index:5000; background:#2d5a37; color:#fff; padding:10px 16px; text-align:center; font-weight:700; box-shadow:0 2px 10px rgba(0,0,0,0.3);">'
            . '👁 Aperçu du profil : ' . htmlspecialchars($label) . ' — vous voyez le site comme cet utilisateur (lecture seule, aucune action n\'est enregistrée).'
            . ' <a href="apercu.php?exit=1" style="color:#fff; text-decoration:underline; margin-left:12px;">Quitter l\'aperçu</a>'
            . '</div>';
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
