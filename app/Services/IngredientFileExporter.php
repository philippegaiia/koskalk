<?php

namespace App\Services;

use App\Enums\MassUnit;
use App\Models\Ingredient;
use App\Models\User;
use App\Models\Workspace;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class IngredientFileExporter
{
    public function __construct(
        private readonly IngredientCatalogQuery $catalogQuery,
        private readonly PriceBasisConverter $priceBasisConverter,
        private readonly TabularFileExporter $fileExporter,
        private readonly WorkspaceAuthorization $workspaceAuthorization,
    ) {}

    /** @param array<string, mixed> $filters */
    public function validate(User $actor, array $filters): Workspace
    {
        return $this->build($actor, $filters, true);
    }

    /** @param array<string, mixed> $filters @return array{path: string, filename: string}|Workspace */
    public function build(User $actor, array $filters, bool $validateOnly = false, ?string $directory = null): array|Workspace
    {
        $catalogQuery = $this->catalogQuery;
        $priceBasisConverter = $this->priceBasisConverter;
        $fileExporter = $this->fileExporter;
        $workspaceAuthorization = $this->workspaceAuthorization;

        $workspace = $workspaceAuthorization->selectedWorkspace($actor);

        abort_unless($workspace instanceof Workspace, 403);

        $originWorkspace = Workspace::withoutGlobalScopes()
            ->where('public_id', $filters['workspace'])
            ->first();

        abort_unless(
            $originWorkspace instanceof Workspace && $workspaceAuthorization->canAccessWorkspace($actor, $originWorkspace),
            403,
        );

        abort_if(
            $originWorkspace->isNot($workspace),
            409,
            __('ingredients.exports.validation.workspace_changed'),
        );

        $ownershipFilter = $filters['ownershipFilter'] ?? 'all';
        $search = trim((string) ($filters['search'] ?? ''));
        $sortField = $filters['sortField'] ?? 'display_name';
        $sortDirection = $filters['sortDirection'] ?? 'asc';
        $query = $catalogQuery->query($actor, $workspace, [
            'ownershipFilter' => $ownershipFilter,
            'search' => $search,
            'sortField' => $sortField,
            'sortDirection' => $sortDirection,
        ]);
        $query->with([
            'workspaceCodes' => fn (HasMany $codes): HasMany => $codes->where('workspace_id', $workspace->id),
        ]);

        $maxRows = min(50_000, max(1, (int) config('workspaces.list_output_limits.max_rows', 50_000)));
        $rowCount = (clone $query)->reorder()->count();

        if ($rowCount === 0) {
            throw ValidationException::withMessages([
                'export' => __('ingredients.exports.validation.empty'),
            ]);
        }

        if ($rowCount > $maxRows) {
            throw ValidationException::withMessages([
                'export' => __('ingredients.exports.validation.too_many', ['limit' => number_format($maxRows)]),
            ]);
        }

        if ($validateOnly) {
            return $workspace;
        }

        $massDisplaySystem = $workspace->mass_display_system;
        $priceUnit = $massDisplaySystem->priceUnit();
        $rows = $this->ingredientRows(
            $query,
            $maxRows,
            (int) config('workspaces.list_output_limits.chunk_size', 500),
            $priceBasisConverter,
            $priceUnit,
            $workspace->name,
        );
        $columns = $this->columns($priceUnit);
        $format = $filters['format'];
        $filename = (Str::slug(__('ingredients.exports.filename')) ?: 'ingredient-price-book')
            .'-'.now()->toDateString().'.'.$format;
        $path = null;

        try {
            if ($format === 'xlsx') {
                $path = $fileExporter->writeTemporaryXlsx([[
                    'name' => __('ingredients.exports.sheet_name'),
                    'title' => __('ingredients.exports.title'),
                    'context' => [
                        ['label' => __('ingredients.exports.filters.source'), 'value' => $workspace->name],
                    ],
                    'columns' => $columns,
                    'rows' => $rows,
                ]], $directory);
            } else {
                $path = $fileExporter->writeTemporaryCsv([
                    'columns' => $columns,
                    'rows' => $rows,
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

    /**
     * @return Generator<array<string, scalar|null>>
     */
    private function ingredientRows(
        Builder $query,
        int $maxRows,
        int $chunkSize,
        PriceBasisConverter $priceBasisConverter,
        MassUnit $priceUnit,
        string $workspaceName,
    ): Generator {
        $rowCount = 0;

        foreach ($query->lazy(max(1, $chunkSize)) as $ingredient) {
            if (! $ingredient instanceof Ingredient) {
                continue;
            }

            if (++$rowCount > $maxRows) {
                throw ValidationException::withMessages([
                    'export' => __('ingredients.exports.validation.changed'),
                ]);
            }

            $priceRecord = $ingredient->currentPrices->first();
            $pricePerKilogram = $ingredient->user_price_per_kg;
            $currentPrice = $pricePerKilogram === null
                ? null
                : $priceBasisConverter->fromPerKilogram($pricePerKilogram, $priceUnit);
            $recordedAt = $priceRecord?->recorded_at;

            yield [
                'name' => $ingredient->localizedDisplayName() ?? $ingredient->display_name,
                'material_code' => $ingredient->workspaceCodes->first()?->material_code,
                'category' => (string) $ingredient->category?->localizedLabel(),
                'price' => $currentPrice,
                'price_unit' => $priceUnit->value,
                'currency' => $pricePerKilogram === null ? null : $priceRecord?->currency,
                'recorded_at' => $recordedAt?->toDateString(),
                'source_workspace' => $workspaceName,
            ];
        }
    }

    /**
     * @return list<array{key: string, label: string, type: 'string'|'number'}>
     */
    private function columns(MassUnit $priceUnit): array
    {
        return [
            ['key' => 'name', 'label' => __('ingredients.exports.columns.name'), 'type' => 'string'],
            ['key' => 'material_code', 'label' => __('ingredients.exports.columns.material_code'), 'type' => 'string'],
            ['key' => 'category', 'label' => __('ingredients.exports.columns.category'), 'type' => 'string'],
            ['key' => 'price', 'label' => __('ingredients.exports.columns.price', ['unit' => $priceUnit->value]), 'type' => 'number', 'format' => '0.00'],
            ['key' => 'price_unit', 'label' => __('ingredients.exports.columns.price_unit'), 'type' => 'string'],
            ['key' => 'currency', 'label' => __('ingredients.exports.columns.currency'), 'type' => 'string'],
            ['key' => 'recorded_at', 'label' => __('ingredients.exports.columns.recorded_at'), 'type' => 'string'],
            ['key' => 'source_workspace', 'label' => __('ingredients.exports.columns.source_workspace'), 'type' => 'string'],
        ];
    }
}
