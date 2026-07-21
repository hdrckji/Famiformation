<?php
// ============================================================
// export_matching.php — Export du matching de la semaine au format "planning".
//
//   Grille : 7 colonnes-jours (lundi -> dimanche), chacune subdivisee en
//   6 sous-colonnes (horaire | I | EI | EFM | nom | agence).
//   Les affectations sont regroupees par departement (une ligne d'en-tete de
//   departement, puis une ligne par personne, chaque jour dans sa colonne).
//
//   Sortie : CSV (separateur ';', BOM UTF-8) -> s'ouvre directement dans Excel.
// ============================================================

require_once 'config.php';
verifierConnexion($db);

$role = (string) ($_SESSION['role'] ?? '');
if ($role !== 'admin') {
    header('Location: ../index.php');
    exit();
}

// --- Semaine demandee (on se cale toujours sur le lundi) ---
$today = new DateTimeImmutable('today');
$defaultMonday = $today->modify('monday this week');
$weekParam = (string) ($_GET['week'] ?? $defaultMonday->format('Y-m-d'));
try {
    $weekStart = new DateTimeImmutable($weekParam);
} catch (Exception $e) {
    $weekStart = $defaultMonday;
}
$weekStart = $weekStart->modify('monday this week');
$weekEnd = $weekStart->modify('+6 days');

$days = [];
for ($i = 0; $i < 7; $i++) {
    $days[] = $weekStart->modify('+' . $i . ' days');
}
$dayIndexByKey = [];
foreach ($days as $idx => $d) {
    $dayIndexByKey[$d->format('Y-m-d')] = $idx;
}

// --- Affectations de la semaine ---
$stmt = $db->prepare(
    "SELECT r.shift_date, r.department_name, r.time_slot,
            a.seat_number, a.student_id, a.external_name, a.agency_name,
            u.nom AS student_nom, u.prenom AS student_prenom, u.interim AS student_interim
     FROM interim_shift_assignments a
     INNER JOIN interim_shift_requests r ON r.id = a.request_id
     LEFT JOIN utilisateurs u ON u.id = a.student_id
     WHERE r.shift_date BETWEEN ? AND ?
     ORDER BY r.department_name ASC, r.time_slot ASC, a.seat_number ASC"
);
$stmt->execute([$weekStart->format('Y-m-d'), $weekEnd->format('Y-m-d')]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- Regroupement : departement -> [jour0..jour6] -> liste d'affectations ---
$byDept = [];
foreach ($rows as $r) {
    $dept = trim((string) $r['department_name']);
    if ($dept === '') {
        $dept = '(sans département)';
    }
    $dateKey = (string) $r['shift_date'];
    if (!isset($dayIndexByKey[$dateKey])) {
        continue;
    }
    $dayIdx = $dayIndexByKey[$dateKey];

    if (!isset($byDept[$dept])) {
        $byDept[$dept] = array_fill(0, 7, []);
    }

    $isExternal = empty($r['student_id']);
    if ($isExternal) {
        $nom = trim((string) ($r['external_name'] ?? ''));
        $agence = trim((string) ($r['agency_name'] ?? ''));
    } else {
        $nom = trim(trim((string) ($r['student_nom'] ?? '')) . ' ' . trim((string) ($r['student_prenom'] ?? '')));
        $agence = trim((string) ($r['student_interim'] ?? ''));
        if ($agence === '') {
            $agence = trim((string) ($r['agency_name'] ?? ''));
        }
    }

    $byDept[$dept][$dayIdx][] = [
        'horaire' => trim((string) $r['time_slot']),
        'nom' => $nom,
        'agence' => $agence,
    ];
}

// Ordre des departements : alphabetique (gerable ensuite depuis Paramètres).
$deptNames = array_keys($byDept);
usort($deptNames, static function ($a, $b) {
    return strcasecmp($a, $b);
});

// --- Libelles de dates en francais (sans dependance a l'ext intl) ---
$joursFr = [1 => 'lundi', 2 => 'mardi', 3 => 'mercredi', 4 => 'jeudi', 5 => 'vendredi', 6 => 'samedi', 7 => 'dimanche'];
$moisFr = [1 => 'janvier', 2 => 'février', 3 => 'mars', 4 => 'avril', 5 => 'mai', 6 => 'juin', 7 => 'juillet', 8 => 'août', 9 => 'septembre', 10 => 'octobre', 11 => 'novembre', 12 => 'décembre'];
$dateLabel = static function (DateTimeImmutable $d) use ($joursFr, $moisFr) {
    return $joursFr[(int) $d->format('N')] . ' ' . $d->format('j') . ' ' . $moisFr[(int) $d->format('n')] . ' ' . $d->format('Y');
};

// --- Construction des lignes CSV (chaque ligne = 42 cellules : 7 jours x 6 colonnes) ---
$csvRows = [];

// Ligne 1 : dates (dans la 1re sous-colonne de chaque jour)
$line = [];
foreach ($days as $d) {
    $line[] = $dateLabel($d);
    $line[] = ''; $line[] = ''; $line[] = ''; $line[] = ''; $line[] = '';
}
$csvRows[] = $line;

// Ligne 2 : en-tetes de colonnes, repetees pour chaque jour
$line = [];
for ($i = 0; $i < 7; $i++) {
    $line[] = 'horaire';
    $line[] = 'I';
    $line[] = 'EI';
    $line[] = 'EFM';
    $line[] = 'nom';
    $line[] = 'agence';
}
$csvRows[] = $line;

// Blocs par departement
foreach ($deptNames as $dept) {
    // Ligne d'en-tete du departement (repetee sur chaque jour)
    $line = [];
    for ($i = 0; $i < 7; $i++) {
        $line[] = $dept;
        $line[] = ''; $line[] = ''; $line[] = ''; $line[] = ''; $line[] = '';
    }
    $csvRows[] = $line;

    // Nombre de lignes = max d'affectations sur un jour pour ce departement
    $maxRows = 0;
    foreach ($byDept[$dept] as $dayList) {
        $maxRows = max($maxRows, count($dayList));
    }

    for ($rowIdx = 0; $rowIdx < $maxRows; $rowIdx++) {
        $line = [];
        for ($dayIdx = 0; $dayIdx < 7; $dayIdx++) {
            $a = $byDept[$dept][$dayIdx][$rowIdx] ?? null;
            if ($a === null) {
                $line[] = ''; $line[] = ''; $line[] = ''; $line[] = ''; $line[] = ''; $line[] = '';
            } else {
                $line[] = $a['horaire'];
                $line[] = ''; // I
                $line[] = ''; // EI
                $line[] = ''; // EFM
                $line[] = $a['nom'];
                $line[] = $a['agence'];
            }
        }
        $csvRows[] = $line;
    }

    // Ligne vide de separation
    $csvRows[] = array_fill(0, 42, '');
}

// --- Sortie CSV ---
$escapeCsv = static function ($value) {
    $value = (string) $value;
    if ($value === '') {
        return '';
    }
    if (strpbrk($value, ";\"\r\n") !== false) {
        return '"' . str_replace('"', '""', $value) . '"';
    }
    return $value;
};

$filename = 'matching_semaine_' . $weekStart->format('Y-m-d') . '.csv';

while (ob_get_level() > 0) {
    ob_end_clean();
}
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

// BOM UTF-8 pour qu'Excel reconnaisse l'encodage (accents corrects).
echo "\xEF\xBB\xBF";

$out = fopen('php://output', 'w');
foreach ($csvRows as $r) {
    $cells = array_map($escapeCsv, $r);
    fwrite($out, implode(';', $cells) . "\r\n");
}
fclose($out);
exit();
