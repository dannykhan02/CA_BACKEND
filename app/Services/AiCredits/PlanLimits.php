<?php

namespace App\Services\AiCredits;

use App\Models\Document;
use App\Services\Documents\SupportedDocumentTypes;
use App\Services\EntitlementService;

/**
 * What a workspace's plan allows: document types, OCR and the largest quote. Limits come from ai_credits.plan_limits (env), and
 * their defaults allow everything, so nothing is limited until the values are set. A workspace without an active paid
 * subscription period is 'free'; the monthly and annual variants of a plan share its tier. Legacy-mode workspaces are not limited.
 */
class PlanLimits
{
    /** Cheapest first: the first plan that clears a limit is the "required plan". */
    public const LADDER = ['free', 'starter', 'professional'];

    public function applies(string $workspaceId): bool
    {
        return QuoteService::enabled() && app(CreditAccountant::class)->mode($workspaceId) === 'credits';
    }

    public function planFor(string $workspaceId): string
    {
        $ent = app(EntitlementService::class);
        $sub = $ent->subscription($workspaceId);
        if (! $ent->paid($sub) || ! $ent->period($sub)) {
            return 'free';
        }

        return in_array($sub->plan_key, self::LADDER, true) ? $sub->plan_key : 'starter';
    }

    /** @return array{allowed_types:array<int,string>,allow_ocr:bool,max_quote_tokens:?int,max_ocr_pages:int} allowed_types are document type keys (PDF, DOCX, ...); empty = all */
    public function limits(string $plan): array
    {
        $config = config("ai_credits.plan_limits.{$plan}") ?? [];
        $types = app(SupportedDocumentTypes::class);
        $allowed = [];
        foreach (explode(',', strtolower((string) ($config['allowed_types'] ?? ''))) as $extension) {
            $type = trim($extension) === '' ? null : $types->typeForExtension(trim($extension));
            if ($type !== null) {
                $allowed[$type] = $type;
            }
        }

        return ['allowed_types' => array_values($allowed), 'allow_ocr' => (bool) ($config['allow_ocr'] ?? true),
            'max_quote_tokens' => isset($config['max_quote_tokens']) ? (int) $config['max_quote_tokens'] : null,
            'max_ocr_pages' => max(0, (int) ($config['max_ocr_pages'] ?? 0))];
    }

    public function allowsType(string $plan, string $type): bool
    {
        $allowed = $this->limits($plan)['allowed_types'];

        return $allowed === [] || in_array($type, $allowed, true);
    }

    /** The cheapest plan above $plan for which $clears(limits) holds, or null when none does. */
    private function requiredPlan(string $plan, callable $clears): ?string
    {
        foreach (array_slice(self::LADDER, (int) array_search($plan, self::LADDER, true) + 1) as $candidate) {
            if ($clears($this->limits($candidate))) {
                return $candidate;
            }
        }

        return null;
    }

    /** Null when the upload is allowed; otherwise the 403 body for it. */
    public function uploadBlock(string $workspaceId, string $type): ?array
    {
        if (! $this->applies($workspaceId)) {
            return null;
        }
        $plan = $this->planFor($workspaceId);
        if ($this->allowsType($plan, $type)) {
            return null;
        }

        return ['code' => 'plan_upgrade_required', 'reason' => 'file_type', 'type' => $type, 'plan' => $plan,
            'required_plan' => $this->requiredPlan($plan, fn (array $l) => $l['allowed_types'] === [] || in_array($type, $l['allowed_types'], true))];
    }

    /**
     * Null when OCR may run; otherwise the credit_block that stops the document before any OCR or AI call. $ocrPages is how many
     * pages need OCR. A plan without OCR still reads a PDF when 1 <= $ocrPages <= its max_ocr_pages and fewer than all pages need
     * it (at least one page has native text); an unknown page count or $ocrPages never qualifies.
     */
    public function ocrBlock(Document $document, ?int $ocrPages = null): ?array
    {
        if (! $this->applies($document->workspace_id)) {
            return null;
        }
        $plan = $this->planFor($document->workspace_id);
        $limits = $this->limits($plan);
        if ($limits['allow_ocr']) {
            return null;
        }
        $total = (int) $document->pages;
        if ($document->type === 'PDF' && $ocrPages !== null && $ocrPages >= 1 && $ocrPages <= $limits['max_ocr_pages'] && $total > 0 && $ocrPages < $total) {
            return null;
        }

        return ['code' => 'plan_limit', 'reason' => 'ocr_required', 'plan' => $plan,
            'required_plan' => $this->requiredPlan($plan, fn (array $l) => $l['allow_ocr']), 'at' => now()->toIso8601String()];
    }

    /** Null when the quote fits the plan; otherwise the credit_block that refuses the document at admission. */
    public function sizeBlock(Document $document, int $quoteTokens): ?array
    {
        if (! $this->applies($document->workspace_id)) {
            return null;
        }
        $plan = $this->planFor($document->workspace_id);
        $limit = $this->limits($plan)['max_quote_tokens'];
        if ($limit === null || $quoteTokens <= $limit) {
            return null;
        }

        return ['code' => 'plan_limit', 'reason' => 'document_size', 'quote_tokens' => $quoteTokens, 'limit_tokens' => $limit, 'plan' => $plan,
            'required_plan' => $this->requiredPlan($plan, fn (array $l) => $l['max_quote_tokens'] === null || $quoteTokens <= $l['max_quote_tokens']),
            'at' => now()->toIso8601String()];
    }

    /** The entitlement summary block for a plan. */
    public function summary(string $workspaceId): array
    {
        $plan = $this->planFor($workspaceId);
        $limits = $this->limits($plan);

        return ['plan' => $plan, 'allowedTypes' => $limits['allowed_types'], 'allowOcr' => $limits['allow_ocr'], 'maxQuoteTokens' => $limits['max_quote_tokens']];
    }
}
