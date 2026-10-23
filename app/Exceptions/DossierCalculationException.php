<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The DIP calculation cannot run for a named reason (spec §5: "a calculation that cannot run" blocks submission).
 */
class DossierCalculationException extends RuntimeException
{
    /**
     * @param  array<string, string|int>  $context
     */
    public function __construct(public readonly string $reason, public readonly array $context = [])
    {
        parent::__construct("The DIP calculation cannot run: {$reason}.");
    }
}
