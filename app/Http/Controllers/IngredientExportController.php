<?php

namespace App\Http\Controllers;

use App\Enums\MassUnit;
use App\Http\Requests\IngredientExportRequest;
use App\Models\Ingredient;
use App\Models\User;
use App\Models\Workspace;
use App\Services\IngredientCatalogQuery;
use App\Services\PriceBasisConverter;
use App\Services\TabularFileExporter;
use App\Services\WorkspaceAuthorization;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

final class IngredientExportController extends Controller
{
    public function __invoke(
        IngredientExportRequest $request,
        IngredientCatalogQuery $catalogQuery,
        PriceBasisConverter $priceBasisConverter,
        TabularFileExporter $fileExporter,
        WorkspaceAuthorization $workspaceAuthorization,
    ): BinaryFileResponse {
        $actor = $request->user();

        abort_unless($actor instanceof User, 403);

        $workspace = $workspaceAuthorization->selectedWorkspace($actor);

        abort_unless($workspace instanceof Workspace, 403);

        $filters = $request->validated();
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

        $massDisplaySystem = $workspace->mass_display_system;
        $priceUnit = $massDisplaySystem->priceUnit();
        $currency = $actor->defaultCurrency();
        $generatedAt = now()->toIso8601String();
        $filterValues = [
            'search_filter' => $search,
            'ownership_filter' => $this->ownershipFilterLabel($ownershipFilter),
            'sort_field' => __('ingredients.exports.filters.'.$sortField),
            'sort_direction' => __('ingredients.exports.filters.'.$sortDirection),
            'source_workspace' => $workspace->name,
            'generated_at' => $generatedAt,
        ];
        $rows = $this->ingredientRows(
            $query,
            $maxRows,
            (int) config('workspaces.list_output_limits.chunk_size', 500),
            $priceBasisConverter,
            $priceUnit,
            $currency,
            $filterValues,
        );
        $columns = $this->columns($priceUnit, $currency);
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
                        ['label' => __('ingredients.exports.filters.generated_at'), 'value' => $generatedAt],
                        ['label' => __('ingredients.exports.filters.search'), 'value' => $search],
                        ['label' => __('ingredients.exports.filters.ownership'), 'value' => $filterValues['ownership_filter']],
                        ['label' => __('ingredients.exports.filters.sorted_by'), 'value' => $filterValues['sort_field']],
                        ['label' => __('ingredients.exports.filters.direction'), 'value' => $filterValues['sort_direction']],
                    ],
                    'columns' => $columns,
                    'rows' => $rows,
                ]]);
            } else {
                $path = $fileExporter->writeTemporaryCsv([
                    'columns' => $columns,
                    'rows' => $rows,
                ]);
            }

            return response()->download($path, $filename)->deleteFileAfterSend(true);
        } catch (Throwable $exception) {
            if (is_string($path)) {
                $fileExporter->deleteTemporaryFile($path);
            }

            throw $exception;
        }
    }

    /**
     * @param  array<string, string>  $filters
     * @return Generator<array<string, scalar|null>>
     */
    private function ingredientRows(
        Builder $query,
        int $maxRows,
        int $chunkSize,
        PriceBasisConverter $priceBasisConverter,
        MassUnit $priceUnit,
        string $currency,
        array $filters,
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

            $pricePerKilogram = $ingredient->user_price_per_kg;
            $currentPrice = $pricePerKilogram === null
                ? null
                : $priceBasisConverter->fromPerKilogram($pricePerKilogram, $priceUnit);
            $recordedAt = $ingredient->currentPrices->first()?->recorded_at;

            yield [
                'name' => $ingredient->localizedDisplayName() ?? $ingredient->display_name,
                'inci_name' => $ingredient->inci_name,
                'material_code' => $ingredient->workspaceCodes->first()?->material_code,
                'category' => (string) $ingredient->category?->localizedLabel(),
                'subcategory' => $ingredient->subcategory === null
                    ? null
                    : (string) $ingredient->subcategory->localizedLabel(),
                'price' => $currentPrice,
                'price_unit' => $priceUnit->value,
                'currency' => $currency,
                'recorded_at' => $recordedAt?->toDateString(),
                ...$filters,
            ];
        }
    }

    /**
     * @return list<array{key: string, label: string, type: 'string'|'number'}>
     */
    private function columns(MassUnit $priceUnit, string $currency): array
    {
        return [
            ['key' => 'name', 'label' => __('ingredients.exports.columns.name'), 'type' => 'string'],
            ['key' => 'inci_name', 'label' => __('ingredients.exports.columns.inci_name'), 'type' => 'string'],
            ['key' => 'material_code', 'label' => __('ingredients.exports.columns.material_code'), 'type' => 'string'],
            ['key' => 'category', 'label' => __('ingredients.exports.columns.category'), 'type' => 'string'],
            ['key' => 'subcategory', 'label' => __('ingredients.exports.columns.subcategory'), 'type' => 'string'],
            ['key' => 'price', 'label' => __('ingredients.exports.columns.price', [
                'unit' => $priceUnit->value,
                'currency' => $currency,
            ]), 'type' => 'number'],
            ['key' => 'price_unit', 'label' => __('ingredients.exports.columns.price_unit'), 'type' => 'string'],
            ['key' => 'currency', 'label' => __('ingredients.exports.columns.currency'), 'type' => 'string'],
            ['key' => 'recorded_at', 'label' => __('ingredients.exports.columns.recorded_at'), 'type' => 'string'],
            ['key' => 'source_workspace', 'label' => __('ingredients.exports.columns.source_workspace'), 'type' => 'string'],
            ['key' => 'generated_at', 'label' => __('ingredients.exports.columns.generated_at'), 'type' => 'string'],
            ['key' => 'search_filter', 'label' => __('ingredients.exports.columns.search_filter'), 'type' => 'string'],
            ['key' => 'ownership_filter', 'label' => __('ingredients.exports.columns.ownership_filter'), 'type' => 'string'],
            ['key' => 'sort_field', 'label' => __('ingredients.exports.columns.sort_field'), 'type' => 'string'],
            ['key' => 'sort_direction', 'label' => __('ingredients.exports.columns.sort_direction'), 'type' => 'string'],
        ];
    }

    private function ownershipFilterLabel(string $ownershipFilter): string
    {
        return match ($ownershipFilter) {
            'mine' => __('ingredients.filters.yours'),
            'platform' => __('ingredients.filters.soapkraft'),
            'priced' => __('ingredients.filters.priced'),
            default => __('ingredients.filters.all'),
        };
    }
}
