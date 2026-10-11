<?php

namespace Tests\Unit\Services;

use App\Services\Intelligence\B2\NarrativeContextBuilder;
use Tests\TestCase;

class B2ProvenanceCheckpointIdentityTest extends TestCase
{
    public function test_changed_role_anchor_changes_checkpoint_identity(): void
    {
        $builder = app(NarrativeContextBuilder::class);
        $context = ['evidence' => [['id' => 'kpi:fixture', 'value' => '12.4']]];
        $first = ['kpi:fixture' => ['source_provenance' => ['roles' => [
            'value' => [['anchor_id' => 'anchor-a', 'text' => '12.4']]]]]];
        $second = ['kpi:fixture' => ['source_provenance' => ['roles' => [
            'value' => [['anchor_id' => 'anchor-b', 'text' => '12.4']]]]]];
        self::assertNotSame($builder->inputHash($context, 'model', $first),
            $builder->inputHash($context, 'model', $second));
        self::assertSame($builder->inputHash($context, 'model', $first),
            $builder->inputHash($context, 'model', $first));
    }
}
