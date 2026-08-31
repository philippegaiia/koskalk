<?php

namespace App\Services;

use App\Enums\IngredientCategory;
use App\Enums\IngredientSubcategory;
use App\Enums\OwnerType;
use App\Enums\Visibility;
use App\Models\Ingredient;
use App\Models\User;
use App\Models\Workspace;
use App\SoapSap;
use App\Support\NumberLocale;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UserIngredientAuthoringService
{
    private const TRUSTED_KOH_SAP_TOLERANCE = 0.03;

    public function __construct(
        protected IngredientDataEntryService $ingredientDataEntryService,
        protected EntitlementService $entitlementService,
        protected IngredientFunctionAssignmentService $functionAssignments,
        protected IngredientIdentitySynchronizer $ingredientIdentitySynchronizer,
        protected IngredientAliasLocaleService $ingredientAliasLocaleService,
        protected WorkspaceIngredientGuidanceService $workspaceIngredientGuidanceService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function blankState(): array
    {
        return [
            'ingredient_structure' => 'ingredient',
            'name' => null,
            'category' => null,
            'subcategory' => null,
            'is_soap_saponification_trusted' => false,
            'requires_aromatic_compliance' => false,
            'inci_name' => null,
            'cas_number' => null,
            'ec_number' => null,
            'additional_identifiers' => [],
            'aliases' => [],
            'notes' => null,
            'featured_image_path' => null,
            'featured_image_original_name' => null,
            'icon_image_path' => null,
            'icon_image_original_name' => null,
            'composition_source_notes' => null,
            'allergen_source_notes' => null,
            'function_ids' => [],
            'verified_function_names' => [],
            'allergen_entries' => [],
            'substance_entries' => [],
            'components' => [],
            'sap_profile' => [
                'koh_sap_value' => null,
                'iodine_value' => null,
                'ins_value' => null,
                'source_notes' => null,
            ],
            'fatty_acid_entries' => [],
            'ifra' => [
                'reference_label' => null,
                'ifra_amendment_id' => null,
                'source_amendment_label' => null,
                'peroxide_value' => null,
                'source_notes' => null,
                'limits' => [],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function formData(Ingredient $ingredient): array
    {
        $entryData = $this->ingredientDataEntryService->formData($ingredient);
        $isPlatformIngredient = $ingredient->owner_type === null;

        return [
            'ingredient_structure' => $ingredient->components()->exists() ? 'blend' : 'ingredient',
            'name' => $isPlatformIngredient
                ? $ingredient->localizedDisplayName()
                : data_get($entryData, 'current_version.display_name'),
            'category' => $ingredient->category?->value,
            'subcategory' => $ingredient->subcategory?->value,
            'is_soap_saponification_trusted' => $ingredient->is_soap_saponification_trusted,
            'requires_aromatic_compliance' => $ingredient->requires_aromatic_compliance,
            'inci_name' => data_get($entryData, 'current_version.inci_name'),
            'cas_number' => $entryData['cas_number'] ?? null,
            'ec_number' => $entryData['ec_number'] ?? null,
            'notes' => $ingredient->notes,
            'featured_image_path' => $ingredient->featured_image_path,
            'featured_image_original_name' => $ingredient->featured_image_original_name,
            'icon_image_path' => $ingredient->icon_image_path,
            'icon_image_original_name' => $ingredient->icon_image_original_name,
            'composition_source_notes' => $ingredient->composition_source_notes,
            'allergen_source_notes' => $ingredient->allergen_source_notes,
            'function_ids' => $entryData['function_ids'] ?? [],
            'verified_function_names' => $entryData['verified_function_names'] ?? [],
            'allergen_entries' => $entryData['allergen_entries'] ?? [],
            'substance_entries' => $entryData['substance_entries'] ?? [],
            'additional_identifiers' => $entryData['additional_identifiers'] ?? [],
            'aliases' => $entryData['aliases'] ?? [],
            'components' => $entryData['components'] ?? [],
            'sap_profile' => [
                'koh_sap_value' => data_get($entryData, 'sap_profile.koh_sap_value'),
                'iodine_value' => data_get($entryData, 'sap_profile.iodine_value'),
                'ins_value' => data_get($entryData, 'sap_profile.ins_value'),
                'source_notes' => data_get($entryData, 'sap_profile.source_notes'),
            ],
            'fatty_acid_entries' => collect(data_get($entryData, 'fatty_acid_entries', []))
                ->map(function (array $entry): array {
                    $percentage = $entry['percentage'] ?? null;

                    return [
                        ...$entry,
                        'percentage' => $percentage === null ? null : round((float) $percentage, 1),
                        '_original_percentage' => $percentage,
                    ];
                })
                ->all(),
            'ifra' => $entryData['ifra'] ?? $this->blankState()['ifra'],
        ];
    }

    public function create(array $state, User $user): Ingredient
    {
        return $this->entitlementService->withinCompanyQuotaLock($user, function (Workspace $workspace) use ($state, $user): Ingredient {
            $this->entitlementService->assertCanCreatePrivateIngredientInWorkspace($workspace);

            $ingredient = new Ingredient([
                'public_id' => Arr::get($state, 'public_id'),
                'catalog_key' => $this->ingredientDataEntryService->generateCatalogKey('USR'),
                'owner_type' => OwnerType::Workspace,
                'owner_id' => $workspace->id,
                'workspace_id' => $workspace->id,
                'visibility' => Visibility::Private,
                'requires_admin_review' => true,
                'is_active' => true,
                'is_soap_saponification_trusted' => false,
                'requires_aromatic_compliance' => false,
                'taxonomy_source' => 'workspace_user',
            ]);

            $this->fillIngredient($ingredient, $state);
            $ingredient->save();

            return $this->syncState($ingredient, $state, $user);
        });
    }

    public function update(Ingredient $ingredient, array $state, User $user): Ingredient
    {
        if (! $ingredient->isEditableBy($user)) {
            throw ValidationException::withMessages([
                'ingredient' => __('ingredients.editor.validation.private_edit_forbidden'),
            ]);
        }

        $previousFeaturedImagePath = $ingredient->featured_image_path;
        $previousIconImagePath = $ingredient->icon_image_path;

        $ingredient = DB::transaction(function () use ($ingredient, $state, $user): Ingredient {
            $this->fillIngredient($ingredient, $state);
            $ingredient->save();

            return $this->syncState($ingredient, $state, $user);
        });

        if ($previousFeaturedImagePath !== $ingredient->featured_image_path) {
            MediaStorage::deleteIngredientPath($ingredient, $previousFeaturedImagePath);
        }

        if ($previousIconImagePath !== $ingredient->icon_image_path) {
            MediaStorage::deleteIngredientPath($ingredient, $previousIconImagePath);
        }

        return $ingredient;
    }

    public function duplicate(Ingredient $source, User $user): Ingredient
    {
        if ($source->owner_type !== null) {
            throw ValidationException::withMessages([
                'ingredient' => __('ingredients.editor.validation.duplicate_platform_only'),
            ]);
        }

        if ($source->category instanceof IngredientCategory) {
            $this->assertWorkspaceAuthorableCategory($source->category);
        }

        if (
            $source->category === IngredientCategory::Lipids
            && $source->sapProfile?->koh_sap_value === null
        ) {
            throw ValidationException::withMessages([
                'ingredient' => __('ingredients.editor.validation.duplicate_soap_profile_required'),
            ]);
        }

        return $this->entitlementService->withinCompanyQuotaLock($user, function (Workspace $workspace) use ($source, $user): Ingredient {
            $this->entitlementService->assertCanCreatePrivateIngredientInWorkspace($workspace);

            $source->loadMissing([
                'translations',
                'identifiers',
                'aliases',
                'substanceEntries',
            ]);

            $copy = $source->replicate([
                'public_id',
                'featured_image_path',
                'featured_image_original_name',
                'icon_image_path',
                'icon_image_original_name',
            ]);

            $copy->catalog_key = $this->ingredientDataEntryService->generateCatalogKey('USR');
            $copy->owner_type = OwnerType::Workspace;
            $copy->owner_id = $workspace->id;
            $copy->workspace_id = $workspace->id;
            $copy->visibility = Visibility::Private;
            $copy->requires_admin_review = false;
            $copy->display_name = $source->localizedDisplayName($user->locale) ?? $source->display_name;
            $copy->saponification_name = $source->localizedSaponificationName($user->locale) ?? $source->saponification_name;
            $copy->info_markdown = null;
            $copy->source_data = $this->duplicateSourceData($source);
            $copy->featured_image_path = null;
            $copy->featured_image_original_name = null;
            $copy->icon_image_path = null;
            $copy->icon_image_original_name = null;
            $copy->save();

            $this->deepCopyRelations($source, $copy);
            $this->ingredientIdentitySynchronizer->sync($copy, $this->localizedIdentityState($source, $user));

            $localizedGuidance = $source->localizedInfoMarkdown($user->locale);

            if (filled($localizedGuidance)) {
                $this->workspaceIngredientGuidanceService->save(
                    $user,
                    $workspace,
                    $copy,
                    $this->workspaceIngredientGuidanceService->platformHtml($localizedGuidance),
                );
            }

            return $copy->fresh([
                'sapProfile',
                'fattyAcidEntries.fattyAcid',
                'components.componentIngredient',
                'allergenEntries.allergen',
                'substanceEntries.substance',
                'identifiers',
                'aliases',
                'functions',
                'ifraCertificates.limits.ifraProductCategory',
            ]);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function localizedIdentityState(Ingredient $source, User $user): array
    {
        $state = $this->ingredientIdentitySynchronizer->formState($source);

        $localeCandidates = Ingredient::translationLocaleCandidates($user->locale);
        $aliases = $this->ingredientAliasLocaleService
            ->eligibleAliases($source->aliases, $localeCandidates);

        $state['aliases'] = $aliases
            ->take(5)
            ->map(fn ($alias): array => [
                'locale' => $alias->locale,
                'name' => $alias->name,
                'kind' => $alias->kind->value,
            ])
            ->all();

        return $state;
    }

    private function deepCopyRelations(Ingredient $source, Ingredient $copy): void
    {
        if ($source->sapProfile) {
            $source->sapProfile->replicate()->fill([
                'ingredient_id' => $copy->id,
            ])->save();
        }

        $source->fattyAcidEntries->each(function ($entry) use ($copy): void {
            $entry->replicate()->fill(['ingredient_id' => $copy->id])->save();
        });

        $source->components->each(function ($component) use ($copy): void {
            $component->replicate()->fill(['ingredient_id' => $copy->id])->save();
        });

        $source->allergenEntries->each(function ($entry) use ($copy): void {
            $entry->replicate()->fill(['ingredient_id' => $copy->id])->save();
        });

        $source->substanceEntries->each(function ($entry) use ($copy): void {
            $entry->replicate()->fill(['ingredient_id' => $copy->id])->save();
        });

        $this->functionAssignments->copyTo($source, $copy);

        $source->ifraCertificates->each(function ($certificate) use ($copy): void {
            $newCertificate = $certificate->replicate()->fill(['ingredient_id' => $copy->id]);
            $newCertificate->save();

            $certificate->limits->each(function ($limit) use ($newCertificate): void {
                $limit->replicate()->fill(['ifra_certificate_id' => $newCertificate->id])->save();
            });
        });
    }

    public function createInlineComponent(array $state, User $user): Ingredient
    {
        return $this->create([
            'name' => $state['name'] ?? null,
            'category' => $state['category'] ?? null,
            'inci_name' => $state['inci_name'] ?? null,
            'cas_number' => $state['cas_number'] ?? null,
            'ec_number' => $state['ec_number'] ?? null,
            'additional_identifiers' => $state['additional_identifiers'] ?? [],
            'aliases' => $state['aliases'] ?? [],
            'substance_entries' => $state['substance_entries'] ?? [],
            'notes' => $state['notes'] ?? null,
            'featured_image_path' => null,
            'icon_image_path' => null,
            'function_ids' => [],
            'allergen_entries' => [],
            'components' => [],
            'ifra' => [
                'reference_label' => null,
                'ifra_amendment_id' => null,
                'source_amendment_label' => null,
                'peroxide_value' => null,
                'source_notes' => null,
                'limits' => [],
            ],
        ], $user);
    }

    /**
     * Soapmaking alkalis are Koskalk-curated canonical materials; a workspace
     * can never create, reclassify, or duplicate them.
     */
    private function assertWorkspaceAuthorableCategory(IngredientCategory $category): void
    {
        if ($category->isWorkspaceAuthorable()) {
            return;
        }

        throw ValidationException::withMessages([
            'category' => __('ingredients.editor.validation.soapmaking_alkalis_platform_only'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function fillIngredient(Ingredient $ingredient, array $state): void
    {
        $category = $state['category'] ?? null;

        if ($category instanceof IngredientCategory) {
            $ingredient->category = $category;
        } else {
            $ingredient->category = IngredientCategory::from((string) $category);
        }

        $this->assertWorkspaceAuthorableCategory($ingredient->category);

        $subcategory = Arr::get($state, 'subcategory');
        $ingredient->subcategory = $subcategory instanceof IngredientSubcategory
            ? $subcategory
            : (is_string($subcategory) ? IngredientSubcategory::tryFrom($subcategory) : null);

        if ($ingredient->subcategory instanceof IngredientSubcategory
            && $ingredient->subcategory->category() !== $ingredient->category) {
            throw ValidationException::withMessages([
                'subcategory' => __('ingredients.editor.validation.subcategory_mismatch'),
            ]);
        }

        $ingredient->taxonomy_source = $ingredient->owner_type === null ? 'platform_curated' : 'workspace_user';
        $ingredient->taxonomy_reviewed_at = null;
        $ingredient->taxonomy_reviewed_by_user_id = null;

        if (array_key_exists('featured_image_path', $state)) {
            $featuredImagePath = Arr::get($state, 'featured_image_path');
            $ingredient->featured_image_path = $featuredImagePath;
            $ingredient->featured_image_original_name = filled($featuredImagePath)
                ? Arr::get($state, 'featured_image_original_name')
                : null;
        }

        if (array_key_exists('icon_image_path', $state)) {
            $iconImagePath = Arr::get($state, 'icon_image_path');
            $ingredient->icon_image_path = $iconImagePath;
            $ingredient->icon_image_original_name = filled($iconImagePath)
                ? Arr::get($state, 'icon_image_original_name')
                : null;
        }
        $ingredient->notes = blank(Arr::get($state, 'notes'))
            ? null
            : trim((string) Arr::get($state, 'notes'));
        $ingredient->composition_source_notes = Arr::get($state, 'ingredient_structure') === 'blend'
            ? Arr::get($state, 'composition_source_notes')
            : null;
        $ingredient->is_soap_saponification_trusted = (bool) Arr::get($state, 'is_soap_saponification_trusted', false)
            && $this->canRetainUserSoapTrust($ingredient);
        $ingredient->requires_aromatic_compliance = (bool) Arr::get($state, 'requires_aromatic_compliance', false);
        $ingredient->allergen_source_notes = $ingredient->requiresAromaticCompliance()
            ? Arr::get($state, 'allergen_source_notes')
            : $ingredient->allergen_source_notes;
        $ingredient->is_active = true;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function syncState(Ingredient $ingredient, array $state, User $user): Ingredient
    {
        $state['fatty_acid_entries'] = $this->reconcileFattyAcidPrecision(
            Arr::get($state, 'fatty_acid_entries', []),
        );

        $this->validateAllergenEntries(Arr::get($state, 'allergen_entries', []));
        $this->validateIfraState(Arr::get($state, 'ifra', []));
        $this->validateTrustedKohSapValue($ingredient, $state);
        $this->validateTrustedFattyAcidProfile($ingredient, $state);
        $this->validateBlendComponents($ingredient, $state, $user);

        $ingredient = $this->ingredientDataEntryService->syncCurrentData($ingredient, [
            'current_version' => [
                'display_name' => Arr::get($state, 'name'),
                'inci_name' => Arr::get($state, 'inci_name'),
                'is_active' => true,
                'is_manufactured' => false,
            ],
            'cas_number' => Arr::get($state, 'cas_number'),
            'ec_number' => Arr::get($state, 'ec_number'),
            'additional_identifiers' => Arr::get($state, 'additional_identifiers', []),
            'aliases' => Arr::get($state, 'aliases', []),
            'function_ids' => Arr::get($state, 'function_ids', []),
            'sap_profile' => Arr::get($state, 'sap_profile', []),
            'fatty_acid_entries' => Arr::get($state, 'fatty_acid_entries', []),
            'allergen_entries' => Arr::get($state, 'allergen_entries', []),
            'substance_entries' => Arr::get($state, 'substance_entries', []),
            'components' => array_key_exists('ingredient_structure', $state)
                && Arr::get($state, 'ingredient_structure') !== 'blend'
                    ? []
                    : Arr::get($state, 'components', []),
            'ifra' => Arr::get($state, 'ifra', []),
        ]);

        return $ingredient->fresh([
            'sapProfile',
            'fattyAcidEntries.fattyAcid',
            'components.componentIngredient',
            'allergenEntries.allergen',
            'functions',
            'ifraCertificates.ifraAmendment',
            'ifraCertificates.limits.ifraProductCategory',
        ]);
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function validateBlendComponents(Ingredient $ingredient, array $state, User $user): void
    {
        if (Arr::get($state, 'ingredient_structure') !== 'blend') {
            return;
        }

        $componentIds = collect(Arr::get($state, 'components', []))
            ->filter(fn (mixed $row): bool => is_array($row) && filled($row['component_ingredient_id'] ?? null))
            ->pluck('component_ingredient_id')
            ->map(fn (mixed $id): int => (int) $id);

        if ($componentIds->isEmpty()) {
            throw ValidationException::withMessages([
                'components' => __('ingredients.editor.validation.blend_required'),
            ]);
        }

        $accessibleCount = Ingredient::query()
            ->accessibleTo($user)
            ->where('is_active', true)
            ->whereKey($componentIds->unique()->all())
            ->count();

        if ($accessibleCount !== $componentIds->unique()->count()) {
            throw ValidationException::withMessages([
                'components' => __('ingredients.editor.validation.blend_component_unavailable'),
            ]);
        }
    }

    /**
     * @param  array<int|string, mixed>  $entries
     * @return array<int|string, mixed>
     */
    private function reconcileFattyAcidPrecision(array $entries): array
    {
        return collect($entries)
            ->map(function (mixed $entry): mixed {
                if (! is_array($entry) || ! array_key_exists('_original_percentage', $entry)) {
                    return $entry;
                }

                $displayed = NumberLocale::parseDecimalInput($entry['percentage'] ?? null);
                $original = NumberLocale::parseDecimalInput($entry['_original_percentage']);

                if ($displayed !== null && $original !== null && round($displayed, 1) === round($original, 1)) {
                    $entry['percentage'] = $original;
                }

                unset($entry['_original_percentage']);

                return $entry;
            })
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function duplicateSourceData(Ingredient $source): ?array
    {
        $sourceData = is_array($source->source_data) ? $source->source_data : [];
        $trustedKohSapValue = $source->sapProfile?->koh_sap_value;

        if (
            ! $source->is_soap_saponification_trusted
            || $trustedKohSapValue === null
        ) {
            return $sourceData === [] ? null : $sourceData;
        }

        $trustedFattyAcidProfile = $source->fattyAcidEntries()
            ->pluck('percentage', 'fatty_acid_id')
            ->map(fn (mixed $percentage): float => (float) $percentage)
            ->all();

        return array_replace_recursive($sourceData, [
            'user_authoring' => [
                'trusted_koh_sap_value' => SoapSap::normalizeKohSapInput((float) $trustedKohSapValue),
                'trusted_fatty_acid_profile' => $trustedFattyAcidProfile,
            ],
        ]);
    }

    private function canRetainUserSoapTrust(Ingredient $ingredient): bool
    {
        return $ingredient->owner_type !== null
            && is_numeric(Arr::get($ingredient->source_data, 'user_authoring.trusted_koh_sap_value'));
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function validateTrustedKohSapValue(Ingredient $ingredient, array $state): void
    {
        if (! $this->canRetainUserSoapTrust($ingredient)) {
            return;
        }

        $trustedKohSapValue = (float) Arr::get($ingredient->source_data, 'user_authoring.trusted_koh_sap_value');
        $kohSapValue = Arr::get($state, 'sap_profile.koh_sap_value');

        if ($kohSapValue === null || $kohSapValue === '' || ! is_numeric($kohSapValue)) {
            throw ValidationException::withMessages([
                'sap_profile.koh_sap_value' => __('ingredients.editor.validation.soap_koh_required'),
            ]);
        }

        $normalizedKohSapValue = SoapSap::normalizeKohSapInput((float) $kohSapValue);
        $minimumValue = $trustedKohSapValue * (1 - self::TRUSTED_KOH_SAP_TOLERANCE);
        $maximumValue = $trustedKohSapValue * (1 + self::TRUSTED_KOH_SAP_TOLERANCE);

        if ($normalizedKohSapValue < $minimumValue || $normalizedKohSapValue > $maximumValue) {
            throw ValidationException::withMessages([
                'sap_profile.koh_sap_value' => __('ingredients.editor.validation.soap_koh_tolerance', [
                    'tolerance' => self::TRUSTED_KOH_SAP_TOLERANCE * 100,
                ]),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function validateTrustedFattyAcidProfile(Ingredient $ingredient, array $state): void
    {
        if (! $this->canRetainUserSoapTrust($ingredient)) {
            return;
        }

        $trustedProfile = collect(Arr::get(
            $ingredient->source_data,
            'user_authoring.trusted_fatty_acid_profile',
            [],
        ))->mapWithKeys(fn (mixed $value, mixed $key): array => [(int) $key => (float) $value]);

        $currentProfile = collect(Arr::get($state, 'fatty_acid_entries', []))
            ->filter(fn (mixed $row): bool => is_array($row) && filled($row['fatty_acid_id'] ?? null))
            ->mapWithKeys(fn (array $row): array => [
                (int) $row['fatty_acid_id'] => (float) ($row['percentage'] ?? 0),
            ]);

        if ($trustedProfile->isEmpty() && $currentProfile->isEmpty()) {
            return;
        }

        $total = $currentProfile->sum();

        if ($total < 80 || $total > 100) {
            throw ValidationException::withMessages([
                'fatty_acid_entries' => __('ingredients.editor.validation.fatty_acid_total'),
            ]);
        }

        foreach ($trustedProfile->keys()->merge($currentProfile->keys())->unique() as $fattyAcidId) {
            $trustedValue = (float) $trustedProfile->get($fattyAcidId, 0);
            $currentValue = (float) $currentProfile->get($fattyAcidId, 0);
            [$minimum, $maximum] = $this->fattyAcidRange($trustedValue);

            if ($currentValue < $minimum || $currentValue > $maximum) {
                throw ValidationException::withMessages([
                    'fatty_acid_entries' => __('ingredients.editor.validation.fatty_acid_range', [
                        'minimum' => $this->formatRangeValue($minimum),
                        'maximum' => $this->formatRangeValue($maximum),
                    ]),
                ]);
            }
        }
    }

    /**
     * @return array{minimum: float, maximum: float, original: float}|null
     */
    public function trustedKohSapRange(Ingredient $ingredient): ?array
    {
        if (! $this->canRetainUserSoapTrust($ingredient)) {
            return null;
        }

        $original = (float) Arr::get($ingredient->source_data, 'user_authoring.trusted_koh_sap_value');

        return [
            'minimum' => $original * (1 - self::TRUSTED_KOH_SAP_TOLERANCE),
            'maximum' => $original * (1 + self::TRUSTED_KOH_SAP_TOLERANCE),
            'original' => $original,
        ];
    }

    /**
     * @return array{minimum: float, maximum: float, original: float}|null
     */
    public function trustedFattyAcidRange(Ingredient $ingredient, mixed $fattyAcidId): ?array
    {
        if (! $this->canRetainUserSoapTrust($ingredient) || ! is_numeric($fattyAcidId)) {
            return null;
        }

        $original = (float) Arr::get(
            $ingredient->source_data,
            'user_authoring.trusted_fatty_acid_profile.'.(int) $fattyAcidId,
            0,
        );
        [$minimum, $maximum] = $this->fattyAcidRange($original);

        return compact('minimum', 'maximum', 'original');
    }

    /** @return array{float, float} */
    private function fattyAcidRange(float $original): array
    {
        if ($original < 5) {
            return [0.0, 5.0];
        }

        return [max(0, $original * 0.8), min(100, $original * 1.2)];
    }

    private function formatRangeValue(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    /**
     * @param  array<int, array<string, mixed>>  $entries
     */
    private function validateAllergenEntries(array $entries): void
    {
        foreach ($entries as $index => $entry) {
            $concentration = (float) ($entry['concentration_percent'] ?? 0);

            if ($concentration < 0) {
                throw ValidationException::withMessages([
                    "allergen_entries.{$index}.concentration_percent" => __('ingredients.editor.validation.allergen_negative'),
                ]);
            }

            if ($concentration > 100) {
                throw ValidationException::withMessages([
                    "allergen_entries.{$index}.concentration_percent" => __('ingredients.editor.validation.allergen_maximum'),
                ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function validateIfraState(array $state): void
    {
        $peroxideValue = Arr::get($state, 'peroxide_value');

        if ($peroxideValue !== null && (float) $peroxideValue < 0) {
            throw ValidationException::withMessages([
                'ifra.peroxide_value' => __('ingredients.editor.validation.peroxide_negative'),
            ]);
        }

        $limits = collect(Arr::get($state, 'limits', []));

        foreach ($limits as $index => $limit) {
            $maxPercentage = (float) ($limit['max_percentage'] ?? 0);

            if ($maxPercentage < 0) {
                throw ValidationException::withMessages([
                    "ifra.limits.{$index}.max_percentage" => __('ingredients.editor.validation.ifra_maximum_negative'),
                ]);
            }

            if ($maxPercentage > 100) {
                throw ValidationException::withMessages([
                    "ifra.limits.{$index}.max_percentage" => __('ingredients.editor.validation.ifra_maximum'),
                ]);
            }
        }
    }
}
