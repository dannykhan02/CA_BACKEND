<?php

namespace App\Services\AI\Incremental;

class SynthesisSchema
{
    public static function schema(): array
    {
        $text = ['type' => 'string'];
        $properties = ['executive_summary' => $text];
        foreach (['key_findings', 'critical_risks', 'upcoming_deadlines', 'important_entities', 'recommended_attention'] as $field) {
            $properties[$field] = ['type' => 'array', 'items' => $text];
        }
        foreach (['executive_assessment' => ['text'], 'material_findings' => ['title', 'category', 'explanation', 'why_it_matters', 'severity'],
            'trends' => ['observation', 'significance'], 'tensions' => ['observation', 'significance'], 'questions' => ['question', 'reason']] as $field => $names) {
            $fields = array_fill_keys($names, $text);
            $fields['basis'] = ['type' => 'string', 'enum' => ['explicit', 'inferred']];
            $fields['source_ids'] = ['type' => 'array', 'items' => $text];
            $item = EvidenceSchema::object($fields);
            if ($field === 'executive_assessment') {
                $properties[$field] = ['anyOf' => [$item, ['type' => 'null']]];
            } else {
                $properties[$field] = ['type' => 'array', 'items' => $item];
            }
        }

        $properties['document_type_assessment'] = EvidenceSchema::object([
            'document_type' => ['type' => 'string', 'enum' => ['compliance_report', 'financial_report', 'regulatory_filing',
                'policy_document', 'contract', 'correspondence', 'technical_report', 'meeting_minutes', 'research_report', 'application_form', 'invoice', 'other']],
            'confidence' => ['type' => 'number'], 'reasoning' => ['type' => 'string'],
        ]);

        return EvidenceSchema::object($properties);
    }
}
