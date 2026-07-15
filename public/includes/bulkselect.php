<?php
// ============================================================
// bulkselect.php — SÉLECTION MULTIPLE réutilisable + corbeille flottante.
//
//   Sur une liste, on n'affiche PAS les cases par défaut : un bouton « Sélectionner »
//   les fait apparaître. On coche (clic, ou Maj+clic pour tout un intervalle), et une
//   CORBEILLE RONDE flottante — qui suit le défilement — apparaît dès qu'une ligne est
//   cochée, avec le nombre. Plus besoin de remonter en haut de page pour supprimer.
//
//   CONVENTION (par groupe, identifié par un nom) :
//     • Bouton   : <button data-bulk-toggle="users" data-bulk-entity="user"
//                          data-bulk-label="utilisateur">Sélectionner</button>
//     • Cases    : <input type="checkbox" class="bulk-cb" data-bulk="users" value="ID">
//                  (masquées tant que le mode sélection n'est pas actif)
//     • Le groupe partage le meme data-bulk="users".
//
//   La suppression POSTe vers bulk_action.php (entity = data-bulk-entity), avec mot de
//   passe admin demandé dans une petite fenêtre. bulkAssets() s'émet une seule fois.
// ============================================================

if (!function_exists('bulkAssets')) {
    function bulkAssets()
    {
        static $done = false;
        if ($done) { return ''; }
        $done = true;
        $csrf = function_exists('getCSRFToken') ? getCSRFToken() : '';
        $ret  = htmlspecialchars($_SERVER['REQUEST_URI'] ?? 'parametres.php', ENT_QUOTES);
        ob_start();
        ?>
        <style>
        .bulk-cb { display:none; }
        .bulk-on .bulk-cb { display:inline-block; }
        .bulk-col { display:none; }
        .bulk-on .bulk-col { display:table-cell; }
        tr.bulk-sel, .bulk-sel { background:#eef7f0 !important; }
        .bulk-toggle.on { background:#2d5a37 !important; color:#fff !important; }

        /* Corbeille ronde flottante : suit le defilement, apparait des qu'on selectionne. */
        #famiTrash { position:fixed; right:22px; bottom:22px; z-index:9000; display:none;
            width:60px; height:60px; border:none; border-radius:50%; cursor:pointer;
            background:#c0392b; color:#fff; box-shadow:0 8px 22px rgba(192,57,66,.45);
            font-size:1.5rem; transition:transform .12s; }
        #famiTrash:hover { transform:scale(1.08); }
        #famiTrash .n { position:absolute; top:-6px; right:-6px; background:#fff; color:#c0392b;
            border-radius:999px; min-width:24px; height:24px; font-size:.8rem; font-weight:800;
            display:flex; align-items:center; justify-content:center; padding:0 5px; box-shadow:0 0 0 2px #c0392b; }
        #famiTrash.show { display:block; animation:trashPop .18s ease-out; }
        @keyframes trashPop { from { transform:scale(.6); opacity:0; } to { transform:scale(1); opacity:1; } }

        .bulk-mask { position:fixed; inset:0; z-index:9500; background:rgba(0,0,0,.5); display:none; align-items:center; justify-content:center; padding:20px; }
        .bulk-box { background:#fff; border-radius:16px; padding:24px; max-width:400px; width:100%; text-align:center; box-shadow:0 20px 50px rgba(0,0,0,.3); }
        .bulk-box h3 { margin:6px 0; color:#c0392b; }
        .bulk-box input[type=password] { width:100%; box-sizing:border-box; padding:10px; border:1px solid #ccc; border-radius:8px; margin:10px 0; }
        .bulk-box .row { display:flex; gap:10px; justify-content:center; }
        .bulk-box .btn { border:none; border-radius:10px; padding:11px 20px; font-weight:700; cursor:pointer; }
        </style>

        <button type="button" id="famiTrash" onclick="famiBulkAsk()" title="<?= t('Supprimer la sélection', 'Selectie verwijderen') ?>">🗑️<span class="n" id="famiTrashN">0</span></button>

        <div class="bulk-mask" id="famiBulkModal">
            <div class="bulk-box">
                <div style="font-size:2rem;">🗑️</div>
                <h3 id="famiBulkTitle"><?= t('Supprimer ?', 'Verwijderen?') ?></h3>
                <p class="muted" id="famiBulkText"></p>
                <input type="password" id="famiBulkPass" placeholder="<?= t('Mot de passe admin', 'Adminwachtwoord') ?>" autocomplete="off">
                <div class="row">
                    <button type="button" class="btn" style="background:#e9ecef; color:#333;" onclick="document.getElementById('famiBulkModal').style.display='none';"><?= t('Annuler', 'Annuleren') ?></button>
                    <button type="button" class="btn" style="background:#c0392b; color:#fff;" onclick="famiBulkGo()"><?= t('Supprimer', 'Verwijderen') ?></button>
                </div>
            </div>
        </div>

        <form method="POST" action="bulk_action.php" id="famiBulkForm" style="display:none;">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
            <input type="hidden" name="action" value="bulk_delete">
            <input type="hidden" name="entity" id="famiBulkEntity" value="">
            <input type="hidden" name="return" value="<?= $ret ?>">
            <input type="hidden" name="admin_password" id="famiBulkPassHidden" value="">
            <div id="famiBulkIds"></div>
        </form>

        <script>
        (function () {
            var active = null;      // nom du groupe en cours (data-bulk)
            var lastIdx = {};       // derniere case cliquee par groupe (Maj+clic)

            function boxes(group) {
                return Array.prototype.slice.call(document.querySelectorAll('.bulk-cb[data-bulk="' + group + '"]'));
            }
            function checkedBoxes() {
                return Array.prototype.slice.call(document.querySelectorAll('.bulk-cb:checked'));
            }
            function refresh() {
                var c = checkedBoxes();
                var t = document.getElementById('famiTrash');
                document.getElementById('famiTrashN').textContent = c.length;
                t.classList.toggle('show', c.length > 0);
                document.querySelectorAll('.bulk-cb').forEach(function (b) {
                    var row = b.closest('tr') || b.closest('[data-bulk-row]') || b.parentElement;
                    if (row) { row.classList.toggle('bulk-sel', b.checked); }
                });
                active = c.length ? c[0].getAttribute('data-bulk') : active;
            }

            // Bouton « Sélectionner » : montre/masque les cases de son groupe.
            document.addEventListener('click', function (e) {
                var t = e.target.closest ? e.target.closest('[data-bulk-toggle]') : null;
                if (!t) { return; }
                var group = t.getAttribute('data-bulk-toggle');
                var cont = t.closest('.bulk-scope') || t.closest('.tab-content') || document.body;
                var on = !cont.classList.contains('bulk-on');
                cont.classList.toggle('bulk-on', on);
                t.classList.toggle('on', on);
                if (!on) { boxes(group).forEach(function (b) { b.checked = false; }); refresh(); }
            });

            // « Tout selectionner » du groupe.
            document.addEventListener('change', function (e) {
                var a = e.target;
                if (!a.classList || !a.classList.contains('bulk-all')) { return; }
                boxes(a.getAttribute('data-bulk')).forEach(function (b) { b.checked = a.checked; });
                refresh();
            });

            // Cases : clic simple + Maj+clic (intervalle).
            document.addEventListener('click', function (e) {
                var b = e.target;
                if (!b.classList || !b.classList.contains('bulk-cb')) { return; }
                var group = b.getAttribute('data-bulk');
                var list = boxes(group);
                var idx = list.indexOf(b);
                if (e.shiftKey && lastIdx[group] != null && lastIdx[group] !== idx) {
                    var a = Math.min(lastIdx[group], idx), z = Math.max(lastIdx[group], idx);
                    for (var k = a; k <= z; k++) { list[k].checked = b.checked; }
                }
                lastIdx[group] = idx;
                refresh();
            });

            window.famiBulkAsk = function () {
                var c = checkedBoxes();
                if (!c.length) { return; }
                var group = c[0].getAttribute('data-bulk');
                var toggle = document.querySelector('[data-bulk-toggle="' + group + '"]');
                window._bulkEntity = toggle ? (toggle.getAttribute('data-bulk-entity') || '') : '';
                var lbl = toggle ? (toggle.getAttribute('data-bulk-label') || 'élément') : 'élément';
                document.getElementById('famiBulkText').textContent =
                    'Supprimer ' + c.length + ' ' + lbl + (c.length > 1 ? 's' : '') + ' ? Cette action est définitive.';
                document.getElementById('famiBulkPass').value = '';
                document.getElementById('famiBulkModal').style.display = 'flex';
                setTimeout(function () { document.getElementById('famiBulkPass').focus(); }, 50);
            };

            window.famiBulkGo = function () {
                var c = checkedBoxes();
                if (!c.length) { return; }
                document.getElementById('famiBulkEntity').value = window._bulkEntity || '';
                document.getElementById('famiBulkPassHidden').value = document.getElementById('famiBulkPass').value;
                var ids = document.getElementById('famiBulkIds');
                ids.innerHTML = '';
                c.forEach(function (b) {
                    var h = document.createElement('input');
                    h.type = 'hidden'; h.name = 'ids[]'; h.value = b.value;
                    ids.appendChild(h);
                });
                document.getElementById('famiBulkForm').submit();
            };
        }());
        </script>
        <?php
        return ob_get_clean();
    }
}
