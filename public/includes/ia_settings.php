<?php
// ============================================================
// ia_settings.php — réglage du modèle d'IA (préférences admin).
//   - iaModelCatalog()        : liste des modèles disponibles + prix indicatif
//   - iaSelectedModel($db)     : modèle choisi (défaut : Sonnet 5)
//   - iaSettingsHandlePost($db): traite l'enregistrement du choix (POST)
//   - iaSettingsCard($db)      : affiche la carte de réglage dans les Préférences
// Ajout NON destructif : autonome, ne modifie aucun code existant.
// ============================================================

/**
 * Modèles proposés à l'admin. Prix approximatif PAR CONTENU
 * (document d'environ 10 pages : lecture PDF + uniformisation + quiz + traduction NL).
 * 'reco' = recommandé par défaut.
 */
function iaModelCatalog()
{
    return [
        'claude-sonnet-5' => [
            'label' => 'Claude Sonnet 5',
            'price' => '~0,10 €',
            'note'  => 'Recommandé — bon équilibre qualité / prix',
            'reco'  => true,
        ],
        'claude-haiku-4-5' => [
            'label' => 'Claude Haiku 4.5',
            'price' => '~0,05 €',
            'note'  => 'Le moins cher — qualité un peu en dessous',
            'reco'  => false,
        ],
        'claude-opus-4-8' => [
            'label' => 'Claude Opus 4.8',
            'price' => '~0,25 €',
            'note'  => 'Qualité maximale — plus cher',
            'reco'  => false,
        ],
    ];
}

/** Identifiant du modèle actuellement choisi (défaut : Sonnet 5). */
function iaSelectedModel($db)
{
    $cur = widgetGet($db, 'ia_model', 'claude-sonnet-5');
    $cat = iaModelCatalog();
    return isset($cat[$cur]) ? $cur : 'claude-sonnet-5';
}

/**
 * Traite l'enregistrement du choix de modèle. À appeler tôt (avant tout affichage),
 * car il redirige. N'agit que sur son action dédiée -> n'interfère avec rien d'autre.
 */
function iaSettingsHandlePost($db)
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }
    if (($_POST['action'] ?? '') !== 'set_ia_model') {
        return;
    }
    requireValidCSRF();
    $model = (string) ($_POST['ia_model'] ?? '');
    if (array_key_exists($model, iaModelCatalog())) {
        widgetSet($db, 'ia_model', $model);
        $_SESSION['module_flash'] = 'Modèle IA enregistré : ' . iaModelCatalog()[$model]['label'];
    }
    header('Location: parametres.php#prefs');
    exit();
}

/** Affiche la carte de réglage IA (dans l'onglet Préférences → Paramètres administrateur). */
function iaSettingsCard($db)
{
    $cur = iaSelectedModel($db);
    $cat = iaModelCatalog();
    ?>
    <div style="border-top:1px solid #eee; padding-top:14px; margin-top:16px;">
        <h3 style="margin:0 0 6px; color:#244230;">🤖 Intelligence artificielle</h3>
        <p class="muted">Modèle utilisé pour <strong>lire les PDF</strong>, uniformiser le contenu, traduire en néerlandais et générer les quiz. Prix indicatif par contenu (document d'environ 10 pages).</p>

        <form method="POST" action="parametres.php#prefs" style="display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end;">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="set_ia_model">
            <div style="flex:1; min-width:260px;">
                <label style="display:block; font-weight:700; color:#244230; font-size:0.85rem;">Modèle d'IA</label>
                <select name="ia_model" style="width:100%; box-sizing:border-box; padding:9px 10px; border:1px solid #ccc; border-radius:8px;">
                    <?php foreach ($cat as $id => $info): ?>
                        <option value="<?= htmlspecialchars($id) ?>" <?= $id === $cur ? 'selected' : '' ?>>
                            <?= htmlspecialchars($info['label']) ?> — <?= htmlspecialchars($info['price']) ?>/contenu<?= $info['reco'] ? ' (recommandé)' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-primary">Enregistrer</button>
        </form>

        <div style="margin-top:12px; display:flex; flex-direction:column; gap:5px;">
            <?php foreach ($cat as $id => $info): ?>
                <div style="font-size:.86rem; <?= $id === $cur ? 'font-weight:700; color:#2d5a37;' : 'color:#7a8a80;' ?>">
                    <?= $id === $cur ? '✓ ' : '• ' ?><?= htmlspecialchars($info['label']) ?> — <strong><?= htmlspecialchars($info['price']) ?></strong> — <?= htmlspecialchars($info['note']) ?>
                </div>
            <?php endforeach; ?>
        </div>

        <p class="muted" style="font-size:.8rem; margin-top:10px;">
            Prix approximatifs (varient selon la taille du PDF). La transcription vidéo (Whisper) est facturée à part,
            ~0,05 € / 10 min, et seulement si aucun sous-titre <code>.srt</code> n'est fourni.
        </p>
    </div>
    <?php
}
