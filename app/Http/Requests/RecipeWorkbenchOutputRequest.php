<?php

namespace App\Http\Requests;

use App\Enums\IfraCategorySelectionMode;
use Filament\Forms\Components\RichEditor\RichContentRenderer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Throwable;

class RecipeWorkbenchOutputRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'workspace' => ['required', 'uuid'],
            'recipe' => ['nullable', 'uuid'],
            'product_family' => ['required', 'string', 'max:80'],
            'kind' => ['required', Rule::in(['formula', 'costing', 'composition'])],
            'format' => ['required', Rule::in(['xlsx', 'csv'])],
            'draft' => ['required', 'array'],
            'draft.name' => ['sometimes', 'nullable', 'string', 'max:160'],
            'draft.oil_unit' => ['required', Rule::in(['g', 'kg', 'oz', 'lb'])],
            'draft.oil_weight' => ['required', 'numeric', 'gt:0', 'max:1000000'],
            'draft.manufacturing_mode' => ['required', 'string', 'max:40'],
            'draft.exposure_mode' => ['required', Rule::in(['rinse_off', 'leave_on'])],
            'draft.regulatory_regime' => ['required', 'string', 'max:80'],
            'draft.product_type_id' => ['nullable', 'integer', 'min:1'],
            'draft.editing_mode' => ['required', Rule::in(['percentage', 'weight'])],
            'draft.lye_type' => ['required', Rule::in(['naoh', 'koh', 'dual'])],
            'draft.koh_purity_percentage' => ['required', Rule::in([90, 100, '90', '100'])],
            'draft.dual_lye_koh_percentage' => ['required', 'numeric', 'between:0,100'],
            'draft.water_mode' => ['required', Rule::in(['percent_of_oils', 'lye_ratio', 'lye_concentration'])],
            'draft.water_value' => ['required', 'numeric', 'min:0', 'max:500'],
            'draft.superfat' => ['required', 'numeric', 'between:-25,99.999999'],
            'draft.ifra_category_selection_mode' => ['nullable', Rule::enum(IfraCategorySelectionMode::class)],
            'draft.ifra_product_category_id' => ['nullable', 'integer', 'min:1'],
            'draft.final_ingredient_list' => ['nullable', 'string', 'max:10000'],
            'draft.final_ingredient_list_basis_hash' => ['nullable', 'string', 'max:160'],
            'draft.final_plain_ingredient_list' => ['nullable', 'string', 'max:10000'],
            'draft.final_plain_ingredient_list_basis_hash' => ['nullable', 'string', 'max:160'],
            'draft.phases' => ['sometimes', 'array', 'max:80'],
            'draft.phases.*' => ['array'],
            'draft.phases.*.key' => ['required', 'string', 'max:64'],
            'draft.phases.*.name' => ['required', 'string', 'max:100'],
            'draft.phase_items' => ['required', 'array', 'max:80'],
            'draft.phase_items.*' => ['array'],
            'draft.phase_items.*.*' => ['array'],
            'draft.phase_items.*.*.id' => ['nullable'],
            'draft.phase_items.*.*.ingredient_id' => ['nullable', 'integer', 'min:1'],
            'draft.phase_items.*.*.percentage' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'draft.phase_items.*.*.weight' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'draft.phase_items.*.*.note' => ['nullable', 'string', 'max:1000'],
            'draft.packaging_items' => ['sometimes', 'array', 'max:1000'],
            'draft.packaging_items.*' => ['array'],
            'draft.packaging_items.*.packaging_item_id' => ['nullable', 'integer', 'min:1'],
            'draft.packaging_items.*.name' => ['nullable', 'string', 'max:160'],
            'draft.packaging_items.*.components_per_unit' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'draft.packaging_items.*.notes' => ['nullable', 'string', 'max:1000'],
            'draft.production_output_type' => ['nullable', 'string', 'max:40'],
            'draft.output_ingredient_id' => ['nullable', 'integer', 'min:1'],
            'draft.ready_delay_days' => ['nullable', 'integer', 'min:0', 'max:36500'],
            'draft.product_reference' => ['nullable', 'string', 'max:160'],
            'draft.nominal_content_value' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'draft.nominal_content_unit' => ['nullable', 'string', 'max:20'],
            'manufacturing_instructions' => [
                'nullable',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_string($value) && ! is_array($value)) {
                        $fail(__('validation.string', ['attribute' => $attribute]));

                        return;
                    }

                    $encoded = is_string($value) ? $value : json_encode($value);

                    if (! is_string($encoded) || strlen($encoded) > 512_000) {
                        $fail(__('formula_documents.validation.output_payload_too_large'));

                        return;
                    }

                    if (is_array($value)) {
                        try {
                            RichContentRenderer::make($value)->getEditor()->getHTML();
                        } catch (Throwable) {
                            $fail(__('formula_documents.validation.output_instructions_invalid'));
                        }
                    }
                },
            ],
            'costing' => ['sometimes', 'nullable', 'array'],
            'costing.loaded' => ['sometimes', 'boolean'],
            'costing.oil_weight_for_costing' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'costing.oil_unit_for_costing' => ['nullable', Rule::in(['g', 'kg', 'oz', 'lb'])],
            'costing.units_produced' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'costing.currency' => ['nullable', 'string', 'size:3'],
            'costing.items' => ['sometimes', 'array', 'max:1000'],
            'costing.items.*' => ['array'],
            'costing.items.*.id' => ['nullable'],
            'costing.items.*.ingredient_id' => ['nullable', 'integer', 'min:1'],
            'costing.items.*.phase_key' => ['nullable', 'string', 'max:64'],
            'costing.items.*.position' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'costing.items.*.price_per_kg' => ['nullable', 'numeric', 'min:0', 'max:1000000000'],
            'costing.packaging_items' => ['sometimes', 'array', 'max:1000'],
            'costing.packaging_items.*' => ['array'],
            'costing.packaging_items.*.packaging_item_id' => ['nullable', 'integer', 'min:1'],
            'costing.packaging_items.*.name' => ['nullable', 'string', 'max:160'],
            'costing.packaging_items.*.unit_cost' => ['nullable', 'numeric', 'min:0', 'max:1000000000'],
            'costing.packaging_items.*.components_per_unit' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'print_context' => ['sometimes', Rule::in(['formula', 'instructions', 'costing', 'output', 'sheet'])],
            'sections' => ['sometimes', 'array', 'max:7'],
            'sections.*' => ['string', Rule::in(['formula', 'procedure', 'label', 'composition', 'costing', 'analysis', 'notes'])],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (strlen($this->getContent()) > (int) config('workspaces.formula_output_limits.payload_bytes', 2 * 1024 * 1024)) {
                $validator->errors()->add('payload', __('formula_documents.validation.output_payload_too_large'));
            }

            if ($this->input('kind') === 'composition') {
                $validator->errors()->add('kind', __('formula_documents.validation.output_kind_unavailable'));
            }

            $costing = $this->input('costing');
            $costingIsLoaded = is_array($costing) && ($costing['loaded'] ?? false) === true;

            if ($this->input('kind') === 'costing') {
                if ($this->input('format') !== 'xlsx') {
                    $validator->errors()->add('format', __('formula_documents.validation.output_costing_xlsx_only'));
                }

                if (! $costingIsLoaded) {
                    $validator->errors()->add('costing', __('formula_documents.validation.output_costing_unavailable'));
                }
            }

            if ($this->routeIs('recipes.workbench.print')
                && $this->input('print_context') === 'costing'
                && ! $costingIsLoaded) {
                $validator->errors()->add('costing', __('formula_documents.validation.output_costing_unavailable'));
            }

            $draft = $this->input('draft', []);
            $costing = $this->input('costing', []);
            $phaseItemCount = collect(is_array($draft) ? ($draft['phase_items'] ?? []) : [])
                ->sum(fn (mixed $phaseRows): int => is_array($phaseRows) ? count($phaseRows) : 0);
            $draftPackagingRows = is_array($draft) && is_array($draft['packaging_items'] ?? null) ? $draft['packaging_items'] : [];
            $costingRows = is_array($costing) && is_array($costing['items'] ?? null) ? $costing['items'] : [];
            $costingPackagingRows = is_array($costing) && is_array($costing['packaging_items'] ?? null)
                ? $costing['packaging_items']
                : [];
            $rowCount = $phaseItemCount + count($draftPackagingRows) + count($costingRows) + count($costingPackagingRows);

            if ($rowCount > (int) config('workspaces.formula_output_limits.formula_rows', 1_000)) {
                $validator->errors()->add('draft', __('formula_documents.validation.output_rows_exceeded'));
            }
        }];
    }
}
