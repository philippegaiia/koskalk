<?php

namespace App\Http\Requests;

use App\SoapSap;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use JsonException;

class PublicCalculatorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (strlen($this->getContent()) > 131072) {
            throw ValidationException::withMessages(['draft' => __('calculators.validation_payload')]);
        }
        if (is_string($this->input('draft'))) {
            try {
                $this->merge(['draft' => json_decode($this->input('draft'), true, 24, JSON_THROW_ON_ERROR)]);
            } catch (JsonException) {
                $this->merge(['draft' => null]);
            }
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'draft' => ['required', 'array:name,notes,oil_unit,oil_weight,editing_mode,lye_type,koh_purity_percentage,dual_lye_koh_percentage,water_mode,water_value,superfat,cured_water_percentage,liquid_process,phases,phase_items'],
            'draft.name' => ['nullable', 'string', 'max:160'], 'draft.notes' => ['nullable', 'string', 'max:4000'],
            'draft.oil_unit' => ['required', 'in:g,kg,oz,lb'], 'draft.oil_weight' => ['required', 'numeric', 'gt:0', 'max:1000000'],
            'draft.editing_mode' => ['required', 'in:percentage,weight'], 'draft.lye_type' => ['required', 'in:naoh,koh,dual'],
            'draft.koh_purity_percentage' => ['required', 'in:90,100'], 'draft.dual_lye_koh_percentage' => ['required', 'numeric', 'between:0,100'],
            'draft.water_mode' => ['required', 'in:percent_of_oils,lye_ratio,lye_concentration'], 'draft.water_value' => ['required', 'numeric', 'gt:0', 'max:500'],
            'draft.superfat' => ['required', 'numeric', 'between:-25,99'], 'draft.cured_water_percentage' => ['nullable', 'numeric', 'between:0,'.SoapSap::MAX_CURED_WATER_PERCENTAGE],
            'draft.liquid_process' => ['sometimes', 'nullable', 'array:first_dilution_percentage,final_dilution_percentage,neutralizer_solution_percentage,measured_paste_weight'],
            'draft.liquid_process.first_dilution_percentage' => ['required_with:draft.liquid_process', 'numeric', 'gt:0', 'lt:100'],
            'draft.liquid_process.final_dilution_percentage' => ['required_with:draft.liquid_process', 'numeric', 'gt:0', 'lt:100'],
            'draft.liquid_process.neutralizer_solution_percentage' => ['required_with:draft.liquid_process', 'numeric', 'gt:0', 'max:100'],
            'draft.liquid_process.measured_paste_weight' => ['nullable', 'numeric', 'gt:0', 'max:1000000'],
            'draft.phases' => ['required', 'array', 'max:12'], 'draft.phases.*' => ['array:key,name'],
            'draft.phases.*.key' => ['required', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_-]*$/', 'distinct'],
            'draft.phases.*.name' => ['required', 'string', 'max:100'],
            'draft.phase_items' => ['required', 'array', 'max:12'], 'draft.phase_items.*' => ['array', 'max:100'],
            'draft.phase_items.*.*' => ['array:id,ingredient_id,name,percentage,weight,note,fingerprint,final_percentage'],
            'draft.phase_items.*.*.id' => ['required', 'string', 'max:80'],
            'draft.phase_items.*.*.ingredient_id' => ['nullable', 'integer', 'min:1'],
            'draft.phase_items.*.*.name' => ['nullable', 'string', 'max:160'],
            'draft.phase_items.*.*.percentage' => ['required', 'numeric', 'between:0,1000000'],
            'draft.phase_items.*.*.weight' => ['required', 'numeric', 'between:0,1000000'],
            'draft.phase_items.*.*.final_percentage' => ['nullable', 'numeric', 'between:0,100'],
            'draft.phase_items.*.*.note' => ['nullable', 'string', 'max:1000'],
            'draft.phase_items.*.*.fingerprint' => ['nullable', 'string', 'max:64'],
        ];
    }
}
