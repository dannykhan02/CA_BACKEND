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
        $extension = strtolower($file->getClientOriginalExtension());
        $path = "{$workspaceId}/" . (string) Str::uuid() . ".{$extension}";

        Storage::disk(self::DISK)->putFileAs(
            dirname($path),
            $file,
            basename($path)
        );

        return $path;
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
