<?php

namespace App\Actions\Production;

use App\Models\ProductionTaskType;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ProductionBenchAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaveProductionTaskType
{
    public function __construct(private readonly ProductionBenchAccess $access) {}

    public function handle(
        User $actor,
        Workspace $workspace,
        string $name,
        ?int $defaultDurationMinutes = null,
        ?string $colour = null,
        bool $isActive = true,
        ?ProductionTaskType $taskType = null,
    ): ProductionTaskType {
        $this->access->assertWritable($actor, $workspace);
        $name = trim($name);

        if ($name === '' || mb_strlen($name) > 120) {
            throw ValidationException::withMessages(['name' => 'Enter a task type name.']);
        }

        if ($defaultDurationMinutes !== null && $defaultDurationMinutes < 0) {
            throw ValidationException::withMessages([
                'default_duration_minutes' => 'Duration cannot be negative.',
            ]);
        }

        $colour = $colour !== null ? trim($colour) : null;

        if ($colour !== null && ($colour === '' || mb_strlen($colour) > 16)) {
            throw ValidationException::withMessages(['colour' => 'Enter a valid task colour.']);
        }

        return DB::transaction(function () use ($actor, $colour, $defaultDurationMinutes, $isActive, $name, $taskType, $workspace): ProductionTaskType {
            $lockedWorkspace = Workspace::withoutGlobalScopes()->lockForUpdate()->findOrFail($workspace->id);
            $this->access->assertWritable($actor, $lockedWorkspace);
            $current = null;

            if ($taskType instanceof ProductionTaskType) {
                $current = ProductionTaskType::query()->lockForUpdate()->find($taskType->id);

                if (! $current instanceof ProductionTaskType || (int) $current->workspace_id !== (int) $lockedWorkspace->id) {
                    throw ValidationException::withMessages([
                        'task_type' => 'The task type does not belong to this workspace.',
                    ]);
                }
            }

            $values = [
                'workspace_id' => $lockedWorkspace->id,
                'name' => $name,
                'default_duration_minutes' => $defaultDurationMinutes,
                'colour' => $colour,
                'is_active' => $isActive,
            ];

            if ($current instanceof ProductionTaskType) {
                $current->update($values);

                return $current->fresh();
            }

            return ProductionTaskType::query()->create($values);
        }, attempts: 5);
    }
}
