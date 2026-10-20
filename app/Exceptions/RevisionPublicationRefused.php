<?php

namespace App\Exceptions;

/**
 * publish() refused to publish a candidate revision. Carries the short condition out of the
 * rolled-back publication transaction so the refusal can be logged and recorded on the revision.
 *
 * Extends LogicException because every caller already treats a refused publication as one.
 */
class RevisionPublicationRefused extends \LogicException
{
    public function __construct(
        string $message,
        public readonly string $condition,
        public readonly string $documentId,
        public readonly string $revisionId,
        public readonly int $generation,
    ) {
        parent::__construct($message);
    }
}
