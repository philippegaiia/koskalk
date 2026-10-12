<?php

namespace App\Services;

use App\Models\User;
use App\Models\Workspace;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class PackagingFileExporter
{
    public function __construct(
        private readonly PackagingCatalogQuery $catalogQuery,
        private readonly TabularFileExporter $fileExporter,
        private readonly WorkspaceAuthorization $authorization,
    ) {}

    /** @param array<string, mixed> $filters @return array{path: string, filename: string}|Workspace */
    public function build(User $actor, array $filters, bool $validateOnly = false, ?string $directory = null): array|Workspace
    {
        $workspace = $this->authorization->selectedWorkspace($actor);
        abort_unless($workspace instanceof Workspace, 403);
        $origin = Workspace::withoutGlobalScopes()->where('public_id', $filters['workspace'])->first();
        abort_unless($origin instanceof Workspace && $this->authorization->canAccessWorkspace($actor, $origin), 403);
        abort_if($origin->isNot($workspace), 409, __('packaging.exports.validation.workspace_changed'));

        $query = $this->catalogQuery->query($workspace->id, $filters);
        $maxRows = min(50_000, max(1, (int) config('workspaces.list_output_limits.max_rows', 50_000)));
        $count = (clone $query)->reorder()->count();
        if ($count === 0) {
            throw ValidationException::withMessages(['export' => __('packaging.exports.validation.empty')]);
        }
        if ($count > $maxRows) {
            throw ValidationException::withMessages([
                'export' => __('packaging.exports.validation.too_many', ['limit' => number_format($maxRows)]),
            ]);
        }
        if ($validateOnly) {
            return $workspace;
        }

        $table = [
            'columns' => $this->columns(),
            'rows' => $this->rows($query, $maxRows, $workspace->name),
        ];
        $format = $filters['format'];
        $path = $format === 'xlsx'
            ? $this->fileExporter->writeTemporaryXlsx([[
                'name' => __('packaging.exports.sheet_name'),
                'title' => __('packaging.exports.title'),
                ...$table,
            ]], $directory)
            : $this->fileExporter->writeTemporaryCsv($table, $directory);

        return [
            'path' => $path,
            'filename' => (Str::slug(__('packaging.exports.filename')) ?: 'packaging-price-book')
                .'-'.now()->toDateString().'.'.$format,
        ];
    }

    /** @return Generator<array<string, scalar|null>> */
    private function rows(Builder $query, int $maxRows, string $workspaceName): Generator
    {
        $count = 0;
        $chunkSize = max(1, (int) config('workspaces.list_output_limits.chunk_size', 500));
        foreach ($query->lazy($chunkSize) as $item) {
            if (++$count > $maxRows) {
                throw ValidationException::withMessages(['export' => __('packaging.exports.validation.changed')]);
            }
            $price = $item->currentPrice;
            yield [
                'name' => $item->name,
                'material_code' => $item->material_code,
                'category' => $item->category?->getLabel(),
                'unit_price' => $price?->price_per_canonical_unit,
                'currency' => $price?->currency,
                'recorded_at' => $price?->recorded_at?->toDateString(),
                'notes' => $item->notes,
                'workspace' => $workspaceName,
            ];
        }
    }

    /** @return list<array{key: string, label: string, type: 'string'|'number'}> */
    private function columns(): array
    {
        return [
            ['key' => 'name', 'label' => __('packaging.table.name'), 'type' => 'string'],
            ['key' => 'material_code', 'label' => __('packaging.table.material_code'), 'type' => 'string'],
            ['key' => 'category', 'label' => __('packaging.editor.form.category'), 'type' => 'string'],
            ['key' => 'unit_price', 'label' => __('packaging.exports.columns.unit_price'), 'type' => 'number', 'format' => '0.00##'],
            ['key' => 'currency', 'label' => __('ingredients.exports.columns.currency'), 'type' => 'string'],
            ['key' => 'recorded_at', 'label' => __('ingredients.exports.columns.recorded_at'), 'type' => 'string'],
            ['key' => 'notes', 'label' => __('packaging.table.notes'), 'type' => 'string'],
            ['key' => 'workspace', 'label' => __('ingredients.exports.columns.source_workspace'), 'type' => 'string'],
        ];
    }
}
