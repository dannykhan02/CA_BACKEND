<?php

namespace App\Services\Intelligence;

/** Surface normalization for source matching only. Never writes back to evidence. */
class MatchingTextNormalizer
{
    public function normalize(string $text): string
    {
        if (class_exists(\Normalizer::class)) {
            $text = \Normalizer::normalize($text, \Normalizer::FORM_C) ?: $text;
        }
        $text = strtr($text, [
            "\u{FB00}" => 'ff', "\u{FB01}" => 'fi', "\u{FB02}" => 'fl',
            "\u{FB03}" => 'ffi', "\u{FB04}" => 'ffl',
            "\u{2018}" => "'", "\u{2019}" => "'", "\u{201C}" => '"', "\u{201D}" => '"',
            "\u{2010}" => '-', "\u{2011}" => '-', "\u{2012}" => '-',
            "\u{2013}" => '-', "\u{2014}" => '-', "\u{2212}" => '-',
        ]);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    public function fold(string $text): string
    {
        return mb_strtolower($this->normalize($text));
    }
}
