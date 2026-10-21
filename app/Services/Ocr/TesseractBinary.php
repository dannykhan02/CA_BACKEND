<?php

namespace App\Services\Ocr;

use Symfony\Component\Process\Exception\RuntimeException as ProcessRuntimeException;
use Symfony\Component\Process\Process;

/**
 * Whether the tesseract binary is actually callable in this container.
 *
 * TesseractOcrProvider::supports() used to return a bare `true`, which
 * asserted a capability nothing verified — the binary was not installed in
 * the image at all until this change. A provider that claims support and
 * then throws inside extractPage() turns a missing dependency into a failed
 * document, so the claim is now measured once and cached for the life of the
 * process.
 *
 * Resolved once per process, not per call: `tesseract --version` costs a
 * fork, and every word-box lookup would otherwise pay for it.
 */
class TesseractBinary
{
    private ?bool $available = null;

    private ?string $version = null;

    public function path(): string
    {
        return (string) config('ocr.tesseract_binary');
    }

    public function available(): bool
    {
        if ($this->available !== null) {
            return $this->available;
        }

        return $this->available = $this->probe();
    }

    /** Null until available() has confirmed a binary. */
    public function version(): ?string
    {
        $this->available();

        return $this->version;
    }

    /** For tests and the backfill command: forget a cached probe result. */
    public function forget(): void
    {
        $this->available = null;
        $this->version = null;
    }

    private function probe(): bool
    {
        $binary = $this->path();
        if ($binary === '') {
            return false;
        }

        try {
            // proc_open can be disabled (shared hosting, hardened php.ini) or the
            // binary can be absent. Neither is an error here — it means no boxes.
            $process = new Process([$binary, '--version']);
            $process->setTimeout(10);
            $process->run();
            if (! $process->isSuccessful()) {
                return false;
            }
            $this->version = trim(strtok($process->getOutput(), "\n") ?: '') ?: null;

            return true;
        } catch (ProcessRuntimeException|\Throwable) {
            return false;
        }
    }
}
