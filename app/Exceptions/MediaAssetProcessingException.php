<?php

namespace App\Exceptions;

use Exception;

class MediaAssetProcessingException extends Exception
{
    public function __construct(
        string $message,
        public readonly string $failureCode = 'processing_failed',
    ) {
        parent::__construct($message);
    }
}
