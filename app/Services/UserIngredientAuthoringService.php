<?php

namespace App\Services;

use App\IngredientCategory;
use App\Models\IfraCertificateLimit;
use App\Models\Ingredient;
use App\Models\User;
use App\OwnerType;
use App\SoapSap;
use App\Support\NumberLocale;
use App\Visibility;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UserIngredientAuthoringService
{
    private const TRUSTED_KOH_SAP_TOLERANCE = 0.03;

    public function __construct(
        protected IngredientDataEntryService $ingredientDataEntryService,
        protected EntitlementService $entitlementService,
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
            'inci_name' => null,
            'supplier_name' => null,
            'supplier_reference' => null,
            'cas_number' => null,
            'ec_number' => null,
            'is_organic' => false,
            'featured_image_path' => null,
            'icon_image_path' => null,
            'info_markdown' => null,
            'composition_source_notes' => null,
            'allergen_source_notes' => null,
            'function_ids' => [],
            'allergen_entries' => [],
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
                'ifra_amendment' => null,
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
        $currentIfra = $ingredient->ifraCertificates()
            ->with('limits')
            ->where('is_current', true)
            ->latest('id')
            ->first();

        return [
            'ingredient_structure' => $ingredient->components()->exists() ? 'blend' : 'ingredient',
            'name' => data_get($entryData, 'current_version.display_name'),
            'category' => $ingredient->category?->value,
            'inci_name' => data_get($entryData, 'current_version.inci_name'),
            'supplier_name' => data_get($entryData, 'current_version.supplier_name'),
            'supplier_reference' => data_get($entryData, 'current_version.supplier_reference'),
            'cas_number' => data_get($entryData, 'current_version.cas_number'),
            'ec_number' => data_get($entryData, 'current_version.ec_number'),
            'is_organic' => (bool) data_get($entryData, 'current_version.is_organic', false),
            'featured_image_path' => $ingredient->featured_image_path,
            'icon_image_path' => $ingredient->icon_image_path,
            'info_markdown' => $ingredient->info_markdown,
            'composition_source_notes' => $ingredient->composition_source_notes,
            'allergen_source_notes' => $ingredient->allergen_source_notes,
            'function_ids' => $entryData['function_ids'] ?? [],
            'allergen_entries' => $entryData['allergen_entries'] ?? [],
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
            'ifra' => [
                'reference_label' => $currentIfra?->certificate_name,
                'ifra_amendment' => $currentIfra?->ifra_amendment,
                'peroxide_value' => $currentIfra?->peroxide_value === null ? null : (float) $currentIfra->peroxide_value,
                'source_notes' => $currentIfra?->source_notes,
                'limits' => $currentIfra?->limits
                    ->sortBy('ifra_product_category_id')
                    ->map(fn (IfraCertificateLimit $limit): array => [
                        'ifra_product_category_id' => $limit->ifra_product_category_id,
                        'max_percentage' => $limit->max_percentage === null ? null : (float) $limit->max_percentage,
                        'restriction_note' => $limit->restriction_note,
                    ])
                    ->values()
                    ->all() ?? [],
            ],
        ];
    }

    public function create(array $state, User $user): Ingredient
    {
        $this->entitlementService->assertCanCreatePrivateIngredient($user);

        return DB::transaction(function () use ($state, $user): Ingredient {
            $ingredient = new Ingredient([
                'public_id' => Arr::get($state, 'public_id'),
                'source_file' => 'user',
                'source_key' => $this->ingredientDataEntryService->generateSourceKey('USR', 'user'),
                'source_code_prefix' => 'USR',
                'owner_type' => OwnerType::User,
                'owner_id' => $user->id,
                'workspace_id' => null,
                'visibility' => Visibility::Private,
                'requires_admin_review' => true,
                'is_active' => true,
                'is_potentially_saponifiable' => false,
            ]);

            $this->fillIngredient($ingredient, $state);
            $ingredient->save();

            return $this->syncState($ingredient, $state, $user);
        });
    }

    public function update(Ingredient $ingredient, array $state, User $user): Ingredient
    {
        if (! $ingredient->isOwnedBy($user)) {
            throw ValidationException::withMessages([
                'ingredient' => 'Only your own ingredients can be edited from the public app.',
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
        $this->entitlementService->assertCanCreatePrivateIngredient($user);

        if ($source->owner_type !== null) {
            throw ValidationException::withMessages([
                'ingredient' => 'Only platform ingredients can be duplicated.',
            ]);
        }

        if (
            $source->category === IngredientCategory::CarrierOil
            && $source->sapProfile?->koh_sap_value === null
        ) {
            throw ValidationException::withMessages([
                'ingredient' => 'This platform carrier oil cannot be duplicated until its KOH SAP value is available. Contact support@soapkraft.com.',
            ]);
        }

        $copy = $source->replicate([
            'public_id',
            'featured_image_path',
            'icon_image_path',
        ]);

        $copy->source_file = 'user';
        $copy->source_key = $this->ingredientDataEntryService->generateSourceKey('USR', 'user');
        $copy->source_code_prefix = 'USR';
        $copy->owner_type = OwnerType::User;
        $copy->owner_id = $user->id;
        $copy->workspace_id = null;
        $copy->visibility = Visibility::Private;
        $copy->requires_admin_review = false;
        $copy->cas_number = $this->normalizeCasNumber($copy->cas_number);
        $copy->ec_number = $this->normalizeEcNumber($copy->ec_number);
        $copy->source_data = $this->duplicateSourceData($source);
        $copy->featured_image_path = null;
        $copy->icon_image_path = null;
        $copy->save();

        $this->deepCopyRelations($source, $copy);

        return $copy->fresh([
            'sapProfile',
            'fattyAcidEntries.fattyAcid',
            'components.componentIngredient',
            'allergenEntries.allergen',
            'functions',
            'ifraCertificates.limits.ifraProductCategory',
        ]);
    }

    private function deepCopyRelations(Ingredient $source, Ingredient $copy): void
    {
        // SAP profile
        if ($source->sapProfile) {
            $source->sapProfile->replicate()->fill([
                'ingredient_id' => $copy->id,
            ])->save();
        }

        // Fatty acid entries
        $source->fattyAcidEntries->each(function ($entry) use ($copy): void {
            $entry->replicate()->fill(['ingredient_id' => $copy->id])->save();
        });

        // Components
        $source->components->each(function ($component) use ($copy): void {
            $component->replicate()->fill(['ingredient_id' => $copy->id])->save();
        });

        // Allergen entries
        $source->allergenEntries->each(function ($entry) use ($copy): void {
            $entry->replicate()->fill(['ingredient_id' => $copy->id])->save();
        });

        // Functions
        $copy->functions()->sync($source->functions->pluck('id'));

        // IFRA certificates + limits
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
            'supplier_name' => $state['supplier_name'] ?? null,
            'supplier_reference' => $state['supplier_reference'] ?? null,
            'cas_number' => $state['cas_number'] ?? null,
            'ec_number' => $state['ec_number'] ?? null,
            'is_organic' => (bool) ($state['is_organic'] ?? false),
            'featured_image_path' => null,
            'icon_image_path' => null,
            'info_markdown' => null,
            'function_ids' => [],
            'allergen_entries' => [],
            'components' => [],
            'ifra' => [
                'reference_label' => null,
                'ifra_amendment' => null,
                'peroxide_value' => null,
                'source_notes' => null,
                'limits' => [],
            ],
        ], $user);
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

        $ingredient->featured_image_path = Arr::get($state, 'featured_image_path');
        $ingredient->icon_image_path = Arr::get($state, 'icon_image_path');
        $ingredient->info_markdown = Arr::get($state, 'info_markdown');
        $ingredient->composition_source_notes = Arr::get($state, 'ingredient_structure') === 'blend'
            ? Arr::get($state, 'composition_source_notes')
            : null;
        $ingredient->allergen_source_notes = $ingredient->requiresAromaticCompliance()
            ? Arr::get($state, 'allergen_source_notes')
            : null;
        $ingredient->is_potentially_saponifiable = $ingredient->category === IngredientCategory::CarrierOil
            && $this->canRetainUserSoapTrust($ingredient);
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
                'supplier_name' => Arr::get($state, 'supplier_name'),
                'supplier_reference' => Arr::get($state, 'supplier_reference'),
                'cas_number' => $this->normalizeCasNumber(Arr::get($state, 'cas_number')),
                'ec_number' => $this->normalizeEcNumber(Arr::get($state, 'ec_number')),
                'is_organic' => (bool) Arr::get($state, 'is_organic', false),
                'is_active' => true,
                'is_manufactured' => false,
            ],
            'function_ids' => Arr::get($state, 'function_ids', []),
            'sap_profile' => Arr::get($state, 'sap_profile', []),
            'fatty_acid_entries' => Arr::get($state, 'fatty_acid_entries', []),
            'allergen_entries' => Arr::get($state, 'allergen_entries', []),
            'components' => array_key_exists('ingredient_structure', $state)
                && Arr::get($state, 'ingredient_structure') !== 'blend'
                    ? []
                    : Arr::get($state, 'components', []),
        ]);

        if ($ingredient->requiresAromaticCompliance()) {
            $this->syncIfraState($ingredient, Arr::get($state, 'ifra', []));
        } else {
            $ingredient->allergenEntries()->delete();
            $ingredient->ifraCertificates()->delete();
        }

        return $ingredient->fresh([
            'sapProfile',
            'fattyAcidEntries.fattyAcid',
            'components.componentIngredient',
            'allergenEntries.allergen',
            'functions',
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
                'components' => 'Add at least one component to save a blend.',
            ]);
        }

        $accessibleCount = Ingredient::query()
            ->accessibleTo($user)
            ->where('is_active', true)
            ->whereKey($componentIds->unique()->all())
            ->count();

        if ($accessibleCount !== $componentIds->unique()->count()) {
            throw ValidationException::withMessages([
                'components' => 'One or more selected components are not available to you.',
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
     * @param  array<string, mixed>  $state
     */
    private function syncIfraState(Ingredient $ingredient, array $state): void
    {
        $limitsState = collect(Arr::get($state, 'limits', []))
            ->filter(fn (mixed $row): bool => is_array($row))
            ->filter(fn (array $row): bool => filled($row['ifra_product_category_id'] ?? null))
            ->map(fn (array $row): array => [
                'ifra_product_category_id' => (int) $row['ifra_product_category_id'],
                'max_percentage' => filled($row['max_percentage'] ?? null) ? (float) $row['max_percentage'] : null,
                'restriction_note' => filled($row['restriction_note'] ?? null) ? trim((string) $row['restriction_note']) : null,
            ])
            ->unique('ifra_product_category_id')
            ->values();

        $hasMeaningfulIfra = filled($state['reference_label'] ?? null)
            || filled($state['ifra_amendment'] ?? null)
            || filled($state['peroxide_value'] ?? null)
            || filled($state['source_notes'] ?? null)
            || $limitsState->isNotEmpty();

        $ingredient->ifraCertificates()->delete();

        if (! $hasMeaningfulIfra) {
            return;
        }

        $certificate = $ingredient->ifraCertificates()->make([
            'certificate_name' => ($state['reference_label'] ?? null) ?: sprintf('%s current IFRA guidance', $ingredient->display_name),
            'ifra_amendment' => $state['ifra_amendment'] ?? null,
            'peroxide_value' => filled($state['peroxide_value'] ?? null) ? (float) $state['peroxide_value'] : null,
            'source_notes' => $state['source_notes'] ?? null,
            'document_name' => null,
            'document_path' => null,
            'issuer' => null,
            'reference_code' => null,
            'published_at' => null,
            'valid_from' => null,
            'is_current' => true,
        ]);
        $certificate->save();

        $limitsState->each(function (array $limitState) use ($certificate): void {
            $certificate->limits()->create($limitState);
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    private function duplicateSourceData(Ingredient $source): ?array
    {
        $sourceData = is_array($source->source_data) ? $source->source_data : [];
        $trustedKohSapValue = $source->sapProfile?->koh_sap_value;

        if (
            $source->category !== IngredientCategory::CarrierOil
            || ! $source->is_potentially_saponifiable
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
        return $ingredient->owner_type === OwnerType::User
            && is_numeric(Arr::get($ingredient->source_data, 'user_authoring.trusted_koh_sap_value'));
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function validateTrustedKohSapValue(Ingredient $ingredient, array $state): void
    {
        if (! $this->canRetainUserSoapTrust($ingredient) || $ingredient->category !== IngredientCategory::CarrierOil) {
            return;
        }

        $trustedKohSapValue = (float) Arr::get($ingredient->source_data, 'user_authoring.trusted_koh_sap_value');
        $kohSapValue = Arr::get($state, 'sap_profile.koh_sap_value');

        if ($kohSapValue === null || $kohSapValue === '' || ! is_numeric($kohSapValue)) {
            throw ValidationException::withMessages([
                'sap_profile.koh_sap_value' => 'KOH SAP value is required for duplicated carrier oils trusted for soap calculation.',
            ]);
        }

        $normalizedKohSapValue = SoapSap::normalizeKohSapInput((float) $kohSapValue);
        $minimumValue = $trustedKohSapValue * (1 - self::TRUSTED_KOH_SAP_TOLERANCE);
        $maximumValue = $trustedKohSapValue * (1 + self::TRUSTED_KOH_SAP_TOLERANCE);

        if ($normalizedKohSapValue < $minimumValue || $normalizedKohSapValue > $maximumValue) {
            throw ValidationException::withMessages([
                'sap_profile.koh_sap_value' => 'KOH SAP value must be within ±3% of the duplicated platform value.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function validateTrustedFattyAcidProfile(Ingredient $ingredient, array $state): void
    {
        if (! $this->canRetainUserSoapTrust($ingredient) || $ingredient->category !== IngredientCategory::CarrierOil) {
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
                'fatty_acid_entries' => 'Fatty acid percentages must total between 80% and 100%.',
            ]);
        }

        foreach ($trustedProfile->keys()->merge($currentProfile->keys())->unique() as $fattyAcidId) {
            $trustedValue = (float) $trustedProfile->get($fattyAcidId, 0);
            $currentValue = (float) $currentProfile->get($fattyAcidId, 0);
            [$minimum, $maximum] = $this->fattyAcidRange($trustedValue);

            if ($currentValue < $minimum || $currentValue > $maximum) {
                throw ValidationException::withMessages([
                    'fatty_acid_entries' => sprintf(
                        'Fatty acid values must stay between %s%% and %s%% of their allowed range.',
                        $this->formatRangeValue($minimum),
                        $this->formatRangeValue($maximum),
                    ),
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

    private function normalizeCasNumber(mixed $value): ?string
    {
        $value = $this->normalizeIdentifier($value);

        if ($value === null) {
            return null;
        }

        return preg_replace('/^([0-9]{2,7}-[0-9]{2})-0([0-9])$/', '$1-$2', $value) ?? $value;
    }

    private function normalizeEcNumber(mixed $value): ?string
    {
        $value = $this->normalizeIdentifier($value);

        if ($value === null) {
            return null;
        }

        return preg_replace('/^([0-9]{3}-[0-9]{3})-0([0-9])$/', '$1-$2', $value) ?? $value;
    }

    private function normalizeIdentifier(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
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
                    "allergen_entries.{$index}.concentration_percent" => 'Allergen concentration must not be negative.',
                ]);
            }

            if ($concentration > 100) {
                throw ValidationException::withMessages([
                    "allergen_entries.{$index}.concentration_percent" => 'Allergen concentration must not exceed 100%.',
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
                'ifra.peroxide_value' => 'Peroxide value must not be negative.',
            ]);
        }

        $limits = collect(Arr::get($state, 'limits', []));

        foreach ($limits as $index => $limit) {
            $maxPercentage = (float) ($limit['max_percentage'] ?? 0);

            if ($maxPercentage < 0) {
                throw ValidationException::withMessages([
                    "ifra.limits.{$index}.max_percentage" => 'Max concentration must not be negative.',
                ]);
            }

            if ($maxPercentage > 100) {
                throw ValidationException::withMessages([
                    "ifra.limits.{$index}.max_percentage" => 'Max concentration must not exceed 100%.',
                ]);
            }
        }
    }
}
