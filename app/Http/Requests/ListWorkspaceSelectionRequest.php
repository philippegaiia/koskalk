<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ListWorkspaceSelectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        abort_unless(config('workspaces.collaboration_enabled'), 404);

        return $this->user() !== null;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:120'],
            'workspace-selection-page' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ];
    }
}
