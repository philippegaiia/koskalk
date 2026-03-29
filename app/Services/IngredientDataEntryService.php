<?php

namespace App\Services;

use App\IngredientCategory;
use App\Models\FattyAcid;
use App\Models\Ingredient;
use App\Models\IngredientAllergenEntry;
use App\Models\IngredientComponent;
use App\Models\IngredientSapProfile;
use App\Models\IngredientVersion;
use App\Models\IngredientVersionFattyAcid;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class IngredientDataEntryService
{
    /**
     * @return array<string, mixed>
     */
    public function formData(Ingredient $ingredient): array
    {
        $currentVersion = $this->currentVersion($ingredient);

        return [
            'current_version' => [
                'display_name' => $currentVersion?->display_name,
                'display_name_en' => $currentVersion?->display_name_en,
                'display_name_fr' => $currentVersion?->display_name_fr,
                'inci_name' => $currentVersion?->inci_name,
                'supplier_name' => $currentVersion?->supplier_name,
                'supplier_reference' => $currentVersion?->supplier_reference,
                'soap_inci_naoh_name' => $currentVersion?->soap_inci_naoh_name,
                'soap_inci_koh_name' => $currentVersion?->soap_inci_koh_name,
                'cas_number' => $currentVersion?->cas_number,
                'ec_number' => $currentVersion?->ec_number,
                'unit' => $currentVersion?->unit,
                'price_eur' => $currentVersion?->price_eur === null ? null : (float) $currentVersion->price_eur,
                'is_active' => $currentVersion?->is_active ?? $ingredient->is_active,
                'is_manufactured' => $currentVersion?->is_manufactured ?? false,
            ],
            'sap_profile' => [
                'koh_sap_value' => $currentVersion?->sapProfile?->koh_sap_value === null ? null : (float) $currentVersion->sapProfile->koh_sap_value,
                'source_notes' => $currentVersion?->sapProfile?->source_notes,
            ],
            'fatty_acid_entries' => $this->fattyAcidEntriesForForm($currentVersion),
            'allergen_entries' => $currentVersion?->allergenEntries
                ->sortBy('allergen_id')
                ->map(fn (IngredientAllergenEntry $entry): array => [
                    'allergen_id' => $entry->allergen_id,
                    'concentration_percent' => $entry->concentration_percent === null ? null : (float) $entry->concentration_percent,
                    'source_notes' => $entry->source_notes,
                ])
                ->values()
                ->all() ?? [],
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
    public function syncCurrentData(Ingredient $ingredient, array $state): IngredientVersion
    {
        $currentVersionState = is_array($state['current_version'] ?? null) ? $state['current_version'] : [];
        $sapProfileState = is_array($state['sap_profile'] ?? null) ? $state['sap_profile'] : [];
        $fattyAcidEntriesState = is_array($state['fatty_acid_entries'] ?? null) ? $state['fatty_acid_entries'] : [];
        $allergenEntriesState = is_array($state['allergen_entries'] ?? null) ? $state['allergen_entries'] : [];
        $componentsState = is_array($state['components'] ?? null) ? $state['components'] : [];

        $currentVersion = $this->currentVersion($ingredient) ?? new IngredientVersion;

        if (! $currentVersion->exists) {
            $currentVersion->ingredient()->associate($ingredient);
            $currentVersion->version = ((int) $ingredient->versions()->max('version')) + 1;
        }

        $currentVersion->fill([
            'display_name' => $currentVersionState['display_name'] ?? $ingredient->source_key,
            'display_name_en' => $currentVersionState['display_name_en'] ?? null,
            'display_name_fr' => $currentVersionState['display_name_fr'] ?? null,
            'inci_name' => $currentVersionState['inci_name'] ?? null,
            'supplier_name' => array_key_exists('supplier_name', $currentVersionState)
                ? ($currentVersionState['supplier_name'] ?? null)
                : $currentVersion->supplier_name,
            'supplier_reference' => array_key_exists('supplier_reference', $currentVersionState)
                ? ($currentVersionState['supplier_reference'] ?? null)
                : $currentVersion->supplier_reference,
            'soap_inci_naoh_name' => $currentVersionState['soap_inci_naoh_name'] ?? null,
            'soap_inci_koh_name' => $currentVersionState['soap_inci_koh_name'] ?? null,
            'cas_number' => $currentVersionState['cas_number'] ?? null,
            'ec_number' => $currentVersionState['ec_number'] ?? null,
            'unit' => $currentVersionState['unit'] ?? null,
            'price_eur' => $currentVersionState['price_eur'] ?? null,
            'is_active' => $currentVersionState['is_active'] ?? $ingredient->is_active,
            'is_manufactured' => $currentVersionState['is_manufactured'] ?? false,
            'is_current' => true,
            'source_key' => $ingredient->source_key,
            'source_file' => $ingredient->source_file,
        ]);
        $currentVersion->save();

        IngredientVersion::query()
            ->where('ingredient_id', $ingredient->id)
            ->whereKeyNot($currentVersion->id)
            ->update(['is_current' => false]);

        $this->syncSapProfile($ingredient, $currentVersion, $sapProfileState, $fattyAcidEntriesState);
        $this->syncAllergenEntries($ingredient, $currentVersion, $allergenEntriesState);
        $this->syncComponents($ingredient, $componentsState);

        return $currentVersion->fresh([
            'sapProfile',
            'fattyAcidEntries.fattyAcid',
            'allergenEntries.allergen',
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
        IngredientVersion $currentVersion,
        array $sapProfileState,
        array $fattyAcidEntriesState,
    ): void {
        if ($ingredient->category !== IngredientCategory::CarrierOil) {
            return;
        }

        $sapProfile = $currentVersion->sapProfile ?? new IngredientSapProfile([
            'ingredient_version_id' => $currentVersion->id,
        ]);

        if ($sapProfile->exists || $this->hasMeaningfulSapState($sapProfileState, $fattyAcidEntriesState)) {
            $sapProfile->ingredient_version_id = $currentVersion->id;
            $sapProfile->koh_sap_value = $sapProfileState['koh_sap_value'] ?? null;
            $sapProfile->source_notes = $sapProfileState['source_notes'] ?? null;
            $sapProfile->save();
        }

        IngredientVersionFattyAcid::query()
            ->where('ingredient_version_id', $currentVersion->id)
            ->delete();

        collect($fattyAcidEntriesState)
            ->filter(fn (mixed $row): bool => is_array($row))
            ->filter(fn (array $row): bool => filled($row['fatty_acid_id'] ?? null))
            ->each(function (array $row) use ($currentVersion): void {
                IngredientVersionFattyAcid::query()->create([
                    'ingredient_version_id' => $currentVersion->id,
                    'fatty_acid_id' => (int) $row['fatty_acid_id'],
                    'percentage' => $row['percentage'] ?? 0,
                    'source_notes' => $row['source_notes'] ?? null,
                ]);
            });
    }

    private function syncAllergenEntries(
        Ingredient $ingredient,
        IngredientVersion $currentVersion,
        array $allergenEntriesState,
    ): void {
        if (! $ingredient->requiresAromaticCompliance()) {
            return;
        }

        IngredientAllergenEntry::query()
            ->where('ingredient_version_id', $currentVersion->id)
            ->delete();

        collect($allergenEntriesState)
            ->filter(fn (mixed $row): bool => is_array($row))
            ->filter(fn (array $row): bool => filled($row['allergen_id'] ?? null))
            ->each(function (array $row) use ($currentVersion): void {
                IngredientAllergenEntry::query()->create([
                    'ingredient_version_id' => $currentVersion->id,
                    'allergen_id' => (int) $row['allergen_id'],
                    'concentration_percent' => $row['concentration_percent'] ?? 0,
                    'source_notes' => $row['source_notes'] ?? null,
                ]);
            });
    }

    private function hasMeaningfulSapState(array $sapProfileState, array $fattyAcidEntriesState): bool
    {
        if (filled($sapProfileState['koh_sap_value'] ?? null) || filled($sapProfileState['source_notes'] ?? null)) {
            return true;
        }

        return collect($fattyAcidEntriesState)
            ->filter(fn (mixed $row): bool => is_array($row))
            ->contains(fn (array $row): bool => filled($row['fatty_acid_id'] ?? null) || filled($row['percentage'] ?? null));
    }

    /**
     * @return array<int, array{fatty_acid_id:int, percentage:float, source_notes:?string}>
     */
    private function fattyAcidEntriesForForm(?IngredientVersion $currentVersion): array
    {
        if ($currentVersion === null) {
            return [];
        }

        $normalizedEntries = $currentVersion->fattyAcidEntries
            ->sortBy('fatty_acid_id')
            ->map(fn (IngredientVersionFattyAcid $entry): array => [
                'fatty_acid_id' => $entry->fatty_acid_id,
                'percentage' => $entry->percentage === null ? null : (float) $entry->percentage,
                'source_notes' => $entry->source_notes,
            ])
            ->values()
            ->all();

        if ($normalizedEntries !== []) {
            return $normalizedEntries;
        }

        $legacyProfile = $currentVersion->sapProfile?->fattyAcidProfile() ?? [];

        if ($legacyProfile === []) {
            return [];
        }

        $fattyAcidIdsByKey = FattyAcid::query()
            ->whereIn('key', array_keys($legacyProfile))
            ->pluck('id', 'key');

        return collect($legacyProfile)
            ->map(function (float $percentage, string $key) use ($fattyAcidIdsByKey, $currentVersion): ?array {
                $fattyAcidId = $fattyAcidIdsByKey->get($key);

                if ($fattyAcidId === null) {
                    return null;
                }

                return [
                    'fatty_acid_id' => (int) $fattyAcidId,
                    'percentage' => (float) $percentage,
                    'source_notes' => $currentVersion->sapProfile?->source_notes,
                ];
            })
            ->filter()
            ->values()
            ->all();
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

    private function currentVersion(Ingredient $ingredient): ?IngredientVersion
    {
        if ($ingredient->relationLoaded('currentVersion') && $ingredient->currentVersion instanceof IngredientVersion) {
            return $ingredient->currentVersion;
        }

        return $ingredient->currentVersion()->first()
            ?? $ingredient->versions()->where('is_current', true)->first()
            ?? $ingredient->versions()->orderByDesc('version')->first();
    }
}
