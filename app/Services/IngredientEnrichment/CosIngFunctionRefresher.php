<?php

namespace App\Services\IngredientEnrichment;

use App\Enums\IngredientFunctionSource;
use App\Models\Ingredient;
use App\Models\IngredientFunction;
use App\Models\IngredientIdentifier;
use App\Services\IngredientEnrichment\Sources\CosingCheckerClient;
use App\Services\IngredientFunctionAssignmentService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Re-reads CosIng for platform ingredients and replaces only their
 * CosIng-sourced function links, so links stored by an older lookup follow
 * the current CosIng record. Manual and inherited links are never changed,
 * and no AI call is made.
 *
 * An ingredient is refreshed only when the matched CosIng record carries
 * exactly its stored INCI name and lists at least one known function;
 * anything less is skipped with a reason rather than guessed.
 */
class CosIngFunctionRefresher
{
    public function __construct(
        private readonly CosingCheckerClient $cosing,
        private readonly IngredientIdentityMatchService $identityMatcher,
        private readonly IngredientFunctionAssignmentService $functionAssignments,
    ) {}

    /**
     * Plans the refresh without writing. Without catalog keys, it checks every
     * platform ingredient that already carries a CosIng-sourced link.
     *
     * @param  list<string>  $catalogKeys
     * @return Collection<int, array{
     *     ingredient: Ingredient,
     *     catalog_key: string|null,
     *     display_name: string|null,
     *     status: 'change'|'unchanged'|'skipped',
     *     reason: string|null,
     *     current: list<string>,
     *     proposed: list<string>,
     *     added: list<string>,
     *     removed: list<string>,
     *     rows: list<array{key: string, source_reference: string, source_checked_at: CarbonImmutable, source_tier: mixed, confidence: mixed, source_version: mixed, source_updated_at: mixed}>
     * }>
     */
    public function plan(array $catalogKeys = []): Collection
    {
        return $this->ingredients($catalogKeys)
            ->map(fn (Ingredient $ingredient): array => $this->planIngredient($ingredient))
            ->values();
    }

    /**
     * Applies the planned changes and returns how many ingredients changed.
     *
     * @param  Collection<int, array<string, mixed>>  $plan
     */
    public function apply(Collection $plan): int
    {
        $changes = $plan->where('status', 'change');

        foreach ($changes as $row) {
            $this->functionAssignments->replaceCosIng($row['ingredient'], $row['rows']);
        }

        return $changes->count();
    }

    /**
     * @param  list<string>  $catalogKeys
     * @return Collection<int, Ingredient>
     */
    private function ingredients(array $catalogKeys): Collection
    {
        return Ingredient::query()
            ->withoutGlobalScopes()
            ->whereNull('owner_type')
            ->whereNull('owner_id')
            ->whereNull('workspace_id')
            ->when(
                $catalogKeys !== [],
                fn (Builder $query): Builder => $query->whereIn('catalog_key', $catalogKeys),
                fn (Builder $query): Builder => $query->whereHas(
                    'functions',
                    fn (Builder $functions): Builder => $functions->where('ingredient_function_ingredient.source', IngredientFunctionSource::CosIng->value),
                ),
            )
            ->with(['identifiers', 'functions'])
            ->orderBy('catalog_key')
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    private function planIngredient(Ingredient $ingredient): array
    {
        [$cosingLinks, $otherLinks] = $ingredient->functions
            ->partition(fn (IngredientFunction $function): bool => $function->pivot->source === IngredientFunctionSource::CosIng->value);
        $current = $cosingLinks->pluck('key')->sort()->values()->all();
        $plan = [
            'ingredient' => $ingredient,
            'catalog_key' => $ingredient->catalog_key,
            'display_name' => $ingredient->display_name,
            'status' => 'skipped',
            'reason' => null,
            'current' => $current,
            'proposed' => [],
            'added' => [],
            'removed' => [],
            'rows' => [],
        ];

        if (blank($ingredient->inci_name)) {
            return [...$plan, 'reason' => 'No INCI name.'];
        }

        try {
            $result = $this->cosing->lookup([
                'display_name' => $ingredient->display_name,
                'inci_name' => $ingredient->inci_name,
                'identifiers' => $ingredient->identifiers
                    ->map(fn (IngredientIdentifier $identifier): array => [
                        'scheme' => $identifier->scheme->value,
                        'value' => $identifier->value,
                        'is_primary' => $identifier->is_primary,
                    ])
                    ->values()
                    ->all(),
            ]);
        } catch (IngredientSourceException $exception) {
            return [...$plan, 'reason' => 'CosIng lookup failed: '.$exception->getMessage()];
        }

        if ($result->warnings !== []) {
            return [...$plan, 'reason' => implode(' ', $result->warnings)];
        }

        $candidate = $this->identityMatcher->select($result->data['candidates'] ?? [], [
            'display_name' => $ingredient->display_name,
            'inci_name' => $ingredient->inci_name,
            'identifiers' => $ingredient->identifiers->map(fn (IngredientIdentifier $identifier): array => ['value' => $identifier->value])->values()->all(),
        ])['candidate'];

        if (! is_array($candidate)) {
            return [...$plan, 'reason' => 'No matching CosIng record.'];
        }

        if ($this->normalize((string) ($candidate['inci_name'] ?? '')) !== $this->normalize((string) $ingredient->inci_name)) {
            return [...$plan, 'reason' => sprintf('CosIng matched %s, not the stored INCI.', $candidate['inci_name'] ?? '?')];
        }

        $proposed = collect($candidate['functions'] ?? [])->unique()->sort()->values()->all();

        if ($proposed === []) {
            return [...$plan, 'reason' => 'CosIng lists no function for this record.'];
        }

        $evidence = collect($result->evidence)->firstWhere('field', 'source.eu_structured.'.($candidate['cosing_ref'] ?? ''));
        // A function someone chose by hand stays theirs even when CosIng lists it too.
        $rowKeys = collect($proposed)->diff($otherLinks->pluck('key'))->values();
        $added = $rowKeys->diff($current)->values()->all();
        $removed = collect($current)->diff($proposed)->values()->all();

        return [
            ...$plan,
            'status' => $added === [] && $removed === [] ? 'unchanged' : 'change',
            'proposed' => $proposed,
            'added' => $added,
            'removed' => $removed,
            'rows' => $rowKeys
                ->map(fn (string $key): array => [
                    'key' => $key,
                    'source_reference' => (string) ($evidence['source_url'] ?? ''),
                    'source_checked_at' => CarbonImmutable::parse((string) ($evidence['retrieved_at'] ?? now()->toIso8601String())),
                    'source_tier' => $evidence['source_tier'] ?? null,
                    'confidence' => $evidence['confidence'] ?? null,
                    'source_version' => $evidence['source_version'] ?? null,
                    'source_updated_at' => $evidence['source_updated_at'] ?? null,
                ])
                ->all(),
        ];
    }

    private function normalize(string $inciName): string
    {
        return mb_strtoupper(preg_replace('/\s+/u', ' ', trim($inciName)) ?? '');
    }
}
