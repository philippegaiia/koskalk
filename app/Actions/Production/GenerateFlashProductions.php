<?php

namespace App\Actions\Production;

use App\Enums\ProductionRunSource;
use App\Models\ProductionRun;
use App\Models\ProductionTaskSet;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Production\FlashDateProposalService;
use App\Services\Production\FlashProductionSimulator;
use App\Services\ProductionBenchAccess;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GenerateFlashProductions
{
    /** @var array<int, ?ProductionTaskSet> */
    private array $taskSetsById = [];

    public function __construct(
        private readonly ProductionBenchAccess $access,
        private readonly FlashProductionSimulator $simulator,
        private readonly FlashDateProposalService $dateProposal,
        private readonly PlanProduction $planProduction,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return Collection<int, ProductionRun>
     */
    public function handle(
        User $actor,
        Workspace $workspace,
        array $lines,
        string $firstDate,
        int|string $batchesPerDay,
        string $idempotencyKey,
    ): Collection {
        $this->access->assertWritable($actor, $workspace);
        $this->validateKey($idempotencyKey);
        $batchesPerDay = $this->positiveWhole($batchesPerDay);

        return DB::transaction(function () use (
            $actor,
            $batchesPerDay,
            $firstDate,
            $idempotencyKey,
            $lines,
            $workspace,
        ): Collection {
            $lockedWorkspace = Workspace::withoutGlobalScopes()->lockForUpdate()->findOrFail($workspace->id);
            $this->access->assertWritable($actor, $lockedWorkspace);
            $simulation = $this->simulator->simulate($lockedWorkspace, $lines);
            $proposals = $this->dateProposal->propose(
                workspace: $lockedWorkspace,
                lines: $simulation['lines'],
                firstDate: $firstDate,
                batchesPerDay: $batchesPerDay,
            );

            if ($proposals === []) {
                throw ValidationException::withMessages([
                    'lines' => __('production_bench.production.validation.flash_products_required'),
                ]);
            }

            $simulationLines = collect($simulation['lines'])->keyBy('line_index');
            $productions = collect();
            $expectedKeys = collect($proposals)
                ->map(fn (array $proposal): string => $this->productionKey(
                    $idempotencyKey,
                    $proposal['line_index'],
                    $proposal['batch_number'],
                ))
                ->sort()
                ->values()
                ->all();
            $existingKeys = ProductionRun::query()
                ->where('workspace_id', $lockedWorkspace->id)
                ->where('idempotency_key', 'like', $this->productionPrefix($idempotencyKey).'%')
                ->pluck('idempotency_key')
                ->sort()
                ->values()
                ->all();

            if ($existingKeys !== [] && $existingKeys !== $expectedKeys) {
                throw ValidationException::withMessages([
                    'idempotency_key' => __('production_bench.production.validation.flash_idempotency_conflict'),
                ]);
            }

            foreach ($proposals as $proposal) {
                $line = $simulationLines->get($proposal['line_index']);

                if (! is_array($line)) {
                    throw ValidationException::withMessages([
                        'lines' => __('production_bench.production.validation.flash_proposal_stale'),
                    ]);
                }

                $taskSet = $this->taskSet($lockedWorkspace, $line['task_set_id'] ?? null);
                $key = $this->productionKey($idempotencyKey, $proposal['line_index'], $proposal['batch_number']);
                $existing = ProductionRun::query()
                    ->where('workspace_id', $lockedWorkspace->id)
                    ->where('idempotency_key', $key)
                    ->first();

                if ($existing instanceof ProductionRun) {
                    $this->assertSameRequest(
                        $existing,
                        $line,
                        $proposal['production_date'],
                        $proposal['estimated_ready_on'],
                        $taskSet?->id,
                    );
                    $productions->push($existing->fresh(['requirements', 'tasks']));

                    continue;
                }

                $production = $this->planProduction->handle(
                    actor: $actor,
                    workspace: $lockedWorkspace,
                    recipe: $line['recipe'],
                    basisInputValue: (string) $line['basis_input_value'],
                    basisInputUnit: $line['basis_input_unit'],
                    expectedUnits: (int) $line['expected_units_per_batch'],
                    idempotencyKey: $key,
                    plannedFor: $proposal['production_date'],
                    source: ProductionRunSource::Flash,
                    taskSet: $taskSet,
                );
                $productions->push($production);
            }

            return $productions->values();
        }, attempts: 5);
    }

    private function taskSet(Workspace $workspace, mixed $taskSetId): ?ProductionTaskSet
    {
        if (! filled($taskSetId)) {
            return null;
        }

        $id = (int) $taskSetId;

        if (array_key_exists($id, $this->taskSetsById)) {
            return $this->taskSetsById[$id];
        }

        $taskSet = ProductionTaskSet::query()
            ->where('workspace_id', $workspace->id)
            ->where('is_active', true)
            ->find($id);

        if (! $taskSet instanceof ProductionTaskSet) {
            throw ValidationException::withMessages([
                'task_set' => __('production_bench.production.validation.task_set_active_workspace_required'),
            ]);
        }

        return $this->taskSetsById[$id] = $taskSet;
    }

    /** @param array<string, mixed> $line */
    private function assertSameRequest(
        ProductionRun $existing,
        array $line,
        string $plannedFor,
        string $estimatedReadyOn,
        ?int $taskSetId,
    ): void {
        if (
            (int) $existing->recipe_id !== (int) $line['recipe_id']
            || bccomp((string) $existing->basis_input_value, (string) $line['basis_input_value'], 9) !== 0
            || $existing->basis_input_unit !== $line['basis_input_unit']
            || (int) $existing->expected_units !== (int) $line['expected_units_per_batch']
            || $existing->planned_for?->toDateString() !== $plannedFor
            || $existing->estimated_ready_on?->toDateString() !== $estimatedReadyOn
            || (int) ($existing->production_task_set_id ?? 0) !== (int) ($taskSetId ?? 0)
        ) {
            throw ValidationException::withMessages([
                'idempotency_key' => __('production_bench.production.validation.flash_idempotency_conflict'),
            ]);
        }
    }

    private function productionKey(string $key, int $lineIndex, int $batchNumber): string
    {
        return $this->productionPrefix($key).$lineIndex.':'.$batchNumber;
    }

    private function productionPrefix(string $key): string
    {
        return 'flash:'.substr(hash('sha256', $key), 0, 32).':';
    }

    private function validateKey(string $key): void
    {
        if (trim($key) === '' || strlen($key) > 80) {
            throw ValidationException::withMessages([
                'idempotency_key' => __('production_bench.production.validation.flash_idempotency_key_invalid'),
            ]);
        }
    }

    private function positiveWhole(int|string $value): int
    {
        if (preg_match('/^[1-9]\d*$/', trim((string) $value)) !== 1) {
            throw ValidationException::withMessages([
                'batchesPerDay' => __('production_bench.production.validation.flash_batches_per_day_positive'),
            ]);
        }

        return (int) $value;
    }
}
