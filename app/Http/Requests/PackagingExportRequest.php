<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class PackagingExportRequest extends FormRequest
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
            'format' => ['required', 'in:csv,xlsx'],
            'search' => ['nullable', 'string', 'max:255'],
            'sortField' => ['sometimes', 'in:name,material_code,unit_cost'],
            'sortDirection' => ['sometimes', 'in:asc,desc'],
        ];
    }
}
