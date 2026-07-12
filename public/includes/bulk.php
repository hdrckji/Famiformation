<?php
// ============================================================
// bulk.php — sélection multiple + suppression groupée RÉUTILISABLE.
//
// Recette pour rendre une liste supprimable en lot :
//   1) table class="bulk-table" data-entity="module"   (entity : module|profile|phrase)
//   2) En-tete : appeler bulkAllTh() comme 1re colonne du <tr> de <thead>
//   3) Chaque ligne : appeler bulkCheck($row['id']) comme 1re colonne du <tr>
//      (pense a +1 sur les colspan des lignes « vide »)
//   4) Au-dessus de la table : appeler bulkBar('module')
//   5) Une seule fois par page : appeler bulkModalAndJs()
// La confirmation (mot de passe admin) et le POST vers bulk_action.php sont geres ici.
// ============================================================

if (!function_exists('bulkAllTh')) {
    function bulkAllTh()
    {
        echo '<th style="text-align:center; width:34px;"><input type="checkbox" class="bulk-all" onclick="bulkToggleAll(this)" title="Tout sélectionner"></th>';
    }
}

if (!function_exists('bulkCheck')) {
    function bulkCheck($id)
    {
        echo '<td style="text-align:center;"><input type="checkbox" class="bulk-check" value="' . (int) $id . '" onchange="bulkRowChanged(this)"></td>';
    }
}

if (!function_exists('bulkBar')) {
    function bulkBar($entity, $label = 'Supprimer la sélection')
    {
        $e = htmlspecialchars((string) $entity, ENT_QUOTES);
        echo '<div class="bulk-bar" style="margin:0 0 10px;">'
            . '<button type="button" class="btn btn-danger bulk-btn" data-entity="' . $e . '" disabled onclick="bulkAsk(\'' . $e . '\')">'
            . '🗑 ' . htmlspecialchars((string) $label) . ' (<span class="bulk-n" data-entity="' . $e . '">0</span>)</button>'
            . '</div>';
    }
}

if (!function_exists('bulkModalAndJs')) {
    function bulkModalAndJs($return = 'parametres.php')
    {
        static $emitted = false;
        if ($emitted) { return; }
        $emitted = true;
        ?>
        <div id="bulkModal" class="bulk-modal">
            <div class="bulk-modal-box">
                <div style="font-size:2.6rem;">🗑️</div>
                <h3 style="color:#c0392b; margin:8px 0 6px;">Supprimer définitivement ?</h3>
                <p class="muted" style="margin:0 0 6px;">Cette action est <strong>irréversible</strong>. <span id="bulkCount"></span></p>
                <form method="POST" action="bulk_action.php">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="bulk_delete">
                    <input type="hidden" name="entity" id="bulkEntity" value="">
                    <input type="hidden" name="return" value="<?= htmlspecialchars($return) ?>">
                    <div id="bulkIds"></div>
                    <label style="display:block; font-weight:700; color:#244230; margin:0 0 4px; text-align:left;">Mot de passe administrateur</label>
                    <input type="password" name="admin_password" required autocomplete="off" placeholder="Mot de passe de verrouillage"
                        style="width:100%; box-sizing:border-box; padding:10px; border:1px solid #ccc; border-radius:8px;">
                    <div style="display:flex; gap:10px; justify-content:flex-end; margin-top:18px;">
                        <button type="button" class="btn btn-light" onclick="bulkClose()">Annuler</button>
                        <button type="submit" class="btn btn-danger">Oui, supprimer définitivement</button>
                    </div>
                </form>
            </div>
        </div>
        <style>
        .bulk-modal { position:fixed; inset:0; z-index:100000; background:rgba(0,0,0,0.55); display:none; align-items:center; justify-content:center; padding:20px; }
        .bulk-modal.open { display:flex; }
        .bulk-modal-box { background:#fff; border-radius:16px; padding:28px; max-width:440px; width:100%; text-align:center; box-shadow:0 20px 60px rgba(0,0,0,0.35); max-height:92vh; overflow:auto; }
        .bulk-bar .bulk-btn:disabled { opacity:.5; cursor:not-allowed; }
        </style>
        <script>
        function bulkUpdate(entity) {
            var table = document.querySelector('.bulk-table[data-entity="' + entity + '"]');
            var n = table ? table.querySelectorAll('.bulk-check:checked').length : 0;
            document.querySelectorAll('.bulk-n[data-entity="' + entity + '"]').forEach(function (x) { x.textContent = n; });
            document.querySelectorAll('.bulk-btn[data-entity="' + entity + '"]').forEach(function (b) { b.disabled = (n === 0); });
        }
        function bulkRowChanged(cb) {
            var t = cb.closest('.bulk-table');
            if (t) { bulkUpdate(t.getAttribute('data-entity')); }
        }
        function bulkToggleAll(cb) {
            var t = cb.closest('.bulk-table');
            if (!t) { return; }
            t.querySelectorAll('tbody tr').forEach(function (tr) {
                if (tr.style.display === 'none') { return; } // seulement les lignes visibles
                var c = tr.querySelector('.bulk-check');
                if (c) { c.checked = cb.checked; }
            });
            bulkUpdate(t.getAttribute('data-entity'));
        }
        function bulkAsk(entity) {
            var t = document.querySelector('.bulk-table[data-entity="' + entity + '"]');
            if (!t) { return; }
            var ids = [];
            t.querySelectorAll('.bulk-check:checked').forEach(function (c) { ids.push(c.value); });
            if (!ids.length) { return; }
            var box = document.getElementById('bulkIds');
            box.innerHTML = '';
            ids.forEach(function (v) {
                var i = document.createElement('input');
                i.type = 'hidden'; i.name = 'ids[]'; i.value = v;
                box.appendChild(i);
            });
            document.getElementById('bulkEntity').value = entity;
            document.getElementById('bulkCount').textContent = ids.length + ' élément' + (ids.length > 1 ? 's' : '') + ' sélectionné' + (ids.length > 1 ? 's' : '') + '.';
            document.getElementById('bulkModal').classList.add('open');
        }
        function bulkClose() {
            document.getElementById('bulkModal').classList.remove('open');
        }
        </script>
        <?php
    }
}
