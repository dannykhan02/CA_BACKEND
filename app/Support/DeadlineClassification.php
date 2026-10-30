<?php

namespace App\Support;

/** Extracted domain types, shared with Research Studio's obligationKind contract. */
final class DeadlineClassification
{
    public const OBLIGATIONS = ['obligation', 'commitment', 'payment', 'delivery', 'approval', 'completion', 'contractual_obligation'];

    public static function classify(?string $type, ?string $dateType = null, ?string $date = null): string
    {
        $type = preg_replace('/[ -]+/', '_', mb_strtolower(trim($type ?? '')));
        if (in_array($type, self::OBLIGATIONS, true)) {
            return 'obligation';
        }
        if (in_array($type, ['follow_up', 'followup', 'action', 'action_item'], true)) {
            return 'follow-up';
        }

        return $dateType === 'explicit' && $date !== null ? 'dated event' : 'unclassified';
    }

    public static function normalizedSql(): string
    {
        return "regexp_replace(lower(trim(deadline_type)), '[ -]+', '_', 'g')";
    }
}
