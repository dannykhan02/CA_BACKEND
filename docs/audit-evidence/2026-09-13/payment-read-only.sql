-- Owner-run investigation ONLY. No repair, crediting, replay or seeding.
-- Run against each explicitly selected Neon branch and retain its endpoint/
-- branch identity with the output; current_database() alone cannot identify it.
-- Requires the current credit/referral schema. Inspect the catalog first as
-- described in PAYMENT_LIVE_EVIDENCE_2026_09_13.md; stop if missing.
-- Lists are capped at 200 rows; expand a time window only for the affected dates.
BEGIN TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY;
SET LOCAL statement_timeout = '15s';
SET LOCAL lock_timeout = '2s';

SELECT now() AS captured_at, current_database() AS database_name,
       current_setting('transaction_read_only') AS read_only;

-- 1. Recent non-completed purchases and safe stored-provider indicators.
-- A stored success flag is an investigation lead, not independent proof of
-- real payment or the absence of an earlier credit grant.
SELECT cp.id AS purchase_id, cp.paystack_reference, cp.user_id,
       cp.workspace_id AS purchased_workspace_id,
       u.current_workspace_id AS buyer_current_workspace_id,
       cp.status, cp.documents_purchased, cp.amount_kobo_or_cents, cp.currency,
       cp.created_at, cp.updated_at,
       (cp.paystack_response IS NOT NULL) AS has_provider_snapshot,
       CASE WHEN cp.paystack_response::jsonb #>> '{data,status}' IN
            ('success','failed','pending','ongoing','abandoned','processing','queued','reversed')
            THEN cp.paystack_response::jsonb #>> '{data,status}' ELSE NULL END AS stored_provider_status,
       CASE WHEN cp.paystack_response::jsonb #>> '{data,domain}' IN ('test','live')
            THEN cp.paystack_response::jsonb #>> '{data,domain}' ELSE NULL END AS stored_provider_mode,
       (cp.paystack_response::jsonb #>> '{data,reference}' = cp.paystack_reference) AS stored_reference_matches,
       jsonb_typeof(cp.paystack_response::jsonb #> '{data,amount}') AS stored_amount_json_type,
       CASE WHEN cp.paystack_response::jsonb #>> '{data,amount}' ~ '^[0-9]+([.][0-9]+)?$'
            THEN (cp.paystack_response::jsonb #>> '{data,amount}')::numeric = cp.amount_kobo_or_cents
            ELSE NULL END AS stored_amount_value_matches,
       (cp.paystack_response::jsonb #>> '{data,currency}' = cp.currency) AS stored_currency_matches,
       (wc.id IS NOT NULL) AS has_credit_row
FROM credit_purchases cp
LEFT JOIN users u ON u.id = cp.user_id
LEFT JOIN workspace_credits wc ON wc.workspace_id = cp.workspace_id
WHERE cp.status <> 'completed'
  AND cp.created_at >= now() - interval '30 days'
ORDER BY cp.created_at DESC LIMIT 200;

-- 2. Pending/failed rows with a stored success indication, irrespective of age.
SELECT id AS purchase_id, paystack_reference, user_id, workspace_id,
       status, documents_purchased, created_at, updated_at
FROM credit_purchases
WHERE status <> 'completed'
  AND paystack_response::jsonb #>> '{data,status}' = 'success'
ORDER BY updated_at DESC LIMIT 200;

-- 3. Duplicate references should be impossible with the committed unique index.
SELECT paystack_reference, count(*) AS purchase_count,
       array_agg(id ORDER BY id) AS purchase_ids
FROM credit_purchases
GROUP BY paystack_reference HAVING count(*) > 1
ORDER BY purchase_count DESC LIMIT 200;

-- 4. The lifetime purchased counter is distinct from spendable credits.
-- Normal document usage, trials and referrals do NOT change this counter.
-- A mismatch flags aggregate inconsistency; it cannot identify which individual
-- purchase was missed, nor establish the history of manual edits/deleted rows.
WITH purchases AS (
    SELECT workspace_id, count(*) AS completed_count,
           sum(documents_purchased)::bigint AS completed_documents
    FROM credit_purchases WHERE status = 'completed' GROUP BY workspace_id
)
SELECT coalesce(p.workspace_id, wc.workspace_id) AS workspace_id,
       coalesce(p.completed_count, 0) AS completed_purchase_count,
       coalesce(p.completed_documents, 0) AS expected_purchased_total_from_retained_rows,
       wc.documents_purchased_total AS recorded_purchased_total,
       wc.documents_remaining, wc.created_at AS credit_row_created_at,
       wc.updated_at AS credit_row_updated_at, (wc.id IS NULL) AS credit_row_missing
FROM purchases p FULL JOIN workspace_credits wc ON wc.workspace_id = p.workspace_id
WHERE wc.id IS NULL
   OR coalesce(p.completed_documents, 0) <> wc.documents_purchased_total
ORDER BY coalesce(p.workspace_id, wc.workspace_id) LIMIT 200;

-- 5. Recent purchase attribution, including COMPLETED rows that the UI may
-- be displaying under a different currently selected workspace.
SELECT cp.id AS purchase_id, cp.paystack_reference, cp.user_id, cp.status,
       cp.workspace_id AS purchased_workspace_id, u.current_workspace_id,
       (cp.workspace_id IS DISTINCT FROM u.current_workspace_id) AS current_workspace_differs,
       EXISTS (SELECT 1 FROM workspace_members wm
               WHERE wm.workspace_id = cp.workspace_id AND wm.user_id = cp.user_id) AS purchaser_still_member,
       cp.documents_purchased, wc.documents_remaining, wc.documents_purchased_total,
       cp.created_at, cp.updated_at, (wc.id IS NULL) AS credit_row_missing
FROM credit_purchases cp LEFT JOIN users u ON u.id = cp.user_id
LEFT JOIN workspace_credits wc ON wc.workspace_id = cp.workspace_id
WHERE cp.created_at >= now() - interval '30 days'
ORDER BY cp.created_at DESC LIMIT 200;

-- 6. Referral-state anomalies/leads; zero-valued configured rewards are valid.
-- Pending+eligible+completed is a review flag, not automatic entitlement.
-- Deleted referred users retain earned history and must not be misclassified.
SELECT r.id AS referral_id, r.referred_user_id, rc.user_id AS referrer_user_id,
       r.status, r.reward_eligible, r.reward_documents,
       r.rewarded_workspace_id, r.rewarded_at, r.created_at,
       (r.referred_user_id IS NULL) AS referred_account_deleted,
       EXISTS (SELECT 1 FROM credit_purchases cp
               WHERE cp.user_id = r.referred_user_id AND cp.status = 'completed') AS has_completed_purchase,
       (wc.id IS NOT NULL) AS reward_workspace_has_credit_row,
       wc.documents_remaining AS reward_workspace_current_balance
FROM referrals r JOIN referral_codes rc ON rc.id = r.referral_code_id
LEFT JOIN workspace_credits wc ON wc.workspace_id = r.rewarded_workspace_id
WHERE (r.status = 'rewarded' AND (r.rewarded_at IS NULL OR r.rewarded_workspace_id IS NULL OR wc.id IS NULL))
   OR (r.status = 'pending' AND (r.rewarded_at IS NOT NULL OR r.rewarded_workspace_id IS NOT NULL OR r.reward_documents <> 0))
   OR (r.status = 'pending' AND r.reward_eligible AND EXISTS (
       SELECT 1 FROM credit_purchases cp WHERE cp.user_id = r.referred_user_id AND cp.status = 'completed'))
   OR (r.status = 'rewarded' AND r.referred_user_id IS NOT NULL AND NOT EXISTS (
       SELECT 1 FROM credit_purchases cp WHERE cp.user_id = r.referred_user_id AND cp.status = 'completed'))
ORDER BY r.created_at DESC LIMIT 200;

-- 7. Existing history inventory. No metadata, IP, email or provider data output.
-- Current application code does not write purchase/credit/referral audit events;
-- inspect what the actual database contains before relying on their absence.
SELECT action, auditable_type, count(*) AS event_count,
       min(created_at) AS first_at, max(created_at) AS last_at
FROM audit_logs
WHERE action ~* '(credit|purchas|referral|balance)'
   OR auditable_type IN ('App\Models\CreditPurchase', 'App\Models\WorkspaceCredit', 'App\Models\Referral')
GROUP BY action, auditable_type ORDER BY action LIMIT 200;

-- 8. Retained inputs for balance review, NOT a computed expected balance.
-- trial_grants stores no amount; historical config is required. Ready markers
-- include migration backfills and zero-balance completions, and hard deletions
-- can remove usage history. Referral-owner deletion can cascade reward history.
WITH paid AS (
    SELECT workspace_id, sum(documents_purchased)::bigint AS completed_documents
    FROM credit_purchases WHERE status = 'completed' GROUP BY workspace_id
), rewards AS (
    SELECT rewarded_workspace_id AS workspace_id, sum(reward_documents)::bigint AS retained_reward_documents
    FROM referrals WHERE status = 'rewarded' GROUP BY rewarded_workspace_id
), trials AS (
    SELECT workspace_id, count(*) AS trial_grant_count FROM trial_grants GROUP BY workspace_id
), usage AS (
    SELECT workspace_id, count(*) AS retained_accounted_document_markers
    FROM documents WHERE credit_accounted_at IS NOT NULL GROUP BY workspace_id
)
SELECT wc.workspace_id, wc.documents_remaining, wc.documents_purchased_total,
       coalesce(paid.completed_documents, 0) AS retained_completed_documents,
       coalesce(rewards.retained_reward_documents, 0) AS retained_referral_documents,
       coalesce(trials.trial_grant_count, 0) AS trial_grant_count_without_amount_snapshot,
       coalesce(usage.retained_accounted_document_markers, 0) AS document_markers_not_proven_debit_count
FROM workspace_credits wc
LEFT JOIN paid ON paid.workspace_id = wc.workspace_id
LEFT JOIN rewards ON rewards.workspace_id = wc.workspace_id
LEFT JOIN trials ON trials.workspace_id = wc.workspace_id
LEFT JOIN usage ON usage.workspace_id = wc.workspace_id
WHERE paid.workspace_id IS NOT NULL OR rewards.workspace_id IS NOT NULL
ORDER BY wc.workspace_id LIMIT 200;

COMMIT;
