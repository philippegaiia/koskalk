<?php

namespace App\Livewire\ProductionBench\Production;

use App\Actions\Inventory\AttachProductionDocument;
use App\Actions\Inventory\DetachProductionDocument;
use App\Actions\Production\AbortProduction;
use App\Actions\Production\AssignProductionBatchNumbers;
use App\Actions\Production\AssignProductionLocation;
use App\Actions\Production\AssignProductionTask;
use App\Actions\Production\CancelProduction;
use App\Actions\Production\CompleteProduction;
use App\Actions\Production\CompleteProductionTask;
use App\Actions\Production\IssueFinishedGoods;
use App\Actions\Production\ReleaseOutputLot;
use App\Actions\Production\ReleaseProductionStock;
use App\Actions\Production\ReopenProductionTask;
use App\Actions\Production\RescheduleProduction;
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
use App\Livewire\Concerns\InteractsWithProductionEditing;
use App\Livewire\Concerns\InteractsWithProductionWorkspace;
use App\Livewire\Concerns\NormalizesDatePickerState;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Ingredient;
use App\Models\ProductionLocation;
use App\Models\ProductionRun;
use App\Models\ProductionTask;
use App\Models\StockLot;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ContextualHelp\ProductionHelpTopics;
use App\Services\MediaAssetUploadService;
use App\Services\Production\ProductionDailyOccupancy;
use App\Services\Production\ProductionDetailPresenter;
use App\Services\ProductionBenchAccess;
use App\Support\NumberLocale;
use Carbon\CarbonImmutable;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Schemas\Schema;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Renderless;
use Livewire\Component;
use Livewire\WithFileUploads;

class ProductionDetail extends Component implements HasActions, HasForms
{
    use InteractsWithActions;
    use InteractsWithAppNotifications;
    use InteractsWithForms;
    use InteractsWithProductionEditing;
    use InteractsWithProductionWorkspace;
    use NormalizesDatePickerState;
    use WithFileUploads;

    #[Locked]
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

    public string $estimatedReadyOn = '';

    /** @var array<int, string> */
    public array $taskDates = [];

    public ?string $productionLocationId = null;

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
            $context = $this->productionEditingContext();
            $assignProductionBatchNumbers->handle(
                actor: $this->user(),
                workspace: $this->workspace(),
                productionIds: [(int) $this->productionId],
                editing: $context,
            );
            $this->acknowledgeProductionMutation($context);
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

            $context = $this->productionEditingContext();
            $assignProductionTask->handle(
                actor: $this->user(),
                task: $task,
                employeeId: filled($employeeId) ? (int) $employeeId : null,
                departmentId: $task->department_id,
                editing: $context,
            );
            $this->acknowledgeProductionMutation($context);
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

            $context = $this->productionEditingContext();
            $assignProductionTask->handle(
                actor: $this->user(),
                task: $task,
                employeeId: $task->employee_id,
                departmentId: filled($departmentId) ? (int) $departmentId : null,
                editing: $context,
            );
            $this->acknowledgeProductionMutation($context);
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
                $context = $this->productionEditingContext();
                $completeProductionTask->handle($this->user(), $task, editing: $context);
                $this->acknowledgeProductionMutation($context);
            } else {
                $context = $this->productionEditingContext();
                $reopenProductionTask->handle($this->user(), $task, editing: $context);
                $this->acknowledgeProductionMutation($context);
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
        $scheduledFor = $this->normalizeDatePickerState($scheduledFor);

        try {
            $context = $this->productionEditingContext();
            $savedTask = $rescheduleProductionTask->handle(
                actor: $this->user(),
                task: $this->task($taskId),
                scheduledFor: $scheduledFor,
                editing: $context,
            );
            $this->acknowledgeProductionMutation($context);
        } catch (ValidationException $exception) {
            $this->addTaskErrors($exception);

            return;
        }

        $this->taskDates = $savedTask->productionRun->tasks->mapWithKeys(fn (ProductionTask $task): array => [$task->id => $task->scheduled_for->toDateString()])->all();
        $this->showAppNotification(__('production_bench.settings.saved'));
        $this->dispatch('production-task-updated');
    }

    public function resetTaskDate(int $taskId, ResetProductionTaskDate $resetProductionTaskDate): void
    {
        try {
            $context = $this->productionEditingContext();
            $savedTask = $resetProductionTaskDate->handle($this->user(), $this->task($taskId), editing: $context);
            $this->acknowledgeProductionMutation($context);
        } catch (ValidationException $exception) {
            $this->addTaskErrors($exception);

            return;
        }

        $this->taskDates = $savedTask->productionRun->tasks->mapWithKeys(fn (ProductionTask $task): array => [$task->id => $task->scheduled_for->toDateString()])->all();

        $this->showAppNotification(__('production_bench.settings.saved'));
        $this->dispatch('production-task-updated');
    }

    public function mount(string|int|ProductionRun $productionId): void
    {
        if ($productionId instanceof ProductionRun) {
            $this->productionId = (string) $productionId->id;
        } elseif (is_numeric($productionId)) {
            $this->productionId = (string) $productionId;
        } else {
            $this->productionId = (string) (ProductionRun::query()
                ->where('public_id', $productionId)
                ->value('id') ?? abort(404));
        }

        $this->initializeProductionEditing([(int) $this->productionId]);
    }

    public function assignProductionLocation(AssignProductionLocation $assignProductionLocation): void
    {
        $locationId = trim((string) $this->productionLocationId) === ''
            ? null
            : (ctype_digit((string) $this->productionLocationId) ? (int) $this->productionLocationId : 0);

        try {
            $context = $this->productionEditingContext();
            $production = $assignProductionLocation->handle(
                actor: $this->user(),
                production: $this->production(),
                locationId: $locationId,
                editing: $context,
            );
            $this->acknowledgeProductionMutation($context);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                foreach ($messages as $message) {
                    $this->addError(
                        $field === 'production_location_id' ? 'productionLocationId' : 'production',
                        $message,
                    );
                }
            }

            return;
        }

        $this->productionLocationId = $production->production_location_id === null
            ? null
            : (string) $production->production_location_id;
        $this->showAppNotification(__('locations.saved'));
        $this->dispatch('production-location-updated');
    }

    public function updatedManufactureDate(): void
    {
        $this->manufactureDate = $this->normalizeDatePickerState($this->manufactureDate);

        if ($this->estimatedReadyOn !== '' || $this->manufactureDate === '') {
            return;
        }

        $production = $this->production();

        if ($production->output_ready_delay_days === null) {
            return;
        }

        try {
            $this->estimatedReadyOn = CarbonImmutable::parse($this->manufactureDate)
                ->addDays((int) $production->output_ready_delay_days)
                ->toDateString();
        } catch (\Throwable) {
            $this->estimatedReadyOn = '';
        }
    }

    public function updatedEstimatedReadyOn(): void
    {
        $this->estimatedReadyOn = $this->normalizeDatePickerState($this->estimatedReadyOn);
    }

    /**
     * Load saved actual rows so a page reload never shows reservation
     * defaults over real bench data.
     */
    private function loadSavedActualRows(ProductionRun $production): void
    {
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
            $context = $this->productionEditingContext();
            $cancelProduction->handle(
                actor: $this->user(),
                production: $this->production(),
                reason: $this->cancellationReason,
                editing: $context,
            );
            $this->acknowledgeProductionMutation($context);
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
                message: __('production_bench.production.early_start_confirm', [
                    'date' => $production->planned_for->format('Y-m-d'),
                ]),
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
            $context = $this->productionEditingContext();
            $startedProduction = $startProduction->handle(
                actor: $this->user(),
                production: $production,
                editing: $context,
            );
            $this->acknowledgeProductionMutation($context);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                foreach ($messages as $message) {
                    $this->addError(in_array($field, ['production', 'production_bench'], true) ? 'production' : $field, $message);
                }
            }

            return;
        }

        $this->loadSavedActualRows($startedProduction);

        $this->showAppNotification(__('production_bench.production.started'));
        $this->dispatch('production-started');
    }

    public function releaseStock(ReleaseProductionStock $releaseProductionStock): void
    {
        try {
            $context = $this->productionEditingContext();
            $releaseProductionStock->handle(
                actor: $this->user(),
                production: $this->production(),
                editing: $context,
            );
            $this->acknowledgeProductionMutation($context);
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

            $context = $this->productionEditingContext();
            $savedProduction = $saveProductionActuals->handle($this->user(), $production, $rows, $calculatedRows, editing: $context);
            $this->acknowledgeProductionMutation($context);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                foreach ($messages as $message) {
                    $this->addError('actuals', $message);
                }
            }

            return;
        }

        $freshProduction = $savedProduction;
        $this->actualRows = $this->actualRowsFromProduction($freshProduction);
        $this->calculatedActualRows = $this->calculatedActualRowsFromProduction($freshProduction);
        $this->actualsDirty = false;
        $this->showAppNotification(__('production_bench.production.actuals_saved'));
        $this->dispatch('production-actuals-saved');
    }

    public ?string $scheduleDate = '';

    public function scheduleProduction(ScheduleProduction $scheduleProduction): void
    {
        $this->validate([
            'scheduleDate' => ['required', 'date_format:Y-m-d'],
        ]);

        $production = $this->production();

        try {
            $context = $this->productionEditingContext();
            $scheduleProduction->handle(
                actor: $this->user(),
                production: $production,
                plannedFor: $this->scheduleDate,
                editing: $context,
            );
            $this->acknowledgeProductionMutation($context);
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

    public function rescheduleProduction(RescheduleProduction $rescheduleProduction): void
    {
        $this->validate([
            'scheduleDate' => ['required', 'date_format:Y-m-d'],
        ]);

        try {
            $context = $this->productionEditingContext();
            $production = $rescheduleProduction->handle(
                actor: $this->user(),
                production: $this->production(),
                plannedFor: $this->scheduleDate,
                editing: $context,
            );
            $this->acknowledgeProductionMutation($context);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                foreach ($messages as $message) {
                    $this->addError(
                        $field === 'planned_for' ? 'scheduleDate' : 'production',
                        $message,
                    );
                }
            }

            return;
        }

        $this->scheduleDate = $production->planned_for?->format('Y-m-d') ?? '';
        $this->showAppNotification(__('production_bench.settings.saved'));
        $this->dispatch('production-rescheduled');
    }

    public function complete(CompleteProduction $completeProduction): void
    {
        $production = $this->production();

        try {
            if ($production->production_output_type === null) {
                validator([
                    'output_mode' => $this->outputMode,
                    'output_ingredient_id' => $this->outputIngredientId,
                ], [
                    'output_mode' => ['required', 'in:units,intermediate'],
                    'output_ingredient_id' => ['nullable', 'required_if:output_mode,intermediate', 'integer', 'min:1'],
                ], [
                    'output_ingredient_id.required_if' => __('production_bench.production.validation.output_ingredient_required'),
                ])->validate();
            }

            if ($this->manufactureDate === '') {
                $this->addError('manufacture_date', __('production_bench.production.manufacture_date_required'));

                return;
            }

            $context = $this->productionEditingContext();
            $completeProduction->handle(
                actor: $this->user(),
                production: $production,
                actualOutputQuantity: NumberLocale::normalizeDecimalString($this->actualOutputQuantity) ?? $this->actualOutputQuantity,
                manufactureDate: $this->manufactureDate,
                estimatedReadyOn: $this->estimatedReadyOn !== '' ? $this->estimatedReadyOn : null,
                outputIngredientId: $this->outputMode === 'intermediate' && $this->outputIngredientId !== null
                    ? (int) $this->outputIngredientId
                    : null,
                editing: $context,
            );
            $this->acknowledgeProductionMutation($context);
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
            $context = $this->productionEditingContext();
            $abortProduction->handle(
                actor: $this->user(),
                production: $this->production(),
                reason: $this->abortReason,
                editing: $context,
            );
            $this->acknowledgeProductionMutation($context);
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
        $this->performRelease(false);
    }

    public function confirmEarlyRelease(): void
    {
        $this->performRelease(true);
    }

    private function performRelease(bool $earlyReleaseConfirmed): void
    {
        $outputLot = $this->production()->outputLot;

        if (! $outputLot instanceof StockLot) {
            return;
        }

        try {
            $context = $this->productionEditingContext();
            app(ReleaseOutputLot::class)->handle(
                actor: $this->user(),
                lot: $outputLot,
                earlyReleaseConfirmed: $earlyReleaseConfirmed,
                editing: $context,
            );
            $this->acknowledgeProductionMutation($context);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                if ($field === 'early_release_confirmation') {
                    $this->dispatch(
                        'early-release-confirmation-requested',
                        message: (string) $messages[0],
                    );

                    continue;
                }

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
            $context = $this->productionEditingContext();
            app(IssueFinishedGoods::class)->handle(
                actor: $this->user(),
                outputLot: $outputLot,
                kind: $kind,
                quantity: NumberLocale::normalizeDecimalString($this->issueQuantity) ?? $this->issueQuantity,
                note: $this->issueNote,
                editing: $context,
            );
            $this->acknowledgeProductionMutation($context);
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
            $context = $this->productionEditingContext();
            $saveProductionJournalEntry->handle(
                actor: $this->user(),
                production: $this->production(),
                body: $this->journalBody,
                editing: $context,
            );
            $this->acknowledgeProductionMutation($context);
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
            $this->assertProductionEditingBeforeUpload();
            $asset = $uploads->start(
                $this->user(),
                $this->workspace(),
                $validated['journalDocumentUpload'],
                [MediaAssetType::Image, MediaAssetType::Pdf],
                processSynchronously: true,
            )->refresh();

            $context = $this->productionEditingContext();
            app(AttachProductionDocument::class)->handle(
                actor: $this->user(),
                documentable: $production,
                asset: $asset,
                type: ProductionDocumentType::Journal,
                note: filled($validated['journalDocumentNote'] ?? null) ? trim($validated['journalDocumentNote']) : null,
                editing: $context,
            );
            $this->acknowledgeProductionMutation($context);
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

    public function detachJournalDocument(int $documentId): void
    {
        $document = $this->production()->documents()
            ->whereKey($documentId)
            ->first();

        abort_unless($document !== null, 404);

        $context = $this->productionEditingContext();
        app(DetachProductionDocument::class)->handle($this->user(), $document, editing: $context);
        $this->acknowledgeProductionMutation($context);

        $this->showAppNotification(__('production_bench.production.journal_document_detached'));
        $this->dispatch('production-journal-updated');
    }

    public function render(
        ProductionHelpTopics $helpTopics,
        ProductionBenchAccess $access,
        ProductionDailyOccupancy $occupancy,
        ProductionDetailPresenter $detailPresenter,
    ): View {
        $workspace = $this->workspace();
        $production = $this->production();
        $productionLocations = $workspace->uses_production_locations
            ? ProductionLocation::query()
                ->where('workspace_id', $workspace->id)
                ->where('is_active', true)
                ->orderBy('name')
                ->get()
            : collect();
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
            'canDetachDocuments' => $access->canConfigure($this->user(), $workspace),
            'contextualHelp' => $helpTopics->resolve('detail', app()->getLocale()),
            'workspace' => $workspace,
            'production' => $production,
            'productionDetail' => $productionDetail,
            'outputReconciliation' => $productionDetail['output'],
            'isBenchActive' => $access->isActive($workspace),
            'isReadOnly' => $access->isReadOnly($workspace),
            'canMutate' => $canMutate,
            'editingPayload' => $this->productionEditingPayload(),
            'productionLocations' => $productionLocations,
            'capacityWarnings' => $this->capacityWarnings(
                workspace: $workspace,
                production: $production,
                plannedFor: filled($this->scheduleDate) ? $this->scheduleDate : ($production->planned_for?->format('Y-m-d') ?? ''),
                locationId: $this->selectedProductionLocationId(),
                productionLocations: $productionLocations,
                occupancy: $occupancy,
            ),
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
            ->where('production_run_id', (int) $this->productionId)
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
        if (isset($this->editingPresentation[(int) $this->productionId])) {
            return $this->productionModelFromSnapshot($this->editingPresentation[(int) $this->productionId]);
        }

        return $this->productionSnapshotQuery()->where('workspace_id', $this->workspace()->id)->findOrFail((int) $this->productionId);
    }

    private function productionSnapshotQuery(): Builder
    {
        return ProductionRun::query()
            ->where('workspace_id', $this->workspace()->id)
            ->with(['recipe', 'outputIngredient', 'productionLocation', 'requirements.reservations.stockLot', 'formulaLines', 'consumption.stockLot', 'documents.mediaAsset', 'tasks.employee', 'tasks.department', 'journalEntries.createdBy:id,name', 'outputLot', 'cancelledBy:id,name', 'batchNumberAssignedBy:id,name']);
    }

    private function loadSavedProductionState(): void
    {
        $production = ProductionRun::query()
            ->where('workspace_id', $this->workspace()->id)
            ->find((int) $this->productionId);

        if (! $production instanceof ProductionRun) {
            return;
        }

        $this->productionLocationId = $production->production_location_id === null
            ? null
            : (string) $production->production_location_id;
        $this->scheduleDate = $production->planned_for?->format('Y-m-d') ?? '';
    }

    /**
     * @param  Collection<int, ProductionLocation>  $productionLocations
     * @return list<array{label: string, count: int, limit: int}>
     */
    private function capacityWarnings(
        Workspace $workspace,
        ProductionRun $production,
        string $plannedFor,
        ?int $locationId,
        Collection $productionLocations,
        ProductionDailyOccupancy $occupancy,
    ): array {
        if (! $this->isDate($plannedFor)) {
            return [];
        }

        $occupied = $occupancy->between(
            workspace: $workspace,
            from: $plannedFor,
            through: $plannedFor.' 23:59:59',
            exceptRunId: $production->id,
        );
        $warnings = [];
        $overallCount = ($occupied['overall'][$plannedFor] ?? 0) + 1;

        if ($overallCount > (int) $workspace->production_daily_limit) {
            $warnings[] = [
                'label' => __('locations.daily_production_limit'),
                'count' => $overallCount,
                'limit' => (int) $workspace->production_daily_limit,
            ];
        }

        if (! $workspace->uses_production_locations || $locationId === null) {
            return $warnings;
        }

        $location = $productionLocations->firstWhere('id', $locationId);
        if (! $location instanceof ProductionLocation
            && $production->productionLocation?->id === $locationId) {
            $location = $production->productionLocation;
        }

        if (! $location instanceof ProductionLocation) {
            return $warnings;
        }

        $locationCount = ($occupied['locations'][$location->id][$plannedFor] ?? 0) + 1;
        if ($locationCount > $location->daily_production_limit) {
            $warnings[] = [
                'label' => $location->name,
                'count' => $locationCount,
                'limit' => (int) $location->daily_production_limit,
            ];
        }

        return $warnings;
    }

    private function selectedProductionLocationId(): ?int
    {
        if (! $this->workspace()->uses_production_locations || trim((string) $this->productionLocationId) === '') {
            return null;
        }

        return ctype_digit((string) $this->productionLocationId)
            ? (int) $this->productionLocationId
            : 0;
    }

    private function isDate(string $value): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return false;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = \DateTimeImmutable::getLastErrors();

        return $date !== false
            && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
            && $date->format('Y-m-d') === $value;
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

    public function updated(string $property): void
    {
        if (str_starts_with($property, 'taskDates.')) {
            $id = substr($property, strlen('taskDates.'));
            $this->taskDates[$id] = $this->normalizeDatePickerState($this->taskDates[$id]);

            return;
        }
        if ($property !== 'scheduleDate') {
            return;
        }

        $value = $this->scheduleDate ?? '';
        $date = preg_match('/^\d{4}-\d{2}-\d{2}(?: 00:00:00)?$/', $value) === 1 ? substr($value, 0, 10) : '';
        $this->scheduleDate = validator(['date' => $date], ['date' => 'required|date_format:Y-m-d'])->passes() ? $date : '';
    }

    public function planningDateForm(Schema $schema): Schema
    {
        return $schema->components([
            DatePicker::make('scheduleDate')
                ->label(__('production_bench.production.production_date'))
                ->native(false)
                ->displayFormat('d/m/Y')
                ->required()
                ->extraAlpineAttributes($this->productionDateAttributes('planning', 'scheduleDate'))
                ->disabled(! app(ProductionBenchAccess::class)->canWrite($this->user(), $this->workspace())),
        ]);
    }

    public function completionDatesForm(Schema $schema): Schema
    {
        $disabled = ! app(ProductionBenchAccess::class)->canWrite($this->user(), $this->workspace());

        return $schema->components([
            DatePicker::make('manufactureDate')
                ->label(__('production_bench.production.manufacture_date'))
                ->native(false)
                ->displayFormat('d/m/Y')
                ->extraAlpineAttributes($this->productionDateAttributes('completion', 'manufactureDate'))
                ->disabled($disabled),
            DatePicker::make('estimatedReadyOn')
                ->label(__('production_bench.production.estimated_ready_date'))
                ->native(false)
                ->displayFormat('d/m/Y')
                ->helperText(__('production_bench.production.estimated_ready_date_help'))
                ->extraAlpineAttributes($this->productionDateAttributes('completion', 'estimatedReadyOn'))
                ->disabled($disabled),
        ]);
    }

    public function taskDatesForm(Schema $schema): Schema
    {
        $disabled = ! app(ProductionBenchAccess::class)->canWrite($this->user(), $this->workspace());

        return $schema->components(collect($this->taskDates)
            ->map(fn (string $date, int $taskId): DatePicker => DatePicker::make("taskDates.{$taskId}")
                ->key("task_date_{$taskId}")
                ->label(__('production_bench.production.production_date'))
                ->hiddenLabel()
                ->native(false)
                ->displayFormat('d/m/Y')
                ->disabled($disabled)
                ->extraAlpineAttributes($this->productionDateAttributes('tasks', "taskDates.{$taskId}")))
            ->values()
            ->all());
    }

    private function productionDateAttributes(string $group, string $path): array
    {
        $groupJson = json_encode($group, JSON_THROW_ON_ERROR);
        $pathJson = json_encode($path, JSON_THROW_ON_ERROR);

        $command = $group === 'tasks' ? " runCommand('rescheduleTask', [".(int) substr($path, strlen('taskDates.')).", date], 'tasks');" : '';

        return [
            'x-init' => e("state = value({$groupJson}, {$pathJson}); \$watch('state', next => { const date = (next || '').slice(0, 10); if (date !== value({$groupJson}, {$pathJson})) { field({$groupJson}, {$pathJson}, date); {$command} } }); \$watch('forms.{$group}', () => { const next = value({$groupJson}, {$pathJson}); if ((state || '').slice(0, 10) !== next) state = next; });"),
            'x-effect' => e('[$refs.button, $refs.button?.querySelector("input")].forEach(input => { if (input) input.disabled = !canWrite; })'),
        ];
    }

    private function productionDraftGroupsFromSnapshot(Collection $productions): array
    {
        $production = $productions->first();
        $actualRows = $this->actualRowsFromProduction($production);
        if ($production->status === ProductionRunStatus::InProduction) {
            foreach ($production->requirements as $requirement) {
                foreach ($requirement->reservations->where('status', StockReservationStatus::Active) as $reservation) {
                    $actualRows[$requirement->id.'-'.$reservation->stock_lot_id] ??= [
                        'stock_lot_id' => $reservation->stock_lot_id, 'quantity' => (string) $reservation->quantity, 'note' => null,
                    ];
                }
            }
        }

        return [
            'actuals' => ['actualRows' => $actualRows, 'calculatedActualRows' => $this->calculatedActualRowsFromProduction($production)],
            'planning' => ['scheduleDate' => $production->planned_for?->toDateString() ?? ''],
            'location' => ['productionLocationId' => $production->production_location_id === null ? null : (string) $production->production_location_id],
            'completion' => [
                'actualOutputQuantity' => (string) ($production->actual_output_units ?? $production->actual_output_mass_grams ?? ''),
                'manufactureDate' => $production->manufacture_date?->toDateString() ?? '',
                'estimatedReadyOn' => $production->estimated_ready_on?->toDateString() ?? '',
                'outputMode' => $production->output_ingredient_id === null ? 'units' : 'intermediate',
                'outputIngredientId' => $production->output_ingredient_id === null ? null : (string) $production->output_ingredient_id,
            ],
            'tasks' => ['taskDates' => $production->tasks->mapWithKeys(fn (ProductionTask $task): array => [$task->id => $task->scheduled_for->toDateString()])->all()],
            'cancellation' => ['cancellationReason' => ''], 'abort' => ['abortReason' => ''],
            'issue' => ['issueKind' => $this->issueKind, 'issueQuantity' => '', 'issueNote' => ''],
            'journal' => ['journalBody' => ''], 'document' => ['journalDocumentNote' => ''],
        ];
    }

    private function productionDraftGroups(): array
    {
        return collect($this->productionDraftFields())->map(fn (array $fields): array => collect($fields)->mapWithKeys(fn (string $field): array => [$field => $this->{$field}])->all())->all();
    }

    private function productionDraftFields(): array
    {
        return [
            'actuals' => ['actualRows', 'calculatedActualRows'],
            'planning' => ['scheduleDate'], 'location' => ['productionLocationId'],
            'completion' => ['outputMode', 'actualOutputQuantity', 'outputIngredientId', 'manufactureDate', 'estimatedReadyOn'],
            'tasks' => ['taskDates'], 'cancellation' => ['cancellationReason'], 'abort' => ['abortReason'],
            'issue' => ['issueKind', 'issueQuantity', 'issueNote'], 'journal' => ['journalBody'], 'document' => ['journalDocumentNote'],
        ];
    }

    #[Renderless]
    public function executeEditingCommand(string $method, array $arguments = [], ?array $submitted = null, ?string $group = null): array
    {
        $commands = [
            'assignBatchNumber' => [null, []], 'assignProductionLocation' => ['location', []],
            'assignTask' => [null, ['taskId', 'employeeId']], 'assignTaskDepartment' => [null, ['taskId', 'departmentId']],
            'toggleTask' => [null, ['taskId']], 'rescheduleTask' => ['tasks', ['taskId', 'scheduledFor']], 'resetTaskDate' => ['tasks', ['taskId']],
            'cancel' => ['cancellation', []], 'start' => [null, []], 'confirmEarlyStart' => [null, []], 'releaseStock' => [null, []],
            'saveActuals' => ['actuals', []], 'scheduleProduction' => ['planning', []], 'rescheduleProduction' => ['planning', []],
            'complete' => ['completion', []], 'abort' => ['abort', []], 'releaseOutput' => [null, []], 'confirmEarlyRelease' => [null, []],
            'issueFinishedGoods' => ['issue', []], 'saveJournalEntry' => ['journal', []], 'attachJournalDocument' => ['document', []],
            'detachJournalDocument' => [null, ['documentId']],
        ];
        abort_unless(isset($commands[$method]) && $group === $commands[$method][0] && count($arguments) === count($commands[$method][1]), 422);
        $this->resetErrorBag();
        $this->productionCommandAcknowledgment = [];
        try {
            if ($group !== null) {
                $fields = $this->productionDraftFields()[$group];
                abort_unless($submitted !== null && array_diff(array_keys($submitted), $fields) === [] && strlen(json_encode($submitted, JSON_THROW_ON_ERROR)) <= 200000, 422);
                foreach ($fields as $field) {
                    if (array_key_exists($field, $submitted)) {
                        $value = $submitted[$field];
                        $type = (new \ReflectionProperty($this, $field))->getType();
                        validator([$field => $value], [$field => $type->getName() === 'array'
                            ? ['array', 'max:1000'] : [($type->allowsNull() ? 'nullable' : 'present'), 'string', 'max:20000']])->validate();
                        $this->{$field} = $value;
                    }
                }
            }
            $named = array_combine($commands[$method][1], $arguments);
            app()->call([$this, $method], $named);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                foreach ($messages as $message) {
                    $this->addError($field, $message);
                }
            }
        }

        return [
            'ok' => $this->productionCommandAcknowledgment !== [] && $this->getErrorBag()->isEmpty(),
            'revisions' => $this->productionCommandAcknowledgment,
            'canonical' => $group === null ? null : $this->productionDraftGroups()[$group],
            'groups' => in_array($method, ['start', 'confirmEarlyStart'], true) && $this->productionCommandAcknowledgment !== []
                ? ['actuals' => $this->productionDraftGroups()['actuals']] : [],
            'state' => $this->observeProductionEditing(), 'errors' => $this->getErrorBag()->toArray(),
        ];
    }

    private function user(): User
    {
        return auth()->user() ?? abort(401);
    }

    private function workspace(): Workspace
    {
        return $this->productionWorkspace();
    }
}
