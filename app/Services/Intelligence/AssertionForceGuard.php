<?php

namespace App\Services\Intelligence;

/** Checks explicit force and qualification in the cited record's own quotation. */
class AssertionForceGuard
{
    public function compatible(string $claim, array $record): bool
    {
        $quote = (string) ($record['data']['quote'] ?? '');
        if ($quote === '') {
            return true;
        }
        if (($record['kind'] ?? null) === 'obligation') {
            $sourceForce = $this->force($quote);
            $claimForce = $this->force($claim);
            if ($sourceForce !== null && $claimForce !== null && $sourceForce !== $claimForce) {
                return false;
            }
            if ($sourceForce !== null && $claimForce !== null
                && $this->negated($quote) !== $this->negated($claim)) {
                return false;
            }
            if ($sourceForce !== null && $claimForce !== null
                && preg_match('/\b(?:unless|except(?:\s+where|\s+when|\s+that)?)\b/iu', $quote)
                && ! preg_match('/\b(?:unless|except(?:\s+where|\s+when|\s+that)?)\b/iu', $claim)) {
                return false;
            }
        }
        foreach ([['actual', 'estimated'], ['original', 'restated'], ['current', 'historical']] as [$left, $right]) {
            if ($this->has($quote, $left) && $this->has($claim, $right)
                || $this->has($quote, $right) && $this->has($claim, $left)) {
                return false;
            }
        }

        return true;
    }

    private function force(string $text): ?string
    {
        if (preg_match('/\b(?:shall|must)\b/iu', $text)) {
            return 'mandatory';
        }
        if (preg_match('/\bmay\b/u', $text)) {
            return 'permitted';
        }
        if (preg_match('/\bshould\b/iu', $text)) {
            return 'advisory';
        }

        return null;
    }

    private function negated(string $text): bool
    {
        return preg_match('/\b(?:shall|must|may|should)\s+not\b/iu', $text) === 1;
    }

    private function has(string $text, string $word): bool
    {
        return preg_match('/\b'.preg_quote($word, '/').'\b/iu', $text) === 1;
    }
}
