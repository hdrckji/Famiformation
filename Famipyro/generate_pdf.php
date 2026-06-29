<?php
require __DIR__ . '/includes/bootstrap.php';

function pdf_error(string $message): void
{
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'ERREUR PDF : ' . $message;
    exit;
}

function barcode_png_src(string $value): string
{
    $value = normalize_barcode_value($value);
    if ($value === '') {
        return '';
    }

    $value = preg_replace('/[^\x20-\x7E]/', '', $value) ?: '';
    if ($value === '' || !function_exists('imagecreatetruecolor')) {
        return '';
    }

    $patterns = [
        [2,1,2,2,2,2],[2,2,2,1,2,2],[2,2,2,2,2,1],[1,2,1,2,2,3],[1,2,1,3,2,2],
        [1,3,1,2,2,2],[1,2,2,2,1,3],[1,2,2,3,1,2],[1,3,2,2,1,2],[2,2,1,2,1,3],
        [2,2,1,3,1,2],[2,3,1,2,1,2],[1,1,2,2,3,2],[1,2,2,1,3,2],[1,2,2,2,3,1],
        [1,1,3,2,2,2],[1,2,3,1,2,2],[1,2,3,2,2,1],[2,2,3,2,1,1],[2,2,1,1,3,2],
        [2,2,1,2,3,1],[2,1,3,2,1,2],[2,2,3,1,1,2],[3,1,2,1,3,1],[3,1,1,2,2,2],
        [3,2,1,1,2,2],[3,2,1,2,2,1],[3,1,2,2,1,2],[3,2,2,1,1,2],[3,2,2,2,1,1],
        [2,1,2,1,2,3],[2,1,2,3,2,1],[2,3,2,1,2,1],[1,1,1,3,2,3],[1,3,1,1,2,3],
        [1,3,1,3,2,1],[1,1,2,3,1,3],[1,3,2,1,1,3],[1,3,2,3,1,1],[2,1,1,3,1,3],
        [2,3,1,1,1,3],[2,3,1,3,1,1],[1,1,2,1,3,3],[1,1,2,3,3,1],[1,3,2,1,3,1],
        [1,1,3,1,2,3],[1,1,3,3,2,1],[1,3,3,1,2,1],[3,1,3,1,2,1],[2,1,1,3,3,1],
        [2,3,1,1,3,1],[2,1,3,1,1,3],[2,1,3,3,1,1],[2,1,3,1,3,1],[3,1,1,1,2,3],
        [3,1,1,3,2,1],[3,3,1,1,2,1],[3,1,2,1,1,3],[3,1,2,3,1,1],[3,3,2,1,1,1],
        [3,1,4,1,1,1],[2,2,1,4,1,1],[4,3,1,1,1,1],[1,1,1,2,2,4],[1,1,1,4,2,2],
        [1,2,1,1,2,4],[1,2,1,4,2,1],[1,4,1,1,2,2],[1,4,1,2,2,1],[1,1,2,2,1,4],
        [1,1,2,4,1,2],[1,2,2,1,1,4],[1,2,2,4,1,1],[1,4,2,1,1,2],[1,4,2,2,1,1],
        [2,4,1,2,1,1],[2,2,1,1,1,4],[4,1,3,1,1,1],[2,4,1,1,1,2],[1,3,4,1,1,1],
        [1,1,1,2,4,2],[1,2,1,1,4,2],[1,2,1,2,4,1],[1,1,4,2,1,2],[1,2,4,1,1,2],
        [1,2,4,2,1,1],[4,1,1,2,1,2],[4,2,1,1,1,2],[4,2,1,2,1,1],[2,1,2,1,4,1],
        [2,1,4,1,2,1],[4,1,2,1,2,1],[1,1,1,1,4,3],[1,1,1,3,4,1],[1,3,1,1,4,1],
        [1,1,4,1,1,3],[1,1,4,3,1,1],[4,1,1,1,1,3],[4,1,1,3,1,1],[1,1,3,1,4,1],
        [1,1,4,1,3,1],[3,1,1,1,4,1],[4,1,1,1,3,1],[2,1,1,4,1,2],[2,1,1,2,1,4],
        [2,1,1,2,3,2],[2,3,3,1,1,1,2],
    ];

    $codes = [];
    $isNumeric = preg_match('/^\d+$/', $value) === 1;

    if ($isNumeric) {
        if (strlen($value) % 2 === 0) {
            $codes[] = 105;
            for ($i = 0, $len = strlen($value); $i < $len; $i += 2) {
                $codes[] = (int) substr($value, $i, 2);
            }
        } else {
            $codes[] = 104;
            $codes[] = ord($value[0]) - 32;
            if (strlen($value) > 1) {
                $codes[] = 99;
                for ($i = 1, $len = strlen($value); $i < $len; $i += 2) {
                    $codes[] = (int) substr($value, $i, 2);
                }
            }
        }
    } else {
        $codes[] = 104;
        for ($i = 0, $len = strlen($value); $i < $len; $i++) {
            $ascii = ord($value[$i]);
            if ($ascii >= 32 && $ascii <= 126) {
                $codes[] = $ascii - 32;
            }
        }
    }

    if (count($codes) < 2) {
        return '';
    }

    $checksum = $codes[0];
    foreach (array_slice($codes, 1) as $idx => $code) {
        $checksum += $code * ($idx + 1);
    }
    $codes[] = $checksum % 103;
    $codes[] = 106;

    $moduleWidth = 3;
    $height      = 50;
    $quietZone   = 12;

    $totalWidth = $quietZone * 2;
    foreach ($codes as $code) {
        $pattern = $patterns[$code] ?? null;
        if ($pattern !== null) {
            foreach ($pattern as $w) {
                $totalWidth += $w * $moduleWidth;
            }
        }
    }

    $img   = imagecreatetruecolor($totalWidth, $height);
    $white = imagecolorallocate($img, 255, 255, 255);
    $black = imagecolorallocate($img, 17, 17, 17);
    imagefill($img, 0, 0, $white);

    $x = $quietZone;
    foreach ($codes as $code) {
        $pattern = $patterns[$code] ?? null;
        if ($pattern === null) {
            continue;
        }
        foreach ($pattern as $idx => $w) {
            $lineWidth = $w * $moduleWidth;
            if ($idx % 2 === 0) {
                imagefilledrectangle($img, $x, 0, $x + $lineWidth - 1, $height - 1, $black);
            }
            $x += $lineWidth;
        }
    }

    ob_start();
    imagepng($img);
    $png = ob_get_clean();
    imagedestroy($img);

    return 'data:image/png;base64,' . base64_encode((string) $png);
}

$orderId = (int) ($_GET['id'] ?? 0);
$order   = null;
$items   = [];

try {
    $pdo = get_pdo($config);
    $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = ? LIMIT 1');
    $stmt->execute([$orderId]);
    $order = $stmt->fetch();

    if ($order) {
        $itemStmt = $pdo->prepare('SELECT * FROM order_items WHERE order_id = ? ORDER BY product_name');
        $itemStmt->execute([$orderId]);
        $items = $itemStmt->fetchAll();
    }
} catch (Throwable $exception) {
    pdf_error('Erreur base de données : ' . $exception->getMessage());
}

if (!$order) {
    http_response_code(404);
    echo 'Commande introuvable.';
    exit;
}

$autoloadPath = __DIR__ . '/vendor/autoload.php';
if (!is_file($autoloadPath)) {
    pdf_error('vendor/autoload.php introuvable — lancez "composer install".');
}

require_once $autoloadPath;

use Dompdf\Dompdf;
use Dompdf\Options;

$logoPath = __DIR__ . '/assets/logo.png';
$logoSrc  = is_file($logoPath)
    ? 'data:image/png;base64,' . base64_encode((string) file_get_contents($logoPath))
    : '';

$rowsHtml = '';
foreach ($items as $item) {
    $quantity  = (int) $item['quantity'];
    $paidQty   = (int) ($item['paid_quantity'] ?? $quantity);
    $freeQty   = (int) ($item['free_quantity'] ?? 0);
    $lineTotal = isset($item['line_total']) ? (float) $item['line_total'] : ((float) $item['unit_price'] * max(0, $paidQty));
    $promo     = $freeQty > 0 ? $paidQty . ' + ' . $freeQty . ' gratuit' : '—';
    $barSrc    = barcode_png_src((string) ($item['article_number'] ?? ''));
    $barImg    = $barSrc !== ''
        ? '<img src="' . $barSrc . '" style="width:130px;height:40px;display:block;">'
        : '';

    $rowsHtml .= '<tr>';
    $rowsHtml .= '<td class="barcode-cell">' . $barImg . '<div class="ref">' . e((string) $item['article_number']) . '</div></td>';
    $rowsHtml .= '<td>' . e($item['product_name']) . '</td>';
    $rowsHtml .= '<td class="center">' . $quantity . '</td>';
    $rowsHtml .= '<td class="center">' . $promo . '</td>';
    $rowsHtml .= '<td class="right">' . money((float) $item['unit_price']) . '</td>';
    $rowsHtml .= '<td class="right">' . money($lineTotal) . '</td>';
    $rowsHtml .= '</tr>';
}

$orderNumber = e(format_order_number((int) $order['id']));
$clientName  = e($order['customer_name']);
$createdAt   = e($order['created_at']);
$totalAmount = money((float) $order['total_amount']);

$logoHtml = $logoSrc !== ''
    ? '<img src="' . $logoSrc . '" style="height:60px;">'
    : '<span style="font-size:20px;font-weight:bold;color:#1f5a36;">Famipyro</span>';

$html = <<<HTML
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: Helvetica, Arial, sans-serif; font-size: 11px; color: #1a1a1a; padding: 24px; }
.header { text-align: center; margin-bottom: 20px; }
.header h1 { font-size: 17px; color: #1f5a36; margin-top: 8px; }
.header p { color: #555; font-size: 11px; }
.meta { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
.meta td { padding: 6px 12px; border: 1px solid #d4e8c2; font-size: 11px; }
.meta .label { color: #555; font-size: 10px; display: block; margin-bottom: 2px; }
.meta strong { font-size: 12px; color: #1f5a36; }
table.items { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
table.items th { background: #1f5a36; color: white; padding: 7px 8px; text-align: left; font-size: 10px; }
table.items td { padding: 6px 8px; border-bottom: 1px solid #eee; vertical-align: middle; font-size: 11px; }
table.items tr:nth-child(even) td { background: #f7fbf2; }
.center { text-align: center; }
.right { text-align: right; }
.barcode-cell { width: 145px; }
.ref { font-size: 9px; color: #555; margin-top: 3px; }
.total-row { text-align: right; font-size: 14px; font-weight: bold; color: #1f5a36; margin-top: 8px; }
.footer { margin-top: 24px; font-size: 9px; color: #aaa; text-align: center; border-top: 1px solid #eee; padding-top: 8px; }
</style>
</head>
<body>
<div class="header">
    {$logoHtml}
    <h1>Préparation de commande</h1>
    <p>Commande n° {$orderNumber}</p>
</div>
<table class="meta">
    <tr>
        <td style="width:33%;"><span class="label">Client</span><strong>{$clientName}</strong></td>
        <td style="width:33%;"><span class="label">Commande</span><strong>{$orderNumber}</strong></td>
        <td style="width:33%;"><span class="label">Date</span><strong>{$createdAt}</strong></td>
    </tr>
</table>
<table class="items">
    <thead>
        <tr>
            <th>Code barre / Réf.</th>
            <th>Produit</th>
            <th class="center">Qté</th>
            <th class="center">Promo</th>
            <th class="right">Prix unit.</th>
            <th class="right">Sous-total</th>
        </tr>
    </thead>
    <tbody>
        {$rowsHtml}
    </tbody>
</table>
<div class="total-row">Montant total : {$totalAmount}</div>
<div class="footer">Famipyro — Document généré le {$createdAt}</div>
</body>
</html>
HTML;

$options = new Options();
$options->set('isRemoteEnabled', false);
$options->set('defaultFont', 'Helvetica');
$options->set('isHtml5ParserEnabled', true);

try {
    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    $filename = 'commande-' . format_order_number((int) $order['id']) . '.pdf';
    $dompdf->stream($filename, ['Attachment' => 1]);
} catch (Throwable $exception) {
    pdf_error('Génération PDF échouée : ' . $exception->getMessage());
}
