# Specific payment investigation — awaiting production read-only output

Reference: `credits-e5401cf0-dd11-4666-a6a2-69b4d9879361`.

Opened 2026-09-13 against local source/evidence commit `73b37a2`. The account owner reports that Paystack shows this reference as a successful payment. This is newly supplied owner evidence, superseding the earlier investigation's statement that no affected reference had yet been supplied. No production database result, provider payload, transaction mode or delivery history has been supplied for this case.

## Evidence boundary and branch identity

The agent has local repository/shell access only and has **not queried a live database**. The production branch to select and confirm in the Neon SQL editor is:

- Project: `fragrant-cherry-99998400`.
- Production branch: `br-divine-lab-axjoi7y0`.
- Expected production endpoint: `ep-shy-paper-axsw5ecr.c-4.us-east-2.aws.neon.tech`.

These are expected values from prior owner evidence, not a fresh confirmation of the query session. Return the actual selected branch ID and endpoint alongside query outputs and capture time. `current_database() = neondb` alone cannot establish branch identity. Do not use staging for this case; its 2026-09-11 fork may contain inherited rows.

No application verification POST, payment-return navigation, webhook replay, repair, balance adjustment or workspace change is authorized in this investigation. None was performed. Do not run the broad all-purchase listings for this single-reference question.

## Owner-run sequence

1. In the explicitly selected production branch above, run the **first SQL block in section 3** of [the existing live evidence procedure](../../PAYMENT_LIVE_EVIDENCE_2026_09_13.md). Return its catalog/index/migration output and branch/endpoint identity. Stop if required columns are missing or an error occurs; the lookup has not yet been run by the agent.
2. After the schema preflight is confirmed, run the existing procedure's single-reference lookup below. Only its reference literal has been substituted.

```sql
BEGIN TRANSACTION READ ONLY;
SET LOCAL statement_timeout = '15s';
SELECT cp.id AS purchase_id, cp.paystack_reference, cp.user_id,
       cp.workspace_id, u.current_workspace_id, cp.status,
       cp.documents_purchased, cp.amount_kobo_or_cents, cp.currency,
       cp.created_at, cp.updated_at, wc.documents_remaining, wc.documents_purchased_total
FROM credit_purchases cp
LEFT JOIN users u ON u.id = cp.user_id
LEFT JOIN workspace_credits wc ON wc.workspace_id = cp.workspace_id
WHERE cp.paystack_reference = 'credits-e5401cf0-dd11-4666-a6a2-69b4d9879361';
COMMIT;
```

Return the actual outputs, including zero rows or null values. A null credit balance from this LEFT JOIN is not a measured zero balance. A null purchaser/current workspace does not establish that the user switched workspaces.

## Interpretation rules, not current findings

| Production result | Conclusion to draw from actual evidence |
| --- | --- |
| Completed purchase, non-null different workspace IDs | Confirms purchase/current-workspace attribution mismatch for this reference at inspection time. Current source credits the original workspace and reads the current one for display. Report the original balance/counter; do not infer a specific user's historical screen or the timing of the change solely from the IDs. |
| Completed purchase, same workspace IDs | No current workspace mismatch; that hypothesis does not explain the present snapshot. It does not rule out a temporary historical switch that was later reversed. |
| Pending or failed despite owner-confirmed provider success | Distinct provider-success/application-fulfillment discrepancy, even if IDs also differ. The application did not record a committed completion; this alone does not distinguish absent delivery, rejection, rollback or wrong deployed routing. |
| No matching production row | Reference absent in this production query; cannot classify status or balances. Do not substitute an inherited staging row as production evidence. |
| Completed with missing credit row or suspicious lifetime counter | Additional state-integrity discrepancy; do not conclude that a workspace mismatch alone explains the incident. Aggregate reconciliation may be needed as further read-only investigation. |

## Timing limits

`credit_purchases.created_at` dates creation; `updated_at` is a general last-modification timestamp, not a dedicated completion timestamp. The current source has no `completed_at` column or `current_workspace_changed_at` marker. `users.updated_at`, if later inspected, likewise does not identify which user field changed. No workspace-change audit call was found in the inspected `WorkspaceService::createPersonalWorkspaceFor()` path.

Therefore the requested single-reference query cannot by itself establish whether a workspace change happened before or after completion. If the result supports a mismatch, inspect available, narrowly scoped history before making that chronological claim. Do not print full audit metadata/provider payloads to search for it.

## Current findings

| Question | Evidence-backed state |
| --- | --- |
| Paystack success | Reported confirmed by account owner |
| Actual queried production branch | Not yet verified; no live query executed by agent |
| Purchase status | Unknown pending production lookup |
| Purchase/current workspace match | Unknown pending production lookup |
| Original workspace remaining/purchased credits | Unknown pending production lookup |
| Workspace change before/after completion | Not established; purchase timestamps alone are insufficient |
| Attribution hypothesis for this specific reference | Not yet confirmed or rejected |

This task ends with investigation and interpretation. No repair is proposed. If the eventual evidence warrants a repair, it requires separate follow-up authorization under section 7 of the lifecycle report.
