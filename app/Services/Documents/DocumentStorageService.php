<?php

namespace App\Services\Documents;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Single point of contact between controllers/jobs and physical storage.
 * Backed by the 'documents' disk (local today) — swapping to S3 later
 * means changing config/filesystems.php's 'documents' driver, not any
 * caller of this class.
 */
class DocumentStorageService
{
    private const DISK = 'documents';

    public function store(UploadedFile $file, string $workspaceId): string
    {
        // Track A / FU-1: defense-in-depth. getClientOriginalExtension() is
        // attacker-influenced. Laravel's pathinfo()-based extraction makes a
        // slash-based traversal unlikely, but nothing server-side previously
        // filtered this before it landed in a storage key. Strip to a plain
        // alnum extension; if nothing usable survives, store without one
        // rather than trusting an unvalidated fragment.
        $extension = preg_replace('/[^a-z0-9]/', '', strtolower($file->getClientOriginalExtension()));

        // Track A / FU-1: workspaceId originates server-side from the
        // authenticated user's current_workspace_id, but this class has no
        // other caller-independent way to know that — validate the shape
        // here so a malformed value can never become part of a storage key.
        if (! preg_match('/^[0-9a-f-]{36}$/i', $workspaceId)) {
            throw new \InvalidArgumentException('Invalid workspace id supplied to storage service.');
        }

        $suffix = $extension !== '' ? ".{$extension}" : '';
        $path = "{$workspaceId}/".(string) Str::uuid().$suffix;

        Storage::disk(self::DISK)->putFileAs(
            dirname($path),
            $file,
            basename($path)
        );

        return $path;
    }

    /** Stream a private local copy without loading the original PDF into a PHP string. */
    public function temporaryCopy(string $path, string $prefix = 'docintel_'): string
    {
        $temporary = tempnam(sys_get_temp_dir(), $prefix);
        $input = null;
        $output = null;
        try {
            $input = Storage::disk(self::DISK)->readStream($path);
            $output = fopen($temporary, 'wb');
            if (! is_resource($input) || ! is_resource($output) || stream_copy_to_stream($input, $output) === false) {
                throw new \RuntimeException('Unable to create a local document copy.');
            }
            chmod($temporary, 0600);

            return $temporary;
        } catch (\Throwable $e) {
            @unlink($temporary);
            throw $e;
        } finally {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_resource($output)) {
                fclose($output);
            }
        }
    }

    public function delete(string $path): bool
    {
        return Storage::disk(self::DISK)->delete($path);
    }

    public function download(string $path): string
    {
        return Storage::disk(self::DISK)->path($path);
    }

    public function exists(string $path): bool
    {
        return Storage::disk(self::DISK)->exists($path);
    }

    public function temporaryUrl(string $path, int $expiresInMinutes = 15): string
    {
        return Storage::disk(self::DISK)->temporaryUrl(
            $path,
            now()->addMinutes($expiresInMinutes)
        );
    }
}
