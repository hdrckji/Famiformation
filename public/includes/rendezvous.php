<?php
// ============================================================
// Rendez-vous / réservation de formation.
// Modules spéciaux "Présentiel" (présentiel) et "En ligne" (visio).
// L'admin crée des créneaux + formateur ; l'utilisateur s'inscrit (validé par l'admin).
// ============================================================

if (!function_exists('ensureRendezvousTables')) {
    function ensureRendezvousTables(PDO $db)
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        try {
            $db->exec(
                "CREATE TABLE IF NOT EXISTS formation_slots (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    module_id INT NOT NULL,
                    formation_titre VARCHAR(200) NOT NULL,
                    formation_desc TEXT NULL,
                    mode VARCHAR(20) NOT NULL DEFAULT 'presentiel',
                    formateur_type VARCHAR(20) NOT NULL DEFAULT 'interne',
                    formateur_user_id INT NULL,
                    formateur_nom VARCHAR(150) NULL,
                    formateur_email VARCHAR(190) NULL,
                    date_debut DATETIME NOT NULL,
                    date_fin DATETIME NULL,
                    places SMALLINT UNSIGNED NOT NULL DEFAULT 1,
                    lieu_ou_lien TEXT NULL,
                    complement TEXT NULL,
                    created_by INT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_slot_module (module_id),
                    INDEX idx_slot_date (date_debut)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
            );
            $db->exec(
                "CREATE TABLE IF NOT EXISTS formation_bookings (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    slot_id INT NOT NULL,
                    user_id INT NOT NULL,
                    note TEXT NULL,
                    statut VARCHAR(20) NOT NULL DEFAULT 'pending',
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uniq_booking (slot_id, user_id),
                    INDEX idx_booking_slot (slot_id),
                    INDEX idx_booking_user (user_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
            );
        } catch (Exception $e) {
            // base indisponible : on ignore
        }
    }
}

if (!function_exists('rdvModuleMode')) {
    /** Le mode découle du module : "En ligne" => en_ligne, sinon présentiel. */
    function rdvModuleMode(array $module)
    {
        return (mb_stripos((string) ($module['nom'] ?? ''), 'ligne') !== false) ? 'en_ligne' : 'presentiel';
    }
}

if (!function_exists('rdvFmtDate')) {
    function rdvFmtDate($datetime)
    {
        $ts = strtotime((string) $datetime);
        if (!$ts) {
            return (string) $datetime;
        }
        return date('d/m/Y', $ts) . ' à ' . date('H\hi', $ts);
    }
}

if (!function_exists('rdvFormateurLabel')) {
    function rdvFormateurLabel(array $slot)
    {
        $nom = trim((string) ($slot['formateur_nom'] ?? ''));
        $type = (string) ($slot['formateur_type'] ?? 'interne');
        $suffix = $type === 'externe' ? ' (externe)' : '';
        return $nom !== '' ? $nom . $suffix : '—';
    }
}

if (!function_exists('renderRendezvousModule')) {
    /** Rendu de l'interface de rendez-vous (admin ou utilisateur). */
    function renderRendezvousModule(PDO $db, array $module, $isAdmin, $role, $userId)
    {
        ensureRendezvousTables($db);
        $mode = rdvModuleMode($module);
        $moduleId = (int) $module['id'];
        $lieuLabel = $mode === 'en_ligne' ? 'Lien de visio' : 'Lieu (adresse + salle)';
        $lieuPlaceholder = $mode === 'en_ligne' ? 'https://teams.microsoft.com/... (ou Zoom, Meet...)' : 'Ex : Famiflora Mouscron — salle de formation';

        ob_start();
        ?>
        <div class="rdv-wrap">
            <style>
            .rdv-wrap { width:90%; max-width:1000px; margin:10px auto 40px; }
            .rdv-card { background:rgba(255,255,255,0.97); border-radius:18px; box-shadow:0 10px 25px rgba(0,0,0,0.1); padding:22px; margin-bottom:20px; }
            .rdv-card h2 { color:#2d5a37; margin:0 0 4px; }
            .rdv-muted { color:#6c7a70; font-size:0.9rem; }
            .rdv-step { border-left:3px solid #cfe3d5; padding:4px 0 4px 14px; margin:16px 0; }
            .rdv-step-title { font-weight:800; color:#2d5a37; margin-bottom:8px; }
            .rdv-field { margin-bottom:10px; }
            .rdv-field label { display:block; font-weight:700; color:#244230; font-size:0.86rem; margin-bottom:4px; }
            .rdv-field input[type=text], .rdv-field input[type=email], .rdv-field input[type=number], .rdv-field input[type=datetime-local], .rdv-field select, .rdv-field textarea { width:100%; box-sizing:border-box; padding:10px; border:1px solid #cfd8d2; border-radius:9px; font:inherit; }
            .rdv-row { display:flex; gap:12px; flex-wrap:wrap; }
            .rdv-row > div { flex:1; min-width:180px; }
            .rdv-btn { border:none; border-radius:10px; padding:10px 16px; font-weight:700; cursor:pointer; text-decoration:none; display:inline-block; }
            .rdv-btn-primary { background:#2d5a37; color:#fff; }
            .rdv-btn-light { background:#e9ecef; color:#333; }
            .rdv-btn-danger { background:#fae4e1; color:#a13e35; }
            .rdv-btn-ok { background:#dff3e3; color:#1d6a39; }
            .rdv-slot { border:1px solid #e3ece5; border-radius:14px; padding:16px; margin-bottom:14px; background:#fbfdfb; }
            .rdv-slot-head { display:flex; justify-content:space-between; align-items:flex-start; gap:12px; flex-wrap:wrap; }
            .rdv-slot-title { font-weight:800; color:#244230; font-size:1.08rem; }
            .rdv-slot-meta { color:#5a6b60; font-size:0.9rem; margin-top:4px; line-height:1.5; }
            .rdv-pill { display:inline-block; padding:3px 10px; border-radius:999px; font-size:0.76rem; font-weight:700; }
            .rdv-pill.pending { background:#fff3cd; color:#856404; }
            .rdv-pill.confirmed { background:#dff3e3; color:#1d6a39; }
            .rdv-pill.refused { background:#fae4e1; color:#a13e35; }
            .rdv-booking { display:flex; justify-content:space-between; align-items:center; gap:10px; padding:8px 0; border-top:1px dashed #e3ece5; flex-wrap:wrap; }
            .rdv-toggle { cursor:pointer; }
            </style>

            <?php if ($isAdmin): ?>
                <?php echo rdvAdminView($db, $module, $mode, $moduleId, $lieuLabel, $lieuPlaceholder); ?>
            <?php else: ?>
                <?php echo rdvUserView($db, $module, $mode, $moduleId, (int) $userId, $lieuLabel); ?>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }
}

if (!function_exists('rdvLoadSlots')) {
    /** Créneaux d'un module (futurs d'abord), avec nb d'inscrits confirmés/en attente. */
    function rdvLoadSlots(PDO $db, $moduleId, $onlyFuture = false)
    {
        $sql = "SELECT s.*,
                       (SELECT COUNT(*) FROM formation_bookings b WHERE b.slot_id = s.id AND b.statut = 'confirmed') AS nb_confirmes,
                       (SELECT COUNT(*) FROM formation_bookings b WHERE b.slot_id = s.id AND b.statut = 'pending') AS nb_attente
                FROM formation_slots s
                WHERE s.module_id = ?";
        if ($onlyFuture) {
            $sql .= " AND s.date_debut >= NOW()";
        }
        $sql .= " ORDER BY s.date_debut ASC";
        $st = $db->prepare($sql);
        $st->execute([(int) $moduleId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('rdvAdminView')) {
    function rdvAdminView(PDO $db, array $module, $mode, $moduleId, $lieuLabel, $lieuPlaceholder)
    {
        // Formateurs internes possibles (personnel non-étudiant)
        $staff = $db->query("SELECT id, nom, prenom FROM utilisateurs WHERE role NOT IN ('etudiant','agence_interim') ORDER BY nom ASC, prenom ASC")->fetchAll(PDO::FETCH_ASSOC);
        $slots = rdvLoadSlots($db, $moduleId, false);
        ob_start();
        ?>
        <div class="rdv-card">
            <h2>🗂️ Organisation des formations</h2>
            <p class="rdv-muted">Crée un créneau, choisis le formateur et renseigne <?php echo $mode === 'en_ligne' ? 'le lien de visio' : 'le lieu'; ?>. Les inscriptions se valident plus bas.</p>
            <button type="button" class="rdv-btn rdv-btn-primary" onclick="var f=document.getElementById('rdvAssistant'); f.style.display=(f.style.display==='none'||!f.style.display)?'block':'none';">➕ Organiser une formation</button>

            <form id="rdvAssistant" method="POST" action="rendezvous_save.php" style="display:none; margin-top:18px;">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="create_slot">
                <input type="hidden" name="module_id" value="<?php echo (int) $moduleId; ?>">
                <input type="hidden" name="mode" value="<?php echo htmlspecialchars($mode); ?>">

                <div class="rdv-step">
                    <div class="rdv-step-title">1. La formation</div>
                    <div class="rdv-field">
                        <label>Intitulé de la formation *</label>
                        <input type="text" name="formation_titre" required maxlength="200" placeholder="Ex : Sécurité — secourisme de base">
                    </div>
                    <div class="rdv-field">
                        <label>Description (optionnel)</label>
                        <textarea name="formation_desc" rows="2" placeholder="À qui s'adresse la formation, prérequis..."></textarea>
                    </div>
                </div>

                <div class="rdv-step">
                    <div class="rdv-step-title">2. Le formateur</div>
                    <div class="rdv-field">
                        <label>Type</label>
                        <select name="formateur_type" onchange="rdvFormateurType(this.value)">
                            <option value="interne">Interne (collaborateur Famiflora)</option>
                            <option value="externe">Externe (organisme / intervenant)</option>
                        </select>
                    </div>
                    <div class="rdv-field" id="rdvFormInterne">
                        <label>Collaborateur formateur</label>
                        <select name="formateur_user_id">
                            <option value="">— Choisir —</option>
                            <?php foreach ($staff as $u): ?>
                                <option value="<?php echo (int) $u['id']; ?>"><?php echo htmlspecialchars(trim($u['prenom'] . ' ' . $u['nom'])); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="rdv-muted">Il recevra un message dans Famiformation.</div>
                    </div>
                    <div class="rdv-field" id="rdvFormExterne" style="display:none;">
                        <div class="rdv-row">
                            <div><label>Nom de l'intervenant / organisme</label><input type="text" name="formateur_nom" maxlength="150" placeholder="Ex : Croix-Rouge — J. Martin"></div>
                            <div><label>Email (pour le prévenir)</label><input type="email" name="formateur_email" maxlength="190" placeholder="formateur@exemple.com"></div>
                        </div>
                    </div>
                </div>

                <div class="rdv-step">
                    <div class="rdv-step-title">3. Le créneau</div>
                    <div class="rdv-row">
                        <div class="rdv-field"><label>Début *</label><input type="datetime-local" name="date_debut" required></div>
                        <div class="rdv-field"><label>Fin (optionnel)</label><input type="datetime-local" name="date_fin"></div>
                        <div class="rdv-field"><label>Nombre de places *</label><input type="number" name="places" min="1" max="500" value="1" required></div>
                    </div>
                </div>

                <div class="rdv-step">
                    <div class="rdv-step-title">4. <?php echo htmlspecialchars($lieuLabel); ?> & infos</div>
                    <div class="rdv-field">
                        <label><?php echo htmlspecialchars($lieuLabel); ?> *</label>
                        <input type="text" name="lieu_ou_lien" required placeholder="<?php echo htmlspecialchars($lieuPlaceholder); ?>">
                    </div>
                    <div class="rdv-field">
                        <label>Complément d'information (optionnel)</label>
                        <textarea name="complement" rows="2" placeholder="Ex : apporter une tenue adaptée, prévoir 3h..."></textarea>
                    </div>
                </div>

                <div style="display:flex; gap:10px;">
                    <button type="submit" class="rdv-btn rdv-btn-primary">✅ Créer le créneau</button>
                    <button type="button" class="rdv-btn rdv-btn-light" onclick="document.getElementById('rdvAssistant').style.display='none';">Annuler</button>
                </div>
            </form>
            <script>
            function rdvFormateurType(v) {
                document.getElementById('rdvFormInterne').style.display = (v === 'interne') ? 'block' : 'none';
                document.getElementById('rdvFormExterne').style.display = (v === 'externe') ? 'block' : 'none';
            }
            </script>
        </div>

        <div class="rdv-card">
            <h2>📅 Créneaux & inscriptions</h2>
            <?php if (empty($slots)): ?>
                <p class="rdv-muted">Aucun créneau pour l'instant. Clique sur « Organiser une formation » ci-dessus.</p>
            <?php else: ?>
                <?php foreach ($slots as $slot): ?>
                    <?php
                    $bk = $db->prepare("SELECT b.*, u.nom, u.prenom FROM formation_bookings b LEFT JOIN utilisateurs u ON u.id = b.user_id WHERE b.slot_id = ? ORDER BY b.created_at ASC");
                    $bk->execute([(int) $slot['id']]);
                    $bookings = $bk->fetchAll(PDO::FETCH_ASSOC);
                    ?>
                    <div class="rdv-slot">
                        <div class="rdv-slot-head">
                            <div>
                                <div class="rdv-slot-title"><?php echo htmlspecialchars($slot['formation_titre']); ?></div>
                                <div class="rdv-slot-meta">
                                    📆 <?php echo rdvFmtDate($slot['date_debut']); ?><br>
                                    👤 <?php echo htmlspecialchars(rdvFormateurLabel($slot)); ?><br>
                                    <?php echo $mode === 'en_ligne' ? '🔗' : '📍'; ?> <?php echo htmlspecialchars((string) $slot['lieu_ou_lien']); ?><br>
                                    🎟️ <?php echo (int) $slot['nb_confirmes']; ?>/<?php echo (int) $slot['places']; ?> confirmé(s) · <?php echo (int) $slot['nb_attente']; ?> en attente
                                    <?php if (trim((string) $slot['complement']) !== ''): ?><br>ℹ️ <?php echo htmlspecialchars($slot['complement']); ?><?php endif; ?>
                                </div>
                            </div>
                            <form method="POST" action="rendezvous_save.php" onsubmit="return confirm('Supprimer ce créneau et ses inscriptions ?');">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="action" value="delete_slot">
                                <input type="hidden" name="slot_id" value="<?php echo (int) $slot['id']; ?>">
                                <input type="hidden" name="module_id" value="<?php echo (int) $moduleId; ?>">
                                <button type="submit" class="rdv-btn rdv-btn-danger">🗑 Supprimer</button>
                            </form>
                        </div>

                        <?php if (empty($bookings)): ?>
                            <div class="rdv-muted" style="margin-top:10px;">Aucune inscription.</div>
                        <?php else: ?>
                            <?php foreach ($bookings as $b): ?>
                                <div class="rdv-booking">
                                    <div>
                                        <strong><?php echo htmlspecialchars(trim(($b['prenom'] ?? '') . ' ' . ($b['nom'] ?? ''))); ?></strong>
                                        <span class="rdv-pill <?php echo htmlspecialchars($b['statut']); ?>"><?php echo $b['statut'] === 'confirmed' ? 'Confirmé' : ($b['statut'] === 'refused' ? 'Refusé' : 'En attente'); ?></span>
                                        <?php if (trim((string) ($b['note'] ?? '')) !== ''): ?><div class="rdv-muted">« <?php echo htmlspecialchars($b['note']); ?> »</div><?php endif; ?>
                                    </div>
                                    <?php if ($b['statut'] === 'pending'): ?>
                                        <div style="display:flex; gap:6px;">
                                            <form method="POST" action="rendezvous_save.php"><?php echo csrfField(); ?><input type="hidden" name="action" value="validate_booking"><input type="hidden" name="booking_id" value="<?php echo (int) $b['id']; ?>"><input type="hidden" name="module_id" value="<?php echo (int) $moduleId; ?>"><button class="rdv-btn rdv-btn-ok" type="submit">Valider</button></form>
                                            <form method="POST" action="rendezvous_save.php"><?php echo csrfField(); ?><input type="hidden" name="action" value="refuse_booking"><input type="hidden" name="booking_id" value="<?php echo (int) $b['id']; ?>"><input type="hidden" name="module_id" value="<?php echo (int) $moduleId; ?>"><button class="rdv-btn rdv-btn-danger" type="submit">Refuser</button></form>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }
}

if (!function_exists('rdvUserView')) {
    function rdvUserView(PDO $db, array $module, $mode, $moduleId, $userId, $lieuLabel)
    {
        $slots = rdvLoadSlots($db, $moduleId, true);
        // Inscriptions de l'utilisateur pour ce module
        $mine = $db->prepare(
            "SELECT b.*, s.formation_titre, s.date_debut, s.lieu_ou_lien, s.complement
             FROM formation_bookings b
             INNER JOIN formation_slots s ON s.id = b.slot_id
             WHERE s.module_id = ? AND b.user_id = ?
             ORDER BY s.date_debut ASC"
        );
        $mine->execute([(int) $moduleId, (int) $userId]);
        $myBookings = $mine->fetchAll(PDO::FETCH_ASSOC);
        $myBySlot = [];
        foreach ($myBookings as $mb) {
            $myBySlot[(int) $mb['slot_id']] = $mb;
        }
        ob_start();
        ?>
        <div class="rdv-card">
            <h2>📅 Créneaux disponibles</h2>
            <p class="rdv-muted">Inscris-toi à un créneau. Ton inscription sera <strong>validée par un administrateur</strong>.</p>
            <?php if (empty($slots)): ?>
                <p class="rdv-muted">Aucun créneau proposé pour l'instant.</p>
            <?php else: ?>
                <?php foreach ($slots as $slot): ?>
                    <?php
                    $sid = (int) $slot['id'];
                    $restantes = max(0, (int) $slot['places'] - (int) $slot['nb_confirmes']);
                    $deja = $myBySlot[$sid] ?? null;
                    ?>
                    <div class="rdv-slot">
                        <div class="rdv-slot-title"><?php echo htmlspecialchars($slot['formation_titre']); ?></div>
                        <div class="rdv-slot-meta">
                            📆 <?php echo rdvFmtDate($slot['date_debut']); ?><br>
                            👤 <?php echo htmlspecialchars(rdvFormateurLabel($slot)); ?> ·
                            🎟️ <?php echo $restantes; ?> place(s) restante(s)
                            <?php if (trim((string) $slot['complement']) !== ''): ?><br>ℹ️ <?php echo htmlspecialchars($slot['complement']); ?><?php endif; ?>
                        </div>
                        <div style="margin-top:10px;">
                            <?php if ($deja): ?>
                                <span class="rdv-pill <?php echo htmlspecialchars($deja['statut']); ?>"><?php echo $deja['statut'] === 'confirmed' ? '✅ Inscription confirmée' : ($deja['statut'] === 'refused' ? '❌ Refusée' : '⏳ En attente de validation'); ?></span>
                                <?php if ($deja['statut'] === 'pending' || $deja['statut'] === 'confirmed'): ?>
                                    <form method="POST" action="rendezvous_save.php" style="display:inline;" onsubmit="return confirm('Annuler ton inscription ?');">
                                        <?php echo csrfField(); ?>
                                        <input type="hidden" name="action" value="cancel_booking">
                                        <input type="hidden" name="slot_id" value="<?php echo $sid; ?>">
                                        <input type="hidden" name="module_id" value="<?php echo (int) $moduleId; ?>">
                                        <button type="submit" class="rdv-btn rdv-btn-light">Annuler</button>
                                    </form>
                                <?php endif; ?>
                            <?php elseif ($restantes <= 0): ?>
                                <span class="rdv-pill refused">Complet</span>
                            <?php else: ?>
                                <form method="POST" action="rendezvous_save.php">
                                    <?php echo csrfField(); ?>
                                    <input type="hidden" name="action" value="register">
                                    <input type="hidden" name="slot_id" value="<?php echo $sid; ?>">
                                    <input type="hidden" name="module_id" value="<?php echo (int) $moduleId; ?>">
                                    <div class="rdv-field" style="max-width:520px;">
                                        <label>Complément (optionnel)</label>
                                        <input type="text" name="note" maxlength="300" placeholder="Une précision à ajouter ?">
                                    </div>
                                    <button type="submit" class="rdv-btn rdv-btn-primary">S'inscrire</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }
}
