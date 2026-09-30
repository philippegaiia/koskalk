<?php

namespace App\Actions\FormulaSharing;

use App\Enums\FormulaShareStatus;
use App\Models\FormulaShare;
use App\Models\Ingredient;
use App\Models\Recipe;
use App\Models\User;
use App\Services\FormulaShareBudget;
use App\Services\FormulaSharePreview;
use App\Services\FormulaShareRecipeImporter;
use App\Services\FormulaShareTransaction;
use App\Services\IngredientShareGraph;
use App\Services\IngredientShareImporter;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class AcceptFormulaShare
{
    public function __construct(
        private readonly FormulaShareTransaction $transactions,
        private readonly FormulaShareBudget $budget,
        private readonly FormulaSharePreview $preview,
        private readonly IngredientShareGraph $graphs,
        private readonly IngredientShareImporter $ingredients,
        private readonly FormulaShareRecipeImporter $recipes,
    ) {}

    /** @param list<array<string, mixed>> $decisions */
    public function handle(User $actor, FormulaShare $share, array $decisions, string $expectedPreviewHash): Recipe
    {
        $hint = FormulaShare::query()->findOrFail($share->id);
        $this->budget->consume($actor, $hint->recipient_workspace_id, 'accept');
        $workspaceIds = [$hint->recipient_workspace_id];
        if ($hint->status !== FormulaShareStatus::Accepted && $hint->source_workspace_id !== null) {
            $workspaceIds[] = $hint->source_workspace_id;
        }

        return $this->transactions->run($actor, $workspaceIds, function (User $fresh, array $workspaces) use ($hint, $decisions, $expectedPreviewHash): Recipe {
            $share = FormulaShare::query()->lockForUpdate()->findOrFail($hint->id);
            Gate::forUser($fresh)->authorize('accept', $share);
            $destination = $workspaces[$share->recipient_workspace_id];
            if ($share->status === FormulaShareStatus::Accepted) {
                $accepted = Recipe::withoutGlobalScopes()->where('workspace_id', $destination->id)->lockForUpdate()->find($share->accepted_recipe_id);
                if ($accepted === null) {
                    $this->invalid('accepted_deleted');
                }

                return $accepted;
            }
            $prepared = $this->preview->resolve($fresh, $destination, $share, $decisions);
            $selectedIds = collect($prepared['resolution']['nodes'])->pluck('ingredient_id')->filter()->unique()->values()->all();
            if ($selectedIds !== []) {
                $current = $this->graphs->current($destination, $selectedIds);
                Ingredient::withoutGlobalScopes()->whereIn('id', array_keys($current['source_keys']))->orderBy('id')->lockForUpdate()->get();
            }
            $prepared = $this->preview->resolve($fresh, $destination, $share, $decisions);
            if (! hash_equals($prepared['expected_hash'], $expectedPreviewHash)) {
                $this->invalid('preview_changed');
            }
            if ($prepared['resolution']['remaining_keys'] !== []) {
                $this->invalid('decisions');
            }
            $map = $this->ingredients->import($fresh, $destination, $share, $prepared['resolution']);
            $accepted = $this->recipes->import($fresh, $destination, $share, $map);
            $share->forceFill([
                'status' => FormulaShareStatus::Accepted, 'accepted_recipe_id' => $accepted->id,
                'accepted_by_user_id' => $fresh->id, 'accepted_at' => now(), 'closed_at' => now(),
                'import_receipt' => ['decisions' => $prepared['resolution']['decisions'], 'ingredient_map' => $map],
            ])->save();

            return $accepted;
        });
    }

    private function invalid(string $key): never
    {
        throw ValidationException::withMessages(['sharing' => __("sharing.validation.$key")]);
    }
}
