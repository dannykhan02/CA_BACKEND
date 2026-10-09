<?php

namespace Tests\Feature;

use App\Services\AI\AiModels;
use App\Services\AnthropicClient;
use Database\Seeders\DocumentSummaryPromptSeederV3;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Tests\TestCase;

/**
 * DocIntel may only call the two configured models. Fails if any call purpose
 * resolves to, or any application code hardcodes, another model ID.
 */
class ModelRoutingGuardTest extends TestCase
{
    use RefreshDatabase;

    private const FAST = 'claude-haiku-4-5-20251001';

    private const SMART = 'claude-sonnet-5-5';

    private const MODEL_ENV = ['ANTHROPIC_MODEL', 'ANTHROPIC_EXTRACTION_MODEL', 'ANTHROPIC_SYNTHESIS_MODEL'];

    /** Every purpose string passed to forTask()/modelFor() or used as a request operation. */
    private function purposes(): array
    {
        $purposes = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            preg_match_all("/(?:forTask|modelFor)\\('([a-z_]+)'\\)|currentOperation = '([a-z_]+)'/", file_get_contents($file->getPathname()), $matches);
            $purposes = [...$purposes, ...array_filter($matches[1]), ...array_filter($matches[2])];
        }
        $purposes = array_values(array_unique($purposes));
        sort($purposes);

        return $purposes;
    }

    /** Resolve with the shipped config/services.php defaults and model env variables unset. */
    private function withDefaultModelEnv(callable $callback): mixed
    {
        $saved = [];
        foreach (self::MODEL_ENV as $name) {
            $saved[$name] = [$_ENV[$name] ?? null, $_SERVER[$name] ?? null, getenv($name)];
            unset($_ENV[$name], $_SERVER[$name]);
            putenv($name);
        }
        $config = config('services.anthropic');
        try {
            config(['services.anthropic' => (require config_path('services.php'))['anthropic']]);

            return $callback();
        } finally {
            config(['services.anthropic' => $config]);
            foreach ($saved as $name => [$env, $server, $put]) {
                if ($env !== null) {
                    $_ENV[$name] = $env;
                }
                if ($server !== null) {
                    $_SERVER[$name] = $server;
                }
                $put === false ? putenv($name) : putenv($name.'='.$put);
            }
        }
    }

    public function test_every_call_purpose_resolves_to_one_of_the_two_configured_models(): void
    {
        $purposes = $this->purposes();
        self::assertContains('extraction', $purposes);
        self::assertContains('document_summary', $purposes);
        self::assertSame([self::FAST, self::SMART], config('document_intelligence.approved_models'));

        $resolved = $this->withDefaultModelEnv(fn () => array_combine($purposes,
            array_map(fn ($purpose) => app(AiModels::class)->forTask($purpose), $purposes)));
        foreach ($resolved as $purpose => $model) {
            self::assertContains($model, [self::FAST, self::SMART], "Purpose {$purpose} resolves to {$model}.");
        }
        // Synthesis-class work is SMART; everything else, including per-chunk extraction, is FAST.
        // brief_synthesis (B2) writes narrative over already-validated evidence, so it is
        // synthesis-class and resolves to SMART like the Stage A summary it sits on top of.
        $smart = ['document_summary', 'summary_repair', 'document_comparison', 'document_qa', 'brief_synthesis'];
        foreach ($smart as $purpose) {
            self::assertSame(self::SMART, $resolved[$purpose] ?? app(AiModels::class)->forTask($purpose), $purpose);
        }
        foreach (array_diff($purposes, $smart) as $purpose) {
            self::assertSame(self::FAST, $resolved[$purpose], $purpose);
        }
    }

    public function test_no_other_model_id_is_hardcoded_in_application_code(): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            preg_match_all('/claude-[a-z0-9][a-z0-9.-]*/', file_get_contents($file->getPathname()), $matches);
            foreach ($matches[0] as $model) {
                self::assertContains($model, [self::FAST, self::SMART], "{$file->getPathname()} hardcodes {$model}.");
            }
        }
        // Shipped defaults (no env override) are exactly the two approved models.
        $defaults = $this->withDefaultModelEnv(fn () => config('services.anthropic'));
        self::assertSame(self::FAST, $defaults['model']);
        self::assertSame(self::FAST, $defaults['extraction_model']);
        self::assertSame(self::SMART, $defaults['synthesis_model']);
    }

    public function test_an_env_override_to_another_model_is_reported_not_silently_used(): void
    {
        config(['services.anthropic.synthesis_model' => 'claude-sonnet-4-6']);
        $this->artisan('docintel:verify-models')->assertExitCode(1);
        config(['services.anthropic.synthesis_model' => self::SMART, 'services.anthropic.extraction_model' => self::FAST]);
        $this->artisan('docintel:verify-models')->assertExitCode(0);

        $this->seed(DocumentSummaryPromptSeederV3::class);
        $handler = new TestHandler;
        Log::swap(new Logger('test', [$handler]));
        config(['services.anthropic.synthesis_model' => 'claude-sonnet-4-6', 'services.anthropic.api_key' => 'fake-private-key']);
        Http::fake(['*/messages' => Http::response([], 400)]);
        try {
            app(AnthropicClient::class)->generateDocumentSummary('{}', 'Doc.pdf');
        } catch (\Throwable) {
            // Only the warning matters here.
        }
        $warning = collect($handler->getRecords())->first(fn ($r) => $r['message'] === 'Anthropic request uses an unapproved model');
        self::assertSame(['purpose' => 'document_summary', 'model' => 'claude-sonnet-4-6', 'document_id' => null], $warning['context']);
        self::assertStringNotContainsString('fake-private-key', json_encode($handler->getRecords()));
    }
}
