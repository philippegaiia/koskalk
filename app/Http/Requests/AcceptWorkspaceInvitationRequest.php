<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class AcceptWorkspaceInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) config('workspaces.collaboration_enabled');
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return $this->user() === null ? [
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'password_confirmation' => ['required', 'string'],
        ] : [];
    }
}
