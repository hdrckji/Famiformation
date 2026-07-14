<?php
// ============================================================
// quiz_view.php — affiche le formulaire de quiz d'évaluation.
//   renderQuizForm($quiz, $moduleId) : questions QCM (radio = 1 réponse,
//   cases = plusieurs). Correction faite côté serveur par quiz_check.php.
// Additif : autonome.
// ============================================================

if (!function_exists('quizPickRandom')) {
    /**
     * TIRAGE ALÉATOIRE des questions posées à l'apprenant.
     * La banque contient jusqu'à 75 questions, mais on n'en pose que 10 :
     * 7 à réponses MULTIPLES + 3 à réponse UNIQUE, tirées au hasard.
     * Chaque passage est donc différent (et on ne peut pas apprendre le quiz par cœur).
     *
     * @return int[] indices (dans la banque) des questions retenues, dans un ordre aléatoire
     */
    function quizPickRandom(array $qs, $nMul = 7, $nSin = 3)
    {
        $mul = [];
        $sin = [];
        foreach ($qs as $i => $q) {
            if ((($q['type'] ?? 'single') === 'multiple')) { $mul[] = $i; } else { $sin[] = $i; }
        }
        shuffle($mul);
        shuffle($sin);
        $pickM = array_slice($mul, 0, max(0, (int) $nMul));
        $pickS = array_slice($sin, 0, max(0, (int) $nSin));

        // Si la banque manque d'un type, on complète avec l'autre pour garder le total de 10.
        $need = ((int) $nMul + (int) $nSin) - count($pickM) - count($pickS);
        if ($need > 0) {
            $rest = array_merge(array_slice($mul, count($pickM)), array_slice($sin, count($pickS)));
            shuffle($rest);
            $pickM = array_merge($pickM, array_slice($rest, 0, $need));
        }

        $sel = array_merge($pickM, $pickS);
        shuffle($sel); // ordre d'affichage aléatoire aussi
        return array_values($sel);
    }
}

if (!function_exists('renderQuizForm')) {
    /** @param int[]|null $sel indices des questions à poser (null = toutes) */
    function renderQuizForm($quiz, $moduleId, $sel = null)
    {
        $all = (isset($quiz['questions']) && is_array($quiz['questions'])) ? $quiz['questions'] : [];
        if (empty($all)) { return; }

        // On ne pose que les questions tirées au sort ; les indices restent ceux de la banque
        // (quiz_check.php corrige sur ces indices — les bonnes réponses ne sortent jamais du serveur).
        $qs = [];
        if (is_array($sel) && !empty($sel)) {
            foreach ($sel as $i) {
                $i = (int) $i;
                if (isset($all[$i])) { $qs[$i] = $all[$i]; }
            }
        } else {
            $qs = $all;
        }
        if (empty($qs)) { return; }
        $n = count($qs);
        $num = 0;
        ?>
        <style>
        .qz-wrap { width:92%; max-width:1040px; margin:6px auto 44px; background:#fff; border-radius:16px; box-shadow:0 12px 34px rgba(0,0,0,.14); padding:30px clamp(18px,5vw,54px); }
        .qz-title { color:#2d5a37; margin:0 0 4px; font-size:1.6rem; }
        .qz-sub { color:#7a8a80; font-weight:600; font-size:1rem; }
        .qz-intro { color:#5a6b60; margin:0 0 18px; }
        .qz-q { border-top:1px solid #eef3f0; padding:18px 0 6px; }
        .qz-qh { font-weight:800; color:#243b2e; margin-bottom:10px; }
        /* Le TYPE de question (unique / multiple) ne doit PAS se deviner : sinon l'apprenant
           sait d'avance combien de reponses cocher. Radios et cases sont donc STRICTEMENT
           identiques a l'oeil (meme puce ronde) ; seule la mecanique differe. */
        .qz-opt input[type=radio], .qz-opt input[type=checkbox] {
            appearance:none; -webkit-appearance:none; width:19px; height:19px; flex:none; margin-top:2px;
            border:2px solid #b9cdbf; border-radius:50%; background:#fff; cursor:pointer; transition:all .12s;
        }
        .qz-opt input:hover { border-color:#2d5a37; }
        .qz-opt input:checked { border-color:#2d5a37; background:radial-gradient(circle, #2d5a37 0 45%, #fff 55% 100%); }
        .qz-opt input:focus-visible { outline:2px solid #2d5a37; outline-offset:2px; }
        .qz-opt { display:flex; align-items:flex-start; gap:10px; padding:7px 10px; border-radius:9px; cursor:pointer; }
        .qz-opt:hover { background:#f3f8f4; }
        .qz-opt input { margin-top:3px; }
        .qz-actions { margin-top:22px; text-align:center; }
        .qz-btn { border:none; background:#2d5a37; color:#fff; border-radius:11px; padding:13px 28px; font-weight:800; font-size:1rem; cursor:pointer; }
        .qz-btn:hover { background:#244a2d; }
        </style>
        <div class="qz-wrap">
            <h2 class="qz-title">📝 <?= t('Quiz d\'évaluation', 'Evaluatiequiz') ?> <span class="qz-sub">(<?= (int) $n ?> <?= t($n > 1 ? 'questions' : 'question', $n > 1 ? 'vragen' : 'vraag') ?>)</span></h2>
            <p class="qz-intro"><?= t('Réponds à toutes les questions puis valide. Attention : certaines questions attendent plusieurs réponses — à toi de juger. La correction s\'affichera avec ton score.', 'Beantwoord alle vragen en bevestig daarna. Let op: bij sommige vragen zijn meerdere antwoorden juist — dat moet je zelf inschatten. De verbetering verschijnt met je score.') ?></p>
            <form method="POST" action="quiz_check.php" class="qz-form">
                <?= csrfField() ?>
                <input type="hidden" name="module_id" value="<?= (int) $moduleId ?>">
                <?php foreach ($qs as $i => $q): $multi = (($q['type'] ?? 'single') === 'multiple'); $num++; ?>
                    <input type="hidden" name="sel[]" value="<?= (int) $i ?>">
                    <div class="qz-q">
                        <div class="qz-qh"><?= (int) $num ?>. <?= htmlspecialchars((string) $q['q']) ?></div>
                        <?php foreach (($q['options'] ?? []) as $j => $opt): ?>
                            <label class="qz-opt">
                                <input type="<?= $multi ? 'checkbox' : 'radio' ?>" name="a[<?= (int) $i ?>]<?= $multi ? '[]' : '' ?>" value="<?= (int) $j ?>">
                                <span><?= htmlspecialchars((string) $opt) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
                <div class="qz-actions"><button type="submit" class="qz-btn"><?= t('Valider mes réponses', 'Mijn antwoorden bevestigen') ?></button></div>
            </form>
        </div>
        <?php
    }
}
