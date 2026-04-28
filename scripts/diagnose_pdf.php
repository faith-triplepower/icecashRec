<?php
// scripts/diagnose_pdf.php
//
// Standalone diagnostic for PDF ingestion. Runs the same parsers
// the upload form does and prints what each one extracted, so we
// can tell whether the issue is pdftotext, the parser, or the
// downstream ingestion path.
//
// Usage (from project root):
//   php scripts/diagnose_pdf.php path/to/Ecobank.pdf
// Or with a copy in the project root:
//   php scripts/diagnose_pdf.php Ecobank.pdf

require __DIR__ . '/../core/ingestion.php';

$argv_path = $argv[1] ?? '';
if (!$argv_path) {
    fwrite(STDERR, "Usage: php scripts/diagnose_pdf.php <path-to-pdf>\n");
    exit(1);
}
if (!is_file($argv_path)) {
    fwrite(STDERR, "Not a file: $argv_path\n");
    exit(2);
}

echo "── PDF: $argv_path\n";
echo "── pdftotext binary: ";
$bin = find_pdftotext();
if (!$bin) {
    echo "NOT FOUND. Install Poppler/Git-for-Windows or set pdftotext_path in system_settings.\n";
    exit(3);
}
echo "$bin\n\n";

try {
    $text = _ingestion_pdftotext_run($argv_path);
} catch (Exception $e) {
    echo "pdftotext threw: " . $e->getMessage() . "\n";
    exit(4);
}

echo "── pdftotext extracted " . strlen($text) . " bytes of text.\n";
echo "── First 60 lines:\n";
$lines = preg_split('/\r?\n/', $text);
for ($i = 0; $i < min(60, count($lines)); $i++) {
    printf("  %3d | %s\n", $i + 1, $lines[$i]);
}

echo "\n── Strategy results (Receipts):\n";
$strategies = array(
    'columnar'  => '_parse_pdf_columnar_balance',
    'multiline' => '_parse_pdf_multiline',
    'universal' => '_parse_pdf_universal',
    'simple'    => '_parse_pdf_simple',
);
foreach ($strategies as $name => $fn) {
    if (!function_exists($fn)) { echo "  $name: function missing\n"; continue; }
    $rows = $fn($text, 'Receipts');
    printf("  %-10s -> %d rows\n", $name, count($rows));
    if (!empty($rows)) {
        echo "      first: ref=" . ($rows[0]['reference_no'] ?? '') .
             " date=" . ($rows[0]['txn_date'] ?? '') .
             " amount=" . ($rows[0]['amount'] ?? '') .
             " currency=" . ($rows[0]['currency'] ?? '') . "\n";
    }
}

// Save the full pdftotext output for inspection
$dump_path = __DIR__ . '/../uploads/_diagnose_dump_' . date('Ymd_His') . '.txt';
@mkdir(dirname($dump_path), 0755, true);
file_put_contents($dump_path, $text);
echo "\n── Full extracted text written to: $dump_path\n";
