<?php
// ============================================================
// quiz_config.php — réglages du quiz (préférences admin).
//   • Combien de questions l'IA génère (la « banque »).
//   • Le ratio multiples / uniques à la génération.
//   • Combien de questions sont posées à l'apprenant (tirage au hasard) et
//     combien d'entre elles sont à réponses multiples.
// Additif : autonome (stockage via widgetGet/widgetSet, comme pdf_access.php).
// ============================================================

if (!function_exists('quizCfgGen')) {
    /** Nombre total de questions générées par l'IA (la banque). */
    function quizCfgGen($db)
    {
        $n = (int) (function_exists('widgetGet') ? widgetGet($db, 'quiz_gen_total', '25') : 25);
        return max(5, min(100, $n ?: 25));
    }

    /** Part (%) de questions à réponses MULTIPLES à la génération. */
    function quizCfgGenPctMultiple($db)
    {
        $p = (int) (function_exists('widgetGet') ? widgetGet($db, 'quiz_gen_pct_multiple', '75') : 75);
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
        $n = (int) (function_exists('widgetGet') ? widgetGet($db, 'quiz_asked_total', '10') : 10);
        return max(1, min(100, $n ?: 10));
    }

    /** Parmi les questions posées, combien à réponses MULTIPLES. */
    function quizCfgAskedMultiple($db)
    {
        $asked = quizCfgAsked($db);
        $raw = function_exists('widgetGet') ? widgetGet($db, 'quiz_asked_multiple', '') : '';
        if (trim((string) $raw) === '') {
            return (int) round($asked * 0.7); // défaut historique : 7 multiples sur 10
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

        $gen = max(5, min(100, (int) ($_POST['quiz_gen_total'] ?? 25)));
        $pct = max(0, min(100, (int) ($_POST['quiz_gen_pct_multiple'] ?? 75)));
        $asked = max(1, min($gen, (int) ($_POST['quiz_asked_total'] ?? 10)));
        $askedMul = max(0, min($asked, (int) ($_POST['quiz_asked_multiple'] ?? 7)));

        widgetSet($db, 'quiz_gen_total', (string) $gen);
        widgetSet($db, 'quiz_gen_pct_multiple', (string) $pct);
        widgetSet($db, 'quiz_asked_total', (string) $asked);
        widgetSet($db, 'quiz_asked_multiple', (string) $askedMul);

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
        ?>
        <div class="pref-block">
            <h3 style="margin:0 0 6px; color:#244230;">📝 Quiz — génération &amp; tirage</h3>
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
                <div class="muted" style="font-size:.85rem; margin-top:6px;">L'apprenant répond à <strong><?= (int) $asked ?></strong> questions : <strong><?= (int) $askMul ?></strong> multiples + <strong><?= (int) $askSin ?></strong> uniques. Si la banque manque d'un type, on complète avec l'autre.</div>

                <div style="margin-top:16px;"><button type="submit" class="btn btn-primary">Enregistrer</button></div>
            </form>
        </div>
        <?php
    }
}
