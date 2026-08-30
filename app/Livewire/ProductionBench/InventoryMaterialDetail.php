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
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMaterialSetting;
use App\Services\Inventory\MaterialActivityService;
use App\Services\Inventory\WorkspaceMaterialInventoryQuery;
use App\Services\MassConverter;
use App\Services\ProductionBenchAccess;
use App\Services\StockPositionService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

class InventoryMaterialDetail extends Component
{
    use InteractsWithAppNotifications;

    #[Url(as: 'period', except: '30')]
    public string $periodPreset = '30';

    #[Url(as: 'from', except: '')]
    public string $customFrom = '';

    #[Url(as: 'to', except: '')]
    public string $customTo = '';

    public string|Ingredient|PackagingItem $subject;

    public string $subjectType = 'ingredient';

    public string $bufferQuantity = '';

    public function mount(string|Ingredient|PackagingItem $subject, string $subjectType = 'ingredient'): void
    {
        $this->subjectType = in_array($subjectType, ['ingredient', 'packaging'], true) ? $subjectType : 'ingredient';
        $this->subject = $this->resolveSubject($subject);
        $this->normalizePeriodState();
        $this->bufferQuantity = (string) (WorkspaceMaterialSetting::query()
            ->where('workspace_id', $this->workspace()->id)
            ->when(
                $this->subject instanceof Ingredient,
                fn ($query) => $query->where('ingredient_id', $this->subject->id),
                fn ($query) => $query->where('packaging_item_id', $this->subject->id),
            )
            ->value('buffer_quantity') ?? '');
    }

    public function updatedPeriodPreset(): void
    {
        $this->normalizePeriodState();
        $this->validateCustomPeriod();
    }

    public function updatedCustomFrom(): void
    {
        if ($this->customFrom !== '') {
            $this->periodPreset = 'custom';
        }

        $this->validateCustomPeriod();
    }

    public function updatedCustomTo(): void
    {
        if ($this->customTo !== '') {
            $this->periodPreset = 'custom';
        }

        $this->validateCustomPeriod();
    }

    public function saveBuffer(SaveMaterialBuffer $saveMaterialBuffer): void
    {
        $value = trim($this->bufferQuantity);
        $setting = $saveMaterialBuffer->handle(
            actor: $this->user(),
            workspace: $this->workspace(),
            subject: $this->subject,
            bufferQuantity: $value === '' ? null : $value,
        );

        $this->bufferQuantity = (string) ($setting?->buffer_quantity ?? '');
        $this->showAppNotification(__('production_bench.inventory.buffer_saved'));
    }

    public function render(
        MaterialActivityService $activityService,
        MassConverter $massConverter,
        ProductionBenchAccess $access,
        StockPositionService $positions,
    ): View {
        $workspace = $this->workspace();
        $displayUnit = $this->subject instanceof PackagingItem
            ? __('production_bench.inventory.units')
            : $workspace->mass_display_system->priceUnit()->value;
        $rawPosition = $activityService->currentPosition($workspace, $this->subject);
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
                $this->subject instanceof Ingredient,
                fn ($query) => $query->where('ingredient_id', $this->subject->id),
                fn ($query) => $query->where('packaging_item_id', $this->subject->id),
            )
            ->first();
        $buffer = $setting?->buffer_quantity === null
            ? null
            : $this->formatQuantity((string) $setting->buffer_quantity, $massConverter, $displayUnit);
        $bufferBelow = $setting?->buffer_quantity !== null
            && bccomp($rawPosition['available'], (string) $setting->buffer_quantity, 9) < 0;
        $period = $this->periodDates();
        $periodActivity = $this->presentActivity(
            $activityService->forPeriod($workspace, $this->subject, $period['from'], $period['to']),
            $massConverter,
            $displayUnit,
        );
        $openLots = $activityService->openLots($workspace, $this->subject)
            ->map(fn (StockLot $lot): array => [
                'lot' => $lot,
                'positions' => collect($positions->forLotWithLoadedMovementSum($lot))
                    ->only(['physical', 'reserved', 'available'])
                    ->map(fn (string $quantity): string => $this->formatQuantity($quantity, $massConverter, $displayUnit))
                    ->all(),
            ]);

        return view('livewire.production-bench.inventory-material-detail', [
            'workspace' => $workspace,
            'isActive' => $access->isActive($workspace),
            'isReadOnly' => $access->isReadOnly($workspace),
            'displayUnit' => $displayUnit,
            'materialName' => $this->subject instanceof Ingredient
                ? (string) $this->subject->localizedDisplayName()
                : $this->subject->name,
            'materialCode' => $this->subject instanceof Ingredient
                ? $workspace->ingredientCodes()->where('ingredient_id', $this->subject->id)->value('material_code')
                : $this->subject->material_code,
            'position' => $position,
            'buffer' => $buffer,
            'bufferBelow' => $bufferBelow,
            'bufferConfigured' => $setting instanceof WorkspaceMaterialSetting,
            'openLots' => $openLots,
            'activity' => $periodActivity,
            'periodFrom' => $period['from'],
            'periodTo' => $period['to'],
            'periodLabel' => $this->periodLabel($period),
            'lotRegisterUrl' => route('production-bench.inventory.stock', [
                'material' => $this->subject->public_id,
                'material_type' => $this->subject instanceof Ingredient ? 'ingredient' : 'packaging',
                'lot_scope' => 'all',
            ]),
        ]);
    }

    public function sourceUrl(StockMovement $movement): ?string
    {
        $source = $movement->source;

        return match (true) {
            $source instanceof ProductionRun => route('production-bench.production.show', $source),
            $source instanceof GoodsReceiptLine && $source->goodsReceipt instanceof GoodsReceipt => route('production-bench.purchasing.receipts.show', $source->goodsReceipt),
            $source instanceof GoodsReceipt => route('production-bench.purchasing.receipts.show', $source),
            default => null,
        };
    }

    public function sourceLabel(StockMovement $movement): ?string
    {
        $source = $movement->source;

        return match (true) {
            $source instanceof ProductionRun => $source->displayIdentifier(),
            $source instanceof GoodsReceiptLine && $source->goodsReceipt instanceof GoodsReceipt => $source->goodsReceipt->delivery_reference ?: $source->goodsReceipt->public_id,
            $source instanceof GoodsReceipt => $source->delivery_reference ?: $source->public_id,
            default => null,
        };
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

    private function resolveSubject(string|Ingredient|PackagingItem $subject): Ingredient|PackagingItem
    {
        $workspace = $this->workspace();

        if ($this->subjectType === 'packaging') {
            $packaging = PackagingItem::query()
                ->where('workspace_id', $workspace->id)
                ->where('public_id', $subject instanceof PackagingItem ? $subject->public_id : $subject)
                ->firstOrFail();

            abort_unless(app(WorkspaceMaterialInventoryQuery::class)->tracks($workspace, $packaging), 404);

            return $packaging;
        }

        $ingredient = Ingredient::query()
            ->where('public_id', $subject instanceof Ingredient ? $subject->public_id : $subject)
            ->firstOrFail();

        abort_unless($ingredient->isAccessibleBy($this->user()), 404);
        abort_unless(app(WorkspaceMaterialInventoryQuery::class)->tracks($workspace, $ingredient), 404);

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
        return $this->subject instanceof Ingredient
            ? number_format((float) $massConverter->fromGramsSigned($quantity, $displayUnit), 2)
            : number_format((float) $quantity, 0);
    }

    /** @param array<string, mixed> $activity */
    private function presentActivity(array $activity, MassConverter $massConverter, string $displayUnit): array
    {
        $reconciliationDelta = (string) $activity['reconciliation_delta'];

        foreach ([
            'opening_physical',
            'closing_physical',
            'received',
            'production_consumed',
            'other_inbound',
            'other_outbound',
            'adjustments',
            'net_change',
            'reconciliation_delta',
        ] as $key) {
            $activity[$key] = $this->formatQuantity((string) $activity[$key], $massConverter, $displayUnit);
        }

        $activity['reconciliation_ok'] = bccomp($reconciliationDelta, '0', 9) === 0;

        $activity['movements'] = $activity['movements']->map(function (array $entry) use ($massConverter, $displayUnit): array {
            $entry['quantity_delta'] = $this->formatQuantity(
                (string) $entry['quantity_delta'],
                $massConverter,
                $displayUnit,
            );

            return $entry;
        });

        return $activity;
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
