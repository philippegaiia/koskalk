<?php

namespace App\Services;

use App\Enums\OwnerType;
use App\Enums\Visibility;
use App\Models\Ingredient;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

class IngredientShareGraph
{
    public function __construct(
        private readonly IngredientShareProjector $projector,
        private readonly IngredientShareFingerprint $fingerprint,
        private readonly WorkspaceAuthorization $authorization,
    ) {}

    /** @param list<int> $rootIds @return array{nodes: array<string, array<string, mixed>>, root_keys: list<string>, source_keys: array<int, string>} */
    public function capture(User $actor, Workspace $source, array $rootIds): array
    {
        $freshActor = $actor->fresh();
        if (! $freshActor instanceof User || ! $this->authorization->canManage($freshActor, $source->id)) {
            throw new AuthorizationException;
        }

        return $this->current($source, $rootIds);
    }

    /** Server-only technical graph; callers authorize the workspace before projecting any display data.
     * @param  list<int>  $rootIds  @return array{nodes: array<string, array<string, mixed>>, root_keys: list<string>, source_keys: array<int, string>}
     */
    public function current(Workspace $source, array $rootIds, array &$memo = []): array
    {
        $nodes = [];
        $sourceKeys = [];
        $stack = [];
        $edges = 0;
        $relations = 0;
        $walk = function (int $id, int $depth, array $path) use (&$walk, &$nodes, &$sourceKeys, &$stack, &$edges, &$relations, &$memo, $source): string {
            if ($depth > (int) config('workspaces.formula_sharing.limits.depth', 12) || isset($stack[$id])) {
                throw ValidationException::withMessages(['ingredient' => __('sharing.validation.graph_invalid', ['path' => implode(' → ', $path)])]);
            }
            if (isset($sourceKeys[$id])) {
                return $sourceKeys[$id];
            }
            if (count($sourceKeys) >= (int) config('workspaces.formula_sharing.limits.nodes', 200)) {
                throw ValidationException::withMessages(['ingredient' => __('sharing.validation.graph_limit')]);
            }
            $memoKey = $source->id.':'.$id;
            if (! isset($memo[$memoKey])) {
                $ingredient = Ingredient::withoutGlobalScopes()->find($id);
                $platform = $ingredient !== null && $ingredient->owner_type === null && $ingredient->owner_id === null && $ingredient->workspace_id === null;
                $owned = $ingredient !== null && $ingredient->workspace_id === $source->id
                    && ($ingredient->owner_type !== OwnerType::Workspace || $ingredient->owner_id === $source->id);
                if ($ingredient === null || ! $ingredient->is_active || (! $platform && ! $owned)
                    || ($platform && $ingredient->visibility !== Visibility::Public)) {
                    throw ValidationException::withMessages(['ingredient' => __('sharing.validation.graph_invalid', ['path' => implode(' → ', $path)])]);
                }
                $memo[$memoKey] = ['projection' => $this->projector->project($ingredient), 'relations' => $this->projector->relationCount($ingredient)];
            }
            $projection = $memo[$memoKey]['projection'];
            $path[] = $projection['display']['display_name'] ?? __('sharing.ingredient');
            $key = 'n'.(count($sourceKeys) + 1);
            $sourceKeys[$id] = $key;
            $stack[$id] = true;
            $relations += $memo[$memoKey]['relations'];
            if ($relations > (int) config('workspaces.formula_sharing.limits.relation_rows', 10000)) {
                throw ValidationException::withMessages(['ingredient' => __('sharing.validation.graph_limit')]);
            }
            foreach ($projection['technical']['components'] as &$component) {
                $edges++;
                if ($edges > (int) config('workspaces.formula_sharing.limits.edges', 1000) || $component['component_ingredient_id'] === null) {
                    throw ValidationException::withMessages(['ingredient' => __('sharing.validation.graph_invalid', ['path' => implode(' → ', $path)])]);
                }
                $child = $walk((int) $component['component_ingredient_id'], $depth + 1, $path);
                unset($component['component_ingredient_id']);
                $component['key'] = $child;
                $component['child_fingerprint'] = $nodes[$child]['fingerprint'];
            }
            unset($component);
            $projection['fingerprint'] = $this->fingerprint->forProjection($projection);
            $nodes[$key] = $projection;
            unset($stack[$id]);
            if (strlen(json_encode($nodes, JSON_THROW_ON_ERROR)) > (int) config('workspaces.formula_sharing.limits.bytes', 2097152)) {
                throw ValidationException::withMessages(['ingredient' => __('sharing.validation.snapshot_size')]);
            }

            return $key;
        };
        $roots = [];
        foreach (array_values(array_unique($rootIds)) as $id) {
            $roots[] = $walk((int) $id, 1, []);
        }
        ksort($nodes, SORT_NATURAL);

        return ['nodes' => $nodes, 'root_keys' => $roots, 'source_keys' => $sourceKeys];
    }
}
