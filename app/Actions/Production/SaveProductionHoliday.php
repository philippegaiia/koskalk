<?php

namespace App\Actions\Production;

use App\Models\ProductionHoliday;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ProductionBenchAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaveProductionHoliday
{
    public function __construct(private readonly ProductionBenchAccess $access) {}

    public function handle(
        User $actor,
        Workspace $workspace,
        string $name,
        string $date,
        bool $isRecurring = false,
        ?ProductionHoliday $holiday = null,
    ): ProductionHoliday {
        $this->access->assertWritable($actor, $workspace);
        $name = trim($name);

        if ($name === '' || mb_strlen($name) > 120) {
            throw ValidationException::withMessages(['name' => 'Enter a holiday name.']);
        }

        if (! $this->isValidDate($date)) {
            throw ValidationException::withMessages(['date' => 'The holiday date must use YYYY-MM-DD format.']);
        }

        return DB::transaction(function () use ($actor, $date, $holiday, $isRecurring, $name, $workspace): ProductionHoliday {
            $lockedWorkspace = Workspace::withoutGlobalScopes()->lockForUpdate()->findOrFail($workspace->id);
            $this->access->assertWritable($actor, $lockedWorkspace);
            $current = null;

            if ($holiday instanceof ProductionHoliday) {
                $current = ProductionHoliday::query()->lockForUpdate()->find($holiday->id);

                if (! $current instanceof ProductionHoliday || (int) $current->workspace_id !== (int) $lockedWorkspace->id) {
                    throw ValidationException::withMessages([
                        'holiday' => 'The holiday does not belong to this workspace.',
                    ]);
                }
            }

            $values = [
                'workspace_id' => $lockedWorkspace->id,
                'name' => $name,
                'date' => $date,
                'is_recurring' => $isRecurring,
            ];

            if ($current instanceof ProductionHoliday) {
                $current->update($values);

                return $current->fresh();
            }

            return ProductionHoliday::query()->create($values);
        }, attempts: 5);
    }

    private function isValidDate(string $date): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return false;
        }

        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $errors = \DateTimeImmutable::getLastErrors();

        return $parsed !== false
            && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
            && $parsed->format('Y-m-d') === $date;
    }
}
