<?php
// ============================================================
// api_services.php — inventaire des SERVICES API réellement configurés.
//   Lit les variables d'environnement (Railway) et dit, pour chacun :
//   présent ou non, à quoi il sert, et lequel est ACTIF pour la transcription.
//   But : quand on ajoute une clé, ça se voit tout de suite dans Paramètres → API.
// Additif : autonome.
// ============================================================

if (!function_exists('famiEnvKey')) {
    /** Une variable d'env est-elle définie et non vide ? (getenv OU $_SERVER : Railway peuple les deux) */
    function famiEnvKey($name)
    {
        $v = getenv($name);
        if (($v === false || $v === '') && isset($_SERVER[$name])) { $v = $_SERVER[$name]; }
        return trim((string) $v) !== '';
    }
}

if (!function_exists('famiApiServices')) {
    /**
     * @return array [['name','env','role','ok','active'], ...]
     *   'active' : pour la transcription, quel fournisseur est réellement utilisé.
     */
    function famiApiServices()
    {
        require_once __DIR__ . '/transcription.php'; // famiSttProvider()
        $stt = function_exists('famiSttProvider') ? famiSttProvider() : '';

        return [
            [
                'name'   => '🧠 Claude (Anthropic)',
                'env'    => 'ANTHROPIC_API_KEY',
                'role'   => 'Mise en forme du guide, génération du quiz, relecture, traduction néerlandaise.',
                'ok'     => famiEnvKey('ANTHROPIC_API_KEY'),
                'active' => famiEnvKey('ANTHROPIC_API_KEY'),
            ],
            [
                'name'   => '⚡ Groq (Whisper)',
                'env'    => 'GROQ_API_KEY',
                'role'   => 'Transcription audio des vidéos (sous-titres). Modèle whisper-large-v3.',
                'ok'     => famiEnvKey('GROQ_API_KEY'),
                'active' => famiEnvKey('GROQ_API_KEY') && $stt === 'groq',
            ],
            [
                'name'   => '🤖 OpenAI (Whisper)',
                'env'    => 'OPENAI_API_KEY',
                'role'   => 'Transcription audio des vidéos (sous-titres). Modèle whisper-1.',
                'ok'     => famiEnvKey('OPENAI_API_KEY'),
                'active' => famiEnvKey('OPENAI_API_KEY') && $stt === 'openai',
            ],
        ];
    }

    /** Nombre de services dont la clé est présente. */
    function famiApiServicesCount()
    {
        $n = 0;
        foreach (famiApiServices() as $s) { if (!empty($s['ok'])) { $n++; } }
        return $n;
    }
}

if (!function_exists('famiApiServicesPanel')) {
    function famiApiServicesPanel()
    {
        $svcs = famiApiServices();
        $nb = famiApiServicesCount();
        ?>
        <div class="card">
            <h2 style="margin:0 0 4px; color:#2d5a37;">🔌 Services configurés (<?= (int) $nb ?>)</h2>
            <p class="muted" style="margin:0 0 14px;">Lu <strong>en direct</strong> dans les variables d'environnement (Railway → Variables). Ajoute une clé là-bas, elle apparaît ici au rechargement — rien à saisir dans le site.</p>

            <?php foreach ($svcs as $s): ?>
                <div style="display:flex; align-items:flex-start; gap:12px; padding:12px 14px; border:1px solid <?= $s['ok'] ? '#cfe3d5' : '#e6e6e6' ?>; background:<?= $s['ok'] ? '#f4faf6' : '#fafafa' ?>; border-radius:12px; margin-bottom:10px;">
                    <div style="font-size:1.3rem; line-height:1.2;"><?= $s['ok'] ? '✅' : '⚪' ?></div>
                    <div style="flex:1; min-width:0;">
                        <div style="font-weight:800; color:#244230;">
                            <?= htmlspecialchars($s['name']) ?>
                            <?php if (!empty($s['active'])): ?>
                                <span style="font-size:.72rem; font-weight:800; background:#2d5a37; color:#fff; border-radius:999px; padding:2px 9px; margin-left:6px;">ACTIF</span>
                            <?php elseif ($s['ok']): ?>
                                <span style="font-size:.72rem; font-weight:800; background:#eef1ef; color:#7d8a83; border-radius:999px; padding:2px 9px; margin-left:6px;">en secours</span>
                            <?php endif; ?>
                        </div>
                        <div class="muted" style="font-size:.86rem; margin-top:2px;"><?= htmlspecialchars($s['role']) ?></div>
                        <div class="muted" style="font-size:.78rem; margin-top:4px;">
                            <code><?= htmlspecialchars($s['env']) ?></code> —
                            <?= $s['ok'] ? '<span style="color:#256b39; font-weight:700;">clé présente</span>' : '<span style="color:#8a6d1a; font-weight:700;">clé absente</span>' ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>

            <p class="muted" style="margin:6px 0 0; font-size:.82rem;">
                ℹ️ Pour la <strong>transcription</strong>, le site prend Groq en priorité (moins cher) et bascule sur OpenAI si Groq n'est pas configuré. Sans aucune des deux clés, il faut fournir un fichier <code>.srt</code> à l'import.
            </p>
        </div>
        <?php
    }
}
