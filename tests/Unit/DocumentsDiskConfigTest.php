<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The `documents` disk must never set `root` on the s3 driver.
 *
 * This is the 2026-10-17 incident as a test. `root` means two different
 * things on two drivers: a filesystem directory on `local`, and an
 * **object-key prefix** on `s3`, which Flysystem prepends to every key it
 * reads or writes. Commit 260d730 set it unconditionally, so production —
 * which runs s3 — began addressing every object as
 * `/app/storage/app/documents/<workspace>/<uuid>` while the objects in the
 * bucket sat at bare `<workspace>/<uuid>` keys. Nothing was deleted; 79 of 86
 * documents simply stopped resolving, and `exists()` answered false for
 * objects that were sitting untouched in R2.
 *
 * What makes that failure worth a test rather than a comment: it is
 * invisible from inside the application. Writes succeed, newly uploaded
 * objects read back fine, and only documents stored *before* the change
 * disappear from view. No request errors, no exception, no log line.
 *
 * The config file is re-read here with the environment set per case, rather
 * than asserting on the booted container's config, so this tests the file's
 * own logic and not whatever this machine's .env happens to say.
 *
 * Touches no storage: it reads a config array. `Storage::fake('documents')`
 * is kept per this suite's convention so that nothing in this file could
 * reach the real R2 bucket even if it were later extended.
 */
class DocumentsDiskConfigTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
    }

    /** The `documents` disk as config/filesystems.php builds it for this driver. */
    private function documentsDisk(?string $driver): array
    {
        $keys = ['DOCUMENTS_DISK_DRIVER', 'DOCUMENTS_LOCAL_ROOT'];
        $saved = [];
        foreach ($keys as $key) {
            $saved[$key] = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null, getenv($key)];
        }

        try {
            // Unset both, then set only the driver under test, so a value in
            // this machine's environment cannot decide the outcome.
            foreach ($keys as $key) {
                unset($_ENV[$key], $_SERVER[$key]);
                putenv($key);
            }
            if ($driver !== null) {
                $_ENV['DOCUMENTS_DISK_DRIVER'] = $driver;
                $_SERVER['DOCUMENTS_DISK_DRIVER'] = $driver;
                putenv('DOCUMENTS_DISK_DRIVER='.$driver);
            }

            $config = require config_path('filesystems.php');

            return $config['disks']['documents'];
        } finally {
            foreach ($saved as $key => [$env, $server, $put]) {
                unset($_ENV[$key], $_SERVER[$key]);
                putenv($key);
                if ($env !== null) {
                    $_ENV[$key] = $env;
                }
                if ($server !== null) {
                    $_SERVER[$key] = $server;
                }
                if ($put !== false) {
                    putenv($key.'='.$put);
                }
            }
        }
    }

    public function test_the_default_driver_is_s3_and_sets_no_key_prefix(): void
    {
        // The production shape: DOCUMENTS_DISK_DRIVER unset.
        $disk = $this->documentsDisk(null);

        self::assertSame('s3', $disk['driver'], 'production must default to the R2/s3 driver');
        self::assertTrue(
            ($disk['root'] ?? null) === null || $disk['root'] === '',
            'root is an object-key prefix on s3: it must be null or empty, otherwise every '
            .'existing object stops resolving. Got: '.var_export($disk['root'] ?? null, true)
        );
    }

    public function test_an_explicit_s3_driver_also_sets_no_key_prefix(): void
    {
        $disk = $this->documentsDisk('s3');

        self::assertSame('s3', $disk['driver']);
        self::assertTrue(($disk['root'] ?? null) === null || $disk['root'] === '');
    }

    public function test_the_local_driver_does_get_a_root_directory(): void
    {
        // The reason the line was added in the first place: local development
        // needs a real directory. That half must keep working.
        $disk = $this->documentsDisk('local');

        self::assertSame('local', $disk['driver']);
        self::assertIsString($disk['root']);
        self::assertNotSame('', $disk['root'], 'the local driver needs a directory to write into');
    }

    public function test_the_s3_credentials_are_still_read_from_the_environment(): void
    {
        // Guards against "fixing" the root by hard-coding the disk: the bucket
        // and endpoint must stay environment-driven, and no secret may be
        // written into the config file.
        $disk = $this->documentsDisk(null);

        foreach (['key', 'secret', 'bucket', 'endpoint'] as $field) {
            self::assertArrayHasKey($field, $disk);
        }
        self::assertSame('auto', $disk['region']);
        self::assertTrue($disk['use_path_style_endpoint']);
        self::assertSame('private', $disk['visibility'], 'originals must never be public');
    }
}
