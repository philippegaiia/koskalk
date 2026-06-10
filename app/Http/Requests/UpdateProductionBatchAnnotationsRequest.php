<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProductionBatchAnnotationsRequest extends FormRequest
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
            'production_batch_number' => ['nullable', 'string', 'max:120'],
            'production_notes' => ['nullable', 'string', 'max:10000'],
            'ingredient_lot_numbers' => ['array'],
            'ingredient_lot_numbers.*' => ['nullable', 'string', 'max:120'],
        ];
    }
}
