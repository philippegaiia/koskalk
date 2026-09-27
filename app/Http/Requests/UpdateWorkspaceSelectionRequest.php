<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateWorkspaceSelectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        abort_unless(config('workspaces.collaboration_enabled'), 404);

        return $this->user() !== null;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return ['workspace_public_id' => ['required', 'string', 'uuid']];
    }
}
