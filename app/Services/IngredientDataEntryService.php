<?php

namespace App\Services;

use App\IngredientCategory;
use App\Models\Ingredient;
use App\Models\IngredientAllergenEntry;
use App\Models\IngredientComponent;
use App\Models\IngredientFattyAcid;
use App\Models\IngredientFunction;
use App\Models\IngredientSapProfile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class IngredientDataEntryService
{
    /**
     * @return array<string, mixed>
     */
    public function formData(Ingredient $ingredient): array
    {
        return [
            'current_version' => [
                'display_name' => $ingredient->display_name,
                'display_name_en' => $ingredient->display_name_en,
                'inci_name' => $ingredient->inci_name,
                'supplier_name' => $ingredient->supplier_name,
                'supplier_reference' => $ingredient->supplier_reference,
                'soap_inci_naoh_name' => $ingredient->soap_inci_naoh_name,
                'soap_inci_koh_name' => $ingredient->soap_inci_koh_name,
                'cas_number' => $ingredient->cas_number,
                'ec_number' => $ingredient->ec_number,
                'is_organic' => $ingredient->is_organic,
                'unit' => $ingredient->unit,
                'price_eur' => $ingredient->price_eur === null ? null : (float) $ingredient->price_eur,
                'is_active' => $ingredient->is_active,
                'is_manufactured' => $ingredient->is_manufactured ?? false,
            ],
            'sap_profile' => [
                'koh_sap_value' => $ingredient->sapProfile?->koh_sap_value === null ? null : (float) $ingredient->sapProfile->koh_sap_value,
                'iodine_value' => $ingredient->sapProfile?->iodine_value === null ? null : (float) $ingredient->sapProfile->iodine_value,
                'ins_value' => $ingredient->sapProfile?->ins_value === null ? null : (float) $ingredient->sapProfile->ins_value,
                'source_notes' => $ingredient->sapProfile?->source_notes,
            ],
            'fatty_acid_entries' => $this->fattyAcidEntriesForForm($ingredient),
            'allergen_entries' => $ingredient->allergenEntries
                ->sortBy('allergen_id')
                ->map(fn (IngredientAllergenEntry $entry): array => [
                    'allergen_id' => $entry->allergen_id,
                    'concentration_percent' => $entry->concentration_percent === null ? null : (float) $entry->concentration_percent,
                    'source_notes' => $entry->source_notes,
                ])
                ->values()
                ->all() ?? [],
            'function_ids' => $ingredient->functions()
                ->orderBy('ingredient_functions.sort_order')
                ->orderBy('ingredient_functions.name')
                ->pluck('ingredient_functions.id')
                ->map(fn (int|string $id): int => (int) $id)
                ->all(),
            'components' => $ingredient->components
                ->map(fn (IngredientComponent $entry): array => [
                    'component_ingredient_id' => $entry->component_ingredient_id,
                    'percentage_in_parent' => $entry->percentage_in_parent === null ? null : (float) $entry->percentage_in_parent,
                    'source_notes' => $entry->source_notes,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     */
    public function syncCurrentData(Ingredient $ingredient, array $state): Ingredient
    {
        $currentVersionState = is_array($state['current_version'] ?? null) ? $state['current_version'] : [];
        $sapProfileState = is_array($state['sap_profile'] ?? null) ? $state['sap_profile'] : [];
        $fattyAcidEntriesState = is_array($state['fatty_acid_entries'] ?? null) ? $state['fatty_acid_entries'] : [];
        $allergenEntriesState = is_array($state['allergen_entries'] ?? null) ? $state['allergen_entries'] : [];
        $functionIdsState = is_array($state['function_ids'] ?? null) ? $state['function_ids'] : [];
        $componentsState = is_array($state['components'] ?? null) ? $state['components'] : [];

        $ingredient->fill([
            'display_name' => $currentVersionState['display_name'] ?? $ingredient->source_key,
            'display_name_en' => $currentVersionState['display_name_en'] ?? null,
            'inci_name' => $currentVersionState['inci_name'] ?? null,
            'supplier_name' => array_key_exists('supplier_name', $currentVersionState)
                ? ($currentVersionState['supplier_name'] ?? null)
                : $ingredient->supplier_name,
            'supplier_reference' => array_key_exists('supplier_reference', $currentVersionState)
                ? ($currentVersionState['supplier_reference'] ?? null)
                : $ingredient->supplier_reference,
            'soap_inci_naoh_name' => $currentVersionState['soap_inci_naoh_name'] ?? null,
            'soap_inci_koh_name' => $currentVersionState['soap_inci_koh_name'] ?? null,
            'cas_number' => $currentVersionState['cas_number'] ?? null,
            'ec_number' => $currentVersionState['ec_number'] ?? null,
            'is_organic' => (bool) ($currentVersionState['is_organic'] ?? false),
            'unit' => $currentVersionState['unit'] ?? null,
            'price_eur' => $currentVersionState['price_eur'] ?? null,
            'is_active' => array_key_exists('is_active', $currentVersionState)
                ? (bool) $currentVersionState['is_active']
                : $ingredient->is_active,
            'is_manufactured' => $currentVersionState['is_manufactured'] ?? false,
        ]);
        $ingredient->save();

        $this->syncSapProfile($ingredient, $sapProfileState, $fattyAcidEntriesState);
        $this->syncAllergenEntries($ingredient, $allergenEntriesState);
        $this->syncFunctions($ingredient, $functionIdsState);
        $this->syncComponents($ingredient, $componentsState);

        return $ingredient->fresh([
            'sapProfile',
            'fattyAcidEntries.fattyAcid',
            'allergenEntries.allergen',
            'functions',
        ]);
    }

    public function generateSourceKey(string $prefix = 'ADM', string $sourceFile = 'admin'): string
    {
        $normalizedPrefix = Str::upper(trim($prefix)) !== ''
            ? Str::upper(trim($prefix))
            : 'ADM';

        do {
            $sourceKey = sprintf('%s-%s', $normalizedPrefix, Str::upper(Str::random(8)));
        } while (Ingredient::query()->where('source_file', $sourceFile)->where('source_key', $sourceKey)->exists());

        return $sourceKey;
    }

    private function syncSapProfile(
        Ingredient $ingredient,
        array $sapProfileState,
        array $fattyAcidEntriesState,
    ): void {
        if ($ingredient->category !== IngredientCategory::CarrierOil) {
            $this->clearSapProfileData($ingredient);

            return;
        }

        $sapProfile = $ingredient->sapProfile ?? new IngredientSapProfile([
            'ingredient_id' => $ingredient->id,
        ]);

        if ($sapProfile->exists || $this->hasMeaningfulSapState($sapProfileState, $fattyAcidEntriesState)) {
            $sapProfile->ingredient_id = $ingredient->id;
            $sapProfile->koh_sap_value = $sapProfileState['koh_sap_value'] ?? null;
            $sapProfile->iodine_value = $sapProfileState['iodine_value'] ?? null;
            $sapProfile->ins_value = $sapProfileState['ins_value'] ?? null;
            $sapProfile->source_notes = $sapProfileState['source_notes'] ?? null;
            $sapProfile->save();
        }

        IngredientFattyAcid::query()
            ->where('ingredient_id', $ingredient->id)
            ->delete();

        collect($fattyAcidEntriesState)
            ->filter(fn (mixed $row): bool => is_array($row))
            ->filter(fn (array $row): bool => filled($row['fatty_acid_id'] ?? null))
            ->each(function (array $row) use ($ingredient): void {
                IngredientFattyAcid::query()->create([
                    'ingredient_id' => $ingredient->id,
                    'fatty_acid_id' => (int) $row['fatty_acid_id'],
                    'percentage' => $row['percentage'] ?? 0,
                    'source_notes' => $row['source_notes'] ?? null,
                ]);
            });
    }

    private function syncAllergenEntries(
        Ingredient $ingredient,
        array $allergenEntriesState,
    ): void {
        if (! $ingredient->requiresAromaticCompliance()) {
            IngredientAllergenEntry::query()
                ->where('ingredient_id', $ingredient->id)
                ->delete();

            return;
        }

        IngredientAllergenEntry::query()
            ->where('ingredient_id', $ingredient->id)
            ->delete();

        collect($allergenEntriesState)
            ->filter(fn (mixed $row): bool => is_array($row))
            ->filter(fn (array $row): bool => filled($row['allergen_id'] ?? null))
            ->unique(fn (array $row): int => (int) $row['allergen_id'])
            ->each(function (array $row) use ($ingredient): void {
                IngredientAllergenEntry::query()->create([
                    'ingredient_id' => $ingredient->id,
                    'allergen_id' => (int) $row['allergen_id'],
                    'concentration_percent' => $row['concentration_percent'] ?? 0,
                    'source_notes' => $row['source_notes'] ?? null,
                ]);
            });
    }

    private function syncFunctions(Ingredient $ingredient, array $functionIdsState): void
    {
        $functionIds = collect($functionIdsState)
            ->filter(fn (mixed $value): bool => filled($value) && is_numeric($value))
            ->map(fn (mixed $value): int => (int) $value)
            ->unique()
            ->values();

        if ($functionIds->isEmpty()) {
            $ingredient->functions()->sync([]);

            return;
        }

        $validFunctionIds = IngredientFunction::query()
            ->whereIn('id', $functionIds)
            ->pluck('id')
            ->map(fn (int|string $id): int => (int) $id)
            ->all();

        $ingredient->functions()->sync($validFunctionIds);
    }

    private function hasMeaningfulSapState(array $sapProfileState, array $fattyAcidEntriesState): bool
    {
        if (
            filled($sapProfileState['koh_sap_value'] ?? null)
            || filled($sapProfileState['iodine_value'] ?? null)
            || filled($sapProfileState['ins_value'] ?? null)
            || filled($sapProfileState['source_notes'] ?? null)
        ) {
            return true;
        }

        return collect($fattyAcidEntriesState)
            ->filter(fn (mixed $row): bool => is_array($row))
            ->contains(fn (array $row): bool => filled($row['fatty_acid_id'] ?? null) || filled($row['percentage'] ?? null));
    }

    private function clearSapProfileData(Ingredient $ingredient): void
    {
        IngredientFattyAcid::query()
            ->where('ingredient_id', $ingredient->id)
            ->delete();

        $ingredient->sapProfile()?->delete();
    }

    /**
     * @return array<int, array{fatty_acid_id:int, percentage:float, source_notes:?string}>
     */
    private function fattyAcidEntriesForForm(?Ingredient $ingredient): array
    {
        if (! $ingredient instanceof Ingredient) {
            return [];
        }

        $normalizedEntries = $ingredient->fattyAcidEntries
            ->sortBy('fatty_acid_id')
            ->map(fn (IngredientFattyAcid $entry): array => [
                'fatty_acid_id' => $entry->fatty_acid_id,
                'percentage' => $entry->percentage === null ? null : (float) $entry->percentage,
                'source_notes' => $entry->source_notes,
            ])
            ->values()
            ->all();

        if ($normalizedEntries !== []) {
            return $normalizedEntries;
        }

        return [];
    }

    private function syncComponents(Ingredient $ingredient, array $componentsState): void
    {
        $rawComponents = collect($componentsState)
            ->filter(fn (mixed $row): bool => is_array($row))
            ->values();

        $hasUnsupportedManualComponent = $rawComponents->contains(
            fn (array $row): bool => blank($row['component_ingredient_id'] ?? null)
                && (
                    filled($row['percentage_in_parent'] ?? null)
                    || filled($row['source_notes'] ?? null)
                ),
        );

        if ($hasUnsupportedManualComponent) {
            throw ValidationException::withMessages([
                'components' => 'Composite components must reference existing catalog ingredients.',
            ]);
        }

        $components = $rawComponents
            ->filter(fn (array $row): bool => filled($row['component_ingredient_id'] ?? null))
            ->map(function (array $row): array {
                return [
                    'component_ingredient_id' => (int) $row['component_ingredient_id'],
                    'percentage_in_parent' => isset($row['percentage_in_parent']) ? (float) $row['percentage_in_parent'] : null,
                    'source_notes' => filled($row['source_notes'] ?? null) ? trim((string) $row['source_notes']) : null,
                ];
            })
            ->values();

        IngredientComponent::query()
            ->where('ingredient_id', $ingredient->id)
            ->delete();

        if ($components->isEmpty()) {
            return;
        }

        $totalPercentage = $components->sum(fn (array $row): float => (float) ($row['percentage_in_parent'] ?? 0));

        if (abs($totalPercentage - 100.0) > 0.01) {
            throw ValidationException::withMessages([
                'components' => 'Composite ingredient percentages must total 100%.',
            ]);
        }

        $componentIngredientIds = $components
            ->pluck('component_ingredient_id')
            ->filter()
            ->map(fn (mixed $value): int => (int) $value)
            ->all();

        if (in_array($ingredient->id, $componentIngredientIds, true)) {
            throw ValidationException::withMessages([
                'components' => 'An ingredient cannot include itself as a component.',
            ]);
        }

        foreach ($componentIngredientIds as $componentIngredientId) {
            if ($this->ingredientDependsOn($componentIngredientId, $ingredient->id)) {
                throw ValidationException::withMessages([
                    'components' => 'This component would create a circular ingredient composition.',
                ]);
            }
        }

        $components->each(function (array $row, int $index) use ($ingredient): void {
            IngredientComponent::query()->create([
                'ingredient_id' => $ingredient->id,
                'component_ingredient_id' => $row['component_ingredient_id'],
                'percentage_in_parent' => $row['percentage_in_parent'] ?? 0,
                'sort_order' => $index + 1,
                'source_notes' => $row['source_notes'],
            ]);
        });
    }

    /**
     * @param  array<int, true>  $visited
     */
    private function ingredientDependsOn(int $ingredientId, int $targetIngredientId, array $visited = []): bool
    {
        if (isset($visited[$ingredientId])) {
            return false;
        }

        $visited[$ingredientId] = true;

        $componentIngredientIds = IngredientComponent::query()
            ->where('ingredient_id', $ingredientId)
            ->whereNotNull('component_ingredient_id')
            ->pluck('component_ingredient_id')
            ->map(fn (mixed $value): int => (int) $value)
            ->all();

        foreach ($componentIngredientIds as $componentIngredientId) {
            if ($componentIngredientId === $targetIngredientId) {
                return true;
            }

            if ($this->ingredientDependsOn($componentIngredientId, $targetIngredientId, $visited)) {
                return true;
            }
        }

        return false;
    }
}
