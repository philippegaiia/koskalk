<?php

namespace App\Livewire\ProductionBench\Production;

use App\Actions\Inventory\AttachProductionDocument;
use App\Actions\Production\AbortProduction;
use App\Actions\Production\AssignProductionBatchNumbers;
use App\Actions\Production\AssignProductionTask;
use App\Actions\Production\CancelProduction;
use App\Actions\Production\CompleteProduction;
use App\Actions\Production\CompleteProductionTask;
use App\Actions\Production\IssueFinishedGoods;
use App\Actions\Production\ReleaseOutputLot;
use App\Actions\Production\ReleaseProductionStock;
use App\Actions\Production\ReopenProductionTask;
use App\Actions\Production\RescheduleProductionTask;
use App\Actions\Production\ResetProductionTaskDate;
use App\Actions\Production\SaveProductionActuals;
use App\Actions\Production\SaveProductionJournalEntry;
use App\Actions\Production\ScheduleProduction;
use App\Actions\Production\StartProduction;
use App\Enums\MediaAssetType;
use App\Enums\ProductionDocumentType;
use App\Enums\ProductionRunStatus;
use App\Enums\StockMovementType;
use App\Enums\StockReservationStatus;
use App\Enums\WorkspaceMemberRole;
use App\Livewire\Concerns\InteractsWithAppNotifications;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Ingredient;
use App\Models\ProductionRun;
use App\Models\ProductionTask;
use App\Models\StockLot;
use App\Models\User;
use App\Models\Workspace;
use App\Services\MediaAssetUploadService;
use App\Services\Production\ProductionDetailPresenter;
use App\Services\ProductionBenchAccess;
use App\Support\NumberLocale;
use Illuminate\Contracts\View\View;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\WithFileUploads;

class ProductionDetail extends Component
{
    use InteractsWithAppNotifications;
    use WithFileUploads;

    public string $productionId = '';

    public string $cancellationReason = '';

    public ?string $statusMessage = null;

    public string $statusType = 'idle';

    /** @var array<string, array{stock_lot_id?: int|null, quantity: string, note?: string|null}> */
    public array $actualRows = [];

    /** @var array<string, array{actual_mass_grams: string}> */
    public array $calculatedActualRows = [];

    public bool $actualsDirty = false;

    public string $outputMode = 'units';

    public string $actualOutputQuantity = '';

    public string $manufactureDate = '';

    // The HTML select sends '' as its no-choice sentinel; typing this ?int
    // would make the empty value coerce ambiguously across Livewire versions.
    // The boundary cast happens in complete().
    public ?string $outputIngredientId = null;

    public string $abortReason = '';

    public string $issueKind = 'shipment';

    public string $issueQuantity = '';

    public string $issueNote = '';

    public string $journalBody = '';

    /** @var UploadedFile|null */
    public $journalDocumentUpload = null;

    public string $journalDocumentNote = '';

    public function assignBatchNumber(AssignProductionBatchNumbers $assignProductionBatchNumbers): void
    {
        try {
            $assignProductionBatchNumbers->handle(
                actor: $this->user(),
                workspace: $this->workspace(),
                productionIds: [(int) $this->productionId],
            );
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                foreach ($messages as $message) {
                    $this->addError(
                        in_array($field, ['production_ids', 'batch_number', 'next_permanent_serial'], true)
                            ? 'production_bench'
                            : $field,
                        $message,
                    );
                }
            }

            return;
        }

        $this->showAppNotification(__('production_bench.production.batch_number_assigned'));
        $this->dispatch('production-batch-numbers-updated');
    }

    public function assignTask(int $taskId, ?string $employeeId, AssignProductionTask $assignProductionTask): void
    {
        try {
            $task = $this->task($taskId);

            $assignProductionTask->handle(
                actor: $this->user(),
                task: $task,
                employeeId: filled($employeeId) ? (int) $employeeId : null,
                departmentId: $task->department_id,
            );
        } catch (ValidationException $exception) {
            $this->addTaskErrors($exception);

            return;
        }

        $this->showAppNotification(__('production_bench.settings.saved'));
        $this->dispatch('production-task-updated');
    }

    public function assignTaskDepartment(int $taskId, ?string $departmentId, AssignProductionTask $assignProductionTask): void
    {
        try {
            $task = $this->task($taskId);

            $assignProductionTask->handle(
                actor: $this->user(),
                task: $task,
                employeeId: $task->employee_id,
                departmentId: filled($departmentId) ? (int) $departmentId : null,
            );
        } catch (ValidationException $exception) {
            $this->addTaskErrors($exception);

            return;
        }

        $this->showAppNotification(__('production_bench.settings.saved'));
        $this->dispatch('production-task-updated');
    }

    public function toggleTask(int $taskId, CompleteProductionTask $completeProductionTask, ReopenProductionTask $reopenProductionTask): void
    {
        $task = $this->task($taskId);

        try {
            if ($task->completed_at === null) {
                $completeProductionTask->handle($this->user(), $task);
            } else {
                $reopenProductionTask->handle($this->user(), $task);
            }
        } catch (ValidationException $exception) {
            $this->addTaskErrors($exception);

            return;
        }

        $this->showAppNotification(__('production_bench.settings.saved'));
        $this->dispatch('production-task-updated');
    }

    public function rescheduleTask(int $taskId, string $scheduledFor, RescheduleProductionTask $rescheduleProductionTask): void
    {
        try {
            $rescheduleProductionTask->handle(
                actor: $this->user(),
                task: $this->task($taskId),
                scheduledFor: $scheduledFor,
            );
        } catch (ValidationException $exception) {
            $this->addTaskErrors($exception);

            return;
        }

        $this->showAppNotification(__('production_bench.settings.saved'));
        $this->dispatch('production-task-updated');
    }

    public function resetTaskDate(int $taskId, ResetProductionTaskDate $resetProductionTaskDate): void
    {
        try {
            $resetProductionTaskDate->handle($this->user(), $this->task($taskId));
        } catch (ValidationException $exception) {
            $this->addTaskErrors($exception);

            return;
        }

        $this->showAppNotification(__('production_bench.settings.saved'));
        $this->dispatch('production-task-updated');
    }

    public function mount(string|int|ProductionRun $productionId): void
    {
        if ($productionId instanceof ProductionRun) {
            $this->productionId = (string) $productionId->id;
            $this->loadSavedActualRows();

            return;
        }

        if (is_numeric($productionId)) {
            $this->productionId = (string) $productionId;
            $this->loadSavedActualRows();

            return;
        }

        $this->productionId = (string) (ProductionRun::query()
            ->where('public_id', $productionId)
            ->value('id') ?? abort(404));
        $this->loadSavedActualRows();
    }

    /**
     * Load saved actual rows so a page reload never shows reservation
     * defaults over real bench data.
     */
    private function loadSavedActualRows(): void
    {
        if ($this->productionId === '') {
            return;
        }

        $production = ProductionRun::query()
            ->where('workspace_id', $this->workspace()->id)
            ->with(['consumption', 'requirements.reservations.stockLot', 'formulaLines'])
            ->find((int) $this->productionId);

        if ($production === null) {
            return;
        }

        foreach ($production->consumption as $consumption) {
            $key = $consumption->production_requirement_id.'-'.($consumption->stock_lot_id ?? '');
            $this->actualRows[$key] = [
                'stock_lot_id' => $consumption->stock_lot_id,
                'quantity' => (string) $consumption->quantity,
                'note' => $consumption->note,
            ];
        }

        foreach ($production->requirements as $requirement) {
            if ($production->status !== ProductionRunStatus::InProduction) {
                continue;
            }

            foreach ($requirement->reservations->where('status', StockReservationStatus::Active) as $reservation) {
                $key = $requirement->id.'-'.$reservation->stock_lot_id;
                $this->actualRows[$key] ??= [
                    'stock_lot_id' => $reservation->stock_lot_id,
                    'quantity' => (string) $reservation->quantity,
                    'note' => null,
                ];
            }
        }

        foreach ($production->formulaLines->filter(fn ($line): bool => $line->component?->value === 'water') as $line) {
            $this->calculatedActualRows[(string) $line->id] = [
                'actual_mass_grams' => (string) ($line->actual_mass_grams ?? $line->planned_mass_grams),
            ];
        }
    }

    public function cancel(CancelProduction $cancelProduction): void
    {
        try {
            $cancelProduction->handle(
                actor: $this->user(),
                production: $this->production(),
                reason: $this->cancellationReason,
            );
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                foreach ($messages as $message) {
                    $this->addError(in_array($field, ['production', 'production_bench'], true) ? 'cancellationReason' : $field, $message);
                }
            }

            return;
        }

        $this->cancellationReason = '';
        $this->showAppNotification(__('production_bench.settings.saved'));
        $this->dispatch('production-cancelled');
    }

    public function start(StartProduction $startProduction): void
    {
        $production = $this->production();

        if ($production->planned_for?->isFuture()) {
            $this->dispatch(
                'early-start-confirmation-requested',
                plannedFor: $production->planned_for->format('Y-m-d'),
            );

            return;
        }

        $this->performStart($startProduction, $production);
    }

    public function confirmEarlyStart(StartProduction $startProduction): void
    {
        $this->performStart($startProduction, $this->production());
    }

    private function performStart(StartProduction $startProduction, ProductionRun $production): void
    {
        try {
            $startProduction->handle(
                actor: $this->user(),
                production: $production,
            );
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                foreach ($messages as $message) {
                    $this->addError(in_array($field, ['production', 'production_bench'], true) ? 'production' : $field, $message);
                }
            }

            return;
        }

        $this->loadSavedActualRows();

        $this->showAppNotification(__('production_bench.production.started'));
        $this->dispatch('production-started');
    }

    public function releaseStock(ReleaseProductionStock $releaseProductionStock): void
    {
        try {
            $releaseProductionStock->handle(
                actor: $this->user(),
                production: $this->production(),
            );
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                foreach ($messages as $message) {
                    $this->addError($field, $message);
                }
            }

            return;
        }

        $this->showAppNotification(__('production_bench.settings.saved'));
        $this->dispatch('production-stock-released');
    }

    public function updatedActualRows(): void
    {
        $this->actualsDirty = true;
    }

    public function updatedCalculatedActualRows(): void
    {
        $this->actualsDirty = true;
    }

    public function saveActuals(SaveProductionActuals $saveProductionActuals): void
    {
        $production = $this->production();

        try {
            $rows = [];

            foreach ($this->actualRows as $key => $row) {
                [$requirementId, $lotId] = array_pad(explode('-', (string) $key, 2), 2, '');

                if ($requirementId === '') {
                    continue;
                }

                $rows[] = [
                    'production_requirement_id' => (int) $requirementId,
                    'stock_lot_id' => $lotId !== ''
                        ? (int) $lotId
                        : (isset($row['stock_lot_id']) && $row['stock_lot_id'] !== '' && $row['stock_lot_id'] !== null
                            ? (int) $row['stock_lot_id']
                            : null),
                    'quantity' => NumberLocale::normalizeDecimalString($row['quantity'] ?? '') ?? '0',
                    'note' => isset($row['note']) && $row['note'] !== '' ? $row['note'] : null,
                ];
            }

            $calculatedRows = [];

            foreach ($this->calculatedActualRows as $lineId => $row) {
                $calculatedRows[] = [
                    'production_formula_line_id' => (int) $lineId,
                    'actual_mass_grams' => NumberLocale::normalizeDecimalString($row['actual_mass_grams'] ?? '')
                        ?? '0',
                ];
            }

            $saveProductionActuals->handle($this->user(), $production, $rows, $calculatedRows);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                foreach ($messages as $message) {
                    $this->addError('actuals', $message);
                }
            }

            return;
        }

        $freshProduction = $production->fresh(['requirements', 'consumption', 'formulaLines']);
        $this->actualRows = $this->actualRowsFromProduction($freshProduction);
        $this->calculatedActualRows = $this->calculatedActualRowsFromProduction($freshProduction);
        $this->actualsDirty = false;
        $this->showAppNotification(__('production_bench.production.actuals_saved'));
        $this->dispatch('production-actuals-saved');
    }

    public string $scheduleDate = '';

    public function scheduleProduction(ScheduleProduction $scheduleProduction): void
    {
        $this->validate([
            'scheduleDate' => ['required', 'date_format:Y-m-d'],
        ]);

        $production = $this->production();

        try {
            $scheduleProduction->handle(
                actor: $this->user(),
                production: $production,
                plannedFor: $this->scheduleDate,
            );
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                foreach ($messages as $message) {
                    $this->addError($field === 'planned_for' ? 'scheduleDate' : 'production', $message);
                }
            }

            return;
        }

        $this->scheduleDate = '';
        $this->showAppNotification(__('production_bench.production.planned_success'));
        $this->dispatch('production-scheduled');
    }

    public function complete(CompleteProduction $completeProduction): void
    {
        $production = $this->production();

        try {
            if ($this->manufactureDate === '') {
                $this->addError('manufacture_date', __('production_bench.production.manufacture_date_required'));

                return;
            }

            $completeProduction->handle(
                actor: $this->user(),
                production: $production,
                actualOutputQuantity: NumberLocale::normalizeDecimalString($this->actualOutputQuantity) ?? $this->actualOutputQuantity,
                manufactureDate: $this->manufactureDate,
                outputIngredientId: $this->outputMode === 'intermediate' && $this->outputIngredientId !== null
                    ? (int) $this->outputIngredientId
                    : null,
            );
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                foreach ($messages as $message) {
                    $this->addError($field, $message);
                }
            }

            return;
        }

        $this->showAppNotification(__('production_bench.production.completed'));
        $this->dispatch('production-completed');
    }

    public function abort(AbortProduction $abortProduction): void
    {
        try {
            $abortProduction->handle(
                actor: $this->user(),
                production: $this->production(),
                reason: $this->abortReason,
            );
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                foreach ($messages as $message) {
                    $this->addError($field, $message);
                }
            }

            return;
        }

        $this->showAppNotification(__('production_bench.production.aborted'));
        $this->dispatch('production-aborted');
    }

    public function releaseOutput(): void
    {
        $outputLot = $this->production()->outputLot;

        if (! $outputLot instanceof StockLot) {
            return;
        }

        try {
            app(ReleaseOutputLot::class)->handle($this->user(), $outputLot);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                foreach ($messages as $message) {
                    $this->addError('output', $message);
                }
            }

            return;
        }

        $this->showAppNotification(__('production_bench.production.output_released'));
        $this->dispatch('production-output-released');
    }

    public function issueFinishedGoods(): void
    {
        $outputLot = $this->production()->outputLot;

        if (! $outputLot instanceof StockLot) {
            return;
        }

        $kind = match ($this->issueKind) {
            'sample' => StockMovementType::Sample,
            'damaged' => StockMovementType::Damaged,
            'internal_use' => StockMovementType::InternalUse,
            default => StockMovementType::Shipment,
        };

        try {
            app(IssueFinishedGoods::class)->handle(
                actor: $this->user(),
                outputLot: $outputLot,
                kind: $kind,
                quantity: NumberLocale::normalizeDecimalString($this->issueQuantity) ?? $this->issueQuantity,
                note: $this->issueNote,
            );
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                foreach ($messages as $message) {
                    $this->addError('output', $message);
                }
            }

            return;
        }

        $this->issueQuantity = '';
        $this->issueNote = '';
        $this->showAppNotification(__('production_bench.production.issued'));
        $this->dispatch('production-output-issued');
    }

    public function saveJournalEntry(SaveProductionJournalEntry $saveProductionJournalEntry): void
    {
        try {
            $saveProductionJournalEntry->handle(
                actor: $this->user(),
                production: $this->production(),
                body: $this->journalBody,
            );
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                foreach ($messages as $message) {
                    $this->addError($field, $message);
                }
            }

            return;
        }

        $this->journalBody = '';
        $this->showAppNotification(__('production_bench.production.journal_added'));
        $this->dispatch('production-journal-updated');
    }

    public function attachJournalDocument(MediaAssetUploadService $uploads): void
    {
        $production = $this->production();

        $validated = $this->validate([
            'journalDocumentUpload' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp,heic,heif', 'max:10240'],
            'journalDocumentNote' => ['nullable', 'string', 'max:1000'],
        ]);

        $asset = null;

        try {
            $asset = $uploads->start(
                $this->user(),
                $this->workspace(),
                $validated['journalDocumentUpload'],
                [MediaAssetType::Image, MediaAssetType::Pdf],
                processSynchronously: true,
            )->refresh();

            app(AttachProductionDocument::class)->handle(
                actor: $this->user(),
                documentable: $production,
                asset: $asset,
                type: ProductionDocumentType::Journal,
                note: filled($validated['journalDocumentNote'] ?? null) ? trim($validated['journalDocumentNote']) : null,
            );
        } catch (\Throwable $exception) {
            if ($asset !== null && $asset->exists) {
                try {
                    $uploads->rollbackUnreferencedUpload($this->user(), $this->workspace(), $asset);
                } catch (\Throwable $cleanupException) {
                    report($cleanupException);
                }
            }

            if (! $exception instanceof ValidationException) {
                throw $exception;
            }

            $errors = $exception->errors();

            if (isset($errors['upload']) || isset($errors['document'])) {
                throw ValidationException::withMessages([
                    'journalDocumentUpload' => collect($errors['upload'] ?? $errors['document'])->first(),
                ]);
            }

            throw $exception;
        }

        $this->reset('journalDocumentUpload', 'journalDocumentNote');
        $this->showAppNotification(__('production_bench.production.journal_document_attached'));
        $this->dispatch('production-journal-updated');
    }

    public function render(ProductionBenchAccess $access, ProductionDetailPresenter $detailPresenter): View
    {
        $workspace = $this->workspace();
        $production = $this->production();
        $productionDetail = $detailPresenter->present(
            production: $production,
            actualRows: $this->actualRows,
            calculatedActualRows: $this->calculatedActualRows,
            locale: $this->user()->number_locale,
        );
        $canMutate = $access->isActive($workspace)
            && ! $access->isReadOnly($workspace)
            && in_array($workspace->roleFor($this->user()), [
                WorkspaceMemberRole::Owner,
                WorkspaceMemberRole::Admin,
                WorkspaceMemberRole::Editor,
            ], true);

        $completionReadiness = $this->completionReadiness($production);

        return view('livewire.production-bench.production.production-detail', [
            'workspace' => $workspace,
            'production' => $production,
            'productionDetail' => $productionDetail,
            'outputReconciliation' => $productionDetail['output'],
            'isBenchActive' => $access->isActive($workspace),
            'isReadOnly' => $access->isReadOnly($workspace),
            'canMutate' => $canMutate,
            'completionReadiness' => $completionReadiness,
            'intermediateIngredients' => Ingredient::query()
                ->withoutGlobalScopes()
                ->where(fn ($query) => $query->whereNull('workspace_id')->orWhere('workspace_id', $workspace->id))
                ->orderBy('display_name')
                ->get(['id', 'display_name']),
            'employees' => Employee::query()
                ->where('workspace_id', $workspace->id)
                ->where('is_active', true)
                ->orderBy('last_name')
                ->orderBy('first_name')
                ->get(),
            'departments' => Department::query()
                ->where('workspace_id', $workspace->id)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(),
        ]);
    }

    private function task(int $taskId): ProductionTask
    {
        return ProductionTask::query()
            ->where('workspace_id', $this->workspace()->id)
            ->findOrFail($taskId);
    }

    private function addTaskErrors(ValidationException $exception): void
    {
        foreach ($exception->errors() as $field => $messages) {
            foreach ($messages as $message) {
                $this->addError('task_'.$field, $message);
            }
        }
    }

    private function production(): ProductionRun
    {
        return ProductionRun::query()
            ->where('workspace_id', $this->workspace()->id)
            ->with(['recipe', 'requirements.reservations.stockLot', 'formulaLines', 'consumption.stockLot', 'documents.mediaAsset', 'tasks.employee', 'tasks.department', 'journalEntries.createdBy', 'outputLot', 'cancelledBy', 'batchNumberAssignedBy'])
            ->findOrFail((int) $this->productionId);
    }

    /**
     * @return array<string, array{ok: bool, message: string|null}>
     */
    private function completionReadiness(ProductionRun $production): array
    {
        if ($production->status !== ProductionRunStatus::InProduction) {
            return [];
        }

        $missingActuals = $production->requirements
            ->reject(fn ($requirement): bool => $production->consumption->contains(
                fn ($consumption): bool => $consumption->production_requirement_id === $requirement->id,
            ))
            ->pluck('subject_name_snapshot')
            ->values();
        $missingWaterActual = $production->formulaLines
            ->filter(fn ($line): bool => $line->component?->value === 'water')
            ->filter(fn ($line): bool => $line->actual_mass_grams === null || bccomp((string) $line->actual_mass_grams, '0', 9) <= 0)
            ->pluck('subject_name_snapshot')
            ->values();
        $missingActuals = $missingActuals->merge($missingWaterActual)->values();

        $shortRequirements = collect();
        $activeReservations = $production->requirements
            ->flatMap->reservations
            ->where('status', StockReservationStatus::Active);

        foreach ($production->requirements as $requirement) {
            $required = $requirement->ingredient_id !== null
                ? (string) $requirement->required_mass_grams
                : (string) $requirement->required_units;
            $reserved = '0';

            foreach ($activeReservations->where('production_requirement_id', $requirement->id) as $reservation) {
                $reserved = bcadd($reserved, (string) $reservation->quantity, 9);
            }

            if (bccomp($reserved, $required, 9) < 0) {
                $shortRequirements->push($requirement->subject_name_snapshot);
            }
        }

        $unpricedLots = $production->consumption
            ->map(fn ($consumption): ?string => $consumption->stockLot?->costing_unit_cost === null
                && $consumption->stockLot?->historical_unit_cost === null
                    ? $consumption->stockLot?->internal_lot_code
                    : null)
            ->filter()
            ->unique()
            ->values();

        return [
            'actuals' => [
                'ok' => $missingActuals->isEmpty(),
                'message' => $missingActuals->isEmpty() ? null : $missingActuals->implode(', '),
            ],
            'coverage' => [
                'ok' => $shortRequirements->isEmpty(),
                'message' => $shortRequirements->isEmpty() ? null : $shortRequirements->implode(', '),
            ],
            'output' => ['ok' => $this->actualOutputQuantity !== '', 'message' => null],
            'date' => ['ok' => $this->manufactureDate !== '', 'message' => null],
            'number' => ['ok' => $production->batch_number !== null, 'message' => null],
            'costs' => ['ok' => $unpricedLots->isEmpty(), 'message' => $unpricedLots->isEmpty() ? null : $unpricedLots->implode(', ')],
        ];
    }

    /**
     * @return array<string, array{stock_lot_id: int|null, quantity: string, note: string|null}>
     */
    private function actualRowsFromProduction(ProductionRun $production): array
    {
        $rows = [];

        foreach ($production->consumption as $consumption) {
            $rows[$consumption->production_requirement_id.'-'.($consumption->stock_lot_id ?? '')] = [
                'stock_lot_id' => $consumption->stock_lot_id,
                'quantity' => (string) $consumption->quantity,
                'note' => $consumption->note,
            ];
        }

        return $rows;
    }

    /**
     * @return array<string, array{actual_mass_grams: string}>
     */
    private function calculatedActualRowsFromProduction(ProductionRun $production): array
    {
        $rows = [];

        foreach ($production->formulaLines as $line) {
            if ($line->component?->value !== 'water') {
                continue;
            }

            $rows[(string) $line->id] = [
                'actual_mass_grams' => (string) ($line->actual_mass_grams ?? $line->planned_mass_grams),
            ];
        }

        return $rows;
    }

    private function user(): User
    {
        return auth()->user() ?? abort(401);
    }

    private function workspace(): Workspace
    {
        return $this->user()->company() ?? abort(404);
    }
}
