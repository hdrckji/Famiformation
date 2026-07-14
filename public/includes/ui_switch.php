<?php
// ============================================================
// ui_switch.php — INTERRUPTEUR (switch) réutilisable des paramètres admin.
//   Remplace les cases à cocher « activer / désactiver » : on bascule d'un clic,
//   sans le mot ON ni OFF écrit dans le bouton — la position et la couleur suffisent.
//   C'est une vraie <input type="checkbox"> (donc elle s'envoie normalement dans le
//   formulaire, aucun JS requis) : seule son apparence change.
// ============================================================

if (!function_exists('famiSwitchCss')) {
    /** CSS du switch — écrit une seule fois par page. */
    function famiSwitchCss()
    {
        static $done = false;
        if ($done) { return; }
        $done = true;
        ?>
        <style>
        .fsw { display:inline-flex; align-items:center; gap:12px; cursor:pointer; user-select:none; }
        .fsw > input { position:absolute; opacity:0; width:0; height:0; }
        .fsw .fsw-track {
            position:relative; flex:none; width:52px; height:29px; border-radius:999px;
            background:#cdd6d0; box-shadow:inset 0 1px 3px rgba(0,0,0,.16); transition:background .18s ease;
        }
        .fsw .fsw-track::after {
            content:""; position:absolute; top:3px; left:3px; width:23px; height:23px; border-radius:50%;
            background:#fff; box-shadow:0 2px 5px rgba(0,0,0,.25); transition:transform .18s ease;
        }
        .fsw > input:checked + .fsw-track { background:#3E8E4E; }
        .fsw > input:checked + .fsw-track::after { transform:translateX(23px); }
        .fsw > input:focus-visible + .fsw-track { outline:2px solid #2d5a37; outline-offset:2px; }
        .fsw .fsw-lab { font-weight:700; color:#244230; }
        .fsw .fsw-lab small { display:block; font-weight:400; color:#7a8a80; font-size:.82rem; }
        /* En-tête d'un réglage : titre à gauche, interrupteur tout à droite, MÊME ligne. */
        .pref-head { display:flex; align-items:center; justify-content:space-between; gap:16px;
                     padding-bottom:10px; border-bottom:1px solid #e6efe9; margin-bottom:14px; }
        .pref-head h3 { margin:0 !important; border:none !important; padding:0 !important; }
        .pref-head form { margin:0; line-height:0; }
        /* Bloc entier désactivé (interrupteur maître coupé) : grisé et inopérant. */
        .pref-off { opacity:.45; pointer-events:none; filter:saturate(.4); }
        </style>
        <script>
        // Un interrupteur MAÎTRE (data-master="x") grise en direct le bloc data-body="x".
        // Pas besoin d'enregistrer pour voir l'effet : le retour visuel est immédiat.
        document.addEventListener('change', function (e) {
            var m = e.target.getAttribute && e.target.getAttribute('data-master');
            if (!m) { return; }
            var body = document.querySelector('[data-body="' + m + '"]');
            if (body) { body.classList.toggle('pref-off', !e.target.checked); }
        });
        </script>
        <?php
    }
}

if (!function_exists('famiSwitch')) {
    /**
     * @param string $name  nom du champ (envoyé dans le formulaire)
     * @param bool   $on    état courant
     * @param string $label libellé à droite (facultatif)
     * @param string $hint  précision sous le libellé (facultatif)
     * @param string $attrs attributs HTML supplémentaires (ex. data-master="...")
     */
    function famiSwitch($name, $on, $label = '', $hint = '', $attrs = '')
    {
        famiSwitchCss();
        ?>
        <label class="fsw">
            <input type="checkbox" name="<?= htmlspecialchars($name) ?>" value="1" <?= $on ? 'checked' : '' ?> <?= $attrs ?>>
            <span class="fsw-track"></span>
            <?php if ($label !== ''): ?>
                <span class="fsw-lab"><?= $label ?><?php if ($hint !== ''): ?><small><?= $hint ?></small><?php endif; ?></span>
            <?php endif; ?>
        </label>
        <?php
    }
}

if (!function_exists('famiPrefHead')) {
    /**
     * En-tête d'un réglage admin : le titre, et SUR LA MÊME LIGNE, tout à droite,
     * l'interrupteur qui active/désactive ce réglage. Aucun libellé, aucune ligne en plus.
     *
     * @param string $title  titre affiché (HTML autorisé : emoji, gras…)
     * @param string $action valeur du champ `action` du formulaire (celui du bloc)
     * @param string $name   nom du réglage on/off
     * @param bool   $on     état courant
     * @param string $title2 infobulle (facultatif)
     */
    function famiPrefHead($title, $action, $name, $on, $title2 = '')
    {
        famiSwitchCss();
        ?>
        <div class="pref-head">
            <h3 style="color:#244230;"><?= $title ?></h3>
            <form method="POST" action="parametres.php#prefs"<?= $title2 !== '' ? ' title="' . htmlspecialchars($title2, ENT_QUOTES) . '"' : '' ?>>
                <?= csrfField() ?>
                <input type="hidden" name="action" value="<?= htmlspecialchars($action) ?>">
                <input type="hidden" name="toggle_only" value="1">
                <label class="fsw">
                    <input type="checkbox" name="<?= htmlspecialchars($name) ?>" value="1" <?= $on ? 'checked' : '' ?> onchange="this.form.submit()">
                    <span class="fsw-track"></span>
                </label>
            </form>
        </div>
        <?php
    }
}
