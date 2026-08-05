<?php

namespace App\Actions\Production;

use App\Models\ProductionRunNumberSetting;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Production\ProductionRunNumberService;
use App\Services\ProductionBenchAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaveProductionRunNumberSettings
{
    public function __construct(
        private readonly ProductionBenchAccess $access,
        private readonly ProductionRunNumberService $numbers,
    ) {}

    public function handle(
        User $actor,
        Workspace $workspace,
        string $prefix,
        string $suffix,
        int|string $padding,
        int|string $nextPermanentSerial,
    ): ProductionRunNumberSetting {
        $this->access->assertCanConfigure($actor, $workspace);

        $padding = $this->normalizePositiveInteger($padding, 'permanent_padding', 120);
        $nextPermanentSerial = $this->normalizePositiveInteger($nextPermanentSerial, 'next_permanent_serial');
        $this->validateAffix($prefix, 'permanent_prefix');
        $this->validateAffix($suffix, 'permanent_suffix');
        $candidate = $this->numbers->formatPermanentNumber($prefix, $nextPermanentSerial, $suffix, $padding);

        if (mb_strlen($candidate) > 120) {
            throw ValidationException::withMessages([
                'batch_number' => 'The rendered batch number must be no longer than 120 characters.',
            ]);
        }

        return DB::transaction(function () use ($actor, $candidate, $nextPermanentSerial, $padding, $prefix, $suffix, $workspace): ProductionRunNumberSetting {
            [$lockedWorkspace, $settings] = $this->numbers->lockWorkspaceAndSettings($workspace);
            $this->access->assertCanConfigure($actor, $lockedWorkspace);

            if ($this->numbers->identityExists($lockedWorkspace->id, [$candidate])) {
                throw ValidationException::withMessages([
                    'next_permanent_serial' => 'The next rendered batch number is already in use.',
                ]);
            }

            $settings->update([
                'permanent_prefix' => $prefix,
                'permanent_suffix' => $suffix,
                'permanent_padding' => $padding,
                'next_permanent_serial' => $nextPermanentSerial,
            ]);

            return $settings->refresh();
        }, attempts: 5);
    }

    private function validateAffix(string $value, string $field): void
    {
        if (mb_strlen($value) > 32 || preg_match('/\A[A-Za-z0-9._\/-]*\z/', $value) !== 1) {
            throw ValidationException::withMessages([
                $field => 'Use only letters, numbers, hyphens, underscores, dots, or slashes.',
            ]);
        }
    }

    private function normalizePositiveInteger(int|string $value, string $field, int $maximum = PHP_INT_MAX): int
    {
        $normalized = trim((string) $value);

        if (
            preg_match('/^[1-9]\d*$/', $normalized) !== 1
            || strlen($normalized) > strlen((string) $maximum)
            || (strlen($normalized) === strlen((string) $maximum) && strcmp($normalized, (string) $maximum) > 0)
        ) {
            throw ValidationException::withMessages([
                $field => 'Enter a positive whole number.',
            ]);
        }

        return (int) $normalized;
    }
}
