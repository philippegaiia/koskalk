<?php

namespace App\Livewire\Concerns;

use App\Actions\Inventory\AdjustStockLot;
use App\Enums\MassUnit;
use App\Enums\StockUnitKind;
use App\Models\StockLot;
use App\Services\Inventory\StockAdjustmentCalculator;
use App\Services\MassConverter;
use App\Support\NumberLocale;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Enums\Width;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

trait InteractsWithStockAdjustments
{
    #[Locked]
    public ?int $stockAdjustmentLotId = null;

    /** @var array{latest_movement_id: int|null, physical: string, reserved: string, status: string} */
    #[Locked]
    public array $stockAdjustmentSnapshot = [];

    #[Locked]
    public ?string $stockAdjustmentRequestKey = null;

    #[Locked]
    public bool $stockAdjustmentStale = false;

    public function adjustStockAction(): Action
    {
        return Action::make('adjustStock')
            ->label(__('production_bench.inventory.adjustment.action'))
            ->modalHeading(__('production_bench.inventory.adjustment.title'))
            ->modalSubmitActionLabel(__('production_bench.inventory.adjustment.save'))
            ->modalCancelActionLabel(__('production_bench.common.cancel'))
            ->modalWidth(Width::Large)
            ->modal()
            ->visible(fn (array $arguments): bool => $this->productionBenchAccess->canWrite($this->user(), $this->workspace())
                && $this->stockAdjustmentLotIsSupported($arguments['lot_id'] ?? null))
            ->fillForm(function (array $arguments, AdjustStockLot $adjust): array {
                $lot = $this->stockLotForAdjustmentAction($arguments['lot_id'] ?? null);
                $this->stockAdjustmentLotId = $lot->id;
                $this->stockAdjustmentSnapshot = $adjust->snapshot($this->user(), $this->workspace(), $lot->id);
                $this->stockAdjustmentRequestKey = (string) Str::uuid();
                $this->stockAdjustmentStale = false;

                return [
                    'mode' => 'set_counted',
                    'quantity' => null,
                    'unit' => $lot->unit_kind === StockUnitKind::Count
                        ? 'count'
                        : $this->workspace()->mass_display_system->priceUnit()->value,
                    'reason' => 'measurement_difference',
                    'note' => null,
                    'shortage_acknowledged' => false,
                ];
            })
            ->schema(fn (): array => [
                Placeholder::make('identity')
                    ->label(__('production_bench.inventory.item_lot'))
                    ->content(function (): string {
                        if ($this->stockAdjustmentLotId === null) {
                            return '';
                        }

                        $lot = $this->stockLotForAdjustmentAction($this->stockAdjustmentLotId);

                        return $lot->subjectName().' · '.$lot->internal_lot_code;
                    }),
                Placeholder::make('stale_notice')
                    ->label(__('production_bench.inventory.adjustment.review_required'))
                    ->content(fn (): string => $this->stockAdjustmentStale
                        ? __('production_bench.inventory.adjustment.stale')
                        : '')
                    ->visible(fn (): bool => $this->stockAdjustmentStale),
                Grid::make(2)->schema([
                    Placeholder::make('current_physical')
                        ->label(__('production_bench.inventory.adjustment.current_physical'))
                        ->content(fn (Get $get): string => $this->formatAdjustmentCanonical(
                            (string) ($this->stockAdjustmentSnapshot['physical'] ?? '0'),
                            (string) ($get('unit') ?: $this->adjustmentDefaultUnit()),
                        )),
                    Placeholder::make('current_reserved')
                        ->label(__('production_bench.inventory.adjustment.current_reserved'))
                        ->content(fn (Get $get): string => $this->formatAdjustmentCanonical(
                            (string) ($this->stockAdjustmentSnapshot['reserved'] ?? '0'),
                            (string) ($get('unit') ?: $this->adjustmentDefaultUnit()),
                        )),
                ])->columnSpanFull(),
                Select::make('mode')
                    ->label(__('production_bench.inventory.adjustment.mode'))
                    ->options(fn (): array => collect([
                        'set_counted' => __('production_bench.inventory.adjustment.modes.set_counted'),
                        'add' => __('production_bench.inventory.adjustment.modes.add'),
                        'remove' => __('production_bench.inventory.adjustment.modes.remove'),
                    ])->when(
                        bccomp((string) ($this->stockAdjustmentSnapshot['physical'] ?? '0'), '0', 9) <= 0,
                        fn ($options) => $options->except('remove'),
                    )->all())
                    ->required()
                    ->native(false)
                    ->live()
                    ->afterStateUpdated(fn (Set $set): mixed => $set('shortage_acknowledged', false)),
                Grid::make(2)->schema([
                    TextInput::make('quantity')
                        ->label(__('production_bench.inventory.adjustment.quantity'))
                        ->type('text')
                        ->inputMode('decimal')
                        ->required()
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (Set $set): mixed => $set('shortage_acknowledged', false)),
                    Select::make('unit')
                        ->label(__('production_bench.inventory.adjustment.unit'))
                        ->options(fn (): array => $this->adjustmentUnitOptions())
                        ->required()
                        ->native(false)
                        ->live()
                        ->afterStateUpdated(fn (Set $set): mixed => $set('shortage_acknowledged', false)),
                ])->columnSpanFull(),
                Placeholder::make('preview')
                    ->label(__('production_bench.inventory.adjustment.details'))
                    ->content(fn (Get $get): string => $this->adjustmentPreview($get)),
                Placeholder::make('shortage_warning')
                    ->label(__('production_bench.production.shortage'))
                    ->content(fn (Get $get): string => $this->adjustmentShortageWarning($get))
                    ->visible(fn (Get $get): bool => $this->adjustmentCreatesShortage($get)),
                Select::make('reason')
                    ->label(__('production_bench.inventory.adjustment.reason'))
                    ->options(fn (Get $get): array => $this->adjustmentReasonOptions($get))
                    ->required()
                    ->native(false),
                Textarea::make('note')
                    ->label(__('production_bench.inventory.adjustment.note'))
                    ->helperText(__('production_bench.inventory.adjustment.note_help'))
                    ->maxLength(1000)
                    ->rows(3),
                Checkbox::make('shortage_acknowledged')
                    ->label(__('production_bench.inventory.adjustment.shortage_acknowledgement'))
                    ->visible(fn (Get $get): bool => $this->adjustmentCreatesShortage($get))
                    ->columnSpanFull(),
            ])
            ->action(function (array $data, AdjustStockLot $adjust): void {
                if ($this->stockAdjustmentLotId === null
                    || $this->stockAdjustmentRequestKey === null
                    || $this->stockAdjustmentSnapshot === []) {
                    abort(404);
                }

                $lot = $this->stockLotForAdjustmentAction($this->stockAdjustmentLotId);

                try {
                    $adjust->handle(
                        actor: $this->user(),
                        workspace: $this->workspace(),
                        lotIdentifier: $lot->id,
                        mode: (string) ($data['mode'] ?? ''),
                        enteredQuantity: $data['quantity'] ?? null,
                        enteredUnit: (string) ($data['unit'] ?? ''),
                        reason: (string) ($data['reason'] ?? ''),
                        note: isset($data['note']) ? (string) $data['note'] : null,
                        snapshot: $this->stockAdjustmentSnapshot,
                        shortageAcknowledged: (bool) ($data['shortage_acknowledged'] ?? false),
                        idempotencyKey: $this->stockAdjustmentRequestKey,
                    );
                } catch (ValidationException $exception) {
                    if (array_key_exists('adjustment_snapshot', $exception->errors())) {
                        $this->stockAdjustmentSnapshot = $adjust->snapshot(
                            $this->user(),
                            $this->workspace(),
                            $lot->id,
                        );
                        $this->stockAdjustmentRequestKey = (string) Str::uuid();
                        $this->stockAdjustmentStale = true;
                        $this->mountedActions[0]['data']['shortage_acknowledged'] = false;
                    }

                    throw ValidationException::withMessages(collect($exception->errors())
                        ->mapWithKeys(function (array $messages, string $field): array {
                            $formField = $field === 'adjustment_snapshot' ? 'mode' : $field;

                            return ["mountedActions.0.data.{$formField}" => $messages];
                        })
                        ->all());
                }

                $this->stockAdjustmentLotId = null;
                $this->stockAdjustmentSnapshot = [];
                $this->stockAdjustmentRequestKey = null;
                $this->stockAdjustmentStale = false;
                $this->resetStockAdjustmentPaginator();
                $this->showAppNotification(__('production_bench.inventory.adjustment.success'));
            });
    }

    abstract protected function resetStockAdjustmentPaginator(): void;

    protected function stockLotForAdjustmentAction(mixed $lotId): StockLot
    {
        if (! is_numeric($lotId) || (int) $lotId < 1) {
            abort(404);
        }

        $lot = StockLot::query()
            ->where('workspace_id', $this->workspace()->id)
            ->where(function ($query): void {
                $query->whereNotNull('ingredient_id')->whereNull('packaging_item_id')->whereNull('recipe_id')
                    ->orWhere(function ($query): void {
                        $query->whereNull('ingredient_id')->whereNotNull('packaging_item_id')->whereNull('recipe_id');
                    });
            })
            ->findOrFail((int) $lotId);

        if (method_exists($this, 'stockAdjustmentSubjectMatches') && ! $this->stockAdjustmentSubjectMatches($lot)) {
            abort(404);
        }

        return $lot;
    }

    private function stockAdjustmentLotIsSupported(mixed $lotId): bool
    {
        if (! is_numeric($lotId) || (int) $lotId < 1) {
            return false;
        }

        $lot = StockLot::query()
            ->where('workspace_id', $this->workspace()->id)
            ->find((int) $lotId);

        return $lot instanceof StockLot && $this->stockAdjustmentSupportsLot($lot);
    }

    protected function stockAdjustmentSupportsLot(StockLot $lot): bool
    {
        if ((($lot->ingredient_id === null) === ($lot->packaging_item_id === null))
            || $lot->recipe_id !== null) {
            return false;
        }

        return ! method_exists($this, 'stockAdjustmentSubjectMatches') || $this->stockAdjustmentSubjectMatches($lot);
    }

    /** @return array<string, string> */
    private function adjustmentUnitOptions(): array
    {
        $lot = $this->stockAdjustmentLotId === null
            ? null
            : StockLot::query()->where('workspace_id', $this->workspace()->id)->find($this->stockAdjustmentLotId);

        if ($lot?->unit_kind === StockUnitKind::Count) {
            return ['count' => __('production_bench.inventory.units')];
        }

        return collect(MassUnit::cases())->mapWithKeys(fn (MassUnit $unit): array => [
            $unit->value => $unit->value,
        ])->all();
    }

    private function adjustmentDefaultUnit(): string
    {
        return $this->adjustmentUnitOptions() === ['count' => __('production_bench.inventory.units')]
            ? 'count'
            : $this->workspace()->mass_display_system->priceUnit()->value;
    }

    private function adjustmentPreview(Get $get): string
    {
        try {
            $calculation = $this->adjustmentCalculation($get);
        } catch (ValidationException) {
            return '';
        }

        $unit = (string) $get('unit');
        $delta = ltrim($calculation['delta'], '-');
        $direction = str_starts_with($calculation['delta'], '-')
            ? __('production_bench.inventory.adjustment.preview_decrease')
            : __('production_bench.inventory.adjustment.preview_increase');

        return __('production_bench.inventory.adjustment.preview', [
            'before' => $this->formatAdjustmentCanonical($calculation['physical_before'], $unit),
            'after' => $this->formatAdjustmentCanonical($calculation['physical_after'], $unit),
            'direction' => $direction,
            'delta' => $this->formatAdjustmentCanonical($delta, $unit),
        ]);
    }

    private function adjustmentCreatesShortage(Get $get): bool
    {
        try {
            $calculation = $this->adjustmentCalculation($get);

            return bccomp((string) ($this->stockAdjustmentSnapshot['reserved'] ?? '0'), '0', 9) > 0
                && bccomp(
                    $calculation['physical_after'],
                    (string) ($this->stockAdjustmentSnapshot['reserved'] ?? '0'),
                    9,
                ) < 0;
        } catch (ValidationException) {
            return false;
        }
    }

    private function adjustmentShortageWarning(Get $get): string
    {
        try {
            $calculation = $this->adjustmentCalculation($get);
        } catch (ValidationException) {
            return '';
        }

        $reserved = (string) ($this->stockAdjustmentSnapshot['reserved'] ?? '0');
        $shortage = bcsub($reserved, $calculation['physical_after'], 9);
        $unit = (string) $get('unit');

        return __('production_bench.inventory.adjustment.shortage_warning', [
            'after' => $this->formatAdjustmentCanonical($calculation['physical_after'], $unit),
            'reserved' => $this->formatAdjustmentCanonical($reserved, $unit),
            'shortage' => $this->formatAdjustmentCanonical($shortage, $unit),
        ]);
    }

    /** @return array<string, string> */
    private function adjustmentReasonOptions(Get $get): array
    {
        $options = [
            'measurement_difference' => __('production_bench.inventory.adjustment.reasons.measurement_difference'),
            'spillage' => __('production_bench.inventory.adjustment.reasons.spillage'),
            'damaged_discarded' => __('production_bench.inventory.adjustment.reasons.damaged_discarded'),
            'entry_error' => __('production_bench.inventory.adjustment.reasons.entry_error'),
            'other' => __('production_bench.inventory.adjustment.reasons.other'),
        ];

        try {
            if (bccomp($this->adjustmentCalculation($get)['delta'], '0', 9) > 0) {
                unset($options['spillage'], $options['damaged_discarded']);
            }
        } catch (ValidationException) {
        }

        return $options;
    }

    /** @return array{entered_quantity: string, entered_unit: string, physical_before: string, physical_after: string, delta: string, original_quantity: string} */
    private function adjustmentCalculation(Get $get): array
    {
        if ($this->stockAdjustmentLotId === null) {
            throw ValidationException::withMessages([]);
        }

        $lot = $this->stockLotForAdjustmentAction($this->stockAdjustmentLotId);

        return app(StockAdjustmentCalculator::class)->calculate(
            $lot->unit_kind,
            (string) ($this->stockAdjustmentSnapshot['physical'] ?? '0'),
            (string) $get('mode'),
            $get('quantity'),
            (string) $get('unit'),
        );
    }

    private function formatAdjustmentCanonical(string $quantity, string $unit): string
    {
        $display = $unit === 'count'
            ? $quantity
            : app(MassConverter::class)->fromGramsSigned($quantity, $unit);

        return NumberLocale::formatAdaptiveDecimal(
            $display,
            minimumDecimals: $unit === 'count' ? 0 : 0,
            maximumDecimals: $unit === 'count' ? 0 : 9,
            locale: $this->user()->number_locale,
        ).' '.($unit === 'count' ? __('production_bench.inventory.units') : $unit);
    }
}
