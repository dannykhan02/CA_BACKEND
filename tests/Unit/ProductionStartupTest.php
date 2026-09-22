<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class ProductionStartupTest extends TestCase
{
    public static function scenarios(): array
    {
        return [
            'config failure' => ['config:cache', 0, 0, true, 'web', false],
            'route failure' => ['route:cache', 0, 0, true, 'web', false],
            'view failure' => ['view:cache', 0, 0, true, 'web', false],
            'safety failure' => ['config:check-production-safety', 0, 0, true, 'web', false],
            'missing signatures' => ['', 1, 0, false, 'web', false],
            'invalid signatures' => ['', 1, 2, true, 'web', false],
            'existing usable signatures' => ['', 1, 0, true, 'web', true],
            'successful update' => ['', 0, 0, true, 'web', true],
            'worker role' => ['', 0, 0, true, 'worker', true],
        ];
    }

    #[DataProvider('scenarios')]
    public function test_startup_gates(string $fail, int $freshclam, int $scan, bool $signatures, string $role, bool $starts): void
    {
        $dir = sys_get_temp_dir().'/startup-'.bin2hex(random_bytes(8));
        mkdir($dir);
        mkdir($dir.'/db');
        try {
            foreach (['php', 'freshclam', 'clamscan', 'frankenphp'] as $binary) {
                file_put_contents($dir.'/'.$binary, <<<'SH'
#!/bin/sh
name=${0##*/}
echo "$name $*" >> "$AUDIT_CALLS"
case "$name" in
 php) [ "$2" != "$FAIL_COMMAND" ] ;;
 freshclam) exit "$FRESHCLAM_EXIT" ;;
 clamscan) exit "$SCAN_EXIT" ;;
 frankenphp) exit 0 ;;
esac
SH);
                chmod($dir.'/'.$binary, 0700);
            }
            if ($signatures) {
                file_put_contents($dir.'/db/daily.cvd', 'synthetic database');
            }
            $process = new Process(['sh', dirname(__DIR__, 2).'/bin/start-production.sh', $role], env: [
                'PATH' => $dir.':'.getenv('PATH'), 'AUDIT_CALLS' => $dir.'/calls',
                'CLAMAV_DATABASE_DIRECTORY' => $dir.'/db', 'FAIL_COMMAND' => $fail,
                'FRESHCLAM_EXIT' => (string) $freshclam, 'SCAN_EXIT' => (string) $scan,
            ]);
            $process->run();
            $calls = file_get_contents($dir.'/calls');
            $this->assertSame($starts, $process->isSuccessful(), $process->getErrorOutput());
            $this->assertSame($starts && $role === 'web', str_contains($calls, 'frankenphp run'));
            $this->assertSame($starts && $role === 'worker', str_contains($calls, 'php artisan horizon'));
            if ($freshclam === 1 && $fail === '') {
                $this->assertStringContainsString('freshclam update failed', $process->getErrorOutput());
            }
        } finally {
            foreach (glob($dir.'/db/*') as $file) {
                unlink($file);
            }
            rmdir($dir.'/db');
            foreach (glob($dir.'/*') as $file) {
                unlink($file);
            }
            rmdir($dir);
        }
    }
}
