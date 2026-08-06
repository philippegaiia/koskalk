<?php

namespace App\Services\Production;

use Illuminate\Validation\ValidationException;

class FlashProductionLimits
{
    public const MAX_BATCHES_PER_SUBMISSION = 1000;

    public function assertWithinLimit(int $batchCount): void
    {
        if ($batchCount > self::MAX_BATCHES_PER_SUBMISSION) {
            throw ValidationException::withMessages([
                'lines' => 'A flash proposal cannot contain more than '.self::MAX_BATCHES_PER_SUBMISSION.' batches.',
            ]);
        }
    }
}
