<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReleaseProductionEditingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'uuid'],
            'production_ids' => ['required', 'array', 'min:1', 'max:100'],
            'production_ids.*' => ['required', 'string', 'uuid', 'distinct'],
        ];
    }
}
