<?php

use App\Enums\IngredientCategory;
use App\Enums\MediaAssetUsageRole;
use App\Enums\OwnerType;
use App\Enums\Visibility;
use App\Enums\WorkspaceMemberRole;
use App\Models\Allergen;
use App\Models\FattyAcid;
use App\Models\IfraProductCategory;
use App\Models\Ingredient;
use App\Models\IngredientFunction;
use App\Models\IngredientTranslation;
use App\Models\MediaAsset;
use App\Models\MediaAssetUsage;
use App\Models\ProductionOutputSetting;
use App\Models\Substance;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceIngredientGuidance;
use App\Models\WorkspaceMember;
use App\Services\EntitlementService;
use App\Services\UserIngredientAuthoringService;
use App\Services\WorkspaceProvisioner;
use Database\Seeders\SupportedLocaleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

use function Pest\Laravel\mock;

uses(RefreshDatabase::class);

it('rechecks platform source activity after the duplication quota lock opens', function (): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user, 'owner')->create();
    $source = Ingredient::factory()->create([
        'category' => IngredientCategory::Other,
        'owner_type' => null,
        'owner_id' => null,
        'workspace_id' => null,
        'visibility' => Visibility::Public,
        'is_active' => true,
    ]);

    $entitlementService = mock(EntitlementService::class);
    $entitlementService
        ->shouldReceive('withinWorkspaceQuotaLock')
        ->once()
        ->withArgs(fn (Workspace $destination, Closure $callback): bool => $destination->is($workspace))
        ->andReturnUsing(function (Workspace $destination, Closure $callback) use ($source): Ingredient {
            $source->forceFill(['is_active' => false])->save();

            return $callback($destination);
        });
    $entitlementService
        ->shouldReceive('assertCanCreatePrivateIngredientInWorkspace')
        ->zeroOrMoreTimes()
        ->andReturnNull();
    app()->instance(EntitlementService::class, $entitlementService);

    expect(fn (): Ingredient => app(UserIngredientAuthoringService::class)->duplicateIntoWorkspace(
        $source,
        $user,
        $workspace,
    ))->toThrow(AuthorizationException::class);

    expect($source->fresh()->is_active)->toBeFalse()
        ->and(Ingredient::query()
            ->where('owner_type', OwnerType::Workspace)
            ->where('owner_id', $workspace->id)
            ->exists())->toBeFalse();
});

it('rechecks platform source ownership after the duplication quota lock opens', function (): void {
    $user = User::factory()->create();
    $otherOwner = User::factory()->create();
    $workspace = Workspace::factory()->for($user, 'owner')->create();
    $otherWorkspace = Workspace::factory()->for($otherOwner, 'owner')->create();
    $source = Ingredient::factory()->create([
        'category' => IngredientCategory::Other,
        'owner_type' => null,
        'owner_id' => null,
        'workspace_id' => null,
        'visibility' => Visibility::Public,
        'is_active' => true,
    ]);

    $entitlementService = mock(EntitlementService::class);
    $entitlementService
        ->shouldReceive('withinWorkspaceQuotaLock')
        ->once()
        ->withArgs(fn (Workspace $destination, Closure $callback): bool => $destination->is($workspace))
        ->andReturnUsing(function (Workspace $destination, Closure $callback) use ($source, $otherWorkspace): Ingredient {
            $source->forceFill([
                'owner_type' => OwnerType::Workspace,
                'owner_id' => $otherWorkspace->id,
                'workspace_id' => $otherWorkspace->id,
                'visibility' => Visibility::Private,
            ])->save();

            return $callback($destination);
        });
    $entitlementService
        ->shouldReceive('assertCanCreatePrivateIngredientInWorkspace')
        ->zeroOrMoreTimes()
        ->andReturnNull();
    app()->instance(EntitlementService::class, $entitlementService);

    expect(fn (): Ingredient => app(UserIngredientAuthoringService::class)->duplicateIntoWorkspace(
        $source,
        $user,
        $workspace,
    ))->toThrow(AuthorizationException::class);

    expect($source->fresh()->owner_type)->toBe(OwnerType::Workspace)
        ->and($source->fresh()->workspace_id)->toBe($otherWorkspace->id)
        ->and(Ingredient::query()
            ->where('owner_type', OwnerType::Workspace)
            ->where('owner_id', $workspace->id)
            ->exists())->toBeFalse();
});

it('rechecks the bound destination after the duplication quota lock opens', function (): void {
    $user = User::factory()->create();
    $otherOwner = User::factory()->create();
    $workspace = Workspace::factory()->for($user, 'owner')->create();
    $otherWorkspace = Workspace::factory()->for($otherOwner, 'owner')->create();
    WorkspaceMember::factory()->for($otherWorkspace)->for($user)->create([
        'role' => WorkspaceMemberRole::Editor,
    ]);
    $user->forceFill(['active_workspace_id' => $workspace->id])->save();
    $user->forgetAccessibleWorkspaceIds();
    $source = Ingredient::factory()->create([
        'category' => IngredientCategory::Other,
        'owner_type' => null,
        'owner_id' => null,
        'workspace_id' => null,
        'visibility' => Visibility::Public,
        'is_active' => true,
    ]);

    $entitlementService = mock(EntitlementService::class);
    $entitlementService
        ->shouldReceive('withinWorkspaceQuotaLock')
        ->once()
        ->withArgs(fn (Workspace $destination, Closure $callback): bool => $destination->is($workspace))
        ->andReturnUsing(function (Workspace $destination, Closure $callback) use ($user, $otherWorkspace): Ingredient {
            $user->forceFill(['active_workspace_id' => $otherWorkspace->id])->save();
            $user->forgetAccessibleWorkspaceIds();

            return $callback($destination);
        });
    $entitlementService
        ->shouldReceive('assertCanCreatePrivateIngredientInWorkspace')
        ->zeroOrMoreTimes()
        ->andReturnNull();
    app()->instance(EntitlementService::class, $entitlementService);

    expect(fn (): Ingredient => app(UserIngredientAuthoringService::class)->duplicateIntoWorkspace(
        $source,
        $user,
        $workspace,
    ))->toThrow(AuthorizationException::class);

    expect(Ingredient::query()
        ->where('owner_type', OwnerType::Workspace)
        ->where('owner_id', $workspace->id)
        ->exists())->toBeFalse();
});

it('rejects an explicit null duplicate after another user instance provisions a workspace', function (): void {
    $user = User::factory()->create();
    $source = Ingredient::factory()->create([
        'category' => IngredientCategory::Other,
        'owner_type' => null,
        'owner_id' => null,
        'workspace_id' => null,
        'visibility' => Visibility::Public,
        'is_active' => true,
    ]);
    $warmedUser = User::query()->findOrFail($user->id);

    expect($warmedUser->company())->toBeNull()
        ->and($warmedUser->accessibleWorkspaceIds())->toBe([]);

    $otherInstance = User::query()->findOrFail($user->id);
    app(WorkspaceProvisioner::class)->ensureOwnerWorkspace($otherInstance);

    $before = [
        'workspaces' => Workspace::withoutGlobalScopes()->count(),
        'memberships' => WorkspaceMember::withoutGlobalScopes()->count(),
        'settings' => ProductionOutputSetting::query()->count(),
        'active_workspace_id' => User::query()->whereKey($user->id)->value('active_workspace_id'),
    ];

    expect(fn (): Ingredient => app(UserIngredientAuthoringService::class)->duplicateIntoWorkspace(
        $source,
        $warmedUser,
        null,
    ))->toThrow(AuthorizationException::class);

    expect(Ingredient::query()
        ->where('owner_type', OwnerType::Workspace)
        ->exists())->toBeFalse()
        ->and(Workspace::withoutGlobalScopes()->count())->toBe($before['workspaces'])
        ->and(WorkspaceMember::withoutGlobalScopes()->count())->toBe($before['memberships'])
        ->and(ProductionOutputSetting::query()->count())->toBe($before['settings'])
        ->and(User::query()->whereKey($user->id)->value('active_workspace_id'))
        ->toBe($before['active_workspace_id']);
});

it('duplicates a platform ingredient into a workspace-owned copy with all data except images', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user, 'owner')->create();
    $function = IngredientFunction::factory()->create(['is_active' => true]);
    $allergen = Allergen::factory()->create();
    $ifraCategory = IfraProductCategory::factory()->create(['is_active' => true]);

    $source = Ingredient::factory()->create([
        'category' => IngredientCategory::AromaticMaterials,
        'display_name' => 'Lavender 40/42',
        'inci_name' => 'LAVANDULA ANGUSTIFOLIA OIL',
        'notes' => 'Supplier-neutral catalogue note',
        'owner_type' => null,
        'owner_id' => null,
        'visibility' => Visibility::Public,
        'is_soap_saponification_trusted' => false,
        'featured_image_path' => 'ingredients/featured-images/lavender.webp',
        'featured_image_original_name' => 'Lavender portrait.webp',
        'icon_image_path' => 'ingredients/icons/lavender.webp',
        'icon_image_original_name' => 'Lavender icon.webp',
        'info_markdown' => 'A popular essential oil.',
        'is_active' => true,
    ]);
    $source->identifiers()->createMany([
        ['scheme' => 'cas', 'value' => '8000-28-0', 'normalized_value' => '8000-28-0', 'is_primary' => true],
        ['scheme' => 'ec', 'value' => '289-995-2', 'normalized_value' => '289-995-2', 'is_primary' => true],
    ]);
    $source->sapProfile()->create(['koh_sap_value' => 0.188]);

    $source->functions()->sync([$function->id]);
    $source->allergenEntries()->create([
        'allergen_id' => $allergen->id,
        'concentration_percent' => 2.5,
        'source_notes' => 'Supplier spec',
    ]);
    $source->ifraCertificates()->create([
        'certificate_name' => 'Lavender IFRA',
        'ifra_amendment' => '50th',
        'peroxide_value' => 12.0,
        'source_notes' => 'Certificate data',
        'is_current' => true,
    ])->limits()->create([
        'ifra_product_category_id' => $ifraCategory->id,
        'max_percentage' => 5.0,
        'restriction_note' => 'Standard limit',
    ]);
    $imageAsset = MediaAsset::factory()->ready()->create([
        'workspace_id' => $workspace->id,
        'uploaded_by_user_id' => $user->id,
    ]);
    $documentAsset = MediaAsset::factory()->pdf()->ready()->create([
        'workspace_id' => $workspace->id,
        'uploaded_by_user_id' => $user->id,
    ]);
    MediaAssetUsage::factory()->create([
        'media_asset_id' => $imageAsset->id,
        'usable_type' => Ingredient::class,
        'usable_id' => $source->id,
        'role' => MediaAssetUsageRole::IngredientMain,
    ]);
    MediaAssetUsage::factory()->create([
        'media_asset_id' => $documentAsset->id,
        'usable_type' => Ingredient::class,
        'usable_id' => $source->id,
        'role' => MediaAssetUsageRole::IngredientDocument,
    ]);

    $service = app(UserIngredientAuthoringService::class);
    $copy = $service->duplicate($source, $user);

    $workspace = $user->fresh()->company();

    expect($copy->owner_type)->toBe(OwnerType::Workspace);
    expect($copy->owner_id)->toBe($workspace->id);
    expect($copy->workspace_id)->toBe($workspace->id);
    expect($copy->visibility)->toBe(Visibility::Private);
    expect($copy->display_name)->toBe('Lavender 40/42');
    expect($copy->inci_name)->toBe('LAVANDULA ANGUSTIFOLIA OIL');
    expect($copy->notes)->toBe('Supplier-neutral catalogue note');
    expect($copy->identifiers->where('scheme', 'cas')->value('value'))->toBe('8000-28-0');
    expect($copy->featured_image_path)->toBeNull();
    expect($copy->featured_image_original_name)->toBeNull();
    expect($copy->icon_image_path)->toBeNull();
    expect($copy->icon_image_original_name)->toBeNull();
    expect($copy->info_markdown)->toBeNull();
    expect(WorkspaceIngredientGuidance::query()
        ->where('workspace_id', $workspace->id)
        ->where('ingredient_id', $copy->id)
        ->firstOrFail()
        ->toArray())
        ->toMatchArray([
            'guidance_html' => '<p>A popular essential oil.</p>',
            'is_active' => true,
            'created_by_user_id' => $user->id,
            'updated_by_user_id' => $user->id,
        ]);
    expect(MediaAssetUsage::query()
        ->where('usable_type', Ingredient::class)
        ->where('usable_id', $copy->id)
        ->exists())->toBeFalse();
    expect($copy->is_active)->toBeTrue();
    expect($copy->catalog_key)->toStartWith('USR-');
    expect($copy->id)->not->toBe($source->id);

    $copy->load(['functions', 'allergenEntries', 'ifraCertificates.limits']);
    expect($copy->functions)->toHaveCount(1);
    expect($copy->functions->first()->id)->toBe($function->id);
    expect($copy->allergenEntries)->toHaveCount(1);
    expect($copy->allergenEntries->first()->allergen_id)->toBe($allergen->id);
    expect((float) $copy->allergenEntries->first()->concentration_percent)->toBe(2.5);
    expect($copy->ifraCertificates)->toHaveCount(1);
    expect($copy->ifraCertificates->first()->limits)->toHaveCount(1);

    // Original is unchanged
    expect($source->fresh()->owner_type)->toBeNull();
    expect(Ingredient::query()->count())->toBe(2);
});

it('duplicates a workspace ingredient with its guidance and original trusted chemistry limits', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $owner->forceFill(['active_workspace_id' => $workspace->id])->save();
    $owner->forgetAccessibleWorkspaceIds();

    $source = Ingredient::factory()->create([
        'display_name' => 'Private olive oil',
        'category' => IngredientCategory::Lipids,
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
        'visibility' => Visibility::Private,
        'is_active' => true,
        'is_soap_saponification_trusted' => true,
        'source_data' => [
            'user_authoring' => [
                'trusted_koh_sap_value' => 0.188,
                'trusted_fatty_acid_profile' => [],
            ],
        ],
    ]);
    $source->sapProfile()->create(['koh_sap_value' => 0.19]);
    WorkspaceIngredientGuidance::factory()->create([
        'workspace_id' => $workspace->id,
        'ingredient_id' => $source->id,
        'guidance_html' => '<p>Private workspace guidance.</p>',
        'is_active' => true,
        'created_by_user_id' => $owner->id,
        'updated_by_user_id' => $owner->id,
    ]);

    $copy = app(UserIngredientAuthoringService::class)->duplicateIntoWorkspace(
        $source,
        $owner,
        $workspace,
    );

    expect($copy->id)->not->toBe($source->id)
        ->and($copy->owner_type)->toBe(OwnerType::Workspace)
        ->and($copy->owner_id)->toBe($workspace->id)
        ->and(data_get($copy->source_data, 'user_authoring.trusted_koh_sap_value'))->toBe(0.188)
        ->and((float) $copy->sapProfile->koh_sap_value)->toBe(0.19);

    expect(WorkspaceIngredientGuidance::query()
        ->where('workspace_id', $workspace->id)
        ->where('ingredient_id', $copy->id)
        ->value('guidance_html'))
        ->toBe('<p>Private workspace guidance.</p>');
});

it('previews inherited chemistry limits and newly added acids for an edited trusted workspace source', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $owner->forceFill(['active_workspace_id' => $workspace->id])->save();
    $owner->forgetAccessibleWorkspaceIds();
    $oleic = FattyAcid::factory()->create(['key' => 'oleic', 'name' => 'Oleic']);
    $palmitic = FattyAcid::factory()->create(['key' => 'palmitic', 'name' => 'Palmitic']);
    $linoleic = FattyAcid::factory()->create(['key' => 'linoleic', 'name' => 'Linoleic']);
    $source = Ingredient::factory()->create([
        'display_name' => 'Edited private olive oil',
        'category' => IngredientCategory::Lipids,
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
        'visibility' => Visibility::Private,
        'is_active' => true,
        'is_soap_saponification_trusted' => true,
        'source_data' => [
            'user_authoring' => [
                'trusted_koh_sap_value' => 0.188,
                'trusted_fatty_acid_profile' => [
                    $oleic->id => 70.0,
                    $palmitic->id => 20.0,
                ],
            ],
        ],
    ]);
    $source->sapProfile()->create(['koh_sap_value' => 0.188]);
    $source->fattyAcidEntries()->createMany([
        ['fatty_acid_id' => $oleic->id, 'percentage' => 70.0],
        ['fatty_acid_id' => $palmitic->id, 'percentage' => 20.0],
        ['fatty_acid_id' => $linoleic->id, 'percentage' => 4.0],
    ]);
    $source->sapProfile()->update(['koh_sap_value' => 0.19]);
    $source->fattyAcidEntries()->where('fatty_acid_id', $oleic->id)->update(['percentage' => 60.0]);
    $source->fattyAcidEntries()->where('fatty_acid_id', $palmitic->id)->update(['percentage' => 21.0]);
    $source->load(['sapProfile', 'fattyAcidEntries.fattyAcid']);

    $service = app(UserIngredientAuthoringService::class);
    $preview = $service->duplicationChemistryPreview($source);

    expect($preview)->not->toBeNull()
        ->and($preview['koh_sap'])->toMatchArray([
            'minimum' => 0.18236,
            'maximum' => 0.19364,
            'original' => 0.188,
        ]);

    $fattyAcids = collect($preview['fatty_acids'])->keyBy('id');

    expect($fattyAcids->get($oleic->id))->toMatchArray([
        'name' => 'Oleic',
        'minimum' => 56.0,
        'maximum' => 84.0,
        'original' => 70.0,
    ])->and($fattyAcids->get($palmitic->id))->toMatchArray([
        'name' => 'Palmitic',
        'minimum' => 16.0,
        'maximum' => 24.0,
        'original' => 20.0,
    ])->and($fattyAcids->get($linoleic->id))->toMatchArray([
        'name' => 'Linoleic',
        'minimum' => 0.0,
        'maximum' => 5.0,
        'original' => 0.0,
    ]);

    expect($service->trustedFattyAcidRange($source, $linoleic->id))->toMatchArray([
        'minimum' => 0.0,
        'maximum' => 5.0,
        'original' => 0.0,
    ]);

    $copy = $service->duplicateIntoWorkspace($source, $owner, $workspace);

    expect((float) data_get($copy->source_data, 'user_authoring.trusted_koh_sap_value'))->toBe(0.188)
        ->and((float) data_get($copy->source_data, 'user_authoring.trusted_fatty_acid_profile.'.$oleic->id))->toBe(70.0);
});

it('batches names for trusted profile acids removed from current relationships', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $owner->forceFill(['active_workspace_id' => $workspace->id])->save();
    $owner->forgetAccessibleWorkspaceIds();
    $oleic = FattyAcid::factory()->create(['key' => 'oleic', 'name' => 'Oleic']);
    $sourceAttributes = [
        'display_name' => 'Trusted oil with removed profile entry',
        'category' => IngredientCategory::Lipids,
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
        'visibility' => Visibility::Private,
        'is_active' => true,
        'is_soap_saponification_trusted' => true,
        'source_data' => [
            'user_authoring' => [
                'trusted_koh_sap_value' => 0.188,
                'trusted_fatty_acid_profile' => [$oleic->id => 70.0],
            ],
        ],
    ];
    $firstSource = Ingredient::factory()->create($sourceAttributes);
    $secondSource = Ingredient::factory()->create($sourceAttributes);
    $firstSource->sapProfile()->create(['koh_sap_value' => 0.19]);
    $secondSource->sapProfile()->create(['koh_sap_value' => 0.19]);
    $firstSource->load('sapProfile');
    $secondSource->load('sapProfile');

    $fattyAcidQueries = [];
    DB::listen(function ($query) use (&$fattyAcidQueries): void {
        if (str_contains($query->sql, 'from "fatty_acids"')) {
            $fattyAcidQueries[] = $query->sql;
        }
    });

    $service = app(UserIngredientAuthoringService::class);
    $firstPreview = $service->duplicationChemistryPreview($firstSource);
    $secondPreview = $service->duplicationChemistryPreview($secondSource);

    expect($firstPreview)->not->toBeNull()
        ->and($firstPreview['fatty_acids'][0])->toMatchArray([
            'id' => $oleic->id,
            'name' => 'Oleic',
            'minimum' => 56.0,
            'maximum' => 84.0,
            'original' => 70.0,
        ])
        ->and($secondPreview['fatty_acids'][0])->toMatchArray([
            'id' => $oleic->id,
            'name' => 'Oleic',
            'minimum' => 56.0,
            'maximum' => 84.0,
            'original' => 70.0,
        ])
        ->and($fattyAcidQueries)->toHaveCount(1);
});

it('previews current chemistry limits for a trusted platform source', function (): void {
    $oleic = FattyAcid::factory()->create(['key' => 'oleic', 'name' => 'Oleic']);
    $source = Ingredient::factory()->create([
        'display_name' => 'Platform olive oil',
        'category' => IngredientCategory::Lipids,
        'owner_type' => null,
        'owner_id' => null,
        'workspace_id' => null,
        'visibility' => Visibility::Public,
        'is_active' => true,
        'is_soap_saponification_trusted' => true,
    ]);
    $source->sapProfile()->create(['koh_sap_value' => 0.188]);
    $source->fattyAcidEntries()->create(['fatty_acid_id' => $oleic->id, 'percentage' => 70.0]);
    $source->load(['sapProfile', 'fattyAcidEntries.fattyAcid']);

    $preview = app(UserIngredientAuthoringService::class)->duplicationChemistryPreview($source);

    expect($preview)->not->toBeNull()
        ->and($preview['koh_sap'])->toMatchArray([
            'minimum' => 0.18236,
            'maximum' => 0.19364,
            'original' => 0.188,
        ])
        ->and($preview['fatty_acids'][0])->toMatchArray([
            'name' => 'Oleic',
            'minimum' => 56.0,
            'maximum' => 84.0,
            'original' => 70.0,
        ]);
});

it('previews inherited chemistry limits for an edited trusted user-owned source', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $owner->forceFill(['active_workspace_id' => $workspace->id])->save();
    $owner->forgetAccessibleWorkspaceIds();
    $oleic = FattyAcid::factory()->create(['key' => 'oleic', 'name' => 'Oleic']);
    $source = Ingredient::factory()->create([
        'display_name' => 'Edited user olive oil',
        'category' => IngredientCategory::Lipids,
        'owner_type' => OwnerType::User,
        'owner_id' => $owner->id,
        'workspace_id' => null,
        'visibility' => Visibility::Private,
        'is_active' => true,
        'is_soap_saponification_trusted' => true,
        'source_data' => [
            'user_authoring' => [
                'trusted_koh_sap_value' => 0.188,
                'trusted_fatty_acid_profile' => [$oleic->id => 70.0],
            ],
        ],
    ]);
    $source->sapProfile()->create(['koh_sap_value' => 0.19]);
    $source->fattyAcidEntries()->create(['fatty_acid_id' => $oleic->id, 'percentage' => 60.0]);
    $source->load(['sapProfile', 'fattyAcidEntries.fattyAcid']);

    $service = app(UserIngredientAuthoringService::class);
    $preview = $service->duplicationChemistryPreview($source);

    expect($preview)->not->toBeNull()
        ->and($preview['koh_sap']['original'])->toBe(0.188)
        ->and($preview['fatty_acids'][0])->toMatchArray([
            'name' => 'Oleic',
            'minimum' => 56.0,
            'maximum' => 84.0,
            'original' => 70.0,
        ]);

    $copy = $service->duplicateIntoWorkspace($source, $owner, $workspace);

    expect($copy->owner_type)->toBe(OwnerType::Workspace)
        ->and((float) data_get($copy->source_data, 'user_authoring.trusted_koh_sap_value'))->toBe(0.188);
});

it('duplicates localized identity and substance data into an independent workspace copy', function (): void {
    $this->seed(SupportedLocaleSeeder::class);
    $user = User::factory()->create(['locale' => 'fr']);
    $substance = Substance::factory()->create(['name' => 'Linalool']);

    $source = Ingredient::factory()->create([
        'display_name' => 'Lavender oil',
        'saponification_name' => 'Lavender',
        'info_markdown' => 'English guidance',
        'category' => IngredientCategory::AromaticMaterials,
        'owner_type' => null,
        'owner_id' => null,
        'is_active' => true,
    ]);
    $source->translations()->create([
        'locale' => 'fr',
        'display_name' => 'Huile de lavande',
        'saponification_name' => 'Lavande',
        'info_markdown' => 'Conseils en français',
    ]);
    $source->translations()->create([
        'locale' => 'de',
        'display_name' => 'Lavendelöl',
        'saponification_name' => 'Lavendel',
        'info_markdown' => 'Deutsche Hinweise',
    ]);
    $source->identifiers()->createMany([
        ['scheme' => 'cas', 'value' => '8000-28-0', 'normalized_value' => '8000-28-0', 'is_primary' => true],
        ['scheme' => 'ec', 'value' => '289-995-2', 'normalized_value' => '289-995-2', 'is_primary' => true],
        ['scheme' => 'unii', 'value' => 'EXAMPLE123', 'normalized_value' => 'example123', 'is_primary' => true],
    ]);
    $source->aliases()->createMany([
        ['locale' => 'fr', 'name' => 'Lavande vraie', 'normalized_name' => 'lavande vraie', 'kind' => 'common'],
        ['locale' => 'und', 'name' => 'Lavandula angustifolia', 'normalized_name' => 'lavandula angustifolia', 'kind' => 'botanical'],
        ['locale' => 'en', 'name' => 'English lavender', 'normalized_name' => 'english lavender', 'kind' => 'common'],
    ]);
    $source->substanceEntries()->create([
        'substance_id' => $substance->id,
        'concentration_percent' => 0.42,
        'concentration_source' => 'supplier_coa',
        'source_notes' => 'Supplier declaration',
        'source_data' => ['document' => 'coa.pdf'],
    ]);

    $copy = app(UserIngredientAuthoringService::class)->duplicate($source, $user);

    expect($copy->display_name)->toBe('Huile de lavande')
        ->and($copy->saponification_name)->toBe('Lavande')
        ->and($copy->info_markdown)->toBeNull()
        ->and($copy->translations)->toBeEmpty()
        ->and($copy->identifiers)->toHaveCount(3)
        ->and($copy->identifiers->where('scheme', 'cas')->where('is_primary', true)->value('value'))->toBe('8000-28-0')
        ->and($copy->aliases->pluck('name')->all())->toBe([
            'Lavande vraie',
            'Lavandula angustifolia',
        ])
        ->and($copy->substanceEntries)->toHaveCount(1)
        ->and($copy->substanceEntries->first()->source_notes)->toBe('Supplier declaration')
        ->and($copy->substanceEntries->first()->source_data)->toBe(['document' => 'coa.pdf']);

    expect(WorkspaceIngredientGuidance::query()
        ->where('workspace_id', $user->fresh()->company()->id)
        ->where('ingredient_id', $copy->id)
        ->firstOrFail()
        ->toArray())
        ->toMatchArray([
            'guidance_html' => '<p>Conseils en français</p>',
            'is_active' => true,
            'created_by_user_id' => $user->id,
            'updated_by_user_id' => $user->id,
        ]);

    $copy->identifiers->first()->update(['value' => 'changed']);
    $copy->substanceEntries->first()->update(['concentration_percent' => 0.8]);

    expect($source->fresh()->identifiers->first()->value)->toBe('8000-28-0')
        ->and((float) $source->fresh()->substanceEntries->first()->concentration_percent)->toBe(0.42)
        ->and(IngredientTranslation::query()->where('ingredient_id', $copy->id)->exists())->toBeFalse();
});

it('duplicates a carrier oil with SAP profile and fatty acids', function () {
    $user = User::factory()->create();
    $oleic = FattyAcid::factory()->create(['key' => 'oleic', 'name' => 'Oleic']);
    $palmitic = FattyAcid::factory()->create(['key' => 'palmitic', 'name' => 'Palmitic']);

    $source = Ingredient::factory()->create([
        'category' => IngredientCategory::Lipids,
        'display_name' => 'Olive Oil',
        'saponification_name' => 'Olive',
        'owner_type' => null,
        'owner_id' => null,
        'is_soap_saponification_trusted' => true,
        'is_active' => true,
    ]);
    $source->identifiers()->createMany([
        ['scheme' => 'cas', 'value' => '8001-25-00', 'normalized_value' => '8001-25-00', 'is_primary' => true],
        ['scheme' => 'ec', 'value' => '232-277-00', 'normalized_value' => '232-277-00', 'is_primary' => true],
    ]);

    $source->sapProfile()->create([
        'koh_sap_value' => 0.188,
        'iodine_value' => 86.4,
        'ins_value' => 102.8,
        'source_notes' => 'Trusted average',
    ]);
    $source->fattyAcidEntries()->createMany([
        ['fatty_acid_id' => $oleic->id, 'percentage' => 71.0, 'source_notes' => 'Main'],
        ['fatty_acid_id' => $palmitic->id, 'percentage' => 13.0, 'source_notes' => null],
    ]);

    $service = app(UserIngredientAuthoringService::class);
    $copy = $service->duplicate($source, $user);

    expect($copy->is_soap_saponification_trusted)->toBeTrue();
    expect($copy->saponification_name)->toBe('Olive');
    expect($copy->identifiers->where('scheme', 'cas')->value('value'))->toBe('8001-25-00');
    expect($copy->identifiers->where('scheme', 'ec')->value('value'))->toBe('232-277-00');
    expect($copy->sapProfile)->not->toBeNull();
    expect((float) $copy->sapProfile->koh_sap_value)->toBe(0.188);
    expect((float) $copy->sapProfile->iodine_value)->toBe(86.4);
    expect($copy->fattyAcidEntries)->toHaveCount(2);

    // SAP profile is independent
    $copy->sapProfile->update(['koh_sap_value' => 0.195]);
    expect((float) $source->fresh()->sapProfile->koh_sap_value)->toBe(0.188);

    $state = $service->formData($copy);
    $state['sap_profile']['koh_sap_value'] = '0.19';
    $service->update($copy, $state, $user);

    expect((float) $copy->fresh()->sapProfile->koh_sap_value)->toBe(0.19)
        ->and((float) $source->fresh()->sapProfile->koh_sap_value)->toBe(0.188);
});

it('prevents duplicated carrier oil KOH SAP edits outside the trusted range', function () {
    $user = User::factory()->create();

    $source = Ingredient::factory()->create([
        'category' => IngredientCategory::Lipids,
        'display_name' => 'Olive Oil',
        'owner_type' => null,
        'owner_id' => null,
        'is_soap_saponification_trusted' => true,
        'is_active' => true,
    ]);

    $source->sapProfile()->create([
        'koh_sap_value' => 0.188,
        'iodine_value' => 86.4,
        'ins_value' => 102.8,
    ]);

    $service = app(UserIngredientAuthoringService::class);
    $copy = $service->duplicate($source, $user);

    expect(fn () => $service->update($copy, [
        'name' => 'Olive Oil',
        'category' => IngredientCategory::Lipids->value,
        'inci_name' => $copy->inci_name,
        'sap_profile' => [
            'koh_sap_value' => 0.195,
            'iodine_value' => 86.4,
            'ins_value' => 102.8,
        ],
    ], $user))->toThrow(ValidationException::class);
});

it('accepts duplicated carrier oil KOH SAP edits at both trusted boundaries', function (): void {
    $user = User::factory()->create();

    $source = Ingredient::factory()->create([
        'category' => IngredientCategory::Lipids,
        'display_name' => 'Boundary oil',
        'owner_type' => null,
        'owner_id' => null,
        'is_soap_saponification_trusted' => true,
        'is_active' => true,
    ]);
    $source->sapProfile()->create(['koh_sap_value' => 0.188]);

    $service = app(UserIngredientAuthoringService::class);
    $copy = $service->duplicate($source, $user);

    foreach (['0.18236', '0.19364'] as $kohSapValue) {
        $state = $service->formData($copy);
        $state['sap_profile']['koh_sap_value'] = $kohSapValue;

        expect($service->update($copy, $state, $user))->toBeInstanceOf(Ingredient::class)
            ->and((float) $copy->fresh('sapProfile')->sapProfile->koh_sap_value)->toBe((float) $kohSapValue);
    }

    expect((float) $source->fresh('sapProfile')->sapProfile->koh_sap_value)->toBe(0.188);
});

it('refuses to duplicate a carrier oil without a KOH SAP value', function () {
    $user = User::factory()->create();
    $source = Ingredient::factory()->create([
        'category' => IngredientCategory::Lipids,
        'display_name' => 'Incomplete platform oil',
        'owner_type' => null,
        'is_soap_saponification_trusted' => true,
    ]);

    expect(fn () => app(UserIngredientAuthoringService::class)->duplicate($source, $user))
        ->toThrow(ValidationException::class, 'cannot be duplicated until its KOH SAP value is available');
});

it('validates duplicated carrier oil fatty acids against trusted ranges and total', function () {
    $user = User::factory()->create();
    $oleic = FattyAcid::factory()->create(['key' => 'oleic', 'name' => 'Oleic']);
    $trace = FattyAcid::factory()->create(['key' => 'trace', 'name' => 'Trace']);
    $palmitic = FattyAcid::factory()->create(['key' => 'palmitic', 'name' => 'Palmitic']);
    $source = Ingredient::factory()->create([
        'category' => IngredientCategory::Lipids,
        'display_name' => 'Trusted oil',
        'owner_type' => null,
        'is_soap_saponification_trusted' => true,
    ]);
    $source->sapProfile()->create(['koh_sap_value' => 0.188]);
    $source->fattyAcidEntries()->createMany([
        ['fatty_acid_id' => $oleic->id, 'percentage' => 50],
        ['fatty_acid_id' => $trace->id, 'percentage' => 2],
        ['fatty_acid_id' => $palmitic->id, 'percentage' => 40],
    ]);

    $service = app(UserIngredientAuthoringService::class);
    $copy = $service->duplicate($source, $user);
    $state = $service->formData($copy);
    foreach ($state['fatty_acid_entries'] as &$row) {
        if ($row['fatty_acid_id'] === $oleic->id) {
            $row['percentage'] = 61;
        }

        if ($row['fatty_acid_id'] === $palmitic->id) {
            $row['percentage'] = 35;
        }
    }
    unset($row);

    expect(fn () => $service->update($copy, $state, $user))
        ->toThrow(ValidationException::class, 'outside its allowed range');

    $state = $service->formData($copy);
    foreach ($state['fatty_acid_entries'] as &$row) {
        $row['percentage'] = 20;
    }
    unset($row);

    expect(fn () => $service->update($copy, $state, $user))
        ->toThrow(ValidationException::class, 'must total between 80% and 100%');
});

it('duplicates a composite ingredient with components', function () {
    $user = User::factory()->create();

    $component = Ingredient::factory()->create([
        'display_name' => 'Base oil component',
        'category' => IngredientCategory::Lipids,
        'is_active' => true,
    ]);

    $source = Ingredient::factory()->create([
        'display_name' => 'Soap base blend',
        'category' => IngredientCategory::Lipids,
        'owner_type' => null,
        'owner_id' => null,
        'is_active' => true,
    ]);
    $source->sapProfile()->create(['koh_sap_value' => 0.188]);

    $source->components()->create([
        'component_ingredient_id' => $component->id,
        'percentage_in_parent' => 100.0,
        'sort_order' => 1,
        'source_notes' => 'Full blend',
    ]);

    $service = app(UserIngredientAuthoringService::class);
    $copy = $service->duplicate($source, $user);

    expect($copy->components)->toHaveCount(1);
    expect($copy->components->first()->component_ingredient_id)->toBe($component->id);
    expect((float) $copy->components->first()->percentage_in_parent)->toBe(100.0);
});

it('refuses to duplicate a user-owned ingredient', function () {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();

    $source = Ingredient::factory()->create([
        'owner_type' => OwnerType::User,
        'owner_id' => $owner->id,
        'visibility' => Visibility::Private,
    ]);

    $service = app(UserIngredientAuthoringService::class);

    expect(fn () => $service->duplicate($source, $otherUser))
        ->toThrow(AuthorizationException::class);
});

it('duplicates parent-level source notes for composition and allergens', function () {
    $user = User::factory()->create();
    $allergen = Allergen::factory()->create();

    $source = Ingredient::factory()->create([
        'category' => IngredientCategory::AromaticMaterials,
        'display_name' => 'Aromatic blend',
        'owner_type' => null,
        'owner_id' => null,
        'is_active' => true,
        'composition_source_notes' => 'Composition COA',
        'allergen_source_notes' => 'Allergen SDS',
    ]);
    $source->allergenEntries()->create([
        'allergen_id' => $allergen->id,
        'concentration_percent' => 1.0,
    ]);

    $copy = app(UserIngredientAuthoringService::class)->duplicate($source, $user);

    expect($copy->composition_source_notes)->toBe('Composition COA')
        ->and($copy->allergen_source_notes)->toBe('Allergen SDS');
});

it('refuses to duplicate a platform soapmaking alkali into a workspace', function () {
    $user = User::factory()->create();

    $source = Ingredient::factory()->create([
        'catalog_key' => 'CH1',
        'category' => IngredientCategory::SoapmakingAlkalis,
        'display_name' => 'Sodium hydroxide',
        'owner_type' => null,
        'owner_id' => null,
        'visibility' => Visibility::Public,
        'is_active' => true,
    ]);

    expect(fn () => app(UserIngredientAuthoringService::class)->duplicate($source, $user))
        ->toThrow(
            ValidationException::class,
            __('ingredients.editor.validation.soapmaking_alkalis_platform_only'),
        );

    expect(Ingredient::query()
        ->where('category', IngredientCategory::SoapmakingAlkalis->value)
        ->whereNotNull('owner_type')
        ->exists())->toBeFalse();
});
