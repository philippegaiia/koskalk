<?php

namespace App\Services;

use App\Enums\IngredientCategory;
use App\Enums\IngredientSubcategory;
use App\Enums\OwnerType;
use App\Enums\Visibility;
use App\Models\FattyAcid;
use App\Models\Ingredient;
use App\Models\User;
use App\Models\Workspace;
use App\SoapSap;
use App\Support\NumberLocale;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class UserIngredientAuthoringService
{
    private const TRUSTED_KOH_SAP_TOLERANCE = 0.03;

    private const TRUSTED_FATTY_ACID_MIN_TOTAL = 80.0;

    private const TRUSTED_FATTY_ACID_MAX_TOTAL = 100.0;

    /** @var array<int, string>|null */
    private ?array $duplicationFattyAcidNameCache = null;

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
        return $this->createInWorkspace($state, $user, $user->company());
    }

    public function createInWorkspace(array $state, User $user, ?Workspace $workspace): Ingredient
    {
        if (! $workspace instanceof Workspace) {
            $ingredient = DB::transaction(function () use ($state, $user): Ingredient {
                $lockedUser = User::query()->lockForUpdate()->find($user->id);

                if (! $lockedUser instanceof User) {
                    throw new AuthorizationException;
                }

                $lockedUser->forgetAccessibleWorkspaceIds();

                if ($lockedUser->active_workspace_id !== null || $lockedUser->company() instanceof Workspace) {
                    throw new AuthorizationException;
                }

                Gate::forUser($lockedUser)->authorize('createInWorkspace', [Ingredient::class, null]);

                return $this->entitlementService->withinCompanyQuotaLock(
                    $lockedUser,
                    function (Workspace $lockedWorkspace) use ($state, $lockedUser): Ingredient {
                        Gate::forUser($lockedUser)->authorize('createInWorkspace', [Ingredient::class, $lockedWorkspace]);

                        return $this->createInLockedWorkspace($state, $lockedUser, $lockedWorkspace);
                    },
                );
            }, attempts: 5);

            $user->refresh();
            $user->forgetAccessibleWorkspaceIds();

            return $ingredient;
        }

        Gate::forUser($user)->authorize('createInWorkspace', [Ingredient::class, $workspace]);

        return $this->entitlementService->withinWorkspaceQuotaLock(
            $workspace,
            function (Workspace $lockedWorkspace) use ($state, $user): Ingredient {
                Gate::forUser($user)->authorize('createInWorkspace', [Ingredient::class, $lockedWorkspace]);

                return $this->createInLockedWorkspace($state, $user, $lockedWorkspace);
            },
        );
    }

    public function update(
        Ingredient $ingredient,
        array $state,
        User $user,
        ?Workspace $workspace = null,
        ?bool $compositionRemovalConfirmed = null,
    ): Ingredient {
        $ingredientId = (int) $ingredient->getKey();

        if (! Ingredient::query()->whereKey($ingredientId)->exists()) {
            throw new AuthorizationException;
        }

        if (! $workspace instanceof Workspace) {
            $workspaceId = Ingredient::query()->whereKey($ingredientId)->value('workspace_id');

            if ($workspaceId !== null) {
                $workspace = Workspace::withoutGlobalScopes()->find((int) $workspaceId);

                if (! $workspace instanceof Workspace) {
                    throw new AuthorizationException;
                }
            }
        }

        if ($workspace instanceof Workspace) {
            return $this->updateInWorkspace(
                $ingredientId,
                $state,
                $user,
                $workspace,
                $compositionRemovalConfirmed,
            );
        }

        return DB::transaction(function () use ($compositionRemovalConfirmed, $ingredientId, $state, $user): Ingredient {
            $lockedIngredient = Ingredient::query()
                ->lockForUpdate()
                ->find($ingredientId);

            if (! $lockedIngredient instanceof Ingredient) {
                throw new AuthorizationException;
            }

            Gate::forUser($user)->authorize('editWorkspaceIngredient', $lockedIngredient);
            $this->assertCompositionRemovalConfirmed(
                $lockedIngredient,
                $state,
                $compositionRemovalConfirmed,
            );

            return $this->persistUpdate($lockedIngredient, $state, $user);
        });
    }

    private function updateInWorkspace(
        int $ingredientId,
        array $state,
        User $user,
        Workspace $workspace,
        ?bool $compositionRemovalConfirmed = null,
    ): Ingredient {
        return $this->entitlementService->withinWorkspaceQuotaLock(
            $workspace,
            function (Workspace $lockedWorkspace) use ($compositionRemovalConfirmed, $ingredientId, $state, $user): Ingredient {
                $lockedIngredient = Ingredient::query()
                    ->lockForUpdate()
                    ->find($ingredientId);

                if (! $lockedIngredient instanceof Ingredient
                    || (int) $lockedIngredient->workspace_id !== (int) $lockedWorkspace->id) {
                    throw new AuthorizationException;
                }

                Gate::forUser($user)->authorize('editWorkspaceIngredient', $lockedIngredient);
                $this->assertCompositionRemovalConfirmed(
                    $lockedIngredient,
                    $state,
                    $compositionRemovalConfirmed,
                );

                return $this->persistUpdate($lockedIngredient, $state, $user);
            },
        );
    }

    public function duplicate(Ingredient $source, User $user): Ingredient
    {
        return $this->duplicateIntoWorkspace($source, $user, $user->company());
    }

    public function duplicateBlocker(
        Ingredient $source,
        ?User $user = null,
        ?Workspace $workspace = null,
    ): ?string {
        $sourceBlocker = $this->duplicateSourceBlocker($source, $user, $workspace);

        if ($sourceBlocker !== null || ! $user instanceof User) {
            return $sourceBlocker;
        }

        return $this->duplicateDestinationBlocker($user, $workspace);
    }

    public function duplicateDestinationBlocker(User $user, ?Workspace $workspace): ?string
    {
        if (! Gate::forUser($user)->allows('createInWorkspace', [Ingredient::class, $workspace])) {
            return __('ingredients.editor.validation.stale_workspace');
        }

        try {
            if ($workspace instanceof Workspace) {
                $this->entitlementService->assertCanCreatePrivateIngredientInWorkspace($workspace);
            } else {
                $this->entitlementService->assertCanCreatePrivateIngredient($user);
            }
        } catch (ValidationException $exception) {
            $message = collect($exception->errors())
                ->flatten()
                ->first(fn (mixed $message): bool => is_string($message) && filled($message));

            return is_string($message) ? $message : __('ingredients.editor.validation.stale_workspace');
        }

        return null;
    }

    public function duplicateIntoWorkspace(
        Ingredient $source,
        User $user,
        ?Workspace $workspace,
    ): Ingredient {
        $sourceId = $source->getKey();

        if ($workspace instanceof Workspace) {
            $sourceSnapshot = Ingredient::query()->find($sourceId);

            if (! $sourceSnapshot instanceof Ingredient) {
                throw new AuthorizationException;
            }

            $this->authorizeDuplicateSource($user, $sourceSnapshot, $workspace);

            return $this->entitlementService->withinWorkspaceQuotaLock(
                $workspace,
                function (Workspace $lockedWorkspace) use ($sourceId, $user): Ingredient {
                    $lockedUser = $this->lockedDuplicateUser($user, $lockedWorkspace);
                    $lockedSource = Ingredient::query()
                        ->lockForUpdate()
                        ->find($sourceId);

                    if (! $lockedSource instanceof Ingredient) {
                        throw new AuthorizationException;
                    }

                    $this->authorizeDuplicateSource($lockedUser, $lockedSource, $lockedWorkspace);

                    return $this->duplicateInLockedWorkspace($lockedSource, $lockedUser, $lockedWorkspace);
                },
            );
        }

        $copy = DB::transaction(function () use ($sourceId, $user): Ingredient {
            $lockedUser = User::query()->lockForUpdate()->find($user->id);

            if (! $lockedUser instanceof User) {
                throw new AuthorizationException;
            }

            $lockedUser->forgetAccessibleWorkspaceIds();

            if ($lockedUser->active_workspace_id !== null || $lockedUser->company() instanceof Workspace) {
                throw new AuthorizationException;
            }

            $sourceSnapshot = Ingredient::query()->find($sourceId);

            if (! $sourceSnapshot instanceof Ingredient) {
                throw new AuthorizationException;
            }

            $this->authorizeDuplicateSource($lockedUser, $sourceSnapshot, null);

            return $this->entitlementService->withinCompanyQuotaLock(
                $lockedUser,
                function (Workspace $lockedWorkspace) use ($sourceId, $lockedUser): Ingredient {
                    $lockedUser->forgetAccessibleWorkspaceIds();

                    if ($lockedUser->company()?->id !== $lockedWorkspace->id) {
                        throw new AuthorizationException;
                    }

                    $lockedSource = Ingredient::query()
                        ->lockForUpdate()
                        ->find($sourceId);

                    if (! $lockedSource instanceof Ingredient) {
                        throw new AuthorizationException;
                    }

                    $this->authorizeDuplicateSource($lockedUser, $lockedSource, $lockedWorkspace);

                    return $this->duplicateInLockedWorkspace($lockedSource, $lockedUser, $lockedWorkspace);
                },
            );
        }, attempts: 5);

        $user->refresh();
        $user->forgetAccessibleWorkspaceIds();

        return $copy;
    }

    private function lockedDuplicateUser(User $user, Workspace $workspace): User
    {
        $lockedUser = User::query()
            ->lockForUpdate()
            ->find($user->id);

        if (! $lockedUser instanceof User) {
            throw new AuthorizationException;
        }

        $lockedUser->forgetAccessibleWorkspaceIds();

        if ($lockedUser->company()?->id !== $workspace->id) {
            throw new AuthorizationException;
        }

        return $lockedUser;
    }

    private function authorizeDuplicateSource(User $user, Ingredient $source, ?Workspace $workspace): void
    {
        Gate::forUser($user)->authorize('duplicateIntoWorkspace', [$source, $workspace]);

        $blocker = $this->duplicateSourceBlocker($source, $user, $workspace);

        if ($blocker === null) {
            return;
        }

        if ($source->category === IngredientCategory::SoapmakingAlkalis) {
            throw ValidationException::withMessages([
                'category' => $blocker,
            ]);
        }

        if ($source->category === IngredientCategory::Lipids) {
            throw ValidationException::withMessages([
                'ingredient' => $blocker,
            ]);
        }

        throw new AuthorizationException;
    }

    public function duplicateSourceBlocker(
        Ingredient $source,
        ?User $user = null,
        ?Workspace $workspace = null,
    ): ?string {
        if (! $source->is_active) {
            return __('ingredients.status.unavailable');
        }

        $isPlatformIngredient = $this->isPlatformIngredient($source);

        if (! $isPlatformIngredient
            && (! $user instanceof User
                || ! Gate::forUser($user)->allows('duplicateIntoWorkspace', [$source, $workspace]))) {
            return __('ingredients.editor.validation.stale_workspace');
        }

        if ($source->category === IngredientCategory::SoapmakingAlkalis) {
            return __('ingredients.editor.validation.soapmaking_alkalis_platform_only');
        }

        if (
            $isPlatformIngredient
            && $source->category === IngredientCategory::Lipids
            && $source->sapProfile?->koh_sap_value === null
        ) {
            return __('ingredients.editor.validation.duplicate_soap_profile_required');
        }

        return null;
    }

    private function isPlatformIngredient(Ingredient $ingredient): bool
    {
        return $ingredient->owner_type === null
            && $ingredient->owner_id === null
            && $ingredient->workspace_id === null;
    }

    private function createInLockedWorkspace(array $state, User $user, Workspace $workspace): Ingredient
    {
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
    }

    private function persistUpdate(Ingredient $ingredient, array $state, User $user): Ingredient
    {
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

    /**
     * @param  array<string, mixed>  $state
     */
    private function assertCompositionRemovalConfirmed(
        Ingredient $ingredient,
        array $state,
        ?bool $compositionRemovalConfirmed,
    ): void {
        if ($compositionRemovalConfirmed !== false
            || Arr::get($state, 'ingredient_structure') !== 'ingredient'
            || ! $ingredient->components()->lockForUpdate()->exists()) {
            return;
        }

        throw ValidationException::withMessages([
            'confirmCompositionRemoval' => __('ingredients.editor.validation.composition_removal_confirmation'),
        ]);
    }

    private function duplicateInLockedWorkspace(Ingredient $source, User $user, Workspace $workspace): Ingredient
    {
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

        $localizedGuidance = $this->isPlatformIngredient($source)
            ? $this->workspaceIngredientGuidanceService->platformHtml(
                $source->localizedInfoMarkdown($user->locale),
            )
            : $this->workspaceIngredientGuidanceService->effectiveHtml(
                $workspace,
                $source,
                $user->locale,
            );

        if (filled($localizedGuidance)) {
            $this->workspaceIngredientGuidanceService->save(
                $user,
                $workspace,
                $copy,
                $localizedGuidance,
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
        return $this->createInlineComponentInWorkspace($state, $user, $user->company());
    }

    public function createInlineComponentInWorkspace(
        array $state,
        User $user,
        ?Workspace $workspace,
    ): Ingredient {
        return $this->createInWorkspace([
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
        ], $user, $workspace);
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

        if (! $this->isPlatformIngredient($source)) {
            return $sourceData === [] ? null : $sourceData;
        }

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

        if ($total < self::TRUSTED_FATTY_ACID_MIN_TOTAL || $total > self::TRUSTED_FATTY_ACID_MAX_TOTAL) {
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

        return $this->kohSapRange($original);
    }

    /**
     * @return array{
     *     koh_sap: array{minimum: float, maximum: float, original: float},
     *     naoh_sap: array{minimum: float, maximum: float, original: float},
     *     fatty_acid_total: array{minimum: float, maximum: float},
     *     fatty_acids: list<array{id: int, name: string, minimum: float, maximum: float, original: float}>
     * }|null
     */
    public function duplicationChemistryPreview(Ingredient $ingredient): ?array
    {
        if ($ingredient->category !== IngredientCategory::Lipids || ! $ingredient->is_soap_saponification_trusted) {
            return null;
        }

        $usesStoredTrustedProfile = ! $this->isPlatformIngredient($ingredient)
            && $this->canRetainUserSoapTrust($ingredient);
        $kohSapValue = $usesStoredTrustedProfile
            ? Arr::get($ingredient->source_data, 'user_authoring.trusted_koh_sap_value')
            : $ingredient->sapProfile?->koh_sap_value;

        if (! is_numeric($kohSapValue)) {
            return null;
        }

        $kohSapRange = $this->kohSapRange((float) $kohSapValue);
        $trustedFattyAcidProfile = collect($usesStoredTrustedProfile
            ? Arr::get($ingredient->source_data, 'user_authoring.trusted_fatty_acid_profile', [])
            : [])
            ->mapWithKeys(fn (mixed $percentage, mixed $fattyAcidId): array => [
                (int) $fattyAcidId => $percentage,
            ]);
        $fattyAcidEntries = $ingredient->fattyAcidEntries->loadMissing('fattyAcid:id,name');
        $fattyAcidValues = $fattyAcidEntries->mapWithKeys(fn ($entry): array => [
            (int) $entry->fatty_acid_id => $entry->percentage,
        ]);
        $fattyAcidNames = $fattyAcidEntries
            ->filter(fn ($entry): bool => filled($entry->fattyAcid?->name))
            ->mapWithKeys(fn ($entry): array => [
                (int) $entry->fatty_acid_id => (string) $entry->fattyAcid->name,
            ]);
        $storedFattyAcidIds = $trustedFattyAcidProfile->keys()
            ->map(fn (mixed $fattyAcidId): int => (int) $fattyAcidId)
            ->filter(fn (int $fattyAcidId): bool => $fattyAcidId > 0)
            ->values();

        if ($usesStoredTrustedProfile) {
            $missingStoredFattyAcidIds = $storedFattyAcidIds
                ->reject(fn (int $fattyAcidId): bool => $fattyAcidNames->has($fattyAcidId))
                ->values();

            if ($missingStoredFattyAcidIds->isNotEmpty()) {
                $this->duplicationFattyAcidNameCache ??= FattyAcid::query()
                    ->pluck('name', 'id')
                    ->mapWithKeys(fn (mixed $name, mixed $fattyAcidId): array => [
                        (int) $fattyAcidId => (string) $name,
                    ])
                    ->all();
                $fattyAcidNames = $fattyAcidNames->union(
                    collect($this->duplicationFattyAcidNameCache)
                        ->filter(fn (mixed $name, int $fattyAcidId): bool => $missingStoredFattyAcidIds->contains($fattyAcidId)),
                );
            }
        }

        $fattyAcidIds = $fattyAcidEntries
            ->pluck('fatty_acid_id')
            ->map(fn (mixed $fattyAcidId): int => (int) $fattyAcidId)
            ->when($usesStoredTrustedProfile, fn ($ids) => $ids->merge($storedFattyAcidIds))
            ->unique()
            ->values();
        $fattyAcids = $fattyAcidIds
            ->map(function (int $fattyAcidId) use (
                $fattyAcidNames,
                $fattyAcidValues,
                $usesStoredTrustedProfile,
                $trustedFattyAcidProfile,
            ): ?array {
                $original = $usesStoredTrustedProfile
                    ? $trustedFattyAcidProfile->get($fattyAcidId, 0)
                    : $fattyAcidValues->get($fattyAcidId);

                if (! filled($fattyAcidNames->get($fattyAcidId)) || ! is_numeric($original)) {
                    return null;
                }

                $original = (float) $original;
                [$minimum, $maximum] = $this->fattyAcidRange($original);

                return [
                    'id' => $fattyAcidId,
                    'name' => (string) $fattyAcidNames->get($fattyAcidId),
                    'minimum' => $minimum,
                    'maximum' => $maximum,
                    'original' => $original,
                ];
            })
            ->filter()
            ->take(20)
            ->values()
            ->all();

        return [
            'koh_sap' => $kohSapRange,
            'naoh_sap' => [
                'minimum' => SoapSap::deriveNaohFromKoh($kohSapRange['minimum']),
                'maximum' => SoapSap::deriveNaohFromKoh($kohSapRange['maximum']),
                'original' => SoapSap::deriveNaohFromKoh($kohSapRange['original']),
            ],
            'fatty_acid_total' => [
                'minimum' => self::TRUSTED_FATTY_ACID_MIN_TOTAL,
                'maximum' => self::TRUSTED_FATTY_ACID_MAX_TOTAL,
            ],
            'fatty_acids' => $fattyAcids,
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

    /**
     * @return array{minimum: float, maximum: float, original: float}
     */
    private function kohSapRange(float $original): array
    {
        return [
            'minimum' => $original * (1 - self::TRUSTED_KOH_SAP_TOLERANCE),
            'maximum' => $original * (1 + self::TRUSTED_KOH_SAP_TOLERANCE),
            'original' => $original,
        ];
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
