<?php

namespace App\Services;

use App\Enums\IngredientFunctionSource;
use App\Models\IfraCertificate;
use App\Models\IfraCertificateLimit;
use App\Models\IfraProductCategory;
use App\Models\Ingredient;
use App\Models\IngredientAllergenEntry;
use App\Models\IngredientComponent;
use App\Models\IngredientFattyAcid;
use App\Models\IngredientFunction;
use App\Models\IngredientSapProfile;
use App\Models\IngredientSubstanceEntry;
use App\Models\Substance;
use App\Support\NumberLocale;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class IngredientDataEntryService
{
    public function __construct(
        private readonly IngredientFunctionAssignmentService $functionAssignments,
        private readonly IngredientIdentitySynchronizer $ingredientIdentitySynchronizer,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function formData(Ingredient $ingredient): array
    {
        $identityState = $this->ingredientIdentitySynchronizer->formState($ingredient);

        return [
            ...$identityState,
            'current_version' => [
                'display_name' => $ingredient->display_name,
                'inci_name' => $ingredient->inci_name,
                'soap_inci_naoh_name' => $ingredient->soap_inci_naoh_name,
                'soap_inci_koh_name' => $ingredient->soap_inci_koh_name,
                'saponification_name' => $ingredient->saponification_name,
                'cas_number' => $identityState['cas_number'],
                'ec_number' => $identityState['ec_number'],
                'unit' => $ingredient->unit,
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
                ->wherePivot('source', IngredientFunctionSource::Manual->value)
                ->orderBy('ingredient_functions.sort_order')
                ->orderBy('ingredient_functions.name')
                ->pluck('ingredient_functions.id')
                ->map(fn (int|string $id): int => (int) $id)
                ->all(),
            'reviewed_function_ids' => $ingredient->functions()
                ->orderBy('ingredient_functions.sort_order')
                ->orderBy('ingredient_functions.name')
                ->pluck('ingredient_functions.id')
                ->map(fn (int|string $id): int => (int) $id)
                ->all(),
            'verified_function_names' => $ingredient->functions()
                ->wherePivotIn('source', [
                    IngredientFunctionSource::CosIng->value,
                    IngredientFunctionSource::Inherited->value,
                ])
                ->orderBy('ingredient_functions.sort_order')
                ->orderBy('ingredient_functions.name')
                ->get()
                ->map(fn (IngredientFunction $function): string => $function->localizedName())
                ->all(),
            'components' => $ingredient->components
                ->map(fn (IngredientComponent $entry): array => [
                    'component_ingredient_id' => $entry->component_ingredient_id,
                    'percentage_in_parent' => $entry->percentage_in_parent === null ? null : (float) $entry->percentage_in_parent,
                    'source_notes' => $entry->source_notes,
                ])
                ->values()
                ->all(),
            'substance_entries' => $ingredient->substanceEntries
                ->sortBy('substance_id')
                ->map(fn (IngredientSubstanceEntry $entry): array => [
                    'substance_id' => $entry->substance_id,
                    'concentration_percent' => $entry->concentration_percent === null
                        ? null
                        : (float) $entry->concentration_percent,
                ])
                ->values()
                ->all(),
            'ifra' => $this->ifraForForm($ingredient),
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     */
    public function syncCurrentData(Ingredient $ingredient, array $state): Ingredient
    {
        return DB::transaction(
            fn (): Ingredient => $this->syncCurrentDataWithinTransaction($ingredient, $state),
            attempts: 5,
        );
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function syncCurrentDataWithinTransaction(Ingredient $ingredient, array $state): Ingredient
    {
        $hasSapProfileState = array_key_exists('sap_profile', $state);
        $hasFattyAcidEntriesState = array_key_exists('fatty_acid_entries', $state);
        $hasAllergenEntriesState = array_key_exists('allergen_entries', $state);
        $hasFunctionIdsState = array_key_exists('function_ids', $state);
        $hasReviewedFunctionIdsState = array_key_exists('reviewed_function_ids', $state);
        $hasComponentsState = array_key_exists('components', $state);
        $hasIfraState = array_key_exists('ifra', $state);
        $currentVersionState = is_array($state['current_version'] ?? null) ? $state['current_version'] : [];
        $hasIdentityState = array_key_exists('cas_number', $state)
            || array_key_exists('ec_number', $state)
            || array_key_exists('additional_identifiers', $state)
            || array_key_exists('aliases', $state)
            || array_key_exists('cas_number', $currentVersionState ?? [])
            || array_key_exists('ec_number', $currentVersionState ?? []);
        $hasSubstanceEntriesState = array_key_exists('substance_entries', $state);
        $sapProfileState = is_array($state['sap_profile'] ?? null) ? $state['sap_profile'] : [];
        $fattyAcidEntriesState = is_array($state['fatty_acid_entries'] ?? null) ? $state['fatty_acid_entries'] : [];
        $allergenEntriesState = is_array($state['allergen_entries'] ?? null) ? $state['allergen_entries'] : [];
        $functionIdsState = is_array($state['function_ids'] ?? null) ? $state['function_ids'] : [];
        $reviewedFunctionIdsState = is_array($state['reviewed_function_ids'] ?? null) ? $state['reviewed_function_ids'] : [];
        $componentsState = is_array($state['components'] ?? null) ? $state['components'] : [];

        $ingredient->fill([
            'display_name' => $currentVersionState['display_name'] ?? $ingredient->catalog_key,
            'inci_name' => $currentVersionState['inci_name'] ?? null,
            'soap_inci_naoh_name' => $currentVersionState['soap_inci_naoh_name'] ?? null,
            'soap_inci_koh_name' => $currentVersionState['soap_inci_koh_name'] ?? null,
            'saponification_name' => $currentVersionState['saponification_name'] ?? null,
            'unit' => $currentVersionState['unit'] ?? null,
            'is_active' => array_key_exists('is_active', $currentVersionState)
                ? (bool) $currentVersionState['is_active']
                : $ingredient->is_active,
            'is_manufactured' => $currentVersionState['is_manufactured'] ?? false,
        ]);
        $ingredient->save();

        if ($hasSapProfileState || $hasFattyAcidEntriesState) {
            $this->syncSapProfile(
                $ingredient,
                $sapProfileState,
                $fattyAcidEntriesState,
                $hasSapProfileState,
                $hasFattyAcidEntriesState,
            );
        }

        if ($hasAllergenEntriesState) {
            $this->syncAllergenEntries($ingredient, $allergenEntriesState);
        }

        if ($hasReviewedFunctionIdsState) {
            $this->functionAssignments->syncReviewed($ingredient, $reviewedFunctionIdsState);
        } elseif ($hasFunctionIdsState) {
            $this->syncFunctions($ingredient, $functionIdsState);
        }

        if ($hasComponentsState) {
            $this->syncComponents($ingredient, $componentsState);
        }

        if ($hasIdentityState) {
            $identityState = $this->ingredientIdentitySynchronizer->formState($ingredient);
            if (array_key_exists('cas_number', $state)) {
                $identityState['cas_number'] = $state['cas_number'];
            } elseif (array_key_exists('cas_number', $currentVersionState)) {
                $identityState['cas_number'] = $currentVersionState['cas_number'];
            }

            if (array_key_exists('ec_number', $state)) {
                $identityState['ec_number'] = $state['ec_number'];
            } elseif (array_key_exists('ec_number', $currentVersionState)) {
                $identityState['ec_number'] = $currentVersionState['ec_number'];
            }

            if (array_key_exists('additional_identifiers', $state)) {
                $identityState['additional_identifiers'] = is_array($state['additional_identifiers'])
                    ? $state['additional_identifiers']
                    : [];
            }

            if (array_key_exists('aliases', $state)) {
                $identityState['aliases'] = is_array($state['aliases']) ? $state['aliases'] : [];
            }

            $this->ingredientIdentitySynchronizer->sync(
                $ingredient,
                $identityState,
                is_array($state['identifier_evidence'] ?? null) ? $state['identifier_evidence'] : [],
            );
        }

        if ($hasSubstanceEntriesState) {
            $this->syncSubstanceEntries($ingredient, is_array($state['substance_entries'] ?? null)
                ? $state['substance_entries']
                : []);
        }

        if ($hasIfraState && $ingredient->requiresAromaticCompliance()) {
            $this->syncIfra($ingredient, is_array($state['ifra'] ?? null) ? $state['ifra'] : []);
        }

        return $ingredient->fresh([
            'sapProfile',
            'fattyAcidEntries.fattyAcid',
            'allergenEntries.allergen',
            'functions',
            'identifiers',
            'aliases',
            'substanceEntries.substance',
            'ifraCertificates.limits.ifraProductCategory',
        ]);
    }

    /** @return array<string, mixed> */
    private function ifraForForm(Ingredient $ingredient): array
    {
        $certificate = $ingredient->ifraCertificates()
            ->with('limits')
            ->where('is_current', true)
            ->latest('id')
            ->first();

        return [
            'reference_label' => $certificate?->certificate_name,
            'ifra_amendment' => $certificate?->ifra_amendment,
            'peroxide_value' => $certificate?->peroxide_value === null ? null : (float) $certificate->peroxide_value,
            'source_notes' => $certificate?->source_notes,
            'limits' => $certificate?->limits
                ->sortBy('ifra_product_category_id')
                ->map(fn (IfraCertificateLimit $limit): array => [
                    'ifra_product_category_id' => $limit->ifra_product_category_id,
                    'max_percentage' => $limit->max_percentage === null ? null : (float) $limit->max_percentage,
                    'restriction_note' => $limit->restriction_note,
                ])
                ->values()
                ->all() ?? [],
        ];
    }

    /** @param array<string, mixed> $state */
    private function syncIfra(Ingredient $ingredient, array $state): void
    {
        $peroxideValue = NumberLocale::parseDecimalInput($state['peroxide_value'] ?? null);
        if ($peroxideValue !== null && $peroxideValue < 0) {
            throw ValidationException::withMessages([
                'ifra.peroxide_value' => __('ingredients.editor.validation.peroxide_negative'),
            ]);
        }

        $limits = collect($state['limits'] ?? [])
            ->filter(fn (mixed $row): bool => is_array($row) && filled($row['ifra_product_category_id'] ?? null))
            ->map(fn (array $row): array => [
                'ifra_product_category_id' => (int) $row['ifra_product_category_id'],
                'max_percentage' => NumberLocale::parseDecimalInput($row['max_percentage'] ?? null),
                'restriction_note' => filled($row['restriction_note'] ?? null)
                    ? trim((string) $row['restriction_note'])
                    : null,
            ])
            ->unique('ifra_product_category_id')
            ->values();

        foreach ($limits as $index => $limit) {
            if ($limit['max_percentage'] === null || $limit['max_percentage'] < 0 || $limit['max_percentage'] > 100) {
                throw ValidationException::withMessages([
                    "ifra.limits.{$index}.max_percentage" => __('ingredients.editor.validation.ifra_maximum'),
                ]);
            }
        }

        $categoryIds = $limits->pluck('ifra_product_category_id')->all();
        if (IfraProductCategory::query()->whereIn('id', $categoryIds)->count() !== count($categoryIds)) {
            throw ValidationException::withMessages([
                'ifra.limits' => __('ingredients.editor.compliance.ifra.invalid_category'),
            ]);
        }

        $certificate = $ingredient->ifraCertificates()
            ->where('is_current', true)
            ->latest('id')
            ->first();
        $hasMeaningfulData = filled($state['reference_label'] ?? null)
            || filled($state['ifra_amendment'] ?? null)
            || $peroxideValue !== null
            || filled($state['source_notes'] ?? null)
            || $limits->isNotEmpty();

        if (! $hasMeaningfulData) {
            $certificate?->limits()->delete();
            $certificate?->delete();

            return;
        }

        $certificate ??= new IfraCertificate(['ingredient_id' => $ingredient->id, 'is_current' => true]);
        $certificate->fill([
            'certificate_name' => filled($state['reference_label'] ?? null)
                ? trim((string) $state['reference_label'])
                : __('ingredients.editor.compliance.ifra.default_reference', ['ingredient' => $ingredient->display_name]),
            'ifra_amendment' => filled($state['ifra_amendment'] ?? null) ? trim((string) $state['ifra_amendment']) : null,
            'peroxide_value' => $peroxideValue,
            'source_notes' => filled($state['source_notes'] ?? null) ? trim((string) $state['source_notes']) : null,
            'is_current' => true,
        ]);
        $certificate->save();
        $certificate->limits()->delete();
        $limits->each(fn (array $limit): IfraCertificateLimit => $certificate->limits()->create($limit));
    }

    /**
     * @param  array<int, mixed>  $entries
     */
    private function syncSubstanceEntries(Ingredient $ingredient, array $entries): void
    {
        $rows = collect($entries)
            ->filter(fn (mixed $row): bool => is_array($row) && filled($row['substance_id'] ?? null))
            ->map(fn (array $row): array => [
                'substance_id' => (int) $row['substance_id'],
                'concentration_percent' => filled($row['concentration_percent'] ?? null)
                    ? NumberLocale::parseDecimalInput($row['concentration_percent'])
                    : null,
            ])
            ->unique('substance_id')
            ->values();

        if ($rows->contains(fn (array $row): bool => $row['concentration_percent'] !== null
            && ($row['concentration_percent'] < 0 || $row['concentration_percent'] > 100))) {
            throw ValidationException::withMessages([
                'substance_entries' => __('ingredients.editor.compliance.substances.validation.concentration'),
            ]);
        }

        $substanceIds = $rows->pluck('substance_id')->all();
        $validSubstanceIds = Substance::query()
            ->whereIn('id', $substanceIds)
            ->pluck('id')
            ->map(fn (int|string $id): int => (int) $id)
            ->all();

        if (count($validSubstanceIds) !== count($substanceIds)) {
            throw ValidationException::withMessages([
                'substance_entries' => __('ingredients.editor.compliance.substances.validation.catalogue'),
            ]);
        }

        $existing = $ingredient->substanceEntries()
            ->get()
            ->keyBy('substance_id');

        $ingredient->substanceEntries()->delete();

        foreach ($rows as $row) {
            $previous = $existing->get($row['substance_id']);

            $ingredient->substanceEntries()->create([
                'substance_id' => $row['substance_id'],
                'concentration_percent' => $row['concentration_percent'],
                'concentration_source' => $previous?->concentration_source ?? 'supplier',
                'source_notes' => $previous?->source_notes,
                'source_data' => $previous?->source_data,
            ]);
        }
    }

    public function generateCatalogKey(string $prefix = 'ADM'): string
    {
        $normalizedPrefix = Str::upper(trim($prefix)) !== ''
            ? Str::upper(trim($prefix))
            : 'ADM';

        do {
            $catalogKey = sprintf('%s-%s', $normalizedPrefix, Str::upper(Str::random(8)));
        } while (Ingredient::query()->where('catalog_key', $catalogKey)->exists());

        return $catalogKey;
    }

    private function syncSapProfile(
        Ingredient $ingredient,
        array $sapProfileState,
        array $fattyAcidEntriesState,
        bool $syncSapProfile,
        bool $syncFattyAcids,
    ): void {
        if (! $ingredient->is_soap_saponification_trusted
            && ! $this->hasMeaningfulSapState($sapProfileState, $fattyAcidEntriesState)) {
            return;
        }

        $sapProfile = $ingredient->sapProfile ?? new IngredientSapProfile([
            'ingredient_id' => $ingredient->id,
        ]);

        if ($syncSapProfile && ($sapProfile->exists || $this->hasMeaningfulSapState($sapProfileState, $fattyAcidEntriesState))) {
            $sapProfile->ingredient_id = $ingredient->id;
            $sapProfile->koh_sap_value = $sapProfileState['koh_sap_value'] ?? null;
            $sapProfile->iodine_value = $sapProfileState['iodine_value'] ?? null;
            $sapProfile->ins_value = $sapProfileState['ins_value'] ?? null;
            $sapProfile->source_notes = $sapProfileState['source_notes'] ?? null;
            $sapProfile->save();
        }

        if (! $syncFattyAcids) {
            return;
        }

        $existingFattyAcidSources = IngredientFattyAcid::query()
            ->where('ingredient_id', $ingredient->id)
            ->pluck('source_notes', 'fatty_acid_id');

        IngredientFattyAcid::query()
            ->where('ingredient_id', $ingredient->id)
            ->delete();

        collect($fattyAcidEntriesState)
            ->filter(fn (mixed $row): bool => is_array($row))
            ->filter(fn (array $row): bool => filled($row['fatty_acid_id'] ?? null))
            ->each(function (array $row) use ($ingredient, $existingFattyAcidSources): void {
                $fattyAcidId = (int) $row['fatty_acid_id'];

                IngredientFattyAcid::query()->create([
                    'ingredient_id' => $ingredient->id,
                    'fatty_acid_id' => $fattyAcidId,
                    'percentage' => $row['percentage'] ?? 0,
                    'source_notes' => $this->sourceNotesForResync(
                        $row,
                        $existingFattyAcidSources[$fattyAcidId] ?? null,
                    ),
                ]);
            });
    }

    private function syncAllergenEntries(
        Ingredient $ingredient,
        array $allergenEntriesState,
    ): void {
        if (! $ingredient->requiresAromaticCompliance()) {
            return;
        }

        $existingAllergenSources = IngredientAllergenEntry::query()
            ->where('ingredient_id', $ingredient->id)
            ->pluck('source_notes', 'allergen_id');

        IngredientAllergenEntry::query()
            ->where('ingredient_id', $ingredient->id)
            ->delete();

        collect($allergenEntriesState)
            ->filter(fn (mixed $row): bool => is_array($row))
            ->filter(fn (array $row): bool => filled($row['allergen_id'] ?? null))
            ->unique(fn (array $row): int => (int) $row['allergen_id'])
            ->each(function (array $row) use ($ingredient, $existingAllergenSources): void {
                $allergenId = (int) $row['allergen_id'];

                IngredientAllergenEntry::query()->create([
                    'ingredient_id' => $ingredient->id,
                    'allergen_id' => $allergenId,
                    'concentration_percent' => $row['concentration_percent'] ?? 0,
                    'source_notes' => $this->sourceNotesForResync(
                        $row,
                        $existingAllergenSources[$allergenId] ?? null,
                    ),
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
            $this->functionAssignments->syncManual($ingredient, []);

            return;
        }

        $validFunctionIds = IngredientFunction::query()
            ->whereIn('id', $functionIds)
            ->pluck('id')
            ->map(fn (int|string $id): int => (int) $id)
            ->all();

        $this->functionAssignments->syncManual($ingredient, $validFunctionIds);
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
                'components' => __('ingredients.editor.validation.component_reference_required'),
            ]);
        }

        $components = $rawComponents
            ->filter(fn (array $row): bool => filled($row['component_ingredient_id'] ?? null))
            ->map(function (array $row): array {
                $component = [
                    'component_ingredient_id' => (int) $row['component_ingredient_id'],
                    'percentage_in_parent' => NumberLocale::parseDecimalInput($row['percentage_in_parent'] ?? null),
                ];

                if (array_key_exists('source_notes', $row)) {
                    $component['source_notes'] = filled($row['source_notes'])
                        ? trim((string) $row['source_notes'])
                        : null;
                }

                return $component;
            })
            ->values();

        $existingComponentSources = IngredientComponent::query()
            ->where('ingredient_id', $ingredient->id)
            ->pluck('source_notes', 'component_ingredient_id');

        IngredientComponent::query()
            ->where('ingredient_id', $ingredient->id)
            ->delete();

        if ($components->isEmpty()) {
            return;
        }

        if ($components->count() > 20) {
            throw ValidationException::withMessages([
                'components' => __('ingredients.editor.validation.component_limit'),
            ]);
        }

        if ($components->pluck('component_ingredient_id')->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages([
                'components' => __('ingredients.editor.validation.component_duplicate'),
            ]);
        }

        if ($components->contains(fn (array $row): bool => $row['percentage_in_parent'] === null
            || $row['percentage_in_parent'] < 0
            || $row['percentage_in_parent'] > 100)) {
            throw ValidationException::withMessages([
                'components' => __('ingredients.editor.validation.component_share'),
            ]);
        }

        $totalPercentage = $components->sum(fn (array $row): float => (float) ($row['percentage_in_parent'] ?? 0));

        if (abs($totalPercentage - 100.0) > 0.01) {
            throw ValidationException::withMessages([
                'components' => __('ingredients.editor.validation.composition_total'),
            ]);
        }

        $componentIngredientIds = $components
            ->pluck('component_ingredient_id')
            ->filter()
            ->map(fn (mixed $value): int => (int) $value)
            ->all();

        if (in_array($ingredient->id, $componentIngredientIds, true)) {
            throw ValidationException::withMessages([
                'components' => __('ingredients.editor.validation.composition_self'),
            ]);
        }

        foreach ($componentIngredientIds as $componentIngredientId) {
            if ($this->ingredientDependsOn($componentIngredientId, $ingredient->id)) {
                throw ValidationException::withMessages([
                    'components' => __('ingredients.editor.validation.composition_cycle'),
                ]);
            }
        }

        $components->each(function (array $row, int $index) use ($ingredient, $existingComponentSources): void {
            $componentIngredientId = $row['component_ingredient_id'];

            IngredientComponent::query()->create([
                'ingredient_id' => $ingredient->id,
                'component_ingredient_id' => $componentIngredientId,
                'percentage_in_parent' => $row['percentage_in_parent'] ?? 0,
                'sort_order' => $index + 1,
                'source_notes' => $this->sourceNotesForResync(
                    $row,
                    $existingComponentSources[$componentIngredientId] ?? null,
                ),
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function sourceNotesForResync(array $row, mixed $existingSourceNotes): ?string
    {
        if (! array_key_exists('source_notes', $row)) {
            return filled($existingSourceNotes) ? (string) $existingSourceNotes : null;
        }

        return filled($row['source_notes'])
            ? trim((string) $row['source_notes'])
            : null;
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
