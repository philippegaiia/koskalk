<?php

namespace App\Services\Production;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ProductionEditingContext
{
    /** @var array<int, int|null> */
    private array $acknowledgedRevisions = [];

    /** @param array<int, int> $expectedRevisions */
    public function __construct(
        public readonly int $workspaceId,
        public readonly string $token,
        public readonly array $expectedRevisions,
        public readonly bool $temporary = false,
    ) {
        if (! Str::isUuid($token) || count($expectedRevisions) < 1 || count($expectedRevisions) > 100) {
            throw ValidationException::withMessages(['production_editing' => __('production_bench.editing.validation.selection')]);
        }
        foreach ($expectedRevisions as $id => $revision) {
            if (! is_int($id) || $id < 1 || ! is_int($revision) || $revision < 0) {
                throw ValidationException::withMessages(['production_editing' => __('production_bench.editing.validation.selection')]);
            }
        }
    }

    /** @param array<int, int|null> $revisions */
    public function acknowledge(array $revisions): void
    {
        $this->acknowledgedRevisions = $revisions;
    }

    /** @return array<int, int|null> */
    public function acknowledgedRevisions(): array
    {
        return $this->acknowledgedRevisions;
    }
}
