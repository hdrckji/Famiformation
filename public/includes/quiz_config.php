<?php
// ============================================================
// quiz_config.php — réglages du quiz (préférences admin).
//   • Combien de questions l'IA génère (la « banque »).
//   • Le ratio multiples / uniques à la génération.
//   • Combien de questions sont posées à l'apprenant (tirage au hasard) et
//     combien d'entre elles sont à réponses multiples.
// Additif : autonome (stockage via widgetGet/widgetSet, comme pdf_access.php).
// ============================================================

if (!function_exists('quizCfgEnabled')) {
    /**
     * Les quiz sont-ils activés sur le site ?
     * Coupé : plus aucune génération, la case « Générer un quiz » n'a plus d'effet.
     */
    function quizCfgEnabled($db)
    {
        return !function_exists('widgetGet') || widgetGet($db, 'quiz_enabled', '1') === '1';
    }
}

if (!function_exists('quizCfgSaved')) {
    /**
     * L'admin a-t-il DÉJÀ enregistré ces réglages lui-même ?
     * Sinon, on ignore ce qui traîne en base et on applique les valeurs par défaut du code
     * (sans ça, un ancien 75 % enregistré automatiquement écraserait le nouveau 50/50).
     */
    function quizCfgSaved($db)
    {
        return function_exists('widgetGet') && widgetGet($db, 'quiz_cfg_saved', '0') === '1';
    }
}

if (!function_exists('quizCfgGen')) {
    /** Nombre total de questions générées par l'IA (la banque). */
    function quizCfgGen($db)
    {
        if (!quizCfgSaved($db)) { return 25; }
        $n = (int) widgetGet($db, 'quiz_gen_total', '25');
        return max(5, min(100, $n ?: 25));
    }

    /** Part (%) de questions à réponses MULTIPLES à la génération. */
    function quizCfgGenPctMultiple($db)
    {
        if (!quizCfgSaved($db)) { return 50; } // 50 / 50 par défaut
        $p = (int) widgetGet($db, 'quiz_gen_pct_multiple', '50');
        return max(0, min(100, $p));
    }

    /** Répartition générée : [nbMultiple, nbSingle]. */
    function quizCfgGenSplit($db)
    {
        $total = quizCfgGen($db);
        $mul = (int) round($total * quizCfgGenPctMultiple($db) / 100);
        $mul = max(0, min($total, $mul));
        return [$mul, $total - $mul];
    }

    /** Nombre de questions POSÉES à l'apprenant (tirées au hasard dans la banque). */
    function quizCfgAsked($db)
    {
        if (!quizCfgSaved($db)) { return 10; }
        $n = (int) widgetGet($db, 'quiz_asked_total', '10');
        return max(1, min(100, $n ?: 10));
    }

    /** Parmi les questions posées, combien à réponses MULTIPLES. */
    function quizCfgAskedMultiple($db)
    {
        $asked = quizCfgAsked($db);
        $raw = quizCfgSaved($db) ? widgetGet($db, 'quiz_asked_multiple', '') : '';
        if (trim((string) $raw) === '') {
            return (int) round($asked * 0.5); // 50 / 50 : autant de multiples que d'uniques
        }
        return max(0, min($asked, (int) $raw));
    }

    /** Répartition posée : [nbMultiple, nbSingle]. */
    function quizCfgAskedSplit($db)
    {
        $asked = quizCfgAsked($db);
        $mul = quizCfgAskedMultiple($db);
        return [$mul, $asked - $mul];
    }
}

if (!function_exists('quizConfigHandlePost')) {
    function quizConfigHandlePost($db)
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { return; }
        if (($_POST['action'] ?? '') !== 'set_quiz_config') { return; }
        requireValidCSRF();

        // Bascule seule (interrupteur du bloc) : on n'écrase pas les autres réglages.
        if (!empty($_POST['toggle_only'])) {
            widgetSet($db, 'quiz_enabled', !empty($_POST['quiz_enabled']) ? '1' : '0');
            $_SESSION['module_flash'] = !empty($_POST['quiz_enabled'])
                ? '📝 Quiz activés.'
                : '📝 Quiz désactivés : plus aucun quiz ne sera généré.';
            header('Location: parametres.php#prefs');
            exit();
        }

        $gen = max(5, min(100, (int) ($_POST['quiz_gen_total'] ?? 25)));
        $pct = max(0, min(100, (int) ($_POST['quiz_gen_pct_multiple'] ?? 75)));
        $asked = max(1, min($gen, (int) ($_POST['quiz_asked_total'] ?? 10)));
        $askedMul = max(0, min($asked, (int) ($_POST['quiz_asked_multiple'] ?? 5)));

        widgetSet($db, 'quiz_gen_total', (string) $gen);
        widgetSet($db, 'quiz_gen_pct_multiple', (string) $pct);
        widgetSet($db, 'quiz_asked_total', (string) $asked);
        widgetSet($db, 'quiz_asked_multiple', (string) $askedMul);
        widgetSet($db, 'quiz_pass_mark', (string) max(1, min(100, (int) ($_POST['quiz_pass_mark'] ?? 70))));
        widgetSet($db, 'quiz_cfg_saved', '1'); // à partir d'ici, c'est TON réglage qui prime

        $_SESSION['module_flash'] = '📝 Réglages du quiz enregistrés.';
        header('Location: parametres.php#prefs');
        exit();
    }
}

if (!function_exists('quizConfigCard')) {
    function quizConfigCard($db)
    {
        $gen = quizCfgGen($db);
        $pct = quizCfgGenPctMultiple($db);
        list($genMul, $genSin) = quizCfgGenSplit($db);
        $asked = quizCfgAsked($db);
        list($askMul, $askSin) = quizCfgAskedSplit($db);
        $inp = 'padding:9px 11px; border:1px solid #ccd6cf; border-radius:9px; font:inherit; width:110px;';
        require_once __DIR__ . '/ui_switch.php';
        $on = quizCfgEnabled($db);
        ?>
        <div class="pref-block">
            <?php famiPrefHead('📝 Quiz — génération &amp; tirage', 'set_quiz_config', 'quiz_enabled', $on,
                "Coupé : aucun quiz n'est généré, même si la case « Générer un quiz » est cochée à l'import."); ?>
            <div class="pref-body<?= $on ? '' : ' pref-off' ?>">
            <p class="muted">L'IA génère une <strong>banque</strong> de questions à la validation du guide. À chaque passage, l'apprenant n'en reçoit qu'une <strong>partie, tirée au hasard</strong> : deux passages ne tombent pas sur les mêmes questions. Plus la banque est grande, plus la génération coûte du temps et des jetons.</p>

            <form method="POST" action="parametres.php#prefs">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="set_quiz_config">

                <div style="display:flex; gap:22px; flex-wrap:wrap; margin-top:12px;">
                    <label style="font-weight:700; color:#244230;">
                        Questions générées (banque)<br>
                        <input type="number" name="quiz_gen_total" min="5" max="100" value="<?= (int) $gen ?>" style="<?= $inp ?>">
                    </label>
                    <label style="font-weight:700; color:#244230;">
                        Dont multiples (%)<br>
                        <input type="number" name="quiz_gen_pct_multiple" min="0" max="100" value="<?= (int) $pct ?>" style="<?= $inp ?>">
                    </label>
                </div>
                <div class="muted" style="font-size:.85rem; margin-top:6px;">Actuellement : <strong><?= (int) $genMul ?></strong> multiples + <strong><?= (int) $genSin ?></strong> uniques.</div>

                <div style="display:flex; gap:22px; flex-wrap:wrap; margin-top:18px; padding-top:14px; border-top:1px dashed #dfe6e0;">
                    <label style="font-weight:700; color:#244230;">
                        Questions posées (au hasard)<br>
                        <input type="number" name="quiz_asked_total" min="1" max="100" value="<?= (int) $asked ?>" style="<?= $inp ?>">
                    </label>
                    <label style="font-weight:700; color:#244230;">
                        Dont multiples<br>
                        <input type="number" name="quiz_asked_multiple" min="0" max="100" value="<?= (int) $askMul ?>" style="<?= $inp ?>">
                    </label>
                </div>
                <div style="display:flex; gap:22px; flex-wrap:wrap; margin-top:18px; padding-top:14px; border-top:1px dashed #dfe6e0;">
                    <label style="font-weight:700; color:#244230;">
                        Note de réussite (%)<br>
                        <input type="number" name="quiz_pass_mark" min="1" max="100" value="<?= (int) (function_exists('widgetGet') ? widgetGet($db, 'quiz_pass_mark', '70') : 70) ?>" style="<?= $inp ?>">
                    </label>
                </div>
                <div class="muted" style="font-size:.85rem; margin-top:6px;">En dessous de cette note, le quiz n'est pas validé. La réussite peut conditionner le téléchargement (Créateur / Accès aux fichiers).</div>

                <div class="muted" style="font-size:.85rem; margin-top:6px;">L'apprenant répond à <strong><?= (int) $asked ?></strong> questions : <strong><?= (int) $askMul ?></strong> multiples + <strong><?= (int) $askSin ?></strong> uniques. Si la banque manque d'un type, on complète avec l'autre.</div>

                <div style="margin-top:16px;"><button type="submit" class="btn btn-primary">Enregistrer</button></div>
            </form>
            </div>
        </div>
        <?php
    }
}
