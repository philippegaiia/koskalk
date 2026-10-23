<?php

namespace App\Services\Dossiers\Calculation;

use App\Enums\ConstituentKind;
use App\Enums\SupplierQualificationStatus;
use App\Models\Ingredient;
use App\Models\IngredientComponent;
use App\Models\SupplierQualification;
use App\Models\Workspace;
use App\Services\Dossiers\SupplierConstituentResolver;
use App\Support\DecimalMath;
use Illuminate\Support\Collection;

/**
 * Allergen, impurity and restricted-substance overlays (spec §4.1): Σ (worst-case value in the raw material × raw
 * material mass), over both compositions. Overlays never add mass.
 */
class ConstituentOverlayBuilder
{
    private const MAX_COMPONENT_DEPTH = 3;

    public function __construct(private readonly SupplierConstituentResolver $resolver) {}

    /**
     * @param  list<array{key: string, ingredient_id: int|null, grams: string}>  $initial
     * @return array{
     *     rows: list<array{key: string, kind: string, allergen_id: int|null, substance_id: int|null, grams: string, needs_justification: bool,
     *                      contributions: list<array<string, mixed>>, final_percent: string, initial_percent: string, final_ppm: string, initial_ppm: string}>,
     *     undeclared: list<array{initial_key: string, ingredient_id: int, kind: string, qualification_ids: list<int>}>
     * }
     */
    public function build(Workspace $workspace, array $initial, string $initialMass, string $finalMass): array
    {
        $totals = [];
        $undeclared = [];

        foreach ($initial as $row) {
            $ingredient = $row['ingredient_id'] === null ? null : Ingredient::withoutGlobalScopes()->find($row['ingredient_id']);

            if (! $ingredient instanceof Ingredient) {
                continue;
            }

            foreach ($this->materials($workspace, $ingredient, DecimalMath::of($row['grams']), 0) as [$material, $grams]) {
                foreach (ConstituentKind::cases() as $kind) {
                    $silent = $this->resolver->suppliersWithoutDeclaration($workspace, $material, $kind);

                    if ($silent !== []) {
                        $undeclared[] = ['initial_key' => $row['key'], 'ingredient_id' => $material->id, 'kind' => $kind->value, 'qualification_ids' => $silent];
                    }

                    foreach ($this->declared($workspace, $material, $kind) as $value) {
                        $totals = $this->addContribution($totals, $kind, $value, $row['key'], $material, $grams);
                    }
                }
            }
        }

        $rows = collect($totals)
            ->map(fn (array $row): array => [
                ...$row,
                'final_percent' => DecimalMath::percentOf($row['grams'], $finalMass),
                'initial_percent' => DecimalMath::percentOf($row['grams'], $initialMass),
                'final_ppm' => DecimalMath::div(DecimalMath::mul($row['grams'], '1000000'), $finalMass),
                'initial_ppm' => DecimalMath::div(DecimalMath::mul($row['grams'], '1000000'), $initialMass),
            ])
            ->values()
            ->all();

        return ['rows' => $rows, 'undeclared' => $undeclared];
    }

    /**
     * @param  array<string, array<string, mixed>>  $totals
     * @param  array<string, mixed>  $value
     * @return array<string, array<string, mixed>>
     */
    private function addContribution(array $totals, ConstituentKind $kind, array $value, string $initialKey, Ingredient $material, string $grams): array
    {
        $isAllergen = $kind === ConstituentKind::Allergen;
        $id = (int) ($isAllergen ? $value['allergen_id'] : $value['substance_id']);
        $key = $kind->value.':'.$id;
        $declaredValue = DecimalMath::of($value['max_value']);
        $mass = DecimalMath::div(DecimalMath::mul($grams, $declaredValue), $kind === ConstituentKind::Impurity ? '1000000' : '100');

        $totals[$key] ??= [
            'key' => $key,
            'kind' => $kind->value,
            'allergen_id' => $isAllergen ? $id : null,
            'substance_id' => $isAllergen ? null : $id,
            'grams' => '0',
            'needs_justification' => false,
            'contributions' => [],
        ];
        $totals[$key]['grams'] = DecimalMath::add($totals[$key]['grams'], $mass);
        $totals[$key]['needs_justification'] = $totals[$key]['needs_justification'] || (bool) $value['needs_justification'];
        $totals[$key]['contributions'][] = [
            'initial_key' => $initialKey,
            'ingredient_id' => $material->id,
            'material_grams' => $grams,
            'value' => $declaredValue,
            'unit' => $value['unit'],
            'source' => $value['source'],
            'evidence_item_ids' => $value['evidence_item_ids'],
            'suppliers_without_declaration' => $value['suppliers_without_declaration'],
            'grams' => $mass,
        ];

        return $totals;
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function declared(Workspace $workspace, Ingredient $material, ConstituentKind $kind): Collection
    {
        return match ($kind) {
            ConstituentKind::Allergen => $this->resolver->allergens($workspace, $material),
            ConstituentKind::Impurity => $this->resolver->impurities($workspace, $material),
            ConstituentKind::RestrictedSubstance => $this->resolver->restrictedSubstances($workspace, $material),
        };
    }

    /**
     * A blend with components and no validated supplier of its own is read through its components (decision 9).
     *
     * @return list<array{0: Ingredient, 1: string}>
     */
    private function materials(Workspace $workspace, Ingredient $ingredient, string $grams, int $depth): array
    {
        $components = IngredientComponent::query()
            ->where('ingredient_id', $ingredient->id)
            ->whereNotNull('component_ingredient_id')
            ->with(['componentIngredient' => fn ($query) => $query->withoutGlobalScopes()])
            ->orderBy('sort_order')
            ->get()
            ->filter(fn (IngredientComponent $component): bool => $component->componentIngredient instanceof Ingredient);
        $hasOwnSupplier = SupplierQualification::query()
            ->where('workspace_id', $workspace->id)
            ->where('ingredient_id', $ingredient->id)
            ->where('status', SupplierQualificationStatus::Valide->value)
            ->exists();

        if ($components->isEmpty() || $hasOwnSupplier || $depth >= self::MAX_COMPONENT_DEPTH) {
            return [[$ingredient, $grams]];
        }

        return $components
            ->flatMap(fn (IngredientComponent $component): array => $this->materials(
                $workspace,
                $component->componentIngredient,
                DecimalMath::div(DecimalMath::mul($grams, DecimalMath::of($component->percentage_in_parent)), '100'),
                $depth + 1,
            ))
            ->values()
            ->all();
    }
}
