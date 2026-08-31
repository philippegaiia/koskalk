<?php

namespace App\Livewire\ProductionBench;

use App\Actions\Inventory\SaveMaterialBuffer;
use App\Enums\StockMovementType;
use App\Livewire\Concerns\InteractsWithAppNotifications;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\Ingredient;
use App\Models\PackagingItem;
use App\Models\ProductionRun;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\SupplierListing;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMaterialSetting;
use App\Services\Inventory\MaterialActivityService;
use App\Services\Inventory\WorkspaceMaterialInventoryQuery;
use App\Services\Inventory\WorkspaceMaterialSupplierListingsQuery;
use App\Services\MassConverter;
use App\Services\ProductionBenchAccess;
use App\Services\StockPositionService;
use App\Services\SupplierListingPricePresentation;
use App\Support\LocalizedDecimalInput;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class InventoryMaterialDetail extends Component implements HasActions, HasForms
{
    use InteractsWithActions;
    use InteractsWithAppNotifications;
    use InteractsWithForms;
    use WithPagination;

    private const array ALLOWED_PER_PAGE = [25, 50, 100];

    private const array ALLOWED_SUPPLIER_LISTINGS_PER_PAGE = [10, 25, 50];

    #[Url(as: 'period', except: '30')]
    public string $periodPreset = '30';

    #[Url(as: 'from', except: '')]
    public string $customFrom = '';

    #[Url(as: 'to', except: '')]
    public string $customTo = '';

    /**
     * The route-bound subject is reduced to locked identifiers, so a later Livewire
     * request cannot redirect it to another material. The model is re-resolved from
     * these on every request, which also re-runs the workspace and tracked-material
     * guards that previously ran only in mount().
     */
    #[Locked]
    public string $subjectType = 'ingredient';

    #[Locked]
    public ?string $ingredientPublicId = null;

    #[Locked]
    public ?string $packagingPublicId = null;

    public int $perPage = 25;

    /**
     * The supplier listings are a second paginator on this page, so they carry
     * their own page size rather than sharing the activity one.
     */
    public int $supplierListingsPerPage = 10;

    private Ingredient|PackagingItem|null $resolvedSubject = null;

    private bool $subjectResolved = false;

    public function mount(string|Ingredient|PackagingItem $subject, string $subjectType = 'ingredient'): void
    {
        // A model carries its own type; a bare public identifier needs the caller to
        // say which subject it refers to.
        $this->subjectType = $subject instanceof Ingredient ? 'ingredient'
            : ($subject instanceof PackagingItem ? 'packaging'
                : (in_array($subjectType, ['ingredient', 'packaging'], true) ? $subjectType : 'ingredient'));

        $publicId = $subject instanceof Ingredient || $subject instanceof PackagingItem
            ? $subject->public_id
            : (string) $subject;

        $this->ingredientPublicId = $this->subjectType === 'ingredient' ? $publicId : null;
        $this->packagingPublicId = $this->subjectType === 'packaging' ? $publicId : null;

        // Resolve once here so the initial page load still 404s on an inaccessible
        // or untracked material, before any state is rendered.
        $this->subject();

        $this->normalizePeriodState();
    }

    public function updatedPeriodPreset(): void
    {
        $this->normalizePeriodState();
        $this->validateCustomPeriod();
        $this->resetPage('activity');
    }

    public function updatedCustomFrom(): void
    {
        if ($this->customFrom !== '') {
            $this->periodPreset = 'custom';
        }

        $this->validateCustomPeriod();
        $this->resetPage('activity');
    }

    public function updatedCustomTo(): void
    {
        if ($this->customTo !== '') {
            $this->periodPreset = 'custom';
        }

        $this->validateCustomPeriod();
        $this->resetPage('activity');
    }

    public function updatedPerPage(): void
    {
        $this->perPage = $this->normalizedPerPage();
        $this->resetPage('activity');
    }

    public function updatedSupplierListingsPerPage(): void
    {
        $this->supplierListingsPerPage = $this->normalizedSupplierListingsPerPage();
        $this->resetPage('supplier-listings');
    }

    /**
     * Period controls for the activity section.
     *
     * Each field keeps its plan name as its key — `period`, `from`, `to` — while
     * binding to the URL-bound property through an explicit state path, so a
     * bookmarked `?period=365&from=…&to=…` link keeps resolving to the same
     * view. The `updated*()` hooks stay the only place period side effects are
     * applied (validation and the activity paginator reset), which is why the
     * fields carry no `afterStateUpdated()` of their own: the hook fires off
     * the root property the schema writes to.
     *
     * `period` is deliberately rendered as a non-native select so the date
     * pickers that follow it share the module's own control styling rather than
     * the platform control, matching the rest of the Production Bench.
     */
    public function activityFiltersForm(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make(['sm' => 2, 'lg' => 4])
                    ->schema([
                        Select::make('period')
                            ->key('period')
                            ->statePath('periodPreset')
                            ->label(__('production_bench.inventory.period'))
                            ->options([
                                '30' => __('production_bench.inventory.last_30_days'),
                                '365' => __('production_bench.inventory.last_365_days'),
                                'custom' => __('production_bench.inventory.custom_period'),
                            ])
                            ->native(false)
                            ->live(),
                        // Both dates are meaningless outside a custom period,
                        // so they follow the preset rather than sitting in the
                        // form disabled.
                        DatePicker::make('from')
                            ->key('from')
                            ->statePath('customFrom')
                            ->label(__('production_bench.inventory.from'))
                            ->native(false)
                            ->closeOnDateSelection()
                            ->weekStartsOnMonday()
                            ->visible(fn (Get $get): bool => $get('periodPreset') === 'custom')
                            ->live(),
                        DatePicker::make('to')
                            ->key('to')
                            ->statePath('customTo')
                            ->label(__('production_bench.inventory.to'))
                            ->native(false)
                            ->closeOnDateSelection()
                            ->weekStartsOnMonday()
                            ->visible(fn (Get $get): bool => $get('periodPreset') === 'custom')
                            ->live(),
                    ]),
            ]);
    }

    /**
     * Buffer editing goes through a Filament action modal with a localized
     * decimal field, per the plan, rather than a raw input on the page. Saving
     * an empty quantity clears the buffer; the explicit clear action does the
     * same without opening the form.
     */
    public function editBufferAction(): Action
    {
        return Action::make('editBuffer')
            ->label(__('production_bench.inventory.edit_buffer'))
            ->modalHeading(__('production_bench.inventory.buffer_stock'))
            ->modalDescription(__('production_bench.inventory.buffer_stock_help'))
            ->modalSubmitActionLabel(__('production_bench.inventory.save_buffer'))
            ->modalCancelActionLabel(__('production_bench.common.cancel'))
            ->visible(fn (): bool => app(ProductionBenchAccess::class)->canWrite($this->user(), $this->workspace()))
            ->fillForm(fn (): array => [
                'buffer_quantity' => $this->displayBufferQuantity($this->currentBufferGrams()),
            ])
            ->schema([
                LocalizedDecimalInput::make('buffer_quantity')
                    ->label(__('production_bench.inventory.buffer_stock'))
                    ->helperText(__('production_bench.inventory.buffer_empty_clears'))
                    ->minValue(0),
            ])
            ->action(fn (array $data) => $this->saveBufferFromModal($data));
    }

    public function clearBufferAction(): Action
    {
        return Action::make('clearBuffer')
            ->label(__('production_bench.inventory.clear_buffer'))
            ->color('danger')
            ->visible(fn (): bool => $this->currentBufferGrams() !== null
                && app(ProductionBenchAccess::class)->canWrite($this->user(), $this->workspace()))
            ->action(fn () => $this->saveBufferFromModal(['buffer_quantity' => null]));
    }

    public function render(
        MaterialActivityService $activityService,
        MassConverter $massConverter,
        ProductionBenchAccess $access,
        StockPositionService $positions,
        WorkspaceMaterialSupplierListingsQuery $supplierListingQuery,
        SupplierListingPricePresentation $pricePresentation,
    ): View {
        $workspace = $this->workspace();
        $displayUnit = $this->displayUnit();
        $rawPosition = $activityService->currentPosition($this->user(), $workspace, $this->subject());
        $rawPosition['required'] = bcsub(
            bcadd($rawPosition['available'], $rawPosition['incoming'], 9),
            $rawPosition['forecast'],
            9,
        );
        $position = $this->presentPosition(
            $rawPosition,
            $massConverter,
            $displayUnit,
        );
        $setting = WorkspaceMaterialSetting::query()
            ->where('workspace_id', $workspace->id)
            ->when(
                $this->subject() instanceof Ingredient,
                fn ($query) => $query->where('ingredient_id', $this->subject()->id),
                fn ($query) => $query->where('packaging_item_id', $this->subject()->id),
            )
            ->first();
        $buffer = $setting?->buffer_quantity === null
            ? null
            : $this->formatQuantity((string) $setting->buffer_quantity, $massConverter, $displayUnit);
        $bufferBelow = $setting?->buffer_quantity !== null
            && bccomp($rawPosition['available'], (string) $setting->buffer_quantity, 9) < 0;
        $period = $this->periodDates();
        $periodActivity = $this->presentActivity(
            $activityService->forPeriod($this->user(), $workspace, $this->subject(), $period['from'], $period['to']),
            $massConverter,
            $displayUnit,
        );
        $movements = $this->presentMovementPage(
            $activityService->paginateMovements(
                $this->user(),
                $workspace,
                $this->subject(),
                $period['from'],
                $period['to'],
                $this->normalizedPerPage(),
                'activity',
            ),
            $massConverter,
            $displayUnit,
        );
        $openLots = $activityService->openLots($this->user(), $workspace, $this->subject())
            ->map(fn (StockLot $lot): array => [
                'lot' => $lot,
                'positions' => collect($positions->forLotWithLoadedMovementSum($lot))
                    ->only(['physical', 'reserved', 'available'])
                    ->map(fn (string $quantity): string => $this->formatQuantity($quantity, $massConverter, $displayUnit))
                    ->all(),
            ]);

        $supplierListings = $supplierListingQuery
            ->paginate(
                $this->user(),
                $workspace,
                $this->subject(),
                $this->normalizedSupplierListingsPerPage(),
                'supplier-listings',
            )
            ->through(fn (SupplierListing $listing): array => [
                'listing' => $listing,
                // Prices are presented by the same service the Purchasing
                // catalogue uses, so a listing reads identically in both places.
                'price' => $pricePresentation->present($listing, $workspace),
            ]);

        return view('livewire.production-bench.inventory-material-detail', [
            'workspace' => $workspace,
            'isActive' => $access->isActive($workspace),
            'isReadOnly' => $access->isReadOnly($workspace),
            'displayUnit' => $displayUnit,
            'materialName' => $this->subject() instanceof Ingredient
                ? (string) $this->subject()->localizedDisplayName()
                : $this->subject()->name,
            'materialCode' => $this->subject() instanceof Ingredient
                ? $workspace->ingredientCodes()->where('ingredient_id', $this->subject()->id)->value('material_code')
                : $this->subject()->material_code,
            'position' => $position,
            'buffer' => $buffer,
            'bufferBelow' => $bufferBelow,
            'bufferConfigured' => $setting instanceof WorkspaceMaterialSetting,
            'openLots' => $openLots,
            'supplierListings' => $supplierListings,
            'activity' => $periodActivity,
            'movements' => $movements,
            'periodFrom' => $period['from'],
            'periodTo' => $period['to'],
            'periodLabel' => $this->periodLabel($period),
            'lotRegisterUrl' => route('production-bench.inventory.stock', [
                'material' => $this->subject()->public_id,
                'material_type' => $this->subject() instanceof Ingredient ? 'ingredient' : 'packaging',
                'lot_scope' => 'all',
            ]),
        ]);
    }

    public function sourceUrl(StockMovement $movement): ?string
    {
        return $this->sourceLink($movement)['url'] ?? null;
    }

    public function sourceLabel(StockMovement $movement): ?string
    {
        return $this->sourceLink($movement)['label'] ?? null;
    }

    /**
     * Resolves the linked source record for a movement. The source is a morphTo,
     * so nothing in the schema ties it to the movement's workspace: an
     * out-of-workspace record would otherwise print its identifier here and link
     * to a route that 404s at the destination, because both detail components
     * scope their query by workspace. Plan step 5 asks for no URL and a neutral
     * label for every other source type or inaccessible record, and the view
     * already renders `source_not_available` when either half is null.
     *
     * @return array{url: string, label: string}|null
     */
    private function sourceLink(StockMovement $movement): ?array
    {
        $workspaceId = $this->workspace()->id;
        $source = $movement->source;

        if ($source instanceof ProductionRun && (int) $source->workspace_id === $workspaceId) {
            return [
                'url' => route('production-bench.production.show', $source),
                'label' => $source->displayIdentifier(),
            ];
        }

        $receipt = match (true) {
            $source instanceof GoodsReceipt => $source,
            $source instanceof GoodsReceiptLine => $source->goodsReceipt,
            default => null,
        };

        if ($receipt instanceof GoodsReceipt && (int) $receipt->workspace_id === $workspaceId) {
            return [
                'url' => route('production-bench.purchasing.receipts.show', $receipt),
                'label' => $receipt->delivery_reference ?: $receipt->public_id,
            ];
        }

        return null;
    }

    public function movementTypeLabel(StockMovementType|string $type): string
    {
        $type = $type instanceof StockMovementType ? $type : StockMovementType::from($type);

        return __('production_bench.inventory.activity_type_'.$type->value);
    }

    public function groupLabel(string $group): string
    {
        return __('production_bench.inventory.activity_group_'.$group);
    }

    /**
     * Re-resolves the subject from the locked identifiers on every request. Private
     * properties are not part of the Livewire payload, so this cache is per-request
     * and the guards below re-run on every mutation.
     */
    private function subject(): Ingredient|PackagingItem
    {
        if (! $this->subjectResolved) {
            $this->resolvedSubject = $this->resolveSubject();
            $this->subjectResolved = true;
        }

        return $this->resolvedSubject;
    }

    private function resolveSubject(): Ingredient|PackagingItem
    {
        $workspace = $this->workspace();

        if ($this->subjectType === 'packaging') {
            $packaging = PackagingItem::query()
                ->where('workspace_id', $workspace->id)
                ->where('public_id', $this->packagingPublicId)
                ->firstOrFail();

            abort_unless(app(WorkspaceMaterialInventoryQuery::class)->tracks($this->user(), $workspace, $packaging), 404);

            return $packaging;
        }

        $ingredient = Ingredient::query()
            ->where('public_id', $this->ingredientPublicId)
            ->firstOrFail();

        abort_unless($ingredient->isAccessibleBy($this->user()), 404);
        abort_unless(app(WorkspaceMaterialInventoryQuery::class)->tracks($this->user(), $workspace, $ingredient), 404);

        return $ingredient;
    }

    /** @return array{from: CarbonImmutable, to: CarbonImmutable} */
    private function periodDates(): array
    {
        if ($this->periodPreset === 'custom') {
            $from = $this->parsePeriodDate($this->customFrom);
            $to = $this->parsePeriodDate($this->customTo);

            if ($from instanceof CarbonImmutable && $to instanceof CarbonImmutable && $from->lessThanOrEqualTo($to)) {
                return ['from' => $from->startOfDay(), 'to' => $to->endOfDay()];
            }
        }

        $days = $this->periodPreset === '365' ? 365 : 30;
        $today = CarbonImmutable::today();

        return [
            'from' => $today->subDays($days - 1)->startOfDay(),
            'to' => $today->endOfDay(),
        ];
    }

    /** @param array{from: CarbonImmutable, to: CarbonImmutable} $period */
    private function periodLabel(array $period): string
    {
        return $period['from']->format('Y-m-d').' – '.$period['to']->format('Y-m-d');
    }

    private function normalizePeriodState(): void
    {
        if ($this->customFrom !== '' || $this->customTo !== '') {
            $this->periodPreset = 'custom';
        }

        if (! in_array($this->periodPreset, ['30', '365', 'custom'], true)) {
            $this->periodPreset = '30';
        }
    }

    private function validateCustomPeriod(): bool
    {
        $this->resetValidation(['customFrom', 'customTo']);

        if ($this->periodPreset !== 'custom') {
            return true;
        }

        $valid = true;
        $from = $this->parsePeriodDate($this->customFrom);
        $to = $this->parsePeriodDate($this->customTo);

        if ($this->customFrom === '') {
            $this->addError('customFrom', __('production_bench.inventory.period_date_required'));
            $valid = false;
        } elseif (! $from instanceof CarbonImmutable) {
            $this->addError('customFrom', __('production_bench.inventory.period_date_invalid'));
            $valid = false;
        }

        if ($this->customTo === '') {
            $this->addError('customTo', __('production_bench.inventory.period_date_required'));
            $valid = false;
        } elseif (! $to instanceof CarbonImmutable) {
            $this->addError('customTo', __('production_bench.inventory.period_date_invalid'));
            $valid = false;
        }

        if ($valid && $from instanceof CarbonImmutable && $to instanceof CarbonImmutable && $from->greaterThan($to)) {
            $this->addError('customFrom', __('production_bench.inventory.period_date_order'));
            $this->addError('customTo', __('production_bench.inventory.period_date_order'));

            return false;
        }

        return $valid;
    }

    private function parsePeriodDate(string $date): ?CarbonImmutable
    {
        if ($date === '') {
            return null;
        }

        try {
            $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $date);
        } catch (\Throwable) {
            return null;
        }

        return $parsed instanceof CarbonImmutable && $parsed->format('Y-m-d') === $date
            ? $parsed
            : null;
    }

    /** @param array<string, string> $position */
    private function presentPosition(array $position, MassConverter $massConverter, string $displayUnit): array
    {
        return collect($position)
            ->map(fn (string $quantity): string => $this->formatQuantity($quantity, $massConverter, $displayUnit))
            ->all();
    }

    private function formatQuantity(string $quantity, MassConverter $massConverter, string $displayUnit): string
    {
        return $this->subject() instanceof Ingredient
            ? number_format((float) $massConverter->fromGramsSigned($quantity, $displayUnit), 2)
            : number_format((float) $quantity, 0);
    }

    private function displayUnit(): string
    {
        return $this->subject() instanceof PackagingItem
            ? __('production_bench.inventory.units')
            : $this->workspace()->mass_display_system->priceUnit()->value;
    }

    /**
     * The only write path for the buffer: both buffer actions funnel here, and
     * the method calls nothing but SaveMaterialBuffer, per the plan.
     *
     * @param  array{buffer_quantity?: mixed}  $data
     */
    private function saveBufferFromModal(array $data): void
    {
        $value = $data['buffer_quantity'] ?? null;

        app(SaveMaterialBuffer::class)->handle(
            actor: $this->user(),
            workspace: $this->workspace(),
            subject: $this->subject(),
            bufferQuantity: $value === null ? null : (string) $value,
        );

        $this->showAppNotification(__('production_bench.inventory.buffer_saved'));
    }

    private function currentBufferGrams(): ?string
    {
        return WorkspaceMaterialSetting::query()
            ->where('workspace_id', $this->workspace()->id)
            ->when(
                $this->subject() instanceof Ingredient,
                fn ($query) => $query->where('ingredient_id', $this->subject()->id),
                fn ($query) => $query->where('packaging_item_id', $this->subject()->id),
            )
            ->value('buffer_quantity');
    }

    /**
     * Converts a stored canonical-gram buffer into the workspace display unit for
     * the editable input. Packaging is unit-less and is shown exactly as stored.
     *
     * Unlike formatQuantity(), this returns a machine-readable decimal rather than
     * a formatted string, because the value seeds a form field.
     */
    private function displayBufferQuantity(?string $grams): string
    {
        if ($grams === null) {
            return '';
        }

        return $this->subject() instanceof Ingredient
            ? app(MassConverter::class)->fromGramsSigned($grams, $this->displayUnit())
            : $grams;
    }

    /** @param array<string, mixed> $activity */
    private function presentActivity(array $activity, MassConverter $massConverter, string $displayUnit): array
    {
        $reconciliationDelta = (string) $activity['reconciliation_delta'];

        $activity = collect($activity)
            ->map(fn (mixed $value, string $key): mixed => in_array($key, [
                'opening_physical',
                'closing_physical',
                'received',
                'production_consumed',
                'other_inbound',
                'other_outbound',
                'adjustments',
                'net_change',
                'reconciliation_delta',
            ], true)
                ? $this->formatQuantity((string) $value, $massConverter, $displayUnit)
                : $value)
            ->all();

        $activity['reconciliation_ok'] = bccomp($reconciliationDelta, '0', 9) === 0;

        return $activity;
    }

    /**
     * @param  LengthAwarePaginator<int, array{movement: StockMovement, group: string, quantity_delta: string}>  $page
     * @return LengthAwarePaginator<int, array{movement: StockMovement, group: string, quantity_delta: string}>
     */
    private function presentMovementPage(LengthAwarePaginator $page, MassConverter $massConverter, string $displayUnit): LengthAwarePaginator
    {
        return $page->through(function (array $entry) use ($massConverter, $displayUnit): array {
            $entry['quantity_delta'] = $this->formatQuantity(
                (string) $entry['quantity_delta'],
                $massConverter,
                $displayUnit,
            );

            return $entry;
        });
    }

    private function normalizedPerPage(): int
    {
        return in_array($this->perPage, self::ALLOWED_PER_PAGE, true) ? $this->perPage : 25;
    }

    private function normalizedSupplierListingsPerPage(): int
    {
        return in_array($this->supplierListingsPerPage, self::ALLOWED_SUPPLIER_LISTINGS_PER_PAGE, true)
            ? $this->supplierListingsPerPage
            : 10;
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
