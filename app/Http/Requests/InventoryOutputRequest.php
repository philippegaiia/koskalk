<?php

namespace App\Http\Requests;

use App\Enums\IngredientCategory;
use App\Enums\IngredientSubcategory;
use App\Enums\StockLotOrigin;
use App\Models\StorageLocation;
use App\Models\User;
use App\Models\Workspace;
use App\Services\WorkspaceAuthorization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class InventoryOutputRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof User;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $isExport = $this->routeIs(
            'production-bench.inventory.materials.export',
            'production-bench.inventory.stock-lots.export',
        );

        return [
            'workspace' => ['required', 'uuid'],
            'format' => $isExport
                ? ['required', Rule::in(['csv', 'xlsx'])]
                : ['sometimes', Rule::in(['csv', 'xlsx'])],
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'materialType' => ['sometimes', Rule::in(['all', 'ingredient', 'packaging'])],
            'stockState' => ['sometimes', Rule::in(['all', 'negative_forecast', 'below_buffer', 'quarantined', 'incoming'])],
            'demandFilter' => ['sometimes', Rule::in(['all', 'planned', 'unplanned'])],
            'categoryFilter' => ['sometimes', 'nullable', Rule::enum(IngredientCategory::class)],
            'subcategoryFilter' => ['sometimes', 'nullable', Rule::enum(IngredientSubcategory::class)],
            'sort' => ['sometimes', Rule::in(['priority', 'name', 'physical', 'available', 'forecast'])],
            'direction' => ['sometimes', Rule::in(['asc', 'desc'])],
            'lotMaterial' => ['sometimes', 'nullable', 'uuid'],
            'lotMaterialType' => ['sometimes', 'nullable', Rule::in(['ingredient', 'packaging'])],
            'lotScope' => ['sometimes', Rule::in(['open', 'exhausted', 'all'])],
            'lotStatus' => ['sometimes', Rule::in(['all', 'released', 'quarantined'])],
            'lotSupplier' => ['sometimes', 'nullable', 'uuid'],
            'lotOrigin' => ['sometimes', 'nullable', Rule::enum(StockLotOrigin::class)],
            'lotDateBasis' => ['sometimes', Rule::in(['stocked', 'received'])],
            'lotDateFrom' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'lotDateUntil' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'lotExpiry' => ['sometimes', Rule::in(['all', 'active', 'expired', 'none'])],
            // This value is checked after workspace authorization so stale
            // parameters are ignored when locations are disabled.
            'lotStorageLocation' => ['sometimes'],
            'lotSort' => ['sometimes', Rule::in(['newest', 'oldest', 'code'])],
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $from = $this->input('lotDateFrom');
            $until = $this->input('lotDateUntil');

            if (is_string($from) && is_string($until) && $until < $from) {
                $validator->errors()->add('lotDateUntil', __('production_bench.output.validation.date_range'));

                return;
            }

            if (! $this->routeIs(
                'production-bench.inventory.stock-lots.export',
                'production-bench.inventory.count.print',
            )) {
                return;
            }

            $actor = $this->user();

            if (! $actor instanceof User) {
                return;
            }

            $workspace = app(WorkspaceAuthorization::class)->selectedWorkspace($actor);

            if (! $workspace instanceof Workspace
                || strcasecmp((string) $workspace->public_id, (string) $this->input('workspace')) !== 0
                || ! $workspace->uses_storage_locations) {
                return;
            }

            $location = $this->input('lotStorageLocation');

            if ($location === null || $location === 'all' || $location === 'unassigned') {
                return;
            }

            if ((! is_string($location) && ! is_int($location))
                || preg_match('/^[1-9]\d*$/', (string) $location) !== 1) {
                $validator->errors()->add('lotStorageLocation', __('production_bench.output.validation.invalid_storage_location'));

                return;
            }

            if (! StorageLocation::query()
                ->where('workspace_id', $workspace->id)
                ->whereKey((int) $location)
                ->exists()) {
                $validator->errors()->add('lotStorageLocation', __('production_bench.output.validation.invalid_storage_location'));
            }
        }];
    }
}
