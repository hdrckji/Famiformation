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
if (!in_array($role, ['admin', 'teamcoach'], true)) {
    header('Location: ../index.php');
    exit();
}
$isAdmin = ($role === 'admin');

// Un teamcoach n'exporte que le planning de SON agence (comme ce qu'il voit dans le matching).
$agencyName = '';
if (!$isAdmin) {
    try {
        $agencyStmt = $db->prepare('SELECT interim FROM utilisateurs WHERE id = ? LIMIT 1');
        $agencyStmt->execute([(int) ($_SESSION['user_id'] ?? 0)]);
        $agencyName = trim((string) $agencyStmt->fetchColumn());
    } catch (Exception $e) {}
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
$sql =
    "SELECT r.shift_date, r.department_name, r.time_slot,
            a.seat_number, a.student_id, a.external_name, a.agency_name,
            u.nom AS student_nom, u.prenom AS student_prenom, u.interim AS student_interim
     FROM interim_shift_assignments a
     INNER JOIN interim_shift_requests r ON r.id = a.request_id
     LEFT JOIN utilisateurs u ON u.id = a.student_id
     WHERE r.shift_date BETWEEN ? AND ?";
$sqlParams = [$weekStart->format('Y-m-d'), $weekEnd->format('Y-m-d')];
if (!$isAdmin && $agencyName !== '') {
    $sql .= " AND (TRIM(a.agency_name) = ? OR TRIM(u.interim) = ?)";
    $sqlParams[] = $agencyName;
    $sqlParams[] = $agencyName;
}
$sql .= " ORDER BY r.department_name ASC, r.time_slot ASC, a.seat_number ASC";
$stmt = $db->prepare($sql);
$stmt->execute($sqlParams);
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

// ============================================================
// SORTIE : on tente un vrai fichier Excel (.xlsx) mis en forme (couleurs,
// fusions, bordures) via PhpSpreadsheet. En cas d'indisponibilite -> CSV.
// ============================================================

/** Lettre de colonne Excel a partir d'un index 1-based (1 -> A, 27 -> AA). */
function fjxCol($n)
{
    $s = '';
    while ($n > 0) {
        $n--;
        $s = chr(65 + ($n % 26)) . $s;
        $n = intdiv($n, 26);
    }
    return $s;
}

/** Construit le classeur .xlsx et renvoie ses octets (ou lance une exception). */
function fjxBuildXlsx(array $days, array $deptNames, array $byDept, callable $dateLabel)
{
    $ss = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $ss->getActiveSheet();
    $sheet->setTitle('Matching');

    $fillSolid = \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID;
    $hCenter = \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER;
    $vCenter = \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER;
    $thin = \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN;

    // Largeurs de colonnes (motif repete pour chaque jour : horaire,I,EI,EFM,nom,agence).
    $widths = [10, 4, 4, 5, 22, 14];
    for ($d = 0; $d < 7; $d++) {
        for ($c = 0; $c < 6; $c++) {
            $sheet->getColumnDimension(fjxCol($d * 6 + $c + 1))->setWidth($widths[$c]);
        }
    }

    $r = 1;

    // Ligne 1 : dates (fusionnees sur les 6 colonnes de chaque jour).
    foreach ($days as $i => $d) {
        $c1 = $i * 6 + 1;
        $sheet->setCellValue(fjxCol($c1) . $r, ucfirst($dateLabel($d)));
        $sheet->mergeCells(fjxCol($c1) . $r . ':' . fjxCol($c1 + 5) . $r);
    }
    $sheet->getStyle('A' . $r . ':' . fjxCol(42) . $r)->applyFromArray([
        'font' => ['bold' => true, 'size' => 11, 'color' => ['rgb' => 'FFFFFF']],
        'fill' => ['fillType' => $fillSolid, 'startColor' => ['rgb' => '264E35']],
        'alignment' => ['horizontal' => $hCenter, 'vertical' => $vCenter],
    ]);
    $sheet->getRowDimension($r)->setRowHeight(22);
    $r++;

    // Ligne 2 : en-tetes de colonnes.
    $heads = ['horaire', 'I', 'EI', 'EFM', 'nom', 'agence'];
    for ($d = 0; $d < 7; $d++) {
        foreach ($heads as $c => $h) {
            $sheet->setCellValue(fjxCol($d * 6 + $c + 1) . $r, $h);
        }
    }
    $sheet->getStyle('A' . $r . ':' . fjxCol(42) . $r)->applyFromArray([
        'font' => ['bold' => true, 'size' => 9, 'color' => ['rgb' => '21362A']],
        'fill' => ['fillType' => $fillSolid, 'startColor' => ['rgb' => 'D9E7DD']],
        'alignment' => ['horizontal' => $hCenter, 'vertical' => $vCenter],
    ]);
    $r++;

    $headerRows = $r - 1; // fige les 2 lignes d'en-tete

    foreach ($deptNames as $dept) {
        // Bande de departement (fusionnee sur toute la largeur).
        $sheet->setCellValue('A' . $r, $dept);
        $sheet->mergeCells('A' . $r . ':' . fjxCol(42) . $r);
        $sheet->getStyle('A' . $r . ':' . fjxCol(42) . $r)->applyFromArray([
            'font' => ['bold' => true, 'size' => 11, 'color' => ['rgb' => '6B4E00']],
            'fill' => ['fillType' => $fillSolid, 'startColor' => ['rgb' => 'FDE9A9']],
            'alignment' => ['vertical' => $vCenter],
        ]);
        $sheet->getStyle('A' . $r)->getAlignment()->setIndent(1);
        $sheet->getRowDimension($r)->setRowHeight(18);
        $r++;

        $maxRows = 0;
        foreach ($byDept[$dept] as $dayList) {
            $maxRows = max($maxRows, count($dayList));
        }

        $firstDataRow = $r;
        for ($rowIdx = 0; $rowIdx < $maxRows; $rowIdx++) {
            for ($dayIdx = 0; $dayIdx < 7; $dayIdx++) {
                $a = $byDept[$dept][$dayIdx][$rowIdx] ?? null;
                if ($a === null) {
                    continue;
                }
                $base = $dayIdx * 6 + 1;
                $sheet->setCellValue(fjxCol($base) . $r, $a['horaire']);       // horaire
                $sheet->setCellValue(fjxCol($base + 4) . $r, $a['nom']);       // nom
                $sheet->setCellValue(fjxCol($base + 5) . $r, $a['agence']);    // agence
            }
            $r++;
        }

        // Bordures fines sur la zone de donnees du departement.
        if ($maxRows > 0) {
            $sheet->getStyle('A' . $firstDataRow . ':' . fjxCol(42) . ($r - 1))->applyFromArray([
                'borders' => ['allBorders' => ['borderStyle' => $thin, 'color' => ['rgb' => 'DDE6DF']]],
                'font' => ['size' => 10],
            ]);
            // Couleur alternee : on teinte les jours impairs (mardi, jeudi, samedi) pour
            // distinguer visuellement les colonnes de jours (comme dans le planning).
            for ($dayIdx = 1; $dayIdx < 7; $dayIdx += 2) {
                $c1 = fjxCol($dayIdx * 6 + 1);
                $c2 = fjxCol($dayIdx * 6 + 6);
                $sheet->getStyle($c1 . $firstDataRow . ':' . $c2 . ($r - 1))
                    ->getFill()->setFillType($fillSolid)->getStartColor()->setRGB('FBF1E8');
            }
        }

        $r++; // ligne vide de separation
    }

    $lastRow = max($headerRows, $r - 1);

    // Separateurs verticaux entre les jours (bordure moyenne a gauche de chaque jour).
    for ($d = 1; $d < 7; $d++) {
        $col = fjxCol($d * 6 + 1);
        $sheet->getStyle($col . '1:' . $col . $lastRow)->getBorders()->getLeft()
            ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_MEDIUM)
            ->getColor()->setRGB('9BB0A3');
    }
    // Cadre exterieur du tableau.
    $sheet->getStyle('A1:' . fjxCol(42) . $lastRow)->getBorders()->getOutline()
        ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_MEDIUM)
        ->getColor()->setRGB('264E35');

    // Fige les colonnes/lignes d'en-tete.
    $sheet->freezePane('A' . ($headerRows + 1));

    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($ss);
    ob_start();
    $writer->save('php://output');
    $bytes = ob_get_clean();
    $ss->disconnectWorksheets();
    unset($ss);
    return $bytes;
}

// Tentative de chargement de PhpSpreadsheet (present dans public/vendor sur Railway).
$xlsxData = null;
foreach ([
    dirname(__DIR__) . '/vendor/autoload.php',
    dirname(__DIR__) . '/public/vendor/autoload.php',
    __DIR__ . '/vendor/autoload.php',
] as $autoload) {
    if (is_file($autoload)) {
        require_once $autoload;
        break;
    }
}
if (class_exists('PhpOffice\\PhpSpreadsheet\\Spreadsheet')) {
    try {
        $xlsxData = fjxBuildXlsx($days, $deptNames, $byDept, $dateLabel);
    } catch (\Throwable $e) {
        $xlsxData = null;
    }
}

if (is_string($xlsxData) && $xlsxData !== '') {
    $filename = 'matching_semaine_' . $weekStart->format('Y-m-d') . '.xlsx';
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($xlsxData));
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    echo $xlsxData;
    exit();
}

// --- Repli CSV (si PhpSpreadsheet indisponible) ---
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

echo "\xEF\xBB\xBF";

$out = fopen('php://output', 'w');
foreach ($csvRows as $r) {
    $cells = array_map($escapeCsv, $r);
    fwrite($out, implode(';', $cells) . "\r\n");
}
fclose($out);
exit();
