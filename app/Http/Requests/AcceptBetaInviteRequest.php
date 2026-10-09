<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Services\BetaInviteService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AcceptBetaInviteRequest extends FormRequest
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
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        if ($this->user() !== null) {
            return [];
        }

        $invite = app(BetaInviteService::class)->findPending((string) $this->route('token'));
        if ($invite !== null && User::query()->whereRaw('LOWER(email) = ?', [$invite->email])->exists()) {
            throw ValidationException::withMessages(['email' => __('workspaces.validation.sign_in')]);
        }

        return [
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ];
    }
}
