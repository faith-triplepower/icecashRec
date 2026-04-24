<?php
// ============================================================
// core/matcher.php — Smart universal matching engine v3
// Drop-in replacement for run_matching_engine() in
// process/process_reconciliation.php.
//
// Goal: every sale that a human could match, the engine will match.
// Strategy: multiple passes, each catching a different failure mode.
//
//   Pass 0 — EXACT HIGH: receipt has exactly one same-day, exact-amount
//            unmatched sale → match (high confidence, very fast).
//   Pass 1 — SCORING: full 9-signal scoring ≥ threshold (auto-match).
//   Pass 2 — LONELY RECEIPT: only one exact-amount sale in ±14 days.
//   Pass 3 — LONELY SALE:    only one exact-amount receipt in ±14 days.
//   Pass 4 — BATCH:  1 receipt = Σ sales within ±2 days.
//   Pass 5 — SPLIT:  1 sale = Σ receipts same day + channel.
//   Pass 6 — FUZZY FALLBACK: widened window, lower threshold.
//
// Manual matches are never touched. Duplicates in sales/receipts
// (same policy_number or reference_no on multiple rows) are fully
// supported — we key on id, not policy/reference.
// ============================================================

// ─────────────────────────────────────────────────────────────
// SHARED HELPERS
// ─────────────────────────────────────────────────────────────

function match_normalize_channel($ch) {
    $ch = strtolower(trim((string)$ch));
    if ($ch === '') return 'POS';
    if (strpos($ch, 'ipos') !== false || strpos($ch, 'bank pos') !== false
        || strpos($ch, 'pos') !== false || strpos($ch, 'card') !== false) return 'POS';
    if (strpos($ch, 'ecocash') !== false || strpos($ch, 'eco') !== false) return 'EcoCash';
    if (strpos($ch, 'zimswitch') !== false) return 'Zimswitch';
    if (strpos($ch, 'broker') !== false || strpos($ch, 'transfer') !== false
        || strpos($ch, 'rtgs') !== false) return 'Broker';
    return 'POS';
}

function match_tokens($s) {
    if ($s === null || $s === '') return array();
    $out = array();
    foreach (preg_split('/[\s,;:\/\|\-_\.]+/', strtoupper((string)$s)) as $t) {
        $t = preg_replace('/[^A-Z0-9]/', '', (string)$t);
        if (strlen($t) >= 4) $out[$t] = $t;
    }
    return array_values($out);
}

function match_numbers($s) {
    if ($s === null || $s === '') return array();
    preg_match_all('/\d{4,}/', (string)$s, $m);
    return array_values(array_unique($m[0]));
}

function match_amount_tolerance($amount) {
    $amt = max(0.0, (float)$amount);
    $pct = $amt * 0.02;
    return min(100.0, max(1.0, $pct));
}

function match_noise_words() {
    return array(
        'ZIMNAT','LION','INSURANCE','INNSURANCE','INSURANCES','INS',
        'HARARE','HRE','BRANCH','OFFICE','LTD','PVT','LIMITED','PRIVATE',
        'BANK','COMPANY','CO','THE','AND','AT','FROM','TO',
    );
}

// ─────────────────────────────────────────────────────────────
// SCORING
// ─────────────────────────────────────────────────────────────

// score_pair is called up to ~hundreds of thousands of times per run — the
// hot path of the entire engine. Everything that can be pre-computed from a
// single sale or receipt row (tokens, number lists, uppercased text,
// normalized channel/currency, timestamps) MUST be pre-computed by the
// caller and attached as `_`-prefixed keys. score_pair just reads them.
//
// Expected precomputed keys — set by precompute_match_fields():
//   sale/receipt : _amt, _ts, _numbers, _numbers_norm, _term, _chan_norm, _cur
//   sale         : _tokens
//   receipt      : _text_upper, _source_upper
//
// $agent_tokens_by_id — also precomputed, shape: [agent_id => [tok, ...]].
// Tokens are already filtered (>=4 chars, no noise words).

function score_pair($sale, $receipt, $agent_tokens_by_id) {
    $score   = 0;
    $reasons = array();

    $s_amt = $sale['_amt'];
    $r_amt = $receipt['_amt'];
    $diff  = abs($s_amt - $r_amt);
    $rel   = $r_amt > 0 ? $diff / $r_amt : 1.0;
    if ($diff < 0.01)       { $score += 30; $reasons[] = 'exact amount'; }
    elseif ($rel < 0.005)   { $score += 25; $reasons[] = 'amount ±0.5%'; }
    elseif ($rel < 0.02)    { $score += 15; $reasons[] = 'amount ±2%'; }
    elseif ($rel < 0.05)    { $score += 5;  $reasons[] = 'amount ±5%'; }

    $s_ts = $sale['_ts'];
    $r_ts = $receipt['_ts'];
    if ($s_ts && $r_ts) {
        $days = abs(($s_ts - $r_ts) / 86400);
        if ($days == 0)     { $score += 20; $reasons[] = 'same day'; }
        elseif ($days <= 1) { $score += 15; $reasons[] = '±1 day'; }
        elseif ($days <= 3) { $score += 10; $reasons[] = '±3 days'; }
        elseif ($days <= 7) { $score += 5;  $reasons[] = '±7 days'; }
    }

    $sale_id_tokens     = $sale['_tokens'];
    $receipt_text_upper = $receipt['_text_upper'];
    if ($receipt_text_upper !== '' && !empty($sale_id_tokens)) {
        foreach ($sale_id_tokens as $tok) {
            if (strpos($receipt_text_upper, $tok) !== false) {
                $score += 25;
                $reasons[] = 'policy/ref in receipt';
                break;
            }
        }
    }

    $sale_numbers    = $sale['_numbers'];
    $receipt_numbers = $receipt['_numbers'];
    if (!empty($sale_numbers) && !empty($receipt_numbers)) {
        $sale_norm       = $sale['_numbers_norm'];
        $receipt_norm_set = $receipt['_numbers_norm_set']; // flipped: value => key

        // Strong: exact shared 7+ digit ID (after leading-zero normalization).
        // isset() on the flipped set is O(1) vs in_array()'s O(N).
        $strong = false;
        foreach ($sale_norm as $nn) {
            if (strlen($nn) >= 7 && isset($receipt_norm_set[$nn])) {
                $strong = true; break;
            }
        }
        if ($strong) {
            $score += 25;
            $reasons[] = 'shared RRN/ID';
        } else {
            $medium = false;
            foreach ($sale_norm as $nn) {
                if (strlen($nn) >= 4 && isset($receipt_norm_set[$nn])) {
                    $medium = true; break;
                }
            }
            if ($medium) {
                $score += 10;
                $reasons[] = 'shared short ID';
            } else {
                // Weak: suffix match (policy digits appearing at end of a receipt reference)
                foreach ($sale_numbers as $sn) {
                    $snl = strlen((string)$sn);
                    if ($snl < 6) continue;
                    foreach ($receipt_numbers as $rn) {
                        $rnl = strlen((string)$rn);
                        if ($rnl > $snl && substr((string)$rn, -$snl) === (string)$sn) {
                            $score += 8;
                            $reasons[] = 'number suffix match';
                            break 2;
                        }
                    }
                }
            }
        }
    }

    if ($sale['_term'] !== '' && $receipt['_term'] !== '' && $sale['_term'] === $receipt['_term']) {
        $score += 15;
        $reasons[] = 'same terminal';
    }

    $agent_id = (int)($sale['agent_id'] ?? 0);
    if ($agent_id > 0 && $receipt['_source_upper'] !== '' && isset($agent_tokens_by_id[$agent_id])) {
        foreach ($agent_tokens_by_id[$agent_id] as $tok) {
            if (strpos($receipt['_source_upper'], $tok) !== false) {
                $score += 15;
                $reasons[] = 'branch name match';
                break;
            }
        }
    }

    if ($sale['_chan_norm'] === $receipt['_chan_norm']) {
        $score += 10;
        $reasons[] = 'same channel';
    }

    if ($sale['_cur'] !== '' && $receipt['_cur'] !== '') {
        if ($sale['_cur'] === $receipt['_cur']) {
            $score += 10;
        } else {
            $score -= 5;
            $reasons[] = 'CURRENCY MISMATCH';
        }
    }

    return array($score, $reasons);
}

// ─────────────────────────────────────────────────────────────
// PRECOMPUTE — attach every field score_pair() reads onto each
// sale/receipt row exactly once, so the inner loop is pure
// comparisons (no regex, no strtotime, no array_map).
// ─────────────────────────────────────────────────────────────
function precompute_match_fields(&$sales, &$receipts, $agent_name_by_id, &$agent_tokens_by_id) {
    $norm_num = function($n) { $s = ltrim((string)$n, '0'); return $s === '' ? '0' : $s; };

    foreach ($sales as $k => $s) {
        $ts = @strtotime((string)($s['txn_date'] ?? ''));
        $sales[$k]['_ts']   = $ts ?: 0;
        $sales[$k]['_amt']  = (float)($s['amount'] ?? 0);

        $toks = array_merge(
            match_tokens($s['policy_number'] ?? null),
            match_tokens($s['reference_no']  ?? null)
        );
        // Only 4+ char tokens are usable; score_pair assumes they're prefiltered.
        $sales[$k]['_tokens'] = $toks; // match_tokens already enforces len>=4

        $nums      = match_numbers(($s['reference_no'] ?? '') . ' ' . ($s['policy_number'] ?? ''));
        $nums_norm = array_map($norm_num, $nums);
        $sales[$k]['_numbers']      = $nums;
        $sales[$k]['_numbers_norm'] = $nums_norm;

        $sales[$k]['_term']      = strtoupper(trim((string)($s['terminal_id'] ?? '')));
        $sales[$k]['_chan_norm'] = match_normalize_channel($s['payment_method'] ?? '');
        $sales[$k]['_cur']       = strtoupper(trim((string)($s['currency'] ?? '')));
    }

    foreach ($receipts as $k => $r) {
        $ts = @strtotime((string)($r['txn_date'] ?? ''));
        $receipts[$k]['_ts']  = $ts ?: 0;
        $receipts[$k]['_amt'] = (float)($r['amount'] ?? 0);

        $blob_raw = (string)($r['reference_no'] ?? '') . ' ' . (string)($r['source_name'] ?? '');
        $receipts[$k]['_text_upper'] = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $blob_raw));

        $nums      = match_numbers(($r['reference_no'] ?? '') . ' ' . ($r['source_name'] ?? ''));
        $nums_norm = array_map($norm_num, $nums);
        $receipts[$k]['_numbers']      = $nums;
        $receipts[$k]['_numbers_norm'] = $nums_norm;
        // Hash-set of normalized numbers so score_pair can test membership in
        // O(1) with isset() instead of in_array() — the number-intersection
        // check used to be the costliest part of the scoring inner loop.
        $receipts[$k]['_numbers_norm_set'] = $nums_norm ? array_flip($nums_norm) : array();

        $receipts[$k]['_term']         = strtoupper(trim((string)($r['terminal_id'] ?? '')));
        $receipts[$k]['_chan_norm']    = match_normalize_channel($r['channel'] ?? '');
        $receipts[$k]['_cur']          = strtoupper(trim((string)($r['currency'] ?? '')));
        $receipts[$k]['_source_upper'] = strtoupper((string)($r['source_name'] ?? ''));
    }

    // Pre-filter agent name tokens (drop noise words, require 4+ chars).
    $noise = array_flip(match_noise_words());
    $agent_tokens_by_id = array();
    foreach ($agent_name_by_id as $id => $name) {
        $out = array();
        foreach (preg_split('/[\s\-_\.]+/', strtoupper((string)$name)) as $tok) {
            $tok = preg_replace('/[^A-Z0-9]/', '', (string)$tok);
            if (strlen($tok) >= 4 && !isset($noise[$tok])) $out[] = $tok;
        }
        if (!empty($out)) $agent_tokens_by_id[(int)$id] = $out;
    }
}

// ─────────────────────────────────────────────────────────────
// CORE CLAIM — applied identically from every pass.
// Does FX checks, writes the receipt, marks in-memory state.
// ─────────────────────────────────────────────────────────────

function do_claim($db, $upd, $flag_sale, $sale, $receipt, $confidence, $policy_override, $opts, $terminal_reg, &$claimed_sales, &$claimed_receipts, &$fx_flagged, &$matched_count, &$score_hist) {
    $status = 'matched';

    if (!empty($opts['flag_fx']) && !empty($sale['currency']) && !empty($receipt['currency'])
        && $sale['currency'] !== $receipt['currency']) {
        $status = 'variance';
        $fx_flagged++;
        try {
            $flag_sale->bind_param('i', $sale['id']);
            $flag_sale->execute();
        } catch (Throwable $e) { /* non-fatal */ }
    }
    if (!empty($opts['flag_fx']) && !empty($receipt['terminal_id'])
        && isset($terminal_reg[$receipt['terminal_id']])) {
        $t_cur = $terminal_reg[$receipt['terminal_id']]['currency'];
        if ($t_cur !== 'ZWG/USD' && $t_cur !== $receipt['currency']) {
            $status = 'variance';
            $fx_flagged++;
        }
    }

    $policy = $policy_override !== null ? $policy_override : $sale['policy_number'];
    try {
        $upd->bind_param('sissi', $policy, $sale['id'], $status, $confidence, $receipt['id']);
        $upd->execute();
        if (isset($sale['id']))    $claimed_sales[$sale['id']]       = true;
        if (isset($receipt['id'])) $claimed_receipts[$receipt['id']] = true;
        $matched_count++;
        if (isset($score_hist[$confidence])) $score_hist[$confidence]++;
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

// ─────────────────────────────────────────────────────────────
// MAIN ENTRY — drop-in for run_matching_engine()
// ─────────────────────────────────────────────────────────────

function smart_match_all($db, $run_id, $date_from, $date_to, $product, $agent_filter, $opts, $sales_upload_ids = array(), $receipts_upload_ids = array()) {

    // ── Thresholds ──
    $AUTO_THRESHOLD      = 50;
    $FUZZY_THRESHOLD     = 35;   // looser Pass 6 threshold
    $HIGH_CONF_THRESHOLD = 75;
    $MED_CONF_THRESHOLD  = 55;
    $DATE_WINDOW_DAYS    = 7;
    $LONELY_WINDOW_DAYS  = 14;
    $FUZZY_WINDOW_DAYS   = 10;
    $BATCH_MIN_AMOUNT    = 100;

    // ── Sanitize filters ──
    $sales_upload_ids    = array_values(array_filter(array_map('intval', (array)$sales_upload_ids)));
    $receipts_upload_ids = array_values(array_filter(array_map('intval', (array)$receipts_upload_ids)));
    $sales_in_clause     = !empty($sales_upload_ids)    ? ' AND s.upload_id IN ('    . implode(',', $sales_upload_ids)    . ')' : '';
    $receipts_in_clause  = !empty($receipts_upload_ids) ? ' AND upload_id IN ('      . implode(',', $receipts_upload_ids) . ')' : '';

    // ── Reset pending — PRESERVE manual matches AND excluded rows.
    // Excluded rows are non-customer transfers (RTGS/OMNI/test data) and
    // debit outflows; resetting them to 'pending' would re-admit them to
    // the unmatched queue and re-pollute the variance report.
    $reset_sql = "UPDATE receipts SET match_status='pending', matched_policy=NULL,
                         matched_sale_id=NULL, match_confidence=NULL
                  WHERE txn_date BETWEEN ? AND ?
                    AND direction='credit'
                    AND match_status <> 'excluded'
                    AND (match_confidence IS NULL OR match_confidence <> 'manual')"
               . $receipts_in_clause;
    $reset = $db->prepare($reset_sql);
    $reset->bind_param('ss', $date_from, $date_to);
    $reset->execute();
    $reset->close();

    set_progress($db, $run_id, 10, 'Loading sales');

    // ── Load sales ──
    $sw = "s.txn_date BETWEEN ? AND ?";
    $sp = array($date_from, $date_to);
    $st = 'ss';
    if ($product !== 'All Products') { $sw .= " AND s.product = ?";  $sp[] = $product;      $st .= 's'; }
    if ($agent_filter > 0)           { $sw .= " AND s.agent_id = ?"; $sp[] = $agent_filter; $st .= 'i'; }
    $sw .= $sales_in_clause;
    $stmt = $db->prepare(
        "SELECT s.id, s.policy_number, s.reference_no, s.txn_date, s.agent_id,
                s.terminal_id, s.payment_method, s.amount, s.currency
         FROM sales s WHERE $sw"
    );
    $stmt->bind_param($st, ...$sp);
    $stmt->execute();
    $sales = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    set_progress($db, $run_id, 20, 'Loading receipts');

    // ── Load receipts (wide buffer) ──
    $buffer_from = date('Y-m-d', strtotime($date_from . ' -' . $LONELY_WINDOW_DAYS . ' day'));
    $buffer_to   = date('Y-m-d', strtotime($date_to   . ' +' . $LONELY_WINDOW_DAYS . ' day'));
    $stmt = $db->prepare(
        "SELECT id, reference_no, txn_date, terminal_id, channel,
                source_name, amount, currency, match_status
         FROM receipts
         WHERE txn_date BETWEEN ? AND ?
           AND match_status = 'pending'
           AND direction = 'credit'"
        . $receipts_in_clause
    );
    $stmt->bind_param('ss', $buffer_from, $buffer_to);
    $stmt->execute();
    $receipts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // ── Lookups ──
    $agent_name_by_id = array();
    $a_res = $db->query("SELECT id, agent_name FROM agents");
    if ($a_res) {
        while ($a = $a_res->fetch_assoc()) $agent_name_by_id[(int)$a['id']] = (string)$a['agent_name'];
    }
    $terminal_reg = array();
    $treg_res = $db->query("SELECT terminal_id, currency, agent_id FROM pos_terminals");
    if ($treg_res) {
        while ($row = $treg_res->fetch_assoc()) $terminal_reg[$row['terminal_id']] = $row;
    }

    // ── Precompute every field score_pair() touches so the hot loop is
    // pure comparisons. This is the single biggest win in the engine:
    // score_pair gets called ~290K times on a monthly run, and re-running
    // regex/tokenization on each call dominated the previous runtime. ──
    set_progress($db, $run_id, 25, 'Indexing rows for scoring');
    $agent_tokens_by_id = array();
    precompute_match_fields($sales, $receipts, $agent_name_by_id, $agent_tokens_by_id);

    // ── Sort sales by amount for early-exit during scoring ──
    usort($sales, function($a, $b) {
        return ($a['_amt']) <=> ($b['_amt']);
    });

    $claimed_sales    = array();
    $claimed_receipts = array();

    $upd = $db->prepare(
        "UPDATE receipts
         SET matched_policy=?, matched_sale_id=?, match_status=?, match_confidence=?
         WHERE id=?"
    );
    $flag_sale = $db->prepare("UPDATE sales SET currency_flag=1 WHERE id=?");

    $total_sales    = count($sales);
    $total_receipts = count($receipts);
    $matched_count  = 0;
    $fx_flagged     = 0;
    $score_hist     = array('high'=>0, 'medium'=>0, 'low'=>0);
    $pass_counts    = array('p0'=>0, 'p1'=>0, 'p2'=>0, 'p3'=>0, 'p4'=>0, 'p5'=>0, 'p6'=>0);

    // ═══════════════════════════════════════════════════════════
    // PASS 0 — EXACT: same day + exact amount, claim any unclaimed match
    //
    // When a receipt's amount and date exactly match a sale's, we claim
    // it — even if multiple candidates exist. When many sales share the
    // same amount on the same day (e.g., 425 × 985.76 Zinara-fee sales),
    // picking any one satisfies book totals for variance reconciliation;
    // the pairings are mathematically indistinguishable.
    //
    // Single-candidate matches still get 'high' confidence; multi-candidate
    // matches get 'medium' since any specific pairing is arbitrary.
    // ═══════════════════════════════════════════════════════════
    set_progress($db, $run_id, 30, 'Pass 0: exact same-day matches');

    // Build three sale indexes once, reused by passes 0/2/3/4:
    //   $by_amt_date  — "rounded_amt|date"  → [sale, ...]   (exact-pair lookups)
    //   $by_amt       — rounded_amt         → [sale, ...]   (lonely-receipt / lonely-sale)
    //   $by_date      — "Y-m-d"             → [sale, ...]   (batch settlement pool)
    // Each sale gets referenced (small memory overhead — just array keys, not
    // copied rows, because PHP arrays are COW).
    $by_amt_date = array();
    $by_amt      = array();
    $by_date     = array();
    $by_amt_bucket = array(); // (int)floor(amt) => [sale, ...]  — Pass 1/6 range scans
    foreach ($sales as $sale) {
        $amt_raw = $sale['_amt'];
        $a = round($amt_raw, 2);
        $d = (string)($sale['txn_date'] ?? '');
        if ($a <= 0 || $d === '') continue;
        $by_amt_date[$a . '|' . $d][] = $sale;
        $by_amt[(string)$a][] = $sale;
        $by_date[$d][] = $sale;
        // Integer-floor bucketing: a receipt with amount 985.76 only needs to
        // scan buckets 966..1005 instead of the full 35K-row array. Passes 1
        // and 6 were spending most of their time on foreach-skip overhead
        // because sales sorted by amount still forces a linear walk.
        $by_amt_bucket[(int)$amt_raw][] = $sale;
    }
    foreach ($receipts as $receipt) {
        if (isset($claimed_receipts[$receipt['id']])) continue;
        $a = round((float)($receipt['amount'] ?? 0), 2);
        $d = (string)($receipt['txn_date'] ?? '');
        if ($a <= 0 || $d === '') continue;
        $key = $a . '|' . $d;
        if (!isset($by_amt_date[$key])) continue;
        // Pick the first unclaimed candidate
        $pick = null; $cand_count = 0;
        foreach ($by_amt_date[$key] as $s) {
            if (isset($claimed_sales[$s['id']])) continue;
            $cand_count++;
            if ($pick === null) $pick = $s;
        }
        if ($pick === null) continue;
        $confidence = ($cand_count === 1) ? 'high' : 'medium';
        if (do_claim($db, $upd, $flag_sale, $pick, $receipt, $confidence, null, $opts, $terminal_reg,
                     $claimed_sales, $claimed_receipts, $fx_flagged, $matched_count, $score_hist)) {
            $pass_counts['p0']++;
        }
    }

    // ═══════════════════════════════════════════════════════════
    // PASS 1 — MAIN SCORING
    // ═══════════════════════════════════════════════════════════
    set_progress($db, $run_id, 40, 'Pass 1: scoring pass');

    $processed = 0;
    $report_every = max(1, (int)floor($total_receipts / 20));
    // Cap candidates scored per receipt — prevents runaway when thousands of
    // sales share the same amount (e.g., 985.76 Zinara fee).
    $MAX_CANDIDATES = 150;

    // Pre-compute the date window in SECONDS so the inner loop does a single
    // integer compare instead of float division per candidate.
    $date_window_secs = $DATE_WINDOW_DAYS * 86400;

    foreach ($receipts as $receipt) {
        $processed++;
        if ($processed % $report_every === 0) {
            $pct = 40 + (int)floor(30 * ($processed / max(1, $total_receipts)));
            set_progress($db, $run_id, $pct, "Pass 1: scoring ($processed/$total_receipts)");
        }
        if (isset($claimed_receipts[$receipt['id']])) continue;
        $r_amt = $receipt['_amt'];
        if ($r_amt <= 0) continue;
        $r_ts = $receipt['_ts'];
        if (!$r_ts) continue;
        // Skip intercompany transfers — not customer payments, no sale will match.
        // `_source_upper` is precomputed, so this is a handful of strpos calls.
        $src_upper = $receipt['_source_upper'];
        if (strpos($src_upper, 'RTTF') !== false || strpos($src_upper, 'RTTI') !== false
            || strpos($src_upper, 'RTGS') !== false || strpos($src_upper, 'OMNI') !== false
            || strpos($src_upper, 'MAINTENANCE FEE') !== false) {
            continue;
        }

        $amt_tol = match_amount_tolerance($r_amt);
        $amt_lo  = $r_amt - $amt_tol;
        $amt_hi  = $r_amt + $amt_tol;
        $bucket_lo = (int)$amt_lo;
        $bucket_hi = (int)$amt_hi;

        $best_score = -1; $best_sale = null;
        $scored = 0;
        // Walk only the integer amount buckets that overlap the tolerance
        // window. On a 35K-sale dataset this reduces each receipt's scan
        // from ~35,000 rows to ~the number of sales in 40-ish dollar
        // buckets (usually a few hundred at most).
        for ($b = $bucket_lo; $b <= $bucket_hi; $b++) {
            if ($scored >= $MAX_CANDIDATES) break;
            if (!isset($by_amt_bucket[$b])) continue;
            foreach ($by_amt_bucket[$b] as $sale) {
                if ($scored >= $MAX_CANDIDATES) break;
                $s_amt = $sale['_amt'];
                if ($s_amt < $amt_lo || $s_amt > $amt_hi) continue;
                if ($s_amt <= 0) continue;
                if (isset($claimed_sales[$sale['id']])) continue;
                $s_ts = $sale['_ts'];
                if (!$s_ts) continue;
                if ($s_ts > $r_ts ? ($s_ts - $r_ts) > $date_window_secs
                                  : ($r_ts - $s_ts) > $date_window_secs) continue;

                try {
                    list($score, $_) = score_pair($sale, $receipt, $agent_tokens_by_id);
                } catch (Throwable $e) { continue; }
                $scored++;

                if ($score > $best_score) { $best_score = $score; $best_sale = $sale; }
                if ($best_score >= $HIGH_CONF_THRESHOLD) break 2;
            }
        }

        if ($best_sale !== null && $best_score >= $AUTO_THRESHOLD) {
            $confidence = ($best_score >= $HIGH_CONF_THRESHOLD) ? 'high'
                       : (($best_score >= $MED_CONF_THRESHOLD)  ? 'medium' : 'low');
            if (do_claim($db, $upd, $flag_sale, $best_sale, $receipt, $confidence, null, $opts, $terminal_reg,
                         $claimed_sales, $claimed_receipts, $fx_flagged, $matched_count, $score_hist)) {
                $pass_counts['p1']++;
            }
        }
    }

    // ═══════════════════════════════════════════════════════════
    // PASS 2 — LONELY RECEIPT: one exact-amount candidate in ±14 days
    // ═══════════════════════════════════════════════════════════
    set_progress($db, $run_id, 72, 'Pass 2: lonely-receipt pass');

    $lonely_secs = $LONELY_WINDOW_DAYS * 86400;

    // Build an amount-indexed receipt map so Pass 3 doesn't rescan all receipts.
    $receipts_by_amt = array();
    foreach ($receipts as $receipt) {
        $a = round($receipt['_amt'], 2);
        if ($a <= 0) continue;
        $receipts_by_amt[(string)$a][] = $receipt;
    }

    foreach ($receipts as $receipt) {
        if (isset($claimed_receipts[$receipt['id']])) continue;
        $r_amt = round($receipt['_amt'], 2);
        if ($r_amt <= 0) continue;
        $r_ts = $receipt['_ts'];
        if (!$r_ts) continue;

        $bucket = isset($by_amt[(string)$r_amt]) ? $by_amt[(string)$r_amt] : null;
        if ($bucket === null) continue;

        $cands = array();
        foreach ($bucket as $sale) {
            if (isset($claimed_sales[$sale['id']])) continue;
            $s_ts = $sale['_ts'];
            if (!$s_ts) continue;
            $dt = $s_ts > $r_ts ? $s_ts - $r_ts : $r_ts - $s_ts;
            if ($dt > $lonely_secs) continue;
            $cands[] = $sale;
            if (count($cands) > 1) break; // need exactly one
        }
        if (count($cands) === 1) {
            if (do_claim($db, $upd, $flag_sale, $cands[0], $receipt, 'medium', null, $opts, $terminal_reg,
                         $claimed_sales, $claimed_receipts, $fx_flagged, $matched_count, $score_hist)) {
                $pass_counts['p2']++;
            }
        }
    }

    // ═══════════════════════════════════════════════════════════
    // PASS 3 — LONELY SALE: one exact-amount unmatched receipt in ±14 days
    // ═══════════════════════════════════════════════════════════
    set_progress($db, $run_id, 78, 'Pass 3: lonely-sale pass');

    foreach ($sales as $sale) {
        if (isset($claimed_sales[$sale['id']])) continue;
        $s_amt = round($sale['_amt'], 2);
        if ($s_amt <= 0) continue;
        $s_ts = $sale['_ts'];
        if (!$s_ts) continue;

        $bucket = isset($receipts_by_amt[(string)$s_amt]) ? $receipts_by_amt[(string)$s_amt] : null;
        if ($bucket === null) continue;

        $cands = array();
        foreach ($bucket as $receipt) {
            if (isset($claimed_receipts[$receipt['id']])) continue;
            $r_ts = $receipt['_ts'];
            if (!$r_ts) continue;
            $dt = $s_ts > $r_ts ? $s_ts - $r_ts : $r_ts - $s_ts;
            if ($dt > $lonely_secs) continue;
            $cands[] = $receipt;
            if (count($cands) > 1) break;
        }
        if (count($cands) === 1) {
            if (do_claim($db, $upd, $flag_sale, $sale, $cands[0], 'medium', null, $opts, $terminal_reg,
                         $claimed_sales, $claimed_receipts, $fx_flagged, $matched_count, $score_hist)) {
                $pass_counts['p3']++;
            }
        }
    }

    // ═══════════════════════════════════════════════════════════
    // PASS 4 — BATCH: 1 receipt = sum of N unmatched sales ±2 days
    // ═══════════════════════════════════════════════════════════
    set_progress($db, $run_id, 83, 'Pass 4: batch settlement');

    $unmatched_receipts = array();
    foreach ($receipts as $r) {
        if (isset($claimed_receipts[$r['id']])) continue;
        if ($r['_amt'] < $BATCH_MIN_AMOUNT) continue;
        $src_upper = $r['_source_upper'];
        if (strpos($src_upper, 'RTTF') !== false || strpos($src_upper, 'RTTI') !== false
            || strpos($src_upper, 'RTGS') !== false || strpos($src_upper, 'OMNI') !== false) {
            continue;
        }
        $unmatched_receipts[] = $r;
    }
    usort($unmatched_receipts, function($a, $b) {
        return $b['_amt'] <=> $a['_amt'];
    });

    foreach ($unmatched_receipts as $receipt) {
        if (isset($claimed_receipts[$receipt['id']])) continue;
        $r_amt = $receipt['_amt'];
        $r_ts  = $receipt['_ts'];
        if (!$r_ts) continue;
        // Larger amounts often span bigger time windows (bulk weekly deposits)
        $batch_days = ($r_amt >= 10000) ? 7 : 2;
        $batch_tol  = max(5.0, $r_amt * 0.005);
        $s_amt_hi   = $r_amt + $batch_tol;

        // Walk only the dates in the window instead of rescanning all sales —
        // on 35K-sale datasets this cuts Pass 4 from ~70s to a few seconds
        // (each date bucket averages ~500 sales vs 35,000 in the full array).
        $pool = array();
        for ($day = -$batch_days; $day <= $batch_days; $day++) {
            $d = date('Y-m-d', $r_ts + $day * 86400);
            if (!isset($by_date[$d])) continue;
            foreach ($by_date[$d] as $sale) {
                if (isset($claimed_sales[$sale['id']])) continue;
                $s_amt = $sale['_amt'];
                if ($s_amt <= 0 || $s_amt > $s_amt_hi) continue;
                $pool[] = array('id'=>$sale['id'], 'amt'=>$s_amt, 'policy'=>$sale['policy_number'],
                                'sale_obj'=>$sale);
            }
        }
        if (count($pool) < 2) continue;
        usort($pool, function($a, $b) { return $b['amt'] <=> $a['amt']; });

        $running = 0.0; $batch = array();
        foreach ($pool as $s) {
            if ($running + $s['amt'] > $r_amt + $batch_tol) continue;
            $running += $s['amt'];
            $batch[] = $s;
            if (abs($running - $r_amt) <= $batch_tol) break;
            if (count($batch) > 200) break;
        }

        if (abs($running - $r_amt) <= $batch_tol && count($batch) >= 2) {
            $batch_label = 'BATCH-' . date('Ymd', $r_ts) . '-' . count($batch);
            foreach ($batch as $s) $claimed_sales[$s['id']] = true;
            try {
                $upd->bind_param('sissi', $batch_label, $batch[0]['id'], 'matched', 'medium', $receipt['id']);
                $upd->execute();
                $claimed_receipts[$receipt['id']] = true;
                $matched_count += count($batch);
                $score_hist['medium']++;
                $pass_counts['p4'] += count($batch);
            } catch (Throwable $e) { /* skip */ }
        }
    }

    // ═══════════════════════════════════════════════════════════
    // PASS 5 — SPLIT: 1 sale = sum of N unmatched receipts same day + channel
    // ═══════════════════════════════════════════════════════════
    set_progress($db, $run_id, 87, 'Pass 5: split payments');

    foreach ($sales as $sale) {
        if (isset($claimed_sales[$sale['id']])) continue;
        $s_amt = (float)($sale['amount'] ?? 0);
        if ($s_amt <= 0) continue;
        $nch   = $sale['_chan_norm'];
        $sdate = (string)($sale['txn_date'] ?? '');
        if ($sdate === '') continue;

        $pool = array();
        foreach ($receipts as $r) {
            if (isset($claimed_receipts[$r['id']])) continue;
            if ((string)($r['txn_date'] ?? '') !== $sdate) continue;
            if ($r['_chan_norm'] !== $nch) continue;
            $pool[] = array('id'=>$r['id'], 'amt'=>$r['_amt'], 'receipt_obj'=>$r);
            if (count($pool) >= 8) break;
        }
        if (count($pool) < 2) continue;

        $found = null; $n = count($pool);
        for ($i = 0; $i < $n && !$found; $i++) {
            for ($j = $i + 1; $j < $n && !$found; $j++) {
                if (abs($pool[$i]['amt'] + $pool[$j]['amt'] - $s_amt) < 0.01) {
                    $found = array($pool[$i], $pool[$j]);
                    break;
                }
                for ($k = $j + 1; $k < $n && !$found; $k++) {
                    if (abs($pool[$i]['amt'] + $pool[$j]['amt'] + $pool[$k]['amt'] - $s_amt) < 0.01) {
                        $found = array($pool[$i], $pool[$j], $pool[$k]);
                        break;
                    }
                }
            }
        }
        if ($found) {
            try {
                foreach ($found as $r) {
                    $upd->bind_param('sissi', $sale['policy_number'], $sale['id'],
                                     'matched', 'medium', $r['id']);
                    $upd->execute();
                    $claimed_receipts[$r['id']] = true;
                    $matched_count++;
                    $score_hist['medium']++;
                    $pass_counts['p5']++;
                }
                $claimed_sales[$sale['id']] = true;
            } catch (Throwable $e) { /* skip */ }
        }
    }

    // ═══════════════════════════════════════════════════════════
    // PASS 6 — FUZZY FALLBACK: widened window + lower threshold
    // Anything score >= 35 within ±10 days, amount ±5%, matches with 'low' confidence
    // CAPPED at 50 candidates per receipt to prevent runaway on large datasets.
    // ═══════════════════════════════════════════════════════════
    set_progress($db, $run_id, 91, 'Pass 6: fuzzy fallback');
    $FUZZY_MAX_CANDIDATES = 50;

    $fuzzy_secs = $FUZZY_WINDOW_DAYS * 86400;
    foreach ($receipts as $receipt) {
        if (isset($claimed_receipts[$receipt['id']])) continue;
        $r_amt = $receipt['_amt'];
        if ($r_amt <= 0) continue;
        $r_ts = $receipt['_ts'];
        if (!$r_ts) continue;

        $fuzzy_tol = max(1.0, $r_amt * 0.05); // 5%
        $amt_lo = $r_amt - $fuzzy_tol;
        $amt_hi = $r_amt + $fuzzy_tol;
        $bucket_lo = (int)$amt_lo;
        $bucket_hi = (int)$amt_hi;

        $best_score = -1; $best_sale = null;
        $scored = 0;
        $fuzzy_done = false;
        for ($b = $bucket_lo; $b <= $bucket_hi && !$fuzzy_done; $b++) {
            if ($scored >= $FUZZY_MAX_CANDIDATES) break;
            if (!isset($by_amt_bucket[$b])) continue;
            foreach ($by_amt_bucket[$b] as $sale) {
                if ($scored >= $FUZZY_MAX_CANDIDATES) { $fuzzy_done = true; break; }
                $s_amt = $sale['_amt'];
                if ($s_amt < $amt_lo || $s_amt > $amt_hi) continue;
                if ($s_amt <= 0) continue;
                if (isset($claimed_sales[$sale['id']])) continue;
                $s_ts = $sale['_ts'];
                if (!$s_ts) continue;
                $dt = $s_ts > $r_ts ? $s_ts - $r_ts : $r_ts - $s_ts;
                if ($dt > $fuzzy_secs) continue;

                try {
                    list($score, $_) = score_pair($sale, $receipt, $agent_tokens_by_id);
                } catch (Throwable $e) { continue; }
                $scored++;

                if ($score > $best_score) { $best_score = $score; $best_sale = $sale; }
                if ($best_score >= $MED_CONF_THRESHOLD) { $fuzzy_done = true; break; }
            }
        }

        if ($best_sale !== null && $best_score >= $FUZZY_THRESHOLD) {
            if (do_claim($db, $upd, $flag_sale, $best_sale, $receipt, 'low', null, $opts, $terminal_reg,
                         $claimed_sales, $claimed_receipts, $fx_flagged, $matched_count, $score_hist)) {
                $pass_counts['p6']++;
            }
        }
    }

    $upd->close();
    $flag_sale->close();

    $summary = "Matched {$matched_count}/{$total_sales} "
             . "(H:{$score_hist['high']} M:{$score_hist['medium']} L:{$score_hist['low']} "
             . "| P0:{$pass_counts['p0']} P1:{$pass_counts['p1']} P2:{$pass_counts['p2']} "
             . "P3:{$pass_counts['p3']} P4:{$pass_counts['p4']} P5:{$pass_counts['p5']} P6:{$pass_counts['p6']})";
    set_progress($db, $run_id, 94, substr($summary, 0, 200));

    return array(
        'total_sales'    => $total_sales,
        'total_receipts' => $total_receipts,
        'matched_count'  => $matched_count,
        'fx_flagged'     => $fx_flagged,
    );
}
