<?php

namespace App\Services\IngredientIntake;

use App\Enums\IngredientIntakeItemStatus;
use App\Models\Ingredient;
use App\Models\IngredientAlias;
use App\Models\IngredientIdentifier;
use App\Models\IngredientIntakeItem;
use Illuminate\Database\Eloquent\Builder;

final class IngredientDuplicateDetector
{
    private const PossibleMatchThreshold = 70;

    /**
     * @return array{
     *     exact: list<array<string, mixed>>,
     *     possible: list<array<string, mixed>>
     * }
     */
    public function detect(IngredientIntakeItem $item): array
    {
        $values = $this->identityValues($item);
        $exact = $this->exactPlatformCandidates($values);
        $exactIds = collect($exact)->pluck('ingredient_id')->map(fn (mixed $id): int => (int) $id)->all();
        $exact = [
            ...$exact,
            ...$this->exactIntakeCandidates($item, $values),
        ];
        $possible = $this->possiblePlatformCandidates($values, $exactIds);

        return [
            'exact' => array_values($exact),
            'possible' => array_values($possible),
        ];
    }

    public function refresh(IngredientIntakeItem $item): IngredientIntakeItem
    {
        $result = $this->detect($item->loadMissing('batch.items'));
        $candidates = [...$result['exact'], ...$result['possible']];
        $status = $item->status;

        if ($item->duplicate_resolution === null && $result['exact'] !== []) {
            $status = IngredientIntakeItemStatus::NeedsResolution;
        } elseif ($item->duplicate_resolution === null
            && $item->status === IngredientIntakeItemStatus::NeedsResolution) {
            $status = IngredientIntakeItemStatus::Draft;
        }

        $item->update([
            'duplicate_candidates' => $candidates,
            'status' => $status,
        ]);

        return $item->refresh();
    }

    /**
     * @return list<array{field: string, value: string}>
     */
    private function identityValues(IngredientIntakeItem $item): array
    {
        return collect([
            [
                'field' => 'current_name',
                'value' => $this->normalize($item->normalized_current_name ?? $item->original_current_name),
            ],
            [
                'field' => 'inci_name',
                'value' => $this->normalize($item->normalized_inci_name ?? $item->original_inci_name),
            ],
        ])->filter(fn (array $entry): bool => $entry['value'] !== null)
            ->unique('field')
            ->values()
            ->all();
    }

    /**
     * @param  list<array{field: string, value: string}>  $values
     * @return list<array<string, mixed>>
     */
    private function exactPlatformCandidates(array $values): array
    {
        $normalizedValues = collect($values)->pluck('value')->unique()->values()->all();
        $directIds = Ingredient::query()
            ->where('is_active', true)
            ->where(function (Builder $query) use ($normalizedValues): void {
                foreach ($normalizedValues as $value) {
                    $query
                        ->orWhereRaw('LOWER(TRIM(display_name)) = ?', [$value])
                        ->orWhereRaw('LOWER(TRIM(inci_name)) = ?', [$value]);
                }
            })
            ->pluck('id')
            ->all();
        $aliasIds = IngredientAlias::query()
            ->whereIn('normalized_name', $normalizedValues)
            ->pluck('ingredient_id')
            ->all();
        $identifierIds = IngredientIdentifier::query()
            ->whereIn('normalized_value', $normalizedValues)
            ->pluck('ingredient_id')
            ->all();
        $ids = array_values(array_unique(array_map('intval', [
            ...$directIds,
            ...$aliasIds,
            ...$identifierIds,
        ])));

        if ($ids === []) {
            return [];
        }

        return Ingredient::query()
            ->where('is_active', true)
            ->whereIn('id', $ids)
            ->with(['aliases', 'identifiers'])
            ->get()
            ->map(fn (Ingredient $ingredient): ?array => $this->exactCandidate($ingredient, $values))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  list<array{field: string, value: string}>  $values
     * @param  list<int>  $exactIds
     * @return list<array<string, mixed>>
     */
    private function possiblePlatformCandidates(array $values, array $exactIds): array
    {
        $needles = collect($values)
            ->flatMap(fn (array $entry): array => array_slice(
                preg_split('/\s+/u', $entry['value']) ?: [],
                0,
                2,
            ))
            ->filter(fn (string $token): bool => mb_strlen($token) >= 3)
            ->unique()
            ->values()
            ->all();

        if ($needles === []) {
            return [];
        }

        $candidates = Ingredient::query()
            ->where('is_active', true)
            ->whereNotIn('id', $exactIds)
            ->where(function (Builder $query) use ($needles): void {
                foreach ($needles as $needle) {
                    $like = '%'.$needle.'%';
                    $query
                        ->orWhereRaw('LOWER(display_name) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(inci_name) LIKE ?', [$like]);
                }
            })
            ->with(['aliases'])
            ->limit(80)
            ->get();

        return $candidates
            ->map(fn (Ingredient $ingredient): ?array => $this->possibleCandidate($ingredient, $values))
            ->filter()
            ->sortByDesc('score')
            ->take(10)
            ->values()
            ->all();
    }

    /**
     * @param  list<array{field: string, value: string}>  $values
     * @return array<string, mixed>|null
     */
    private function exactCandidate(Ingredient $ingredient, array $values): ?array
    {
        foreach ($values as $value) {
            if ($this->normalize($ingredient->display_name) === $value['value']) {
                return $this->platformCandidate($ingredient, 'display_name', $value['value'], 'exact');
            }

            if ($this->normalize($ingredient->inci_name) === $value['value']) {
                return $this->platformCandidate($ingredient, 'inci_name', $value['value'], 'exact');
            }

            $alias = $ingredient->aliases->first(
                fn ($alias): bool => $this->normalize($alias->normalized_name ?? $alias->name) === $value['value'],
            );

            if ($alias !== null) {
                return $this->platformCandidate($ingredient, 'alias', $value['value'], 'exact');
            }

            $identifier = $ingredient->identifiers->first(
                fn ($identifier): bool => $this->normalize($identifier->normalized_value ?? $identifier->value) === $value['value'],
            );

            if ($identifier !== null) {
                return $this->platformCandidate($ingredient, 'identifier', $value['value'], 'exact');
            }
        }

        return null;
    }

    /**
     * @param  list<array{field: string, value: string}>  $values
     * @return list<array<string, mixed>>
     */
    private function exactIntakeCandidates(IngredientIntakeItem $item, array $values): array
    {
        $peers = $item->batch?->items
            ->where('id', '!=', $item->id)
            ->values() ?? collect();
        $matches = [];

        foreach ($peers as $peer) {
            foreach ($values as $value) {
                $peerValue = $value['field'] === 'current_name'
                    ? $this->normalize($peer->normalized_current_name ?? $peer->original_current_name)
                    : $this->normalize($peer->normalized_inci_name ?? $peer->original_inci_name);

                if ($peerValue !== $value['value']) {
                    continue;
                }

                $matches[] = [
                    'candidate_type' => 'intake',
                    'intake_item_id' => $peer->id,
                    'intake_item_public_id' => $peer->public_id,
                    'label' => $peer->original_current_name ?? $peer->original_inci_name,
                    'matched_field' => $value['field'],
                    'matched_value' => $value['value'],
                    'match_type' => 'exact',
                    'score' => 100,
                ];
                break;
            }
        }

        return $matches;
    }

    /**
     * @param  list<array{field: string, value: string}>  $values
     * @return array<string, mixed>|null
     */
    private function possibleCandidate(Ingredient $ingredient, array $values): ?array
    {
        $best = null;
        $candidateValues = [
            ['field' => 'display_name', 'value' => $ingredient->display_name],
            ['field' => 'inci_name', 'value' => $ingredient->inci_name],
            ...$ingredient->aliases->map(fn ($alias): array => [
                'field' => 'alias',
                'value' => $alias->name,
            ])->all(),
        ];

        foreach ($values as $value) {
            foreach ($candidateValues as $candidateValue) {
                $candidate = $this->normalize($candidateValue['value']);

                if ($candidate === null || $candidate === $value['value']) {
                    continue;
                }

                similar_text($value['value'], $candidate, $score);
                $score = (int) round($score);

                if ($score < self::PossibleMatchThreshold || ($best['score'] ?? 0) >= $score) {
                    continue;
                }

                $best = [
                    'candidate_type' => 'ingredient',
                    'ingredient_id' => $ingredient->id,
                    'ingredient_public_id' => $ingredient->public_id,
                    'catalog_key' => $ingredient->catalog_key,
                    'label' => $ingredient->display_name ?? $ingredient->inci_name ?? $ingredient->catalog_key,
                    'matched_field' => $candidateValue['field'],
                    'matched_value' => $candidateValue['value'],
                    'input_field' => $value['field'],
                    'match_type' => 'possible',
                    'score' => $score,
                ];
            }
        }

        return $best;
    }

    /**
     * @return array<string, mixed>
     */
    private function platformCandidate(
        Ingredient $ingredient,
        string $matchedField,
        string $matchedValue,
        string $matchType,
    ): array {
        return [
            'candidate_type' => 'ingredient',
            'ingredient_id' => $ingredient->id,
            'ingredient_public_id' => $ingredient->public_id,
            'catalog_key' => $ingredient->catalog_key,
            'label' => $ingredient->display_name ?? $ingredient->inci_name ?? $ingredient->catalog_key,
            'matched_field' => $matchedField,
            'matched_value' => $matchedValue,
            'match_type' => $matchType,
            'score' => 100,
        ];
    }

    private function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (class_exists(\Normalizer::class)) {
            $value = \Normalizer::normalize($value, \Normalizer::FORM_C) ?: $value;
        }

        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);

        return $value === '' ? null : mb_strtolower($value, 'UTF-8');
    }
}
