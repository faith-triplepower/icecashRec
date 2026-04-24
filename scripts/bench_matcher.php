<?php
// scripts/bench_matcher.php — CLI benchmark of smart_match_all.
// Disposable: delete after measuring. Prints elapsed seconds and row counts.
//
// Usage: php scripts/bench_matcher.php [date_from] [date_to]

@ini_set('memory_limit', '2048M');
@set_time_limit(900);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/matcher.php';

if (!function_exists('set_progress')) {
    function set_progress($db, $run_id, $pct, $msg) {
        fprintf(STDERR, "[%3d%%] %s\n", $pct, $msg);
        $stmt = $db->prepare("UPDATE reconciliation_runs SET progress_pct=?, progress_msg=? WHERE id=?");
        $msg = substr($msg, 0, 200);
        $stmt->bind_param('isi', $pct, $msg, $run_id);
        $stmt->execute();
        $stmt->close();
    }
}

$date_from = isset($argv[1]) ? $argv[1] : '2026-03-01';
$date_to   = isset($argv[2]) ? $argv[2] : '2026-04-14';

$db = get_db();

$stmt = $db->prepare(
    "INSERT INTO reconciliation_runs
     (period_label, product, agent_id, date_from, date_to, period_type,
      opt_terminal, opt_ecocash, opt_flag_fx, opt_bordeaux, opt_date_tol,
      run_status, run_by, started_at)
     VALUES ('Bench', 'All Products', NULL, ?, ?, 'Monthly',
             1, 1, 1, 0, 1, 'running', 1, NOW())"
);
$stmt->bind_param('ss', $date_from, $date_to);
$stmt->execute();
$run_id = $stmt->insert_id;
$stmt->close();

$opts = array('terminal'=>1, 'ecocash'=>1, 'flag_fx'=>1, 'bordeaux'=>0, 'date_tol'=>1);

$t0 = microtime(true);
$result = smart_match_all($db, $run_id, $date_from, $date_to, 'All Products', 0, $opts);
$elapsed = microtime(true) - $t0;

$db->query("UPDATE reconciliation_runs SET run_status='complete', completed_at=NOW(), progress_pct=100, progress_msg='bench complete' WHERE id=$run_id");

echo "\n========================================\n";
printf("Elapsed: %.2f seconds\n", $elapsed);
echo "total_sales: " . $result['total_sales'] . "\n";
echo "total_receipts: " . $result['total_receipts'] . "\n";
echo "matched_count (inflated by Pass 4 batches): " . $result['matched_count'] . "\n";

$r = $db->query(
    "SELECT
       SUM(match_status IN ('matched','variance')) m,
       SUM(match_status='pending') p,
       SUM(match_status='excluded') x
     FROM receipts
     WHERE direction='credit' AND txn_date BETWEEN '$date_from' AND '$date_to'"
)->fetch_assoc();
$active = $r['m'] + $r['p'];
$rate = $active > 0 ? round(100*$r['m']/$active, 2) : 0;
echo "Receipts matched: {$r['m']} / $active active ($rate%)\n";
echo "Receipts pending: {$r['p']}\n";
echo "Receipts excluded: {$r['x']}\n";
