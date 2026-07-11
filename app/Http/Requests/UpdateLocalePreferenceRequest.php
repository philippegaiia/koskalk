<?php

namespace App\Http\Requests;

use App\Models\SupportedLocale;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLocalePreferenceRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'locale' => [
                'required',
                'string',
                'max:16',
                Rule::exists((new SupportedLocale)->getTable(), 'code')->where('is_active', true),
            ],
        ];
    }
}
