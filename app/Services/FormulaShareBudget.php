<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class FormulaShareBudget
{
    public function __construct(private readonly WorkspaceAuthorization $authorization) {}

    public function consume(User $actor, int $workspaceId, string $operation): void
    {
        $fresh = User::withoutGlobalScopes()->find($actor->id);
        if (! config('workspaces.formula_sharing.enabled', false) || $fresh === null || ! $this->authorization->canManage($fresh, $workspaceId)) {
            throw new AuthorizationException;
        }
        if (! in_array($operation, ['recipient', 'preview', 'send', 'accept'], true)) {
            throw ValidationException::withMessages(['sharing' => __('sharing.validation.options')]);
        }
        $keys = [
            "formula-sharing:$operation:actor:{$fresh->id}" => (int) config("workspaces.formula_sharing.rate_limits.$operation.actor_per_minute", $operation === 'send' ? 5 : 10),
            "formula-sharing:$operation:workspace:$workspaceId" => (int) config("workspaces.formula_sharing.rate_limits.$operation.workspace_per_minute", $operation === 'send' ? 20 : 30),
        ];
        foreach ($keys as $key => $maximum) {
            if ($maximum < 1 || RateLimiter::tooManyAttempts($key, $maximum)) {
                throw ValidationException::withMessages(['sharing' => __('sharing.validation.rate_limit', ['seconds' => max(1, RateLimiter::availableIn($key))])]);
            }
        }
        foreach ($keys as $key => $maximum) {
            RateLimiter::hit($key, 60);
        }
    }
}
