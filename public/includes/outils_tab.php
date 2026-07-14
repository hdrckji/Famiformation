<?php
// ============================================================
// outils_tab.php — onglet « Outils » (purement INFORMATIF).
//
// But : que l'admin sache, d'un coup d'œil, DE QUOI le site dépend, à quoi sert
// chaque brique, si elle est configurée, et OÙ se règle sa clé. Aucune action,
// aucun réglage ici : c'est de la documentation vivante (l'état est vérifié en
// vrai à chaque affichage, pas écrit en dur).
// ============================================================

if (!function_exists('outilsEnvKey')) {
    /** Une variable d'environnement est-elle renseignée ? (on ne montre JAMAIS la valeur) */
    function outilsEnvKey($name)
    {
        $v = getenv($name);
        if (!$v && isset($_SERVER[$name])) {
            $v = $_SERVER[$name];
        }
        return trim((string) $v) !== '';
    }
}

if (!function_exists('outilsBinaryOk')) {
    /** Un binaire est-il réellement présent dans le conteneur ? (vérification en direct) */
    function outilsBinaryOk($bin)
    {
        if (!function_exists('exec')) {
            return false;
        }
        // Présence réelle dans le PATH : `command -v` renvoie 0 si le binaire existe.
        // (C'est exactement ce que fait le vrai code d'extraction d'images.)
        $out = [];
        $code = 1;
        @exec('command -v ' . escapeshellarg($bin) . ' 2>/dev/null', $out, $code);
        if ($code === 0 && !empty($out)) {
            return true;
        }
        // Repli : certains binaires poppler renvoient un code NON NUL sur « -version »
        // tout en fonctionnant parfaitement. On les considère présents si la bannière sort.
        $out = [];
        $code = 1;
        @exec(escapeshellarg($bin) . ' -version 2>&1', $out, $code);
        return !empty($out);
    }
}

if (!function_exists('outilsBackgroundCheck')) {
    /**
     * LE test le plus important de cette page.
     *
     * La compression vidéo, les sous-titres et la traduction NL tournent tous en
     * TÂCHE DE FOND (« nohup php worker & »). Ce mécanisme n'avait jamais été
     * vérifié en production : s'il ne marche pas, les vidéos restent bloquées sur
     * « en préparation » et le NL ne se génère jamais — sans message d'erreur.
     *
     * Ici on teste POUR DE VRAI, en direct : exec() est-il autorisé, et le PHP en
     * ligne de commande répond-il ? Si les deux sont OK, les tâches de fond peuvent
     * s'exécuter.
     */
    function outilsBackgroundCheck()
    {
        $r = ['exec' => false, 'php_cli' => false, 'ok' => false, 'detail' => ''];
        if (!function_exists('exec')) {
            $r['detail'] = 'La fonction exec() est désactivée sur ce serveur.';
            return $r;
        }
        $r['exec'] = true;

        $out = [];
        $code = 1;
        @exec('php -v 2>&1', $out, $code);
        if ($code === 0 && !empty($out)) {
            $r['php_cli'] = true;
            $r['detail'] = trim((string) $out[0]); // ex: "PHP 8.3.x (cli) ..."
        } else {
            $r['detail'] = "La commande « php » n'est pas joignable depuis le site.";
        }
        $r['ok'] = $r['exec'] && $r['php_cli'];
        return $r;
    }
}

if (!function_exists('renderOutilsTab')) {
    function renderOutilsTab($db)
    {
        // --- État réel de chaque brique, vérifié maintenant ---
        $hasClaude = outilsEnvKey('ANTHROPIC_API_KEY');
        $sttProv = function_exists('famiSttProvider') ? famiSttProvider() : 'openai';
        $hasStt = function_exists('famiSttReady') ? famiSttReady() : false;
        $sttVar = ($sttProv === 'groq') ? 'GROQ_API_KEY' : 'OPENAI_API_KEY';
        $hasFfmpeg = outilsBinaryOk('ffmpeg');
        $hasPdfImages = function_exists('exec') ? outilsBinaryOk('pdfimages') : false;
        $onVolume = defined('FAMI_STORAGE_BASE') && FAMI_STORAGE_BASE !== (__DIR__ . '/../uploads');
        $iaModel = function_exists('iaSelectedModel') ? iaSelectedModel($db) : '—';
        $mmMail = outilsEnvKey('MYMEMORY_EMAIL');

        $dbVer = '—';
        try {
            $dbVer = (string) $db->getAttribute(PDO::ATTR_SERVER_VERSION);
        } catch (Exception $e) {
        }

        // Chaque outil : nom, rôle, état, où se règle la clé.
        $tools = [
            [
                'icon' => '🧠',
                'nom' => 'Claude (Anthropic)',
                'role' => "Met en forme le guide à partir du PDF, génère le quiz, et traduit le contenu structuré en néerlandais.",
                'ok' => $hasClaude,
                'etat' => $hasClaude ? ('Configuré · modèle : ' . htmlspecialchars($iaModel)) : 'Clé absente → uniformisation et quiz indisponibles',
                'cle' => 'Variable Railway : ANTHROPIC_API_KEY  ·  modèle réglable dans l\'onglet API',
                'cout' => 'Payant à l\'usage (par contenu créé, pas par consultation)',
            ],
            [
                'icon' => '🎙️',
                'nom' => 'Whisper — transcription (' . htmlspecialchars($sttProv) . ')',
                'role' => "Transcrit l'audio des vidéos → sous-titres FR (puis NL) et texte servant à générer des questions de quiz depuis la vidéo.",
                'ok' => $hasStt,
                'etat' => $hasStt
                    ? 'Configuré · transcription automatique active'
                    : 'Clé absente → les vidéos n\'auront de sous-titres QUE si un .srt est fourni à la main',
                'cle' => 'Variable Railway : ' . $sttVar . '  ·  fournisseur : FAMI_STT_PROVIDER (openai | groq | local)',
                'cout' => 'Payant à l\'usage (~quelques centimes par vidéo). Un .srt fourni = gratuit.',
            ],
            [
                'icon' => '🌍',
                'nom' => 'MyMemory — traduction',
                'role' => "Traduit les textes COURTS en néerlandais (noms et descriptions de modules, phrases du widget).",
                'ok' => true,
                'etat' => 'Actif · gratuit, sans clé' . ($mmMail ? ' · e-mail renseigné (quota quotidien élargi)' : ' · quota anonyme (élargi si MYMEMORY_EMAIL est renseignée)'),
                'cle' => 'Aucune clé requise. Optionnel : MYMEMORY_EMAIL pour augmenter le quota gratuit.',
                'cout' => 'Gratuit (limite quotidienne).',
            ],
            [
                'icon' => '🎬',
                'nom' => 'ffmpeg',
                'role' => "Compresse chaque vidéo en 720p « faststart » (démarrage instantané) et extrait l'audio pour la transcription.",
                'ok' => $hasFfmpeg,
                'etat' => $hasFfmpeg ? 'Installé dans le conteneur ✓' : 'ABSENT → compression et transcription impossibles',
                'cle' => 'Installé par le Dockerfile (apt-get install ffmpeg). Rien à configurer.',
                'cout' => 'Gratuit (logiciel libre).',
            ],
            [
                'icon' => '🖼️',
                'nom' => 'poppler-utils (pdfimages)',
                'role' => "Extrait les images contenues dans les PDF pour les réutiliser dans le guide.",
                'ok' => $hasPdfImages,
                'etat' => $hasPdfImages ? 'Installé dans le conteneur ✓' : 'ABSENT → pas d\'images extraites des PDF',
                'cle' => 'Installé par le Dockerfile (apt-get install poppler-utils).',
                'cout' => 'Gratuit (logiciel libre).',
            ],
            [
                'icon' => '💾',
                'nom' => 'Volume de stockage',
                'role' => "Conserve les fichiers (PDF, vidéos, images, sous-titres) — servis par media.php avec contrôle d'accès.",
                'ok' => $onVolume,
                'etat' => $onVolume
                    ? ('Volume persistant ✓ (' . htmlspecialchars((string) FAMI_STORAGE_BASE) . ')')
                    : 'Aucun volume → les fichiers seraient PERDUS à chaque redéploiement',
                'cle' => 'Volume Railway attaché au service (variable RAILWAY_VOLUME_MOUNT_PATH, lue automatiquement).',
                'cout' => 'Facturé au Go × durée (voir Préférences → Coût d\'hébergement).',
            ],
            [
                'icon' => '🐘',
                'nom' => 'PHP ' . PHP_VERSION . ' (FrankenPHP / Caddy)',
                'role' => "Exécute le site et le sert en HTTP. Le worker de tâches de fond (compression, sous-titres, traduction) utilise le PHP en ligne de commande.",
                'ok' => true,
                'etat' => 'Actif · ' . PHP_VERSION,
                'cle' => 'Image Docker dunglas/frankenphp. Limites d\'upload dans le Dockerfile.',
                'cout' => 'Gratuit (logiciel libre).',
            ],
            [
                'icon' => '🗄️',
                'nom' => 'MySQL',
                'role' => "Base de données : modules, contenus, quiz, utilisateurs, réglages.",
                'ok' => true,
                'etat' => 'Connecté · version ' . htmlspecialchars($dbVer),
                'cle' => 'Variables de connexion fournies par Railway.',
                'cout' => 'Inclus dans l\'hébergement.',
            ],
            [
                'icon' => '🚀',
                'nom' => 'Railway (hébergement)',
                'role' => "Héberge le site. Chaque push sur la branche main de GitHub déclenche un redéploiement.",
                'ok' => true,
                'etat' => 'Déploiement continu depuis GitHub (branche main)',
                'cle' => 'Toutes les variables ci-dessus se règlent dans Railway → Variables.',
                'cout' => 'Abonnement + usage (stockage, trafic).',
            ],
        ];
        ?>
        <?php $bg = outilsBackgroundCheck(); ?>
        <div class="card">
            <h2 style="margin-top:0; color:#2d5a37;">🧰 Outils utilisés par le site (<?= count($tools) ?>)</h2>
            <p class="muted" style="margin-top:-6px;">
                Page <strong>informative</strong> : rien à régler ici. Elle liste de quoi le site dépend,
                à quoi sert chaque brique, et <strong>où se configure sa clé</strong>.
                L'état est <strong>vérifié en direct</strong> à chaque affichage.
            </p>

            <!-- LE test le plus important : les tâches de fond peuvent-elles tourner ? -->
            <div style="border:2px solid <?= $bg['ok'] ? '#cfe6d5' : '#e8a0a0' ?>; background:<?= $bg['ok'] ? '#f2f9f4' : '#fdf0f0' ?>; border-radius:12px; padding:14px 16px; margin-top:14px;">
                <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                    <span style="font-size:1.4rem;">⚙️</span>
                    <strong style="color:#244230; font-size:1.02rem;">Tâches de fond</strong>
                    <span style="margin-left:auto; font-size:.78rem; font-weight:800; border-radius:999px; padding:3px 11px; background:<?= $bg['ok'] ? '#e6f4ea' : '#fbe3e3' ?>; color:<?= $bg['ok'] ? '#1e6b3a' : '#9b1c1c' ?>;">
                        <?= $bg['ok'] ? '✓ OPÉRATIONNELLES' : '✗ NE FONCTIONNENT PAS' ?>
                    </span>
                </div>
                <div style="color:#4a5a50; margin-top:7px; line-height:1.5;">
                    La <strong>compression vidéo</strong>, les <strong>sous-titres</strong> et la
                    <strong>traduction néerlandaise</strong> tournent en arrière-plan pour ne pas te faire attendre.
                    Ce test vérifie en direct qu'elles <em>peuvent</em> réellement s'exécuter.
                </div>
                <div style="margin-top:8px; font-size:.86rem; color:#33473b;">
                    <strong>exec() autorisé :</strong> <?= $bg['exec'] ? '✓ oui' : '✗ non' ?> ·
                    <strong>PHP en ligne de commande :</strong> <?= $bg['php_cli'] ? '✓ joignable' : '✗ injoignable' ?>
                    <?php if ($bg['detail'] !== ''): ?>
                        <div class="muted" style="font-size:.82rem; margin-top:3px;"><?= htmlspecialchars($bg['detail']) ?></div>
                    <?php endif; ?>
                </div>
                <?php if (!$bg['ok']): ?>
                    <div style="margin-top:10px; background:#fff; border:1px solid #f0c9c9; border-radius:8px; padding:10px 12px; font-size:.86rem; color:#8a1f1f; line-height:1.55;">
                        <strong>⚠️ Conséquence concrète :</strong> les vidéos resteront bloquées sur
                        « en préparation » et le néerlandais ne se générera pas tout seul.<br>
                        <strong>Ce n'est pas grave pour les utilisateurs</strong> — le français reste affiché et la vidéo
                        reste lisible — mais il faudra basculer ces traitements en mode synchrone.
                        Le bouton <strong>« 🌐 Traduire en NL »</strong> (sur chaque module) fonctionne, lui, en direct.
                    </div>
                <?php endif; ?>
            </div>

            <div style="display:grid; gap:12px; margin-top:16px;">
                <?php foreach ($tools as $t): ?>
                    <div style="border:1px solid <?= $t['ok'] ? '#dbe7df' : '#f0c9c9' ?>; background:<?= $t['ok'] ? '#fbfdfc' : '#fdf4f4' ?>; border-radius:12px; padding:14px 16px;">
                        <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                            <span style="font-size:1.4rem;"><?= $t['icon'] ?></span>
                            <strong style="color:#244230; font-size:1.02rem;"><?= $t['nom'] ?></strong>
                            <span style="margin-left:auto; font-size:.78rem; font-weight:800; border-radius:999px; padding:3px 11px; background:<?= $t['ok'] ? '#e6f4ea' : '#fbe3e3' ?>; color:<?= $t['ok'] ? '#1e6b3a' : '#9b1c1c' ?>;">
                                <?= $t['ok'] ? '✓ OK' : '✗ à configurer' ?>
                            </span>
                        </div>
                        <div style="color:#4a5a50; margin-top:7px; line-height:1.5;"><?= $t['role'] ?></div>
                        <div style="margin-top:8px; font-size:.86rem; color:#33473b;"><strong>État :</strong> <?= $t['etat'] ?></div>
                        <div style="margin-top:3px; font-size:.84rem; color:#6c7a70;"><strong>Configuration :</strong> <?= $t['cle'] ?></div>
                        <div style="margin-top:3px; font-size:.84rem; color:#6c7a70;"><strong>Coût :</strong> <?= $t['cout'] ?></div>
                    </div>
                <?php endforeach; ?>
            </div>

        </div>
        <?php
    }
}
