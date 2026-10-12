<?php

namespace App\Services;

use App\Enums\IngredientCategory;
use App\Enums\IngredientSubcategory;
use App\Enums\MassUnit;
use App\Enums\StockUnitKind;
use App\Enums\WorkspaceModule;
use App\Models\StockLot;
use App\Models\StorageLocation;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Inventory\WorkspaceMaterialInventoryQuery;
use App\Services\Inventory\WorkspaceStockLotQuery;
use Closure;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

final class InventoryFileExporter
{
    public function __construct(
        private readonly WorkspaceMaterialInventoryQuery $inventoryQuery,
        private readonly WorkspaceStockLotQuery $lotQuery,
        private readonly MassConverter $massConverter,
        private readonly StockPositionService $positions,
        private readonly TabularFileExporter $fileExporter,
        private readonly WorkspaceAuthorization $workspaceAuthorization,
        private readonly ProductionBenchAccess $productionBenchAccess,
    ) {}

    /** @param array<string, mixed> $filters */
    public function materials(User $actor, array $filters, bool $validateOnly = false, ?string $directory = null): array|Workspace
    {
        $inventoryQuery = $this->inventoryQuery;
        $massConverter = $this->massConverter;
        $fileExporter = $this->fileExporter;
        $workspaceAuthorization = $this->workspaceAuthorization;
        $productionBenchAccess = $this->productionBenchAccess;

        $workspace = $this->authorizedWorkspace(
            $actor,
            (string) $filters['workspace'],
            $workspaceAuthorization,
            $productionBenchAccess,
        );
        $queryFilters = $this->materialQueryFilters($filters);
        $rowCount = $inventoryQuery->count($actor, $workspace, $queryFilters);
        $maxRows = min(50_000, max(1, (int) config('workspaces.list_output_limits.max_rows', 50_000)));

        if ($rowCount === 0) {
            throw ValidationException::withMessages([
                'export' => __('production_bench.output.validation.empty'),
            ]);
        }

        if ($rowCount > $maxRows) {
            throw ValidationException::withMessages([
                'export' => __('production_bench.output.validation.too_many', ['limit' => number_format($maxRows)]),
            ]);
        }

        if ($validateOnly) {
            return $workspace;
        }

        $generatedAt = now()->toIso8601String();
        $displayUnit = $workspace->mass_display_system->priceUnit();
        $filterLabels = $this->materialFilterLabels($queryFilters);
        $rows = $this->materialRows(
            $inventoryQuery,
            $actor,
            $workspace,
            $queryFilters,
            $massConverter,
            $displayUnit,
            $maxRows,
        );
        $columns = $this->materialColumns($displayUnit);
        $format = $filters['format'];
        $filename = (Str::slug(__('production_bench.output.materials_filename')) ?: 'inventory-materials')
            .'-'.now()->toDateString().'.'.$format;
        $path = null;

        try {
            if ($format === 'xlsx') {
                $path = $fileExporter->writeTemporaryXlsx([[
                    'name' => __('production_bench.output.materials_sheet'),
                    'title' => __('production_bench.output.materials_title'),
                    'context' => $this->materialContext($workspace, $generatedAt, $filterLabels),
                    'columns' => $columns,
                    'rows' => $rows,
                ]], $directory);
            } else {
                $path = $fileExporter->writeTemporaryCsv([
                    'columns' => [...$columns, ...$this->materialMetadataColumns()],
                    'rows' => $this->rowsWithMetadata($rows, $workspace, $generatedAt, $filterLabels),
                ], $directory);
            }

            return ['path' => $path, 'filename' => $filename];
        } catch (Throwable $exception) {
            if (is_string($path)) {
                $fileExporter->deleteTemporaryFile($path);
            }

            throw $exception;
        }
    }

    /** @param array<string, mixed> $filters */
    public function stockLots(User $actor, array $filters, bool $validateOnly = false, ?string $directory = null): array|Workspace
    {
        $lotQuery = $this->lotQuery;
        $massConverter = $this->massConverter;
        $positions = $this->positions;
        $fileExporter = $this->fileExporter;
        $workspaceAuthorization = $this->workspaceAuthorization;
        $productionBenchAccess = $this->productionBenchAccess;
        $workspace = $this->authorizedWorkspace($actor, $filters['workspace'], $workspaceAuthorization, $productionBenchAccess);
        $query = $lotQuery->query($actor, $workspace, $filters)->with('recipe');
        $maxRows = min(50_000, max(1, (int) config('workspaces.list_output_limits.max_rows', 50_000)));
        $this->assertRowCount((clone $query)->count(), $maxRows);
        if ($validateOnly) {
            return $workspace;
        }

        $generatedAt = now()->toIso8601String();
        $labels = $this->lotFilterLabels($filters, $workspace);
        $rows = $this->lotRows($query, $workspace, $positions, $massConverter, $maxRows);
        $columns = $this->lotColumns($workspace);
        $format = $filters['format'];
        $filename = (Str::slug(__('production_bench.output.lots_filename')) ?: 'stock-lots').'-'.now()->toDateString().'.'.$format;
        $path = null;

        try {
            $path = $format === 'xlsx'
                ? $fileExporter->writeTemporaryXlsx([[
                    'name' => __('production_bench.output.lots_sheet'),
                    'title' => __('production_bench.output.lots_title'),
                    'context' => $this->materialContext($workspace, $generatedAt, $labels),
                    'columns' => $columns,
                    'rows' => $rows,
                ]], $directory)
                : $fileExporter->writeTemporaryCsv([
                    'columns' => [...$columns, ...$this->materialMetadataColumns()],
                    'rows' => $this->rowsWithMetadata($rows, $workspace, $generatedAt, $labels),
                ], $directory);

            return ['path' => $path, 'filename' => $filename];
        } catch (Throwable $exception) {
            if (is_string($path)) {
                $fileExporter->deleteTemporaryFile($path);
            }

            throw $exception;
        }
    }

    /** @param array<string, mixed> $filters */
    public function printCount(User $actor, array $filters): View
    {
        $lotQuery = $this->lotQuery;
        $massConverter = $this->massConverter;
        $positions = $this->positions;
        $workspaceAuthorization = $this->workspaceAuthorization;
        $productionBenchAccess = $this->productionBenchAccess;
        $workspace = $this->authorizedWorkspace($actor, $filters['workspace'], $workspaceAuthorization, $productionBenchAccess);
        $query = $lotQuery->query($actor, $workspace, $filters)->with('recipe');
        $maxRows = min(1_000, max(1, (int) config('workspaces.list_output_limits.max_print_rows', 1_000)));
        $this->assertRowCount((clone $query)->count(), $maxRows);

        return view('production-bench.inventory-count-print', [
            'workspace' => $workspace,
            'generatedAt' => now()->toIso8601String(),
            'filterLabels' => $this->lotFilterLabels($filters, $workspace),
            'rows' => iterator_to_array($this->lotRows($query, $workspace, $positions, $massConverter, $maxRows)),
        ]);
    }

    private function assertRowCount(int $count, int $maximum): void
    {
        if ($count === 0 || $count > $maximum) {
            throw ValidationException::withMessages([
                'export' => $count === 0
                    ? __('production_bench.output.validation.empty')
                    : __('production_bench.output.validation.too_many', ['limit' => number_format($maximum)]),
            ]);
        }
    }

    /** @return Generator<array<string, scalar|null>> */
    private function lotRows(Builder $query, Workspace $workspace, StockPositionService $positions, MassConverter $massConverter, int $maximum): Generator
    {
        $count = 0;
        $displayUnit = $workspace->mass_display_system->priceUnit();
        $chunkSize = max(1, min(500, (int) config('workspaces.list_output_limits.chunk_size', 500)));

        foreach ($query->lazy($chunkSize) as $lot) {
            if (++$count > $maximum) {
                throw ValidationException::withMessages(['export' => __('production_bench.output.validation.changed')]);
            }

            $position = $positions->forLotWithLoadedMovementSum($lot);
            $isMass = $lot->unit_kind === StockUnitKind::Mass;
            $quantity = fn (string $value): string => $isMass ? $massConverter->fromGramsSigned($value, $displayUnit) : $this->normalizedCount($value);
            $receipt = $lot->goodsReceiptLine?->goodsReceipt;

            yield [
                'material' => $lot->subjectName(),
                'code' => $lot->ingredient?->workspaceCodes->firstWhere('workspace_id', $workspace->id)?->material_code ?? $lot->packagingItem?->material_code ?? '',
                'lot' => $lot->internal_lot_code,
                'supplier_batch' => $lot->supplier_batch_number,
                'supplier' => $receipt?->supplier?->name ?? $lot->supplierListing?->supplier?->name ?? '',
                'origin' => __('production_bench.inventory.origin_'.$lot->origin->value),
                'stocked' => $lot->stocked_at?->toDateString(),
                'received' => $receipt?->received_at?->toDateString(),
                'expiry' => $lot->expires_at?->toDateString(),
                'handling' => __('production_bench.inventory.'.$lot->status->value),
                ...($workspace->uses_storage_locations ? ['location' => $lot->storageLocation?->name ?? ''] : []),
                'physical' => $quantity($position['physical']),
                'reserved' => $quantity($position['reserved']),
                'available' => $quantity($position['available']),
                'unit' => $isMass ? $displayUnit->value : 'count',
            ];
        }
    }

    /** @return list<array{key: string, label: string, type: string, format?: Closure}> */
    private function lotColumns(Workspace $workspace): array
    {
        $keys = ['material', 'code', 'lot', 'supplier_batch', 'supplier', 'origin', 'stocked', 'received', 'expiry', 'handling'];
        if ($workspace->uses_storage_locations) {
            $keys[] = 'location';
        }

        return collect([...$keys, 'physical', 'reserved', 'available', 'unit'])->map(fn (string $key): array => [
            'key' => $key,
            'label' => __('production_bench.output.columns.'.$key),
            'type' => in_array($key, ['physical', 'reserved', 'available'], true) ? 'number' : 'string',
            ...(in_array($key, ['physical', 'reserved', 'available'], true) ? ['format' => $this->quantityFormat(...)] : []),
        ])->all();
    }

    /** @param array<string, mixed> $filters @return array<string, string> */
    private function lotFilterLabels(array $filters, Workspace $workspace): array
    {
        $labels = [];
        foreach ([
            'search' => 'search', 'lotScope' => 'scope', 'lotStatus' => 'handling', 'lotOrigin' => 'origin',
            'lotDateBasis' => 'date_basis', 'lotDateFrom' => 'date_from', 'lotDateUntil' => 'date_until',
            'lotExpiry' => 'expiry', 'lotSort' => 'sorted_by',
        ] as $key => $label) {
            $value = (string) ($filters[$key] ?? match ($key) {
                'lotScope' => 'open', 'lotDateBasis' => 'stocked', 'lotSort' => 'newest', default => '',
            });
            if ($value === '' || $value === 'all') {
                continue;
            }
            $labels[__('production_bench.output.filters.'.$label)] = match ($key) {
                'lotOrigin' => __('production_bench.inventory.origin_'.$value),
                'lotStatus' => __('production_bench.inventory.'.$value),
                'lotScope', 'lotDateBasis', 'lotExpiry', 'lotSort' => __('production_bench.output.lot_filters.'.$value),
                default => $value,
            };
        }
        if (! empty($filters['lotSupplier'])) {
            $labels[__('production_bench.output.columns.supplier')] = Supplier::query()->where('workspace_id', $workspace->id)->where('public_id', $filters['lotSupplier'])->value('name') ?? __('production_bench.output.unavailable');
        }
        if (! empty($filters['lotMaterial'])) {
            $material = StockLot::query()->where('workspace_id', $workspace->id)
                ->where(function (Builder $query) use ($filters): void {
                    $query->whereHas('ingredient', fn (Builder $query): Builder => $query->where('public_id', $filters['lotMaterial']))
                        ->orWhereHas('packagingItem', fn (Builder $query): Builder => $query->where('public_id', $filters['lotMaterial']));
                })->with(['ingredient.translations', 'packagingItem'])->first();
            $labels[__('production_bench.output.columns.material')] = $material?->ingredient?->localizedDisplayName() ?? $material?->packagingItem?->name ?? __('production_bench.output.unavailable');
        }
        $location = $workspace->uses_storage_locations ? ($filters['lotStorageLocation'] ?? 'all') : 'all';
        if ($location !== 'all') {
            $labels[__('production_bench.output.columns.location')] = $location === 'unassigned'
                ? __('production_bench.output.lot_filters.unassigned')
                : (StorageLocation::query()->where('workspace_id', $workspace->id)->whereKey($location)->value('name') ?? '');
        }

        return $labels;
    }

    private function authorizedWorkspace(
        User $actor,
        string $workspacePublicId,
        WorkspaceAuthorization $workspaceAuthorization,
        ProductionBenchAccess $productionBenchAccess,
    ): Workspace {
        $workspace = $workspaceAuthorization->selectedWorkspace($actor);

        abort_unless($workspace instanceof Workspace, 403);

        $originWorkspace = Workspace::withoutGlobalScopes()
            ->where('public_id', $workspacePublicId)
            ->first();

        abort_unless(
            $originWorkspace instanceof Workspace
                && $workspaceAuthorization->canAccessWorkspace($actor, $originWorkspace),
            403,
        );

        abort_if(
            $originWorkspace->isNot($workspace),
            409,
            __('production_bench.output.validation.workspace_changed'),
        );

        $productionBenchAccess->assertReadable($actor, $workspace);
        abort_unless($workspaceAuthorization->canViewModule($actor, $workspace->id, WorkspaceModule::ProductionBench), 403);

        return $workspace;
    }

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    private function materialQueryFilters(array $filters): array
    {
        return [
            'search' => trim((string) ($filters['search'] ?? '')),
            'type' => $filters['materialType'] ?? 'all',
            'stock_state' => $filters['stockState'] ?? 'all',
            'demand' => $filters['demandFilter'] ?? 'all',
            'category' => $filters['categoryFilter'] ?? '',
            'subcategory' => $filters['subcategoryFilter'] ?? '',
            'sort' => $filters['sort'] ?? 'priority',
            'direction' => $filters['direction'] ?? 'asc',
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  array<string, string>  $filterLabels
     * @return Generator<array<string, scalar|null>>
     */
    private function materialRows(
        WorkspaceMaterialInventoryQuery $inventoryQuery,
        User $actor,
        Workspace $workspace,
        array $filters,
        MassConverter $massConverter,
        MassUnit $displayUnit,
        int $maxRows,
    ): Generator {
        $rowCount = 0;
        $chunkSize = max(1, min(500, (int) config('workspaces.list_output_limits.chunk_size', 500)));

        foreach ($inventoryQuery->iterateRows($actor, $workspace, $filters, $chunkSize) as $row) {
            if (++$rowCount > $maxRows) {
                throw ValidationException::withMessages([
                    'export' => __('production_bench.output.validation.changed'),
                ]);
            }

            $isMass = $row['display_unit'] === 'mass';
            $quantity = fn (string $value): string => $isMass
                ? $massConverter->fromGramsSigned($value, $displayUnit)
                : $this->normalizedCount($value);

            yield [
                'type' => $row['display_unit'] === 'mass'
                    ? __('production_bench.output.types.ingredient')
                    : __('production_bench.output.types.packaging'),
                'material' => $row['name'],
                'code' => $row['material_code'],
                'physical' => $quantity($row['physical']),
                'quarantined' => $quantity($row['quarantined']),
                'reserved' => $quantity($row['reserved']),
                'available' => $quantity($row['available']),
                'incoming' => $quantity($row['incoming']),
                'required' => $quantity($row['required']),
                'forecast' => $quantity($row['forecast']),
                'unit' => $isMass ? $displayUnit->value : 'count',
            ];
        }
    }

    /** @param array<string, scalar|null> $row */
    private function quantityFormat(array $row): string
    {
        return $row['unit'] === 'count' ? '0' : '0.00';
    }

    private function normalizedCount(string $quantity): string
    {
        if (bccomp($quantity, '0', 9) === 0) {
            return '0';
        }

        return str_contains($quantity, '.') ? rtrim(rtrim($quantity, '0'), '.') : $quantity;
    }

    /** @return list<array{key: string, label: string, type: 'string'|'number', format?: Closure}> */
    private function materialColumns(MassUnit $displayUnit): array
    {
        return collect([
            ['key' => 'type', 'label' => __('production_bench.output.columns.type'), 'type' => 'string'],
            ['key' => 'material', 'label' => __('production_bench.output.columns.material'), 'type' => 'string'],
            ['key' => 'code', 'label' => __('production_bench.output.columns.code'), 'type' => 'string'],
            ['key' => 'physical', 'label' => __('production_bench.output.columns.physical'), 'type' => 'number'],
            ['key' => 'quarantined', 'label' => __('production_bench.output.columns.quarantined'), 'type' => 'number'],
            ['key' => 'reserved', 'label' => __('production_bench.output.columns.reserved'), 'type' => 'number'],
            ['key' => 'available', 'label' => __('production_bench.output.columns.available'), 'type' => 'number'],
            ['key' => 'incoming', 'label' => __('production_bench.output.columns.incoming'), 'type' => 'number'],
            ['key' => 'required', 'label' => __('production_bench.output.columns.required'), 'type' => 'number'],
            ['key' => 'forecast', 'label' => __('production_bench.output.columns.forecast'), 'type' => 'number'],
            ['key' => 'unit', 'label' => __('production_bench.output.columns.unit', ['unit' => $displayUnit->value]), 'type' => 'string'],
        ])->map(fn (array $column): array => $column['type'] === 'number'
            ? [...$column, 'format' => $this->quantityFormat(...)]
            : $column)->all();
    }

    /** @return list<array{key: string, label: string, type: 'string'}> */
    private function materialMetadataColumns(): array
    {
        return [
            ['key' => 'source_workspace', 'label' => __('production_bench.output.filters.source'), 'type' => 'string'],
            ['key' => 'generated_at', 'label' => __('production_bench.output.filters.generated_at'), 'type' => 'string'],
            ['key' => 'applied_filters', 'label' => __('production_bench.output.filters.applied'), 'type' => 'string'],
        ];
    }

    /** @param array<string, string> $filterLabels @return list<array{label: string, value: string}> */
    private function materialContext(Workspace $workspace, string $generatedAt, array $filterLabels): array
    {
        return [
            ['label' => __('production_bench.output.filters.source'), 'value' => $workspace->name],
            ['label' => __('production_bench.output.filters.generated_at'), 'value' => $generatedAt],
            ...collect($filterLabels)->map(fn (string $value, string $label): array => [
                'label' => $label,
                'value' => $value,
            ])->values()->all(),
        ];
    }

    /** @return array<string, string> */
    private function materialFilterLabels(array $filters): array
    {
        $labels = [];
        $search = $filters['search'];

        if ($search !== '') {
            $labels[__('production_bench.output.filters.search')] = $search;
        }

        foreach ([
            'type' => ['materialType', 'types'],
            'stock_state' => ['stockState', 'stock_states'],
            'demand' => ['demandFilter', 'demand'],
        ] as $filter => [$key, $group]) {
            $value = $filters[$filter];

            if ($value !== 'all') {
                $labels[__('production_bench.output.filters.'.$filter)] = __('production_bench.output.'.$group.'.'.$value);
            }
        }

        if ($filters['category'] !== '') {
            $labels[__('production_bench.output.filters.category')] = (string) IngredientCategory::from($filters['category'])->localizedLabel();
        }

        if ($filters['subcategory'] !== '') {
            $labels[__('production_bench.output.filters.subcategory')] = (string) IngredientSubcategory::from($filters['subcategory'])->localizedLabel();
        }

        $labels[__('production_bench.output.filters.sorted_by')] = __('production_bench.output.sort.'.$filters['sort']);
        $labels[__('production_bench.output.filters.direction')] = __('production_bench.output.direction.'.$filters['direction']);

        return $labels;
    }

    /** @param iterable<array<string, scalar|null>> $rows @return Generator<array<string, scalar|null>> */
    private function rowsWithMetadata(iterable $rows, Workspace $workspace, string $generatedAt, array $filterLabels): Generator
    {
        $appliedFilters = implode('; ', collect($filterLabels)
            ->map(fn (string $value, string $label): string => $label.': '.$value)
            ->all());

        foreach ($rows as $row) {
            yield [
                ...$row,
                'source_workspace' => $workspace->name,
                'generated_at' => $generatedAt,
                'applied_filters' => $appliedFilters,
            ];
        }
    }
}
