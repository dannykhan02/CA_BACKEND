<?php

namespace App\Support;

/** Extracted domain types, shared with Research Studio's obligationKind contract. */
final class DeadlineClassification
{
    public const OBLIGATIONS = ['obligation', 'commitment', 'payment', 'delivery', 'approval', 'completion', 'contractual_obligation'];

    public static function normalizedSql(): string
    {
        return "regexp_replace(lower(trim(deadline_type)), '[ -]+', '_', 'g')";
    }
}
