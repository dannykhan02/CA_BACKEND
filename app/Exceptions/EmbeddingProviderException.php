<?php

namespace App\Exceptions;

class EmbeddingProviderException extends \RuntimeException
{
    public function __construct(string $internalMessage = 'Embedding provider unavailable.')
    {
        parent::__construct($internalMessage);
    }
}