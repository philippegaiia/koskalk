<?php

namespace App\Services;

use Closure;
use Illuminate\Database\DatabaseManager;

class RetriableDatabaseTransaction
{
    private const int DEADLOCK_ATTEMPTS = 3;

    public function __construct(
        private readonly DatabaseManager $database,
    ) {}

    public function run(Closure $callback): mixed
    {
        return $this->database->transaction($callback, self::DEADLOCK_ATTEMPTS);
    }
}
