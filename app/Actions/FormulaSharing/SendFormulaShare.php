<?php

namespace App\Actions\FormulaSharing;

use App\Enums\FormulaShareStatus;
use App\Models\FormulaShare;
use App\Models\Recipe;
use App\Models\User;
use App\Models\Workspace;
use App\Services\FormulaShareBudget;
use App\Services\FormulaShareSnapshotBuilder;
use App\Services\FormulaShareTransaction;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SendFormulaShare
{
    public function __construct(
        private readonly FormulaShareSnapshotBuilder $builder,
        private readonly FormulaShareBudget $budget,
        private readonly FormulaShareTransaction $transaction,
    ) {}

    /** @param array<string, mixed> $options */
    public function handle(User $actor, Recipe $recipe, Workspace $recipient, array $options, string $expectedPreviewHash, string $requestKey): FormulaShare
    {
        $sourceId = $this->builder->sourceWorkspaceId($actor, $recipe);
        $options = $this->builder->options($options);
        if (! Str::isUuid($requestKey) || preg_match('/^[a-f0-9]{64}$/D', $expectedPreviewHash) !== 1) {
            $this->invalid('request');
        }
        if ($recipient->id === $sourceId || ! Workspace::withoutGlobalScopes()->whereKey($recipient->id)->exists()) {
            $this->invalid('recipient');
        }
        $this->budget->consume($actor, $sourceId, 'send');

        return $this->transaction->run($actor, [$sourceId, $recipient->id], function (User $freshActor, array $workspaces) use ($recipe, $recipient, $options, $expectedPreviewHash, $requestKey, $sourceId): FormulaShare {
            $source = $workspaces[$sourceId];
            $destination = $workspaces[$recipient->id];
            $existing = FormulaShare::query()->where('source_workspace_id', $sourceId)->where('request_key', $requestKey)->lockForUpdate()->first();
            $lockedRecipe = Recipe::withoutGlobalScopes()->lockForUpdate()->findOrFail($recipe->id);
            $this->builder->sourceWorkspaceId($freshActor, $lockedRecipe);
            if ($lockedRecipe->workspace_id !== $sourceId) {
                $this->invalid('changed');
            }
            if ($existing !== null) {
                if ($existing->source_recipe_id !== $lockedRecipe->id || $existing->recipient_workspace_id !== $destination->id || $existing->options !== $options || ! hash_equals($existing->snapshot_hash, $expectedPreviewHash)) {
                    $this->invalid('request_reused');
                }

                return $existing;
            }
            $snapshot = $this->builder->capture($freshActor, $lockedRecipe, $source, $options);
            $hash = $this->builder->previewHash($snapshot, $destination);
            if (! hash_equals($hash, $expectedPreviewHash)) {
                $this->invalid('changed');
            }
            $pending = FormulaShare::query()->where('source_workspace_id', $sourceId)->where('status', FormulaShareStatus::Pending)->where('expires_at', '>', now());
            if ((clone $pending)->count() >= (int) config('workspaces.formula_sharing.maximum_pending_outgoing', 100)
                || (clone $pending)->where('recipient_workspace_id', $destination->id)->count() >= (int) config('workspaces.formula_sharing.maximum_pending_per_pair', 20)) {
                $this->invalid('pending_limit');
            }
            $share = new FormulaShare;
            $share->forceFill([
                'source_workspace_id' => $sourceId, 'recipient_workspace_id' => $destination->id,
                'source_recipe_id' => $lockedRecipe->id,
                'source_version_id' => $lockedRecipe->versions()->withoutGlobalScopes()->where('public_id', $snapshot['source_saved_formula']['public_id'])->value('id'),
                'sent_by_user_id' => $freshActor->id, 'sender_workspace_name' => $source->name,
                'status' => FormulaShareStatus::Pending, 'schema_version' => 1,
                'snapshot' => $snapshot, 'snapshot_hash' => $hash, 'options' => $options,
                'request_key' => $requestKey, 'sent_at' => now(), 'expires_at' => now()->addDays(14),
            ])->save();

            return $share;
        });
    }

    private function invalid(string $key): never
    {
        throw ValidationException::withMessages(['sharing' => __("sharing.validation.$key")]);
    }
}
