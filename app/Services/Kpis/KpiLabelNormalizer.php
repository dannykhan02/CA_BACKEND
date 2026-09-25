<?php

namespace App\Services\Kpis;

class KpiLabelNormalizer
{
    public function normalize(string $label): string
    {
        // Preserve measurement symbols as words; punctuation must not erase a rate
        // or turn a monetary measure into a count. No stemming/stopword removal here.
        $label = strtr(mb_strtolower($label), ['%' => ' percent ', '+' => ' plus ', '$' => ' dollar ', '£' => ' gbp ', '€' => ' eur ']);

        return trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $label));
    }

    /** Only explicit reporting-period suffixes, never arbitrary numbers/years. */
    public function period(string $label, ?string $supplied = null): array
    {
        $pattern = '/[\s(\[—–-]+((?:Q[1-4](?:\s+(?:FY\s*)?\d{4}(?:[\/-]\d{2,4})?)?|FY\s*\d{4}(?:[\/-]\d{2,4})?))[)\]\s]*$/iu';
        if (preg_match($pattern, $label, $match, PREG_OFFSET_CAPTURE)) {
            return [$this->normalize(substr($label, 0, $match[0][1])), $match[1][0]];
        }
        if ($supplied && preg_match('/^(?:19|20)\d{2}$/', $supplied)
            && preg_match('/\s+'.preg_quote($supplied, '/').'$/u', $label)) {
            return [$this->normalize(substr($label, 0, -strlen($supplied))), $supplied];
        }

        return [$this->normalize($label), $supplied];
    }
}
