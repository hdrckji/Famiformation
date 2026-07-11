<?php
// ============================================================
// perso_ui.php — UI de personnalisation (préférences admin).
//   - persoSwitch()            : joli interrupteur binaire ON/OFF (poste toggle_perso)
//   - renderEventThemeCards()  : une carte par événement avec 3 réglages
//        (🎨 Thème / ✨ Effets / 🎬 Animation), chacun : interrupteur + aperçu EN PLACE.
// Ajout NON destructif : autonome.
// ============================================================

if (!function_exists('persoSwitch')) {
    /**
     * Interrupteur binaire ON/OFF stylé. Poste sur le handler existant `toggle_perso`.
     * @param string $key   clé widget (ex: theme_noel_on)
     * @param bool   $isOn  état courant
     * @param string $confirmOnDisable  message de confirmation à la désactivation (optionnel)
     */
    function persoSwitch($key, $isOn, $confirmOnDisable = '')
    {
        $track = $isOn ? '#2d5a37' : '#c3ccc6';
        $knob  = $isOn ? 'right:3px;' : 'left:3px;';
        $lblPos = $isOn ? 'left:11px;' : 'right:10px;';
        $lbl = $isOn ? 'ON' : 'OFF';
        $title = $isOn ? 'Activé — cliquer pour désactiver' : 'Désactivé — cliquer pour activer';
        $onsub = ($isOn && $confirmOnDisable !== '')
            ? ' onsubmit="return confirm(' . htmlspecialchars(json_encode($confirmOnDisable, JSON_UNESCAPED_UNICODE), ENT_QUOTES) . ')"'
            : '';
        echo '<form method="POST" action="parametres.php#prefs" style="display:inline-block; margin:0; line-height:0;"' . $onsub . '>'
            . csrfField()
            . '<input type="hidden" name="toggle_perso" value="1">'
            . '<input type="hidden" name="perso_key" value="' . htmlspecialchars($key) . '">'
            . '<button type="submit" title="' . htmlspecialchars($title, ENT_QUOTES) . '" aria-label="' . $lbl . '" '
            . 'style="position:relative; display:inline-block; width:64px; height:30px; border:none; border-radius:999px; background:' . $track . '; cursor:pointer; vertical-align:middle; transition:background .15s;">'
            . '<span style="position:absolute; top:0; bottom:0; ' . $lblPos . ' display:flex; align-items:center; font-size:.66rem; font-weight:800; color:#fff; letter-spacing:.5px;">' . $lbl . '</span>'
            . '<span style="position:absolute; top:3px; ' . $knob . ' width:24px; height:24px; border-radius:50%; background:#fff; box-shadow:0 1px 3px rgba(0,0,0,.35);"></span>'
            . '</button>'
            . '</form>';
    }
}

if (!function_exists('_persoEventDateLabel')) {
    /** Libellé de période lisible pour un thème du catalogue. */
    function _persoEventDateLabel($t)
    {
        $mois = [1 => 'jan', 2 => 'fév', 3 => 'mars', 4 => 'avr', 5 => 'mai', 6 => 'juin',
                 7 => 'juil', 8 => 'août', 9 => 'sept', 10 => 'oct', 11 => 'nov', 12 => 'déc'];
        if (isset($t['easter'])) {
            return 'autour de Pâques';
        }
        if (isset($t['md_range'])) {
            list($a, $b) = $t['md_range'];
            $fa = explode('-', $a);
            $fb = explode('-', $b);
            $la = (int) $fa[1] . ' ' . ($mois[(int) $fa[0]] ?? '');
            $lb = (int) $fb[1] . ' ' . ($mois[(int) $fb[0]] ?? '');
            return $a === $b ? $la : ($la . ' – ' . $lb);
        }
        return '';
    }
}

if (!function_exists('renderEventThemeCards')) {
    /** Cartes par événement : 🎨 Thème / ✨ Effets / 🎬 Animation, chacun interrupteur + aperçu en place. */
    function renderEventThemeCards($db)
    {
        // Construction de la liste des événements (anniversaire + catalogue).
        $events = [];
        $bd = function_exists('birthdayTheme') ? birthdayTheme() : [];
        $events['anniversaire'] = [
            'nom'       => is_array($bd['nom'] ?? null) ? $bd['nom'][0] : ($bd['nom'] ?? '🎂 Anniversaire'),
            'accent'    => $bd['accent'] ?? '#e0245e',
            'particles' => $bd['particles'] ?? ['🎈', '🎉', '🎂'],
            'page_bg'   => $bd['page_bg'] ?? 'radial-gradient(circle at 50% 30%, #e0245e, #2a0512 80%)',
            'date'      => 'le jour de l’anniversaire',
        ];
        if (function_exists('siteThemeCatalog')) {
            foreach (siteThemeCatalog() as $k => $t) {
                $events[$k] = [
                    'nom'       => is_array($t['nom']) ? $t['nom'][0] : $t['nom'],
                    'accent'    => $t['accent'] ?? '#2d5a37',
                    'particles' => $t['particles'] ?? ['✨'],
                    'page_bg'   => $t['page_bg'] ?? '',
                    'date'      => _persoEventDateLabel($t),
                ];
            }
        }
        ?>
        <style>
        @keyframes evFall { to { transform: translateY(115vh) rotate(360deg); } }
        @keyframes evPop  { from { transform: translateX(-50%) scale(.6); opacity: 0; } to { transform: translateX(-50%) scale(1); opacity: 1; } }
        .ev-card { border:1px solid #e0e8e2; border-radius:12px; padding:14px 16px; margin-bottom:12px; background:#fff; }
        .ev-head { font-weight:800; color:#244230; font-size:1.05rem; margin-bottom:12px; }
        .ev-row { display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; padding:7px 0; border-top:1px solid #f0f4f1; }
        .ev-row:first-of-type { border-top:none; }
        .ev-lbl { color:#33473b; font-weight:600; }
        .ev-ctrl { display:flex; align-items:center; gap:10px; }
        .ev-eye { border:1.5px solid #2d5a37; color:#2d5a37; background:#fff; border-radius:8px; padding:6px 12px; font-weight:700; cursor:pointer; font-size:.85rem; }
        .ev-eye:hover { background:#2d5a37; color:#fff; }
        </style>
        <script>
        (function () {
            function card(btn) { return btn.closest('.ev-card'); }
            function parts(c) { try { return JSON.parse(c.getAttribute('data-particles')) || ['✨']; } catch (e) { return ['✨']; } }
            function spawn(container, list, count) {
                for (var i = 0; i < count; i++) {
                    var s = document.createElement('span');
                    s.textContent = list[i % list.length];
                    s.style.cssText = 'position:absolute; top:-10%; left:' + (Math.random() * 100) + '%; font-size:' + (18 + Math.random() * 22) + 'px; opacity:.95; animation:evFall ' + (2.6 + Math.random() * 2.6) + 's linear ' + (Math.random() * 1.4) + 's forwards;';
                    container.appendChild(s);
                }
            }
            function banner(text, accent) {
                var b = document.createElement('div');
                b.textContent = text;
                b.style.cssText = 'position:absolute; top:14%; left:50%; transform:translateX(-50%); background:' + accent + '; color:#fff; padding:10px 22px; border-radius:999px; font-weight:800; font-size:1.05rem; box-shadow:0 10px 30px rgba(0,0,0,.25); animation:evPop .5s ease; z-index:2;';
                return b;
            }
            // ✨ EFFETS : les emojis tombent par-dessus la page (page reste visible & utilisable).
            window.famiPrevFx = function (btn) {
                var c = card(btn);
                var ov = document.createElement('div');
                ov.style.cssText = 'position:fixed; inset:0; top:0;left:0;right:0;bottom:0; z-index:99999; overflow:hidden; pointer-events:none;';
                document.body.appendChild(ov);
                spawn(ov, parts(c), 34);
                setTimeout(function () { ov.style.transition = 'opacity .6s'; ov.style.opacity = '0'; setTimeout(function () { ov.remove(); }, 650); }, 4200);
            };
            // 🎬 ANIMATION : splash plein écran (comme la 1ère connexion).
            window.famiPrevIntro = function (btn) {
                var c = card(btn), accent = c.getAttribute('data-accent') || '#2d5a37', nom = c.getAttribute('data-nom') || '';
                var p = parts(c);
                var ov = document.createElement('div');
                ov.style.cssText = 'position:fixed; inset:0; top:0;left:0;right:0;bottom:0; z-index:100000; overflow:hidden; cursor:pointer; display:flex; align-items:center; justify-content:center; background:radial-gradient(circle at 50% 30%, ' + accent + ', #0e120e 80%); transition:opacity .6s;';
                ov.onclick = function () { ov.style.opacity = '0'; setTimeout(function () { ov.remove(); }, 600); };
                spawn(ov, p, 30);
                var card2 = document.createElement('div');
                card2.style.cssText = 'position:relative; z-index:2; text-align:center; color:#fff; animation:evPop .6s ease;';
                card2.innerHTML = '<div style="font-size:4rem;">' + p[0] + '</div>'
                    + '<div style="font-size:.95rem; letter-spacing:1.5px; text-transform:uppercase; opacity:.85;">1ère connexion</div>'
                    + '<div style="font-size:2.2rem; font-weight:800; margin-top:6px; text-shadow:0 4px 20px rgba(0,0,0,.4);">' + nom + '</div>'
                    + '<div style="margin-top:18px; font-size:.75rem; letter-spacing:2px; text-transform:uppercase; opacity:.7;">clique pour fermer</div>';
                ov.appendChild(card2);
                document.body.appendChild(ov);
                setTimeout(function () { if (ov.parentNode) { ov.style.opacity = '0'; setTimeout(function () { ov.remove(); }, 600); } }, 4200);
            };
            // 🎨 THÈME : applique temporairement le fond du template à la page.
            window.famiPrevTemplate = function (btn) {
                var c = card(btn), bg = c.getAttribute('data-pagebg') || '', accent = c.getAttribute('data-accent') || '#2d5a37', nom = c.getAttribute('data-nom') || '';
                if (!bg) { bg = 'linear-gradient(160deg, ' + accent + '22, ' + accent + '55)'; }
                var old = document.body.style.background;
                document.body.style.transition = 'background .5s';
                document.body.style.background = bg;
                var toast = document.createElement('div');
                toast.textContent = '🎨 Aperçu du thème : ' + nom;
                toast.style.cssText = 'position:fixed; top:18px; left:50%; transform:translateX(-50%); z-index:100000; background:' + accent + '; color:#fff; padding:10px 20px; border-radius:999px; font-weight:800; box-shadow:0 8px 24px rgba(0,0,0,.3);';
                document.body.appendChild(toast);
                setTimeout(function () { document.body.style.background = old; toast.remove(); }, 4200);
            };
        })();
        </script>
        <?php foreach ($events as $k => $ev):
            $on    = (widgetGet($db, 'theme_' . $k . '_on', '1') === '1');
            $fx    = (widgetGet($db, 'theme_' . $k . '_anim', '1') === '1');
            $intro = (widgetGet($db, 'theme_' . $k . '_intro', '1') === '1');
            $fxEmojis = implode(' ', array_slice($ev['particles'], 0, 3));
        ?>
            <div class="ev-card"
                 data-key="<?= htmlspecialchars($k) ?>"
                 data-nom="<?= htmlspecialchars($ev['nom'], ENT_QUOTES) ?>"
                 data-accent="<?= htmlspecialchars($ev['accent'], ENT_QUOTES) ?>"
                 data-pagebg="<?= htmlspecialchars($ev['page_bg'], ENT_QUOTES) ?>"
                 data-particles="<?= htmlspecialchars(json_encode($ev['particles'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES) ?>">
                <div class="ev-head"><?= htmlspecialchars($ev['nom']) ?> <span class="muted" style="font-weight:600; font-size:.82rem;">(<?= htmlspecialchars($ev['date']) ?>)</span></div>

                <div class="ev-row">
                    <div class="ev-lbl">🎨 Thème <span class="muted" style="font-weight:400;">(fond + couleurs)</span></div>
                    <div class="ev-ctrl">
                        <button type="button" class="ev-eye" onclick="famiPrevTemplate(this)">👁 Aperçu</button>
                        <?php persoSwitch('theme_' . $k . '_on', $on); ?>
                    </div>
                </div>

                <div class="ev-row">
                    <div class="ev-lbl">✨ Effets <span class="muted" style="font-weight:400;">(<?= htmlspecialchars($fxEmojis) ?> qui tombent)</span></div>
                    <div class="ev-ctrl">
                        <button type="button" class="ev-eye" onclick="famiPrevFx(this)">👁 Aperçu</button>
                        <?php persoSwitch('theme_' . $k . '_anim', $fx); ?>
                    </div>
                </div>

                <div class="ev-row">
                    <div class="ev-lbl">🎬 Animation <span class="muted" style="font-weight:400;">(1ère connexion)</span></div>
                    <div class="ev-ctrl">
                        <button type="button" class="ev-eye" onclick="famiPrevIntro(this)">👁 Aperçu</button>
                        <?php persoSwitch('theme_' . $k . '_intro', $intro); ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
        <?php
    }
}
