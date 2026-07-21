<?php
require_once 'config.php';
verifierConnexion($db);

$pageLang = famiLang();
if (!function_exists('fjrT')) {
  function fjrT($fr, $nl = null)
  {
    return famiLang() === 'nl' && $nl !== null ? $nl : $fr;
  }
}

$role = $_SESSION['role'] ?? '';
if (!in_array($role, ['admin'], true)) {
    header('Location: ../index.php');
    exit();
}

// ─── Table des modèles de mail ───────────────────────────────────────────────
$db->exec("CREATE TABLE IF NOT EXISTS mail_templates (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    nom        VARCHAR(120) NOT NULL,
    sujet      VARCHAR(255) NOT NULL,
    corps      TEXT         NOT NULL,
    created_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ─── Helper : construction des destinataires ─────────────────────────────────
function buildRecipients(PDO $db, array $rayons, bool $sansRayon, array $departements, bool $sansDepartement, bool $tous, string $date, array $statuts, bool $countOnly = false)
{
    $useDate    = $date !== '' && !empty($statuts);
    $conditions = ["u.role = 'etudiant'", "u.email IS NOT NULL", "TRIM(u.email) <> ''"];
    $params     = [];
    if (!$tous) {
        $rc = [];
        if (!empty($rayons)) {
            $ph   = implode(',', array_fill(0, count($rayons), '?'));
            $rc[] = "TRIM(u.interim) IN ($ph)";
            $params = array_merge($params, $rayons);
        }
        if ($sansRayon) {
            $rc[] = "(u.interim IS NULL OR TRIM(u.interim) = '')";
        }
        if (!empty($departements)) {
          $ph   = implode(',', array_fill(0, count($departements), '?'));
          $rc[] = "EXISTS (
            SELECT 1
            FROM student_department_links sdl
            INNER JOIN departments d ON d.id = sdl.department_id
            WHERE sdl.user_id = u.id
              AND d.department_name IN ($ph)
          )";
          $params = array_merge($params, $departements);
        }
        if ($sansDepartement) {
          $rc[] = "NOT EXISTS (
            SELECT 1
            FROM student_department_links sdl2
            WHERE sdl2.user_id = u.id
          )";
        }
        if (empty($rc)) {
            return $countOnly ? 0 : [];
        }
        $conditions[] = '(' . implode(' OR ', $rc) . ')';
    }
    if ($useDate) {
        $sel    = "SELECT u.id,u.nom,u.prenom,u.email,u.interim,COALESCE(sa.availability_status,'non_renseigne') AS avail_status";
        $fr     = "FROM utilisateurs u LEFT JOIN student_availabilities sa ON sa.user_id=u.id AND sa.availability_date=?";
        $params = array_merge([$date], $params);
    } else {
        $sel = "SELECT u.id,u.nom,u.prenom,u.email,u.interim";
        $fr  = "FROM utilisateurs u";
    }
    $wh   = 'WHERE ' . implode(' AND ', $conditions);
    $stmt = $db->prepare("$sel $fr $wh ORDER BY u.nom ASC,u.prenom ASC");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if ($useDate) {
        $rows = array_values(array_filter($rows, static function ($r) use ($statuts) {
            $a = $r['avail_status'] === 'matin' ? 'non_renseigne' : $r['avail_status'];
            return in_array($a, $statuts, true);
        }));
    }
    return $countOnly ? count($rows) : $rows;
}

// ─── Actions POST ────────────────────────────────────────────────────────────
$flash      = null;
$flashType  = 'success';
$sentCount  = 0;
$sendErrors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCSRF();
    $action = $_POST['action'] ?? '';

    // Enregistrer / mettre à jour un modèle
    if ($action === 'save_template') {
        $tNom   = trim($_POST['tpl_nom']   ?? '');
        $tSujet = trim($_POST['tpl_sujet'] ?? '');
        $tCorps = trim($_POST['tpl_corps'] ?? '');
        $tId    = (int)($_POST['tpl_id']   ?? 0);
        if ($tNom === '' || $tSujet === '' || $tCorps === '') {
            $flash     = fjrT('Nom, sujet et corps sont obligatoires.', 'Naam, onderwerp en inhoud zijn verplicht.');
            $flashType = 'error';
        } elseif ($tId > 0) {
            $db->prepare("UPDATE mail_templates SET nom=?,sujet=?,corps=? WHERE id=?")->execute([$tNom, $tSujet, $tCorps, $tId]);
            $flash = fjrT('Modèle mis à jour.', 'Sjabloon bijgewerkt.');
        } else {
            $db->prepare("INSERT INTO mail_templates (nom,sujet,corps) VALUES (?,?,?)")->execute([$tNom, $tSujet, $tCorps]);
            $flash = fjrT('Modèle enregistré.', 'Sjabloon opgeslagen.');
        }

    // Supprimer un modèle
    } elseif ($action === 'delete_template') {
        $tId = (int)($_POST['tpl_id'] ?? 0);
        if ($tId > 0) {
            $db->prepare("DELETE FROM mail_templates WHERE id=?")->execute([$tId]);
            $flash = fjrT('Modèle supprimé.', 'Sjabloon verwijderd.');
        }

    // Envoyer les mails
    } elseif ($action === 'send_mail') {
        $sujet = trim($_POST['sujet'] ?? '');
        $corps = trim($_POST['corps'] ?? '');
        $aOpts = ['non_renseigne' => 'Non renseigné', 'indisponible' => 'Indisponible', 'apres_midi' => 'Après-midi', 'journee' => 'Journée'];
        $allR  = $db->query("SELECT DISTINCT TRIM(interim) AS name FROM utilisateurs WHERE role='etudiant' AND interim IS NOT NULL AND TRIM(interim)<>'' ORDER BY name ASC")->fetchAll(PDO::FETCH_COLUMN);
        $allD  = $db->query("SELECT department_name FROM departments WHERE is_active = 1 ORDER BY department_name ASC")->fetchAll(PDO::FETCH_COLUMN);
        $pR    = array_values(array_intersect($allR, (array)($_POST['rayons'] ?? [])));
        $pSR   = (string)($_POST['sans_rayon'] ?? '') === '1';
        $pDpt  = array_values(array_intersect($allD, (array)($_POST['departements'] ?? [])));
        $pSDpt = (string)($_POST['sans_departement'] ?? '') === '1';
        $pT    = (string)($_POST['tous_etudiants'] ?? '') === '1';
        $pD    = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['filter_date'] ?? '') ? $_POST['filter_date'] : '';
        $pSt   = array_values(array_intersect(array_keys($aOpts), (array)($_POST['statuts'] ?? [])));
        $recips = buildRecipients($db, $pR, $pSR, $pDpt, $pSDpt, $pT, $pD, $pSt);
        if ($sujet === '' || $corps === '') {
            $flash     = fjrT('Sujet et corps du mail sont obligatoires.', 'Onderwerp en inhoud van de e-mail zijn verplicht.');
            $flashType = 'error';
        } elseif (empty($recips)) {
            $flash     = fjrT('Aucun destinataire pour ce groupe cible.', 'Geen ontvanger voor deze doelgroep.');
            $flashType = 'error';
        } else {
            foreach ($recips as $r) {
                $prenom = trim($r['prenom'] ?? '') ?: 'Étudiant';
                $email  = trim($r['email']  ?? '');
                $vars   = [
                    '{{prenom}}' => $prenom,
                    '{{nom}}'    => trim($r['nom'] ?? ''),
                    '{{email}}'  => $email,
                    '{{rayon}}'  => trim($r['interim'] ?? '') ?: 'non renseigné',
                ];
                $fS = strtr($sujet, $vars);
                $fC = strtr($corps, $vars);
                $html = '<div style="font-family:Open Sans,Arial,sans-serif;padding:28px;background:#eef4ef;">'
                      . '<div style="max-width:640px;margin:0 auto;background:#fff;border-radius:18px;overflow:hidden;">'
                      . '<div style="padding:22px 26px;background:linear-gradient(135deg,#2d5a37,#4a7b55);color:#fff;">'
                      . '<div style="font-size:11px;text-transform:uppercase;opacity:.7;">FamiJob</div>'
                      . '<h1 style="margin:8px 0 0;font-size:22px;">' . htmlspecialchars($fS, ENT_QUOTES, 'UTF-8') . '</h1>'
                      . '</div>'
                      . '<div style="padding:26px;font-size:15px;line-height:1.75;color:#21362a;">'
                      . nl2br(htmlspecialchars($fC, ENT_QUOTES, 'UTF-8'))
                      . '</div>'
                      . '<div style="padding:10px 26px 16px;font-size:12px;color:#7a8e82;border-top:1px solid #dde9df;">Message envoyé via FamiJob.</div>'
                      . '</div></div>';
                if (sendMail($email, $fS, $html, true)) {
                    $sentCount++;
                } else {
                    $sendErrors[] = $email;
                }
            }
            $flash = $sentCount . ' ' . fjrT('mail(s) envoyé(s)', 'mail(s) verzonden') . ($sendErrors ? ' - ' . count($sendErrors) . ' ' . fjrT('échec(s).', 'mislukking(en).') : '') . '.';
            $flashType = $sendErrors ? 'error' : 'success';
        }
    }
}

// ─── Données pour l'affichage ─────────────────────────────────────────────────
$templates = $db->query("SELECT * FROM mail_templates ORDER BY nom ASC")->fetchAll(PDO::FETCH_ASSOC);
$rayons    = $db->query("SELECT DISTINCT TRIM(interim) AS name FROM utilisateurs WHERE role='etudiant' AND interim IS NOT NULL AND TRIM(interim)<>'' ORDER BY name ASC")->fetchAll(PDO::FETCH_COLUMN);
$departements = $db->query("SELECT department_name FROM departments WHERE is_active = 1 ORDER BY department_name ASC")->fetchAll(PDO::FETCH_COLUMN);
$aOpts     = ['non_renseigne' => 'Non renseigné', 'indisponible' => 'Indisponible', 'apres_midi' => 'Après-midi', 'journee' => 'Journée'];
$selRayons    = array_values(array_intersect($rayons, (array)($_GET['rayons'] ?? [])));
$selSansRayon = (string)($_GET['sans_rayon']     ?? '') === '1';
$selDepartements = array_values(array_intersect($departements, (array)($_GET['departements'] ?? [])));
$selSansDepartement = (string)($_GET['sans_departement'] ?? '') === '1';
$selTous      = (string)($_GET['tous_etudiants'] ?? '') === '1';
$selDate      = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['filter_date'] ?? '') ? $_GET['filter_date'] : '';
$selStatuts   = array_values(array_intersect(array_keys($aOpts), (array)($_GET['statuts'] ?? [])));
$previewCount = buildRecipients($db, $selRayons, $selSansRayon, $selDepartements, $selSansDepartement, $selTous, $selDate, $selStatuts, true);
?>
<!DOCTYPE html>
<html lang="<?php echo e($pageLang); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?php echo e(fjrT('Relance étudiant - FamiJob', 'Studentenherinnering - FamiJob')); ?></title>
<link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700&display=swap" rel="stylesheet">
<style>
:root{--bg:#f4f7f5;--card:#fff;--line:#dbe5de;--text:#21362a;--muted:#627268;--accent:#2d5a37;--soft:#edf5ef;--shadow:0 12px 32px rgba(22,49,33,.10);--red:#9e4036;--red-bg:#fae4e1}
*,*::before,*::after{box-sizing:border-box}
body{margin:0;padding:20px;background:var(--bg);font-family:'Open Sans',sans-serif;color:var(--text)}
.page{max-width:1360px;margin:0 auto}
.hero{background:linear-gradient(135deg,#264e35,#3f6b4d);color:#fff;border-radius:24px;padding:22px 28px;margin-bottom:18px;box-shadow:var(--shadow);display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap}
.hero h1{margin:6px 0 4px;font-size:1.75rem}.hero p{margin:0;opacity:.9;font-size:.93rem}
.back-link{color:#fff;text-decoration:none;font-weight:700;background:rgba(255,255,255,.14);padding:10px 16px;border-radius:999px;font-size:.88rem;white-space:nowrap}
.flash{padding:12px 16px;border-radius:14px;font-weight:700;margin-bottom:14px}
.flash.success{background:#d6f0de;color:#1c5c32}.flash.error{background:var(--red-bg);color:var(--red)}
.layout{display:grid;grid-template-columns:1fr 390px;gap:18px;align-items:start}
@media(max-width:1100px){.layout{grid-template-columns:1fr}}
.card{background:var(--card);border-radius:22px;box-shadow:var(--shadow);overflow:hidden}
.card+.card{margin-top:16px}
.card-head{padding:14px 18px;background:#f6faf7;border-bottom:1px solid var(--line);font-weight:700;display:flex;align-items:center;gap:10px}
.card-head .badge{background:var(--accent);color:#fff;border-radius:999px;padding:2px 10px;font-size:.78rem}
.card-body{padding:18px}
.field{margin-bottom:14px}
.field label{display:block;font-size:.8rem;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);font-weight:700;margin-bottom:5px}
input[type=text],input[type=date],textarea{width:100%;border:1px solid #cfdad3;border-radius:12px;padding:10px 12px;font-size:.95rem;font-family:inherit;background:#fff;color:var(--text)}
input:focus,textarea:focus{outline:none;border-color:var(--accent)}
textarea{resize:vertical;min-height:130px;line-height:1.6}
.target-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:8px}
.target-sections{display:grid;grid-template-columns:1fr;gap:10px}
.target-menu{border:1px solid var(--line);border-radius:14px;background:#fbfdfb;overflow:hidden}
.target-menu-btn{width:100%;background:transparent;border:none;padding:12px 14px;display:flex;align-items:center;justify-content:space-between;cursor:pointer;font-family:inherit;font-weight:700;color:var(--text)}
.target-menu-title{font-size:.78rem;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);text-align:left}
.target-menu-chevron{transition:transform .16s ease;color:var(--muted)}
.target-menu.open .target-menu-chevron{transform:rotate(180deg)}
.target-menu-body{display:none;padding:0 12px 12px}
.target-menu.open .target-menu-body{display:block}
.check-tile{display:flex;align-items:center;gap:8px;padding:9px 12px;border:1px solid var(--line);border-radius:12px;cursor:pointer;user-select:none;font-size:.9rem;font-weight:600;transition:background .12s,border-color .12s}
.check-tile:has(input:checked){background:var(--soft);border-color:var(--accent);color:var(--accent)}
.check-tile input{accent-color:var(--accent);width:14px;height:14px;cursor:pointer;flex-shrink:0}
.check-tile.all-tile{border-style:dashed}
.dispo-box{border:1px solid var(--line);border-radius:16px;padding:14px;margin-top:12px}
.dispo-legend{font-size:.8rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:var(--muted);margin-bottom:10px}
.dispo-row{display:grid;grid-template-columns:180px 1fr;gap:12px;align-items:start}
@media(max-width:700px){.dispo-row{grid-template-columns:1fr}}
.dispo-checks{display:flex;flex-wrap:wrap;gap:8px;margin-top:4px}
.dispo-chip label{display:flex;align-items:center;gap:6px;padding:6px 12px;border:1px solid var(--line);border-radius:999px;cursor:pointer;font-size:.87rem;font-weight:600;transition:all .12s;white-space:nowrap}
.dispo-chip label:has(input:checked){background:#fff8e1;border-color:#c9a227;color:#7a5c00}
.dispo-chip input{accent-color:#c9a227}
.recipient-count{display:flex;align-items:center;gap:14px;padding:14px 16px;background:var(--soft);border-radius:14px;margin-top:14px;font-weight:700}
.recipient-count .number{font-size:2.2rem;color:var(--accent);line-height:1}
.recipient-count .lbl{font-size:.88rem;color:var(--muted);line-height:1.4}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;border:none;border-radius:12px;padding:11px 18px;font-weight:700;font-size:.9rem;cursor:pointer;font-family:inherit;transition:opacity .12s;text-decoration:none}
.btn:hover{opacity:.86}.btn-primary{background:var(--accent);color:#fff}.btn-soft{background:var(--soft);color:var(--accent)}.btn-danger{background:var(--red-bg);color:var(--red)}.btn-sm{padding:7px 12px;font-size:.82rem;border-radius:9px}
.actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:16px}
.vars-hint{background:#f6faf7;border:1px dashed #c5d9cb;border-radius:12px;padding:10px 14px;font-size:.83rem;color:var(--muted);line-height:1.7;margin-top:8px}
code{background:#edf5ef;padding:1px 5px;border-radius:5px;font-size:.85em}
.sep{border:none;border-top:1px solid var(--line);margin:14px 0}
.small{font-size:.83rem;color:var(--muted)}
.tpl-list{display:grid;gap:8px}
.tpl-item{display:flex;align-items:center;gap:10px;padding:10px 12px;border:1px solid var(--line);border-radius:12px;transition:all .12s}
.tpl-item:hover{background:var(--soft);border-color:var(--accent)}
.tpl-main{flex:1;min-width:0;cursor:pointer}
.tpl-name{font-weight:700;font-size:.9rem}
.tpl-sujet{color:var(--muted);font-size:.82rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.tpl-btns{display:flex;gap:6px;flex-shrink:0}
.fami-lang-switcher{display:inline-flex;align-items:center;gap:6px;background:rgba(255,255,255,.16);border:1px solid rgba(255,255,255,.26);border-radius:999px;padding:4px}
.fami-lang-option{display:inline-block;text-decoration:none;color:#fff;font-weight:800;font-size:.78rem;letter-spacing:.04em;padding:5px 9px;border-radius:999px}
.fami-lang-option.is-active{background:#fff;color:var(--accent)}
</style>
</head>
<body>
<?php require_once __DIR__ . "/includes/topbar.php"; famijobRibbon($db); ?>
<div class="page">

<div class="hero">
  <div>
    <a href="index.php" class="back-link">← FamiJob</a>
    <?php echo famiRenderLanguageSwitcher(); ?>
    <h1><?php echo e(fjrT('Relance étudiant', 'Studentenherinnering')); ?></h1>
    <p><?php echo e(fjrT('Envoi de mails groupés par rayon ou par disponibilité. Créez et sauvegardez vos propres modèles de mail.', 'Verstuur groepsmails per interimkantoor of beschikbaarheid. Maak en bewaar je eigen e-mailsjablonen.')); ?></p>
  </div>
</div>

<?php if ($flash): ?>
<div class="flash <?php echo $flashType; ?>"><?php echo e($flash); ?></div>
<?php endif; ?>

<div class="layout">

  <!-- ═══ GAUCHE : Groupe cible + Composer ═══ -->
  <form method="post" action="">
    <?php echo csrfField(); ?>
    <input type="hidden" name="action" value="send_mail">

    <!-- Groupe cible -->
    <div class="card">
      <div class="card-head">
        🎯 <?php echo e(fjrT('Groupe cible', 'Doelgroep')); ?>
        <span class="badge" id="count-badge"><?php echo $previewCount; ?> <?php echo e(fjrT('étudiant(s)', 'student(en)')); ?></span>
      </div>
      <div class="card-body">

        <div class="field">
          <label><?php echo e(fjrT('Groupe cible', 'Doelgroep')); ?></label>

          <div class="target-grid" style="margin-bottom:10px;">
            <label class="check-tile all-tile">
              <input type="checkbox" name="tous_etudiants" value="1" id="chk-tous"
                <?php echo $selTous ? 'checked' : ''; ?>>
              <?php echo e(fjrT('Tous les étudiants', 'Alle studenten')); ?>
            </label>
          </div>

          <div class="target-sections">
            <div class="target-menu open" id="menu-agences">
              <button type="button" class="target-menu-btn" data-target-menu="menu-agences">
                <span class="target-menu-title"><?php echo e(fjrT('Agences intérim', 'Interimkantoren')); ?></span>
                <span class="target-menu-chevron">▼</span>
              </button>
              <div class="target-menu-body">
                <div class="target-grid">
                  <?php foreach ($rayons as $rayon): ?>
                  <label class="check-tile">
                    <input type="checkbox" name="rayons[]" value="<?php echo e($rayon); ?>"
                      <?php echo in_array($rayon, $selRayons, true) ? 'checked' : ''; ?>>
                    <?php echo e($rayon); ?>
                  </label>
                  <?php endforeach; ?>
                  <label class="check-tile">
                    <input type="checkbox" name="sans_rayon" value="1" id="chk-sr"
                      <?php echo $selSansRayon ? 'checked' : ''; ?>>
                    <?php echo e(fjrT('Sans agence', 'Zonder kantoor')); ?>
                  </label>
                </div>
              </div>
            </div>

            <div class="target-menu" id="menu-departements">
              <button type="button" class="target-menu-btn" data-target-menu="menu-departements">
                <span class="target-menu-title"><?php echo e(fjrT('Départements', 'Afdelingen')); ?></span>
                <span class="target-menu-chevron">▼</span>
              </button>
              <div class="target-menu-body">
                <div class="target-grid">
                  <?php foreach ($departements as $departement): ?>
                  <label class="check-tile">
                    <input type="checkbox" name="departements[]" value="<?php echo e($departement); ?>"
                      <?php echo in_array($departement, $selDepartements, true) ? 'checked' : ''; ?>>
                    <?php echo e($departement); ?>
                  </label>
                  <?php endforeach; ?>
                  <label class="check-tile">
                    <input type="checkbox" name="sans_departement" value="1" id="chk-sd"
                      <?php echo $selSansDepartement ? 'checked' : ''; ?>>
                    <?php echo e(fjrT('Sans département', 'Zonder afdeling')); ?>
                  </label>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="dispo-box">
          <div class="dispo-legend">Affiner par disponibilité <span class="small">(optionnel — si aucun statut n'est coché, tous les étudiants du groupe sont ciblés)</span></div>
          <div class="dispo-row">
            <div>
              <div class="field" style="margin-bottom:0">
                <label>Date</label>
                <input type="date" name="filter_date" value="<?php echo e($selDate); ?>">
              </div>
            </div>
            <div>
              <label style="font-size:.8rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:var(--muted);margin-bottom:5px;display:block;">Statut(s)</label>
              <div class="dispo-checks">
                <?php foreach ($aOpts as $k => $lbl): ?>
                <div class="dispo-chip"><label>
                  <input type="checkbox" name="statuts[]" value="<?php echo e($k); ?>"
                    <?php echo in_array($k, $selStatuts, true) ? 'checked' : ''; ?>>
                  <?php echo e($lbl); ?>
                </label></div>
                <?php endforeach; ?>
              </div>
            </div>
          </div>
        </div>

        <div class="recipient-count">
          <div class="number" id="count-display"><?php echo $previewCount; ?></div>
          <div class="lbl">étudiant(s) correspondent<br>au groupe cible sélectionné</div>
        </div>

      </div>
    </div>

    <!-- Composer -->
    <div class="card">
      <div class="card-head">✏️ Composer le mail</div>
      <div class="card-body">
        <div class="field">
          <label for="sujet">Sujet</label>
          <input type="text" id="sujet" name="sujet" placeholder="Objet du mail…" required>
        </div>
        <div class="field">
          <label for="corps">Corps du mail</label>
          <textarea id="corps" name="corps" rows="9" placeholder="Bonjour {{prenom}},&#10;&#10;…"></textarea>
        </div>
        <div class="vars-hint">Variables : <code>{{prenom}}</code> <code>{{nom}}</code> <code>{{email}}</code> <code>{{rayon}}</code></div>
        <div class="actions">
          <button type="submit" class="btn btn-primary" onclick="return confirmSend()">📨 Envoyer les mails</button>
        </div>
      </div>
    </div>

  </form>

  <!-- ═══ DROITE : Gestion des modèles ═══ -->
  <div>
    <div class="card">
      <div class="card-head">📂 Mes modèles de mail</div>
      <div class="card-body">

        <?php if (empty($templates)): ?>
        <p class="small" style="margin:0 0 14px;">Aucun modèle enregistré. Créez-en un ci-dessous.</p>
        <?php else: ?>
        <div class="tpl-list">
          <?php foreach ($templates as $tpl):
            $jsId    = (int)$tpl['id'];
            $jsNom   = htmlspecialchars(json_encode($tpl['nom'],   JSON_UNESCAPED_UNICODE), ENT_QUOTES);
            $jsSujet = htmlspecialchars(json_encode($tpl['sujet'], JSON_UNESCAPED_UNICODE), ENT_QUOTES);
            $jsCorps = htmlspecialchars(json_encode($tpl['corps'], JSON_UNESCAPED_UNICODE), ENT_QUOTES);
          ?>
          <div class="tpl-item">
            <div class="tpl-main" onclick="loadTpl(<?php echo "$jsId,$jsNom,$jsSujet,$jsCorps"; ?>)">
              <div class="tpl-name"><?php echo e($tpl['nom']); ?></div>
              <div class="tpl-sujet"><?php echo e($tpl['sujet']); ?></div>
            </div>
            <div class="tpl-btns">
              <button type="button" class="btn btn-soft btn-sm"
                onclick="loadTpl(<?php echo "$jsId,$jsNom,$jsSujet,$jsCorps"; ?>)">Charger</button>
              <form method="post" style="display:inline" onsubmit="return confirm('Supprimer ce modèle ?')">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action"  value="delete_template">
                <input type="hidden" name="tpl_id" value="<?php echo $jsId; ?>">
                <button type="submit" class="btn btn-danger btn-sm">✕</button>
              </form>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <hr class="sep">
        <?php endif; ?>

        <!-- Formulaire créer / modifier un modèle -->
        <form method="post">
          <?php echo csrfField(); ?>
          <input type="hidden" name="action" value="save_template">
          <input type="hidden" name="tpl_id" id="tpl_id" value="0">
          <div class="field">
            <label for="tpl_nom">Nom du modèle</label>
            <input type="text" id="tpl_nom" name="tpl_nom" placeholder="Ex: Rappel disponibilité…" required>
          </div>
          <div class="field">
            <label for="tpl_sujet">Sujet du mail</label>
            <input type="text" id="tpl_sujet" name="tpl_sujet" placeholder="Objet…" required>
          </div>
          <div class="field">
            <label for="tpl_corps">Corps du mail</label>
            <textarea id="tpl_corps" name="tpl_corps" rows="7"
              placeholder="Bonjour {{prenom}},&#10;&#10;…" required></textarea>
          </div>
          <div class="actions">
            <button type="submit" class="btn btn-soft" id="tpl-save-btn">💾 Enregistrer le modèle</button>
            <button type="button" class="btn btn-soft btn-sm" onclick="resetTpl()">+ Nouveau</button>
          </div>
        </form>

      </div>
    </div>
  </div>

</div><!-- /.layout -->
</div><!-- /.page -->

<script>
(function () {
    window.loadTpl = function (id, nom, sujet, corps) {
        document.getElementById('sujet').value     = sujet;
        document.getElementById('corps').value     = corps;
        document.getElementById('tpl_id').value    = id;
        document.getElementById('tpl_nom').value   = nom;
        document.getElementById('tpl_sujet').value = sujet;
        document.getElementById('tpl_corps').value = corps;
        document.getElementById('tpl-save-btn').textContent = '💾 Mettre à jour le modèle';
        document.getElementById('sujet').scrollIntoView({ behavior: 'smooth', block: 'center' });
    };
    window.resetTpl = function () {
        document.getElementById('tpl_id').value    = 0;
        document.getElementById('tpl_nom').value   = '';
        document.getElementById('tpl_sujet').value = '';
        document.getElementById('tpl_corps').value = '';
        document.getElementById('tpl-save-btn').textContent = '💾 Enregistrer le modèle';
    };

    // "Tous les étudiants" désactive les tuiles de rayons individuels
    var chkTous = document.getElementById('chk-tous');
    var rayonInputs = document.querySelectorAll('input[name="rayons[]"], #chk-sr, input[name="departements[]"], #chk-sd');
    var menuButtons = document.querySelectorAll('.target-menu-btn[data-target-menu]');
    menuButtons.forEach(function (btn) {
      btn.addEventListener('click', function () {
        var menuId = btn.getAttribute('data-target-menu');
        var menu = document.getElementById(menuId);
        if (menu) {
          menu.classList.toggle('open');
        }
      });
    });
    function syncTous() {
        var d = chkTous.checked;
        rayonInputs.forEach(function (c) {
            c.disabled = d;
            c.closest('.check-tile').style.opacity = d ? '.4' : '1';
        });
    }
    chkTous.addEventListener('change', syncTous);
    syncTous();

    window.confirmSend = function () {
        var n = document.getElementById('count-badge').textContent;
        return confirm('Envoyer un mail à ' + n + ' ?\n\nCette action est irréversible.');
    };
}());
</script>
</body>
</html>
