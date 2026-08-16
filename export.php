<?php
ini_set('display_errors', '0');
error_reporting(E_ALL);

// Buffer output to avoid accidental whitespace or debug output corrupting the XLSX package.
ob_start();

require_once 'db.php';
require_once 'auth.php';
requireLogin();

$user_id = $_SESSION['user_id'];
$format = strtolower(trim($_GET['format'] ?? 'xlsx'));
if ($format !== 'xlsx') {
    $format = 'xlsx';
}

$stmt = $conn->prepare("SELECT t.type, t.amount, t.currency, t.date, t.note, t.category, IFNULL(a.account_name, 'No Account') AS account_name FROM transactions t LEFT JOIN accounts a ON t.account_id = a.id WHERE t.user_id = ? ORDER BY t.date DESC");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

$currency_symbols = [
    'INR' => '₹',
    'USD' => '$',
    'EUR' => '€',
    'GBP' => '£'
];

$incomeKeywords = ['cash in', 'income', 'deposit', 'credit'];

$rows = [];
$totalIn = 0.0;
$totalOut = 0.0;
$runningBalance = 0.0;

while ($row = $result->fetch_assoc()) {
    $amountValue = (float)($row['amount'] ?? 0);
    $typeLabel = strtolower(trim($row['type'] ?? ''));
    $isIncome = false;
    foreach ($incomeKeywords as $keyword) {
        if ($keyword !== '' && strpos($typeLabel, $keyword) !== false) {
            $isIncome = true;
            break;
        }
    }

    if ($isIncome) {
        $totalIn += $amountValue;
        $runningBalance += $amountValue;
    } else {
        $totalOut += $amountValue;
        $runningBalance -= $amountValue;
    }

    $rows[] = [
        'date' => $row['date'] ?? '',
        'note' => $row['note'] ?? '',
        'category' => $row['category'] ?? 'General',
        'type' => $isIncome ? 'Income' : 'Expense',
        'amount' => ($currency_symbols[$row['currency']] ?? $row['currency']) . number_format($amountValue, 2, '.', ','),
        'balance' => ($currency_symbols[$row['currency']] ?? $row['currency']) . number_format($runningBalance, 2, '.', ','),
        'isIncome' => $isIncome,
        'runningBalance' => $runningBalance,
    ];
}

$filename = 'backup_' . date('Y-m-d') . '.xlsx';


$tempFile = tempnam(sys_get_temp_dir(), 'xlsx');
$zip = new ZipArchive();
if ($zip->open($tempFile, ZipArchive::CREATE) !== true) {
    http_response_code(500);
    exit('Unable to generate export file.');
}

$shared = buildSharedStrings($rows);
$zip->addFromString('[Content_Types].xml', buildContentTypesXML());
$zip->addFromString('_rels/.rels', buildRootRelsXML());
$zip->addFromString('xl/workbook.xml', buildWorkbookXML());
$zip->addFromString('xl/_rels/workbook.xml.rels', buildWorkbookRelsXML());
$zip->addFromString('xl/styles.xml', buildStylesXML());
$zip->addFromString('xl/sharedStrings.xml', $shared['xml']);
$zip->addFromString('xl/worksheets/sheet1.xml', buildWorksheetXML($rows, $shared['map']));
if (!$zip->close()) {
    error_log('export.php: failed to finalize XLSX archive.');
}

if (ob_get_level()) {
    ob_end_clean();
}

$filesize = filesize($tempFile);
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Transfer-Encoding: binary');
header('Content-Length: ' . $filesize);
header('Cache-Control: private, max-age=0, no-transform');
header('Pragma: public');
header('Expires: 0');

if (readfile($tempFile) === false) {
    error_log('export.php: failed to send XLSX file ' . $tempFile);
}
unlink($tempFile);
exit();

function buildContentTypesXML()
{
    return '<?xml version="1.0" encoding="UTF-8"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
        . '</Types>';
}

function buildRootRelsXML()
{
    return '<?xml version="1.0" encoding="UTF-8"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>';
}

function buildWorkbookXML()
{
    return '<?xml version="1.0" encoding="UTF-8"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="Backup" sheetId="1" r:id="rId1"/></sheets>'
        . '</workbook>';
}

function buildWorkbookRelsXML()
{
    return '<?xml version="1.0" encoding="UTF-8"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>'
        . '</Relationships>';
}

function buildStylesXML()
{
    return '<?xml version="1.0" encoding="UTF-8"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="2"><font><sz val="11"/><color rgb="FF000000"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FF000000"/><name val="Calibri"/></font></fonts>'
        . '<fills count="5"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFD9EAD3"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFFFD9D9"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFE7E7FF"/></patternFill></fill></fills>'
        . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="10">'
        . '<xf xfId="0" numFmtId="0" fontId="1" fillId="1" borderId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
        . '<xf xfId="0" numFmtId="0" fontId="0" fillId="0" borderId="0" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
        . '<xf xfId="0" numFmtId="0" fontId="0" fillId="0" borderId="0" applyAlignment="1" applyProtection="1"><alignment horizontal="left" vertical="top" wrapText="1"/></xf>'
        . '<xf xfId="0" numFmtId="0" fontId="0" fillId="2" borderId="0" applyFill="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
        . '<xf xfId="0" numFmtId="0" fontId="0" fillId="3" borderId="0" applyFill="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
        . '<xf xfId="0" numFmtId="0" fontId="0" fillId="4" borderId="0" applyFill="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
        . '<xf xfId="0" numFmtId="0" fontId="0" fillId="0" borderId="0" applyFont="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>'
        . '<xf xfId="0" numFmtId="0" fontId="0" fillId="0" borderId="0" applyFont="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>'
        . '<xf xfId="0" numFmtId="0" fontId="0" fillId="0" borderId="0" applyFont="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>'
        . '<xf xfId="0" numFmtId="0" fontId="0" fillId="0" borderId="0" applyFont="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>'
        . '</cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
        . '</styleSheet>';
}

function buildSharedStrings(array $rows)
{
    $items = [];
    $strings = [];

    $addString = function ($value) use (&$items, &$strings) {
        $value = (string)$value;
        if (!isset($items[$value])) {
            $items[$value] = count($strings);
            $strings[] = $value;
        }
    };

    $headers = ['Date', 'Note', 'Category', 'Type', 'Amount', 'Running Balance'];
    foreach ($headers as $header) {
        $addString($header);
    }

    foreach ($rows as $row) {
        $addString($row['date']);
        $addString($row['note']);
        $addString($row['category']);
        $addString($row['type']);
        $addString($row['amount']);
        $addString($row['balance']);
    }

    $totalStrings = 6 + count($rows) * 6;
    $xml = '<?xml version="1.0" encoding="UTF-8"?>';
    $xml .= '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . $totalStrings . '" uniqueCount="'.count($strings).'">';
    foreach ($strings as $string) {
        $xml .= '<si>' . xmlSafe($string) . '</si>';
    }
    $xml .= '</sst>';

    return ['xml' => $xml, 'map' => $items];
}

function buildWorksheetXML(array $rows, array $sharedStringMap)
{
    $xml = '<?xml version="1.0" encoding="UTF-8"?>';
    $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';
    $xml .= '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>';
    $xml .= '<cols>';
    $xml .= '<col min="1" max="1" width="25" customWidth="1"/>';
    $xml .= '<col min="2" max="2" width="40" customWidth="1"/>';
    $xml .= '<col min="3" max="3" width="20" customWidth="1"/>';
    $xml .= '<col min="4" max="4" width="15" customWidth="1"/>';
    $xml .= '<col min="5" max="5" width="15" customWidth="1"/>';
    $xml .= '<col min="6" max="6" width="20" customWidth="1"/>';
    $xml .= '</cols>';
    $xml .= '<sheetData>';

    $headers = ['Date', 'Note', 'Category', 'Type', 'Amount', 'Running Balance'];
    $xml .= '<row r="1" ht="24" customHeight="1">';
    foreach ($headers as $colIndex => $header) {
        $cell = getColumnLetter($colIndex) . '1';
        $xml .= '<c r="' . $cell . '" t="s" s="1"><v>' . $sharedStringMap[$header] . '</v></c>';
    }
    $xml .= '</row>';

    foreach ($rows as $rowIndex => $row) {
        $excelRow = $rowIndex + 2;
        $xml .= '<row r="' . $excelRow . '">';

        $xml .= '<c r="A' . $excelRow . '" t="s" s="2"><v>' . $sharedStringMap[$row['date']] . '</v></c>';
        $xml .= '<c r="B' . $excelRow . '" t="s" s="3"><v>' . $sharedStringMap[$row['note']] . '</v></c>';
        $xml .= '<c r="C' . $excelRow . '" t="s" s="0"><v>' . $sharedStringMap[$row['category']] . '</v></c>';

        $typeStyle = $row['isIncome'] ? 4 : 5;
        $xml .= '<c r="D' . $excelRow . '" t="s" s="' . $typeStyle . '"><v>' . $sharedStringMap[$row['type']] . '</v></c>';

        $amountStyle = $row['isIncome'] ? 6 : 7;
        $xml .= '<c r="E' . $excelRow . '" t="s" s="' . $amountStyle . '"><v>' . $sharedStringMap[$row['amount']] . '</v></c>';

        $balanceStyle = $row['runningBalance'] < 0 ? 9 : 8;
        $xml .= '<c r="F' . $excelRow . '" t="s" s="' . $balanceStyle . '"><v>' . $sharedStringMap[$row['balance']] . '</v></c>';

        $xml .= '</row>';
    }

    $xml .= '</sheetData>';
    $xml .= '<pageMargins left="0.7" right="0.7" top="0.75" bottom="0.75" header="0.3" footer="0.3"/>';
    $xml .= '</worksheet>';

    return $xml;
}

function xmlSafe($value)
{
    $value = str_replace(["\r\n", "\r"], "\n", $value);
    $escaped = htmlspecialchars($value, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    if (strpos($escaped, "\n") !== false || preg_match('/^\s|\s$/', $escaped)) {
        return '<t xml:space="preserve">' . str_replace("\n", '&#10;', $escaped) . '</t>';
    }
    return '<t>' . $escaped . '</t>';
}

function getColumnLetter($index)
{
    $letters = '';
    while ($index >= 0) {
        $letters = chr(65 + ($index % 26)) . $letters;
        $index = intval($index / 26) - 1;
    }
    return $letters;
}

