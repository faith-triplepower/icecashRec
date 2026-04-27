-- ============================================================
-- Split-payment support
--
-- Allows up to 10 receipts to settle one sale within the same
-- calendar month. A sale is considered "covered" only when the
-- sum of attached receipts >= sale.amount (overpayment OK).
-- Mixed-currency allocations are flagged for reconciler review
-- before they count as paid.
-- ============================================================

USE icecash_recon;

-- 1. New receipt match_status values.
--    'partial'         : receipt is allocated to a sale that is not yet fully paid.
--    'currency_review' : receipt is allocated but its currency differs from the sale's;
--                        a reconciler must approve before this counts toward coverage.
ALTER TABLE receipts
    MODIFY COLUMN match_status
        ENUM('matched','pending','variance','excluded','partial','currency_review')
        NOT NULL DEFAULT 'pending';

-- 2. Denormalised payment state on sales — avoids re-summing allocations
--    on every dashboard query.
ALTER TABLE sales
    ADD COLUMN paid_status
        ENUM('unpaid','partial','paid','overpaid','currency_review')
        NOT NULL DEFAULT 'unpaid'
        AFTER currency_flag,
    ADD INDEX idx_paid_status (paid_status);

-- 3. Index used by the split-payment tier and by paid_status recomputation
--    (both group receipts by matched_sale_id).
ALTER TABLE receipts
    ADD INDEX idx_matched_sale_status (matched_sale_id, match_status);

-- 4. Backfill paid_status for historical sales based on current allocations.
--    Uses the same rules the engine will apply going forward.
UPDATE sales s
LEFT JOIN (
    SELECT matched_sale_id,
           SUM(amount) AS total_recv,
           SUM(currency <> (
               SELECT currency FROM sales WHERE id = receipts.matched_sale_id
           )) AS mixed_cnt
      FROM receipts
     WHERE matched_sale_id IS NOT NULL
       AND match_status IN ('matched','variance','partial','currency_review')
     GROUP BY matched_sale_id
) r ON r.matched_sale_id = s.id
SET s.paid_status = CASE
    WHEN r.total_recv IS NULL                 THEN 'unpaid'
    WHEN r.mixed_cnt > 0                      THEN 'currency_review'
    WHEN r.total_recv <  s.amount             THEN 'partial'
    WHEN r.total_recv =  s.amount             THEN 'paid'
    ELSE 'overpaid'
END;
