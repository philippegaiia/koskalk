<?php

namespace App\Services\Production;

use App\Models\ProductionTaskSet;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Validation\ValidationException;

class FlashDateProposalService
{
    public function __construct(
        private readonly ProductionWorkingCalendar $calendar,
        private readonly FlashProductionLimits $limits,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return list<array{line_index: int, recipe_id: int, recipe_name: string, batch_number: int, batch_total: int, production_date: string, tasks: list<array<string, mixed>>}>
     */
    public function propose(
        Workspace $workspace,
        array $lines,
        string|DateTimeInterface $firstDate,
        int $batchesPerDay = 1,
    ): array {
        if ($batchesPerDay < 1) {
            throw ValidationException::withMessages([
                'batchesPerDay' => 'The number of batches per day must be positive.',
            ]);
        }

        $date = $this->parseDate($firstDate);
        $proposals = [];
        $dayBatchCount = 0;
        $totalBatches = 0;

        foreach ($lines as $line) {
            $batchTotal = (int) ($line['whole_batches'] ?? 0);

            if ($batchTotal < 1) {
                continue;
            }

            $totalBatches += $batchTotal;
            $this->limits->assertWithinLimit($totalBatches);
            $taskSet = $this->taskSet($workspace, $line['task_set_id'] ?? null);

            for ($batch = 1; $batch <= $batchTotal; $batch++) {
                if ($dayBatchCount >= $batchesPerDay) {
                    $date = $this->calendar->nextWorkingDate($workspace, $date->addDay());
                    $dayBatchCount = 0;
                } else {
                    $date = $this->calendar->nextWorkingDate($workspace, $date);
                }

                $proposals[] = [
                    'line_index' => (int) $line['line_index'],
                    'recipe_id' => (int) $line['recipe_id'],
                    'recipe_name' => (string) ($line['recipe']->name ?? ''),
                    'batch_number' => $batch,
                    'batch_total' => $batchTotal,
                    'production_date' => $date->toDateString(),
                    'tasks' => $this->tasks($workspace, $date, $taskSet),
                ];

                $dayBatchCount++;
            }
        }

        return $proposals;
    }

    private function parseDate(string|DateTimeInterface $date): CarbonImmutable
    {
        try {
            $parsed = $date instanceof DateTimeInterface
                ? CarbonImmutable::instance($date)->startOfDay()
                : CarbonImmutable::createFromFormat('!Y-m-d', $date);
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'firstDate' => 'Choose a valid first production date.',
            ]);
        }

        if (! $parsed instanceof CarbonImmutable || $parsed->format('Y-m-d') !== (string) ($date instanceof DateTimeInterface ? $parsed->toDateString() : $date)) {
            throw ValidationException::withMessages([
                'firstDate' => 'Choose a valid first production date.',
            ]);
        }

        return $parsed;
    }

    private function taskSet(Workspace $workspace, mixed $taskSetId): ?ProductionTaskSet
    {
        if (! filled($taskSetId)) {
            return null;
        }

        return ProductionTaskSet::query()
            ->where('workspace_id', $workspace->id)
            ->where('is_active', true)
            ->with('items.taskType')
            ->find((int) $taskSetId);
    }

    /** @return list<array<string, mixed>> */
    private function tasks(Workspace $workspace, CarbonImmutable $productionDate, ?ProductionTaskSet $taskSet): array
    {
        if (! $taskSet instanceof ProductionTaskSet) {
            return [];
        }

        return $taskSet->items->map(fn ($item): array => [
            'name' => $item->taskType?->name ?? (string) $item->taskType?->key ?? 'Task',
            'scheduled_for' => $this->calendar
                ->dateRelativeToProduction($workspace, $productionDate, (int) $item->days_after_production)
                ->toDateString(),
            'days_after_production' => (int) $item->days_after_production,
            'colour' => $item->taskType?->colour,
            'duration_minutes' => $item->duration_minutes === null ? null : (int) $item->duration_minutes,
        ])->values()->all();
    }
}
