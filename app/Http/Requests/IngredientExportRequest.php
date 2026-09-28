<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class IngredientExportRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'workspace' => ['required', 'uuid'],
            'format' => ['required', 'in:xlsx,csv'],
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'ownershipFilter' => ['sometimes', 'in:all,mine,platform,priced'],
            'sortField' => ['sometimes', 'in:display_name,category'],
            'sortDirection' => ['sometimes', 'in:asc,desc'],
        ];
    }
}
