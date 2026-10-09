<?php

namespace App\Services;

use App\Enums\OwnerType;
use App\Models\Ingredient;
use App\Models\IngredientShareMapping;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class IngredientShareResolver
{
    public function __construct(private readonly IngredientShareGraph $graphs) {}

    /** Server-only result. Callers authorize before projecting display data.
     * @param  array<string, mixed>  $graph  @param list<array<string, mixed>> $decisions @return array<string, mixed>
     */
    public function resolve(Workspace $destination, array $graph, array $decisions): array
    {
        $incoming = $graph['nodes'] ?? [];
        if (! is_array($incoming) || count($incoming) > (int) config('workspaces.formula_sharing.limits.nodes', 200)) {
            $this->invalid();
        }
        $choices = $this->decisions($incoming, $decisions);
        $resolved = [];
        $current = [];
        $work = [];
        $memo = [];
        $projection = function (Ingredient $ingredient) use (&$current, &$work, &$memo, $destination): ?array {
            if (! array_key_exists($ingredient->id, $current)) {
                try {
                    $graph = $this->graphs->current($destination, [$ingredient->id], $memo);
                    $work += array_fill_keys(array_keys($graph['source_keys']), true);
                    $current[$ingredient->id] = $graph['nodes'][$graph['root_keys'][0]] + ['current_nodes' => $graph['nodes']];
                } catch (ValidationException) {
                    $current[$ingredient->id] = null;
                }
                if (count($work) > (int) config('workspaces.formula_sharing.limits.nodes', 200)) {
                    throw ValidationException::withMessages(['decisions' => __('sharing.validation.graph_limit')]);
                }
            }

            return $current[$ingredient->id];
        };
        $resolveNode = function (string $key) use ($incoming, $choices, $destination, $projection): array {
            $node = $incoming[$key];
            if (($node['schema_version'] ?? null) !== IngredientShareFingerprint::VERSION || ! in_array($node['kind'] ?? null, ['platform', 'private'], true)) {
                $this->invalid();
            }
            $row = ['mode' => 'import', 'ingredient_id' => null, 'ingredient_public_id' => null, 'local_fingerprint' => null, 'warning' => null, 'candidates' => [], 'local_projection' => null];
            if ($node['kind'] === 'platform') {
                if (isset($choices[$key])) {
                    $this->invalid();
                }
                $platform = Ingredient::withoutGlobalScopes()->where('public_id', $node['platform_reference']['public_id'])->where('catalog_key', $node['platform_reference']['catalog_key'])
                    ->whereNull('workspace_id')->whereNull('owner_id')->whereNull('owner_type')->where('is_active', true)->first();
                $state = $platform === null ? null : $projection($platform);
                if ($state === null) {
                    throw ValidationException::withMessages(['sharing' => __('sharing.validation.reference_unavailable')]);
                }
                $row = $this->selected($row, $platform, $state, 'reuse');
                $row['warning'] = $state['fingerprint'] === $node['fingerprint'] ? null : 'platform_changed';

                return $row;
            }
            $mapping = IngredientShareMapping::query()->where('workspace_id', $destination->id)->where('lineage_key', $node['lineage_key'])
                ->where('fingerprint_version', IngredientShareFingerprint::VERSION)->where('incoming_fingerprint', $node['fingerprint'])->first();
            if (isset($choices[$key])) {
                $choice = $choices[$key];
                if ($choice['mode'] === 'import') {
                    return $row;
                }
                $candidate = $this->localQuery($destination)->where('public_id', $choice['ingredient_public_id'])->first();
                $state = $candidate === null ? null : $projection($candidate);
                if ($state === null || ($choice['mode'] === 'reuse' && ($candidate->sharingLineageKey() !== $node['lineage_key'] || $state['fingerprint'] !== $node['fingerprint']))) {
                    $this->invalid();
                }

                return $this->selected($row, $candidate, $state, $choice['mode']);
            }
            if ($mapping?->resolution === 'exact') {
                $candidate = $this->localQuery($destination)->find($mapping->ingredient_id);
                $state = $candidate === null ? null : $projection($candidate);
                if ($state !== null && $state['fingerprint'] === $mapping->local_fingerprint && $state['fingerprint'] === $node['fingerprint']) {
                    return $this->selected($row, $candidate, $state, 'reuse');
                }
            }
            $cap = (int) config('workspaces.formula_sharing.limits.nodes', 200);
            $candidates = $this->localQuery($destination)->where(fn (Builder $query): Builder => $query->where('share_lineage_key', $node['lineage_key'])
                ->orWhere(fn (Builder $query): Builder => $query->whereNull('share_lineage_key')->where('public_id', $node['lineage_key'])))->orderBy('id')->limit($cap + 1)->get();
            if ($candidates->count() > $cap) {
                $this->invalid();
            }
            $exact = [];
            foreach ($candidates as $candidate) {
                $state = $projection($candidate);
                if ($state !== null) {
                    $row['candidates'][] = ['public_id' => $candidate->public_id, 'name' => $candidate->display_name, 'fingerprint' => $state['fingerprint'], 'projection' => $state];
                    if ($state['fingerprint'] === $node['fingerprint']) {
                        $exact[$candidate->public_id] = [$candidate, $state];
                    }
                }
            }
            if ($mapping !== null) {
                $candidate = $this->localQuery($destination)->find($mapping->ingredient_id);
                $state = $candidate === null ? null : $projection($candidate);
                if ($mapping->resolution === 'exact' && $state !== null && $state['fingerprint'] === $mapping->local_fingerprint && $state['fingerprint'] === $node['fingerprint']) {
                    return $this->selected($row, $candidate, $state, 'reuse');
                }
                $row['warning'] = $mapping->resolution === 'substitution' && $state !== null && $state['fingerprint'] === $mapping->local_fingerprint ? 'remembered_substitution' : 'stale_mapping';
                if ($state !== null && ! collect($row['candidates'])->contains('public_id', $candidate->public_id)) {
                    $row['candidates'][] = ['public_id' => $candidate->public_id, 'name' => $candidate->display_name, 'fingerprint' => $state['fingerprint'], 'projection' => $state];
                }
                $row['mode'] = 'decision';
            } elseif (count($exact) === 1) {
                [$candidate, $state] = array_values($exact)[0];
                $row = $this->selected($row, $candidate, $state, 'reuse');
            } elseif ($candidates->isNotEmpty()) {
                $row['mode'] = 'decision';
                $row['warning'] = count($exact) > 1 ? 'ambiguous' : 'technical_changed';
            }
            if ($row['candidates'] === []) {
                $suggestions = $this->localQuery($destination)->where(function (Builder $query) use ($node): void {
                    $query->where('display_name', $node['display']['display_name']);
                    if (filled($node['technical']['inci_name'])) {
                        $query->orWhere('inci_name', $node['technical']['inci_name']);
                    }
                })->orderBy('id')->limit(20)->get();
                foreach ($suggestions as $suggestion) {
                    $state = $projection($suggestion);
                    if ($state !== null) {
                        $row['candidates'][] = ['public_id' => $suggestion->public_id, 'name' => $suggestion->display_name, 'fingerprint' => $state['fingerprint'], 'projection' => $state];
                    }
                }
            }

            return $row;
        };
        $active = [];
        $stack = [];
        $walk = function (string $key, int $depth) use (&$walk, &$active, &$stack, $incoming, &$resolved, $resolveNode): void {
            if (! isset($incoming[$key]) || isset($stack[$key]) || $depth > (int) config('workspaces.formula_sharing.limits.depth', 12)) {
                $this->invalid();
            }
            if (isset($active[$key])) {
                return;
            }
            $active[$key] = true;
            $resolved[$key] = $resolveNode($key);
            if (! in_array($resolved[$key]['mode'], ['import', 'decision'], true)) {
                return;
            }
            $stack[$key] = true;
            foreach ($incoming[$key]['technical']['components'] as $component) {
                $walk($component['key'], $depth + 1);
            }
            unset($stack[$key]);
        };
        foreach ($graph['root_keys'] as $key) {
            $walk($key, 1);
        }
        if (array_diff(array_keys($choices), array_keys($active)) !== []) {
            $this->invalid();
        }
        $resolved = array_intersect_key($resolved, $active);
        ksort($resolved, SORT_NATURAL);

        return [
            'nodes' => $resolved, 'active_keys' => array_keys($resolved),
            'import_count' => collect($resolved)->where('mode', 'import')->count(),
            'remaining_keys' => collect($resolved)->filter(fn (array $row): bool => $row['mode'] === 'decision')->keys()->all(),
            'decisions' => array_values($choices),
        ];
    }

    /** @param array<string, mixed> $nodes @param list<array<string, mixed>> $decisions @return array<string, array<string, mixed>> */
    private function decisions(array $nodes, array $decisions): array
    {
        if (! array_is_list($decisions) || count($decisions) > count($nodes)) {
            $this->invalid();
        }
        $result = [];
        foreach ($decisions as $decision) {
            if (! is_array($decision) || array_diff(array_keys($decision), ['key', 'mode', 'ingredient_public_id']) !== []
                || ! is_string($decision['key'] ?? null) || ! isset($nodes[$decision['key']]) || isset($result[$decision['key']])
                || ! in_array($decision['mode'] ?? null, ['import', 'reuse', 'substitute'], true)
                || ($decision['mode'] === 'import' ? isset($decision['ingredient_public_id']) : ! Str::isUuid($decision['ingredient_public_id'] ?? ''))) {
                $this->invalid();
            }
            $result[$decision['key']] = $decision;
        }
        ksort($result, SORT_NATURAL);

        return $result;
    }

    private function localQuery(Workspace $destination): Builder
    {
        return Ingredient::withoutGlobalScopes()->where('workspace_id', $destination->id)->where('is_active', true)
            ->where(fn (Builder $query): Builder => $query->where('owner_type', '!=', OwnerType::Workspace->value)->orWhereNull('owner_type')->orWhere('owner_id', $destination->id));
    }

    /** @param array<string, mixed> $row @param array<string, mixed> $state @return array<string, mixed> */
    private function selected(array $row, Ingredient $ingredient, array $state, string $mode): array
    {
        return array_replace($row, ['mode' => $mode, 'ingredient_id' => $ingredient->id, 'ingredient_public_id' => $ingredient->public_id, 'local_fingerprint' => $state['fingerprint'], 'local_projection' => $state]);
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages(['decisions' => __('sharing.validation.decisions')]);
    }
}
