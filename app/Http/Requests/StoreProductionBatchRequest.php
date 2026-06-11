<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreProductionBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'recipe_version_id' => ['required', 'integer'],
            'production_batch_number' => ['nullable', 'string', 'max:120'],
            'manufacture_date' => ['required', 'date'],
            'batch_basis' => ['required', 'numeric', 'gt:0'],
            'units_produced' => ['required', 'integer', 'min:1'],
            'production_notes' => ['nullable', 'string', 'max:10000'],
            'ingredient_lot_numbers' => ['array'],
            'ingredient_lot_numbers.*' => ['nullable', 'string', 'max:120'],
        ];
    }
}
