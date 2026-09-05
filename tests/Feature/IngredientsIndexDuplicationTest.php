<?php

use App\Enums\IngredientCategory;
use App\Enums\MediaAssetUsageRole;
use App\Enums\OwnerType;
use App\Enums\WorkspaceMemberRole;
use App\Models\Ingredient;
use App\Models\MediaAsset;
use App\Models\MediaAssetUsage;
use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceIngredientCode;
use App\Models\WorkspaceIngredientGuidance;
use App\Models\WorkspaceMember;
use App\Services\EntitlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\mock;

uses(RefreshDatabase::class);

it('shows a duplicate action in the ingredients page header', function () {
    $user = User::factory()->create();

    actingAs($user);

    $this->get(route('ingredients.index'))
        ->assertSuccessful()
        ->assertSee('Duplicate a Soapkraft ingredient');
});

it('renders a preview-only accessible duplication dialog with its data disclosures', function (): void {
    $user = User::factory()->create();

    actingAs($user);

    $this->get(route('ingredients.index'))
        ->assertSuccessful()
        ->assertSee('Create a private copy in your private ingredient library. You can edit its details. The platform ingredient stays unchanged.')
        ->assertSee('Create private copy')
        ->assertSee('Legacy ingredient images are reset in the private copy.')
        ->assertSee('Documents and media usages are not copied.')
        ->assertSee('Approved guidance becomes a workspace override.')
        ->assertSee('role="dialog"', false)
        ->assertSee('aria-modal="true"', false)
        ->assertSee('for="ingredient-duplication-search"', false)
        ->assertDontSee('info_markdown');
});

it('searches platform ingredients for duplication', function () {
    $user = User::factory()->create();

    Ingredient::factory()->create([
        'display_name' => 'Lavender 40/42',
        'category' => IngredientCategory::AromaticMaterials,
        'owner_type' => null,
        'owner_id' => null,
        'is_active' => true,
    ]);

    Ingredient::factory()->create([
        'display_name' => 'Peppermint Oil',
        'category' => IngredientCategory::AromaticMaterials,
        'owner_type' => null,
        'owner_id' => null,
        'is_active' => true,
    ]);

    actingAs($user);

    $response = $this->getJson(route('ingredients.search-platform').'?q=lavender');

    $response->assertSuccessful();
    $results = $response->json();
    expect($results)->toHaveCount(1);
    expect($results[0]['name'])->toBe('Lavender 40/42');
});

it('searches platform ingredients by curated aliases and typed identifiers without leaking workspace rows', function (): void {
    $user = User::factory()->create();

    $platform = Ingredient::factory()->create([
        'display_name' => 'Black cumin oil',
        'owner_type' => null,
        'owner_id' => null,
        'is_active' => true,
    ]);
    $platform->aliases()->create([
        'locale' => 'und',
        'name' => 'Nigella sativa oil',
        'normalized_name' => 'nigella sativa oil',
        'kind' => 'botanical',
    ]);
    $platform->identifiers()->create([
        'scheme' => 'cas',
        'value' => '8002-75-3',
        'normalized_value' => '8002-75-3',
        'is_primary' => true,
    ]);

    $workspace = Workspace::factory()->for($user, 'owner')->create();
    $private = Ingredient::factory()->create([
        'display_name' => 'Private alias ingredient',
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
        'is_active' => true,
    ]);
    $private->aliases()->create([
        'locale' => 'und',
        'name' => 'Nigella sativa oil',
        'normalized_name' => 'nigella sativa oil',
        'kind' => 'common',
    ]);

    $this->actingAs($user);

    $aliasResults = $this->getJson(route('ingredients.search-platform').'?q=nigella');
    $aliasResults->assertSuccessful();
    expect($aliasResults->json('0.id'))->toBe($platform->id)
        ->and($aliasResults->json())->toHaveCount(1);

    $identifierResults = $this->getJson(route('ingredients.search-platform').'?q=8002-75-3');
    $identifierResults->assertSuccessful();
    expect($identifierResults->json('0.id'))->toBe($platform->id);
});

it('reports duplication eligibility metadata for an eligible platform ingredient', function (): void {
    $user = User::factory()->create();

    $platform = Ingredient::factory()->create([
        'display_name' => 'Olive Oil',
        'category' => IngredientCategory::Lipids,
        'owner_type' => null,
        'owner_id' => null,
        'workspace_id' => null,
        'is_active' => true,
        'is_soap_saponification_trusted' => true,
    ]);
    $platform->sapProfile()->create(['koh_sap_value' => 0.188]);

    actingAs($user);

    $response = $this->getJson(route('ingredients.search-platform').'?q=olive');

    $response->assertSuccessful()
        ->assertJsonPath('0.id', $platform->id)
        ->assertJsonPath('0.duplication.available', true)
        ->assertJsonPath('0.duplication.reason', null)
        ->assertJsonPath('0.duplication.inherits_soap_chemistry', true);

    expect($response->json('0'))->not->toHaveKeys([
        'source_data',
        'requires_admin_review',
        'is_soap_saponification_trusted',
    ]);
});

it('evaluates destination duplication eligibility once for a bounded search', function (): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user, 'owner')->create();

    $user->forceFill(['active_workspace_id' => $workspace->id])->save();
    $user->forgetAccessibleWorkspaceIds();

    Ingredient::factory()->count(3)->create([
        'display_name' => 'Batch search ingredient',
        'owner_type' => null,
        'owner_id' => null,
        'workspace_id' => null,
        'is_active' => true,
    ]);

    $entitlementService = mock(EntitlementService::class);
    $entitlementService
        ->shouldReceive('assertCanCreatePrivateIngredientInWorkspace')
        ->once()
        ->withArgs(fn (Workspace $candidate): bool => $candidate->is($workspace))
        ->andReturnNull();
    app()->instance(EntitlementService::class, $entitlementService);

    actingAs($user);

    $this->getJson(route('ingredients.search-platform').'?q=batch')
        ->assertSuccessful()
        ->assertJsonCount(3);
});

it('reports role denial in search metadata and does not create a copy', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $viewer = User::factory()->create(['active_workspace_id' => $workspace->id]);
    WorkspaceMember::factory()->for($workspace)->for($viewer)->create([
        'role' => WorkspaceMemberRole::Viewer,
    ]);
    $source = Ingredient::factory()->create([
        'display_name' => 'Viewer denied source',
        'owner_type' => null,
        'owner_id' => null,
        'workspace_id' => null,
        'is_active' => true,
    ]);
    $asset = MediaAsset::factory()->ready()->create([
        'workspace_id' => $workspace->id,
        'uploaded_by_user_id' => $owner->id,
    ]);
    MediaAssetUsage::factory()->create([
        'media_asset_id' => $asset->id,
        'usable_type' => Ingredient::class,
        'usable_id' => $source->id,
        'role' => MediaAssetUsageRole::IngredientMain,
    ]);
    WorkspaceIngredientGuidance::factory()->create([
        'workspace_id' => $workspace->id,
        'ingredient_id' => $source->id,
        'created_by_user_id' => $owner->id,
        'updated_by_user_id' => $owner->id,
    ]);
    WorkspaceIngredientCode::factory()->create([
        'workspace_id' => $workspace->id,
        'ingredient_id' => $source->id,
    ]);
    $viewer->forgetAccessibleWorkspaceIds();

    actingAs($viewer);

    $this->getJson(route('ingredients.search-platform').'?q=viewer%20denied')
        ->assertSuccessful()
        ->assertJsonPath('0.id', $source->id)
        ->assertJsonPath('0.duplication.available', false)
        ->assertJsonPath('0.duplication.reason', __('ingredients.editor.validation.stale_workspace'));

    $before = [
        'ingredients' => Ingredient::query()->count(),
        'guidance' => WorkspaceIngredientGuidance::query()->count(),
        'media_usages' => MediaAssetUsage::query()->count(),
        'media_assets' => MediaAsset::query()->count(),
        'codes' => WorkspaceIngredientCode::query()->count(),
    ];
    $signature = hash_hmac(
        'sha256',
        $viewer->id.'|'.$workspace->id,
        (string) config('app.key'),
    );

    $this->postJson(route('ingredients.duplicate'), [
        'ingredient_id' => $source->id,
        'destination_workspace_id' => $workspace->id,
        'destination_workspace_signature' => $signature,
    ])
        ->assertForbidden()
        ->assertJsonPath('message', __('ingredients.editor.validation.stale_workspace'));

    expect([
        'ingredients' => Ingredient::query()->count(),
        'guidance' => WorkspaceIngredientGuidance::query()->count(),
        'media_usages' => MediaAssetUsage::query()->count(),
        'media_assets' => MediaAsset::query()->count(),
        'codes' => WorkspaceIngredientCode::query()->count(),
    ])->toBe($before);
});

it('rejects a nonplatform source without creating a copy', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $source = Ingredient::factory()->create([
        'display_name' => 'Workspace source cannot be duplicated',
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
        'is_active' => true,
    ]);
    $owner->forceFill(['active_workspace_id' => $workspace->id])->save();
    $owner->forgetAccessibleWorkspaceIds();
    actingAs($owner);

    $signature = hash_hmac(
        'sha256',
        $owner->id.'|'.$workspace->id,
        (string) config('app.key'),
    );

    $this->postJson(route('ingredients.duplicate'), [
        'ingredient_id' => $source->id,
        'destination_workspace_id' => $workspace->id,
        'destination_workspace_signature' => $signature,
    ])
        ->assertForbidden()
        ->assertJsonPath('message', __('ingredients.editor.validation.stale_workspace'));

    expect(Ingredient::query()
        ->where('owner_type', OwnerType::Workspace)
        ->where('owner_id', $workspace->id)
        ->whereKeyNot($source->id)
        ->exists())->toBeFalse();
});

it('rejects an inactive platform source without creating a copy', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $source = Ingredient::factory()->create([
        'display_name' => 'Inactive source cannot be duplicated',
        'owner_type' => null,
        'owner_id' => null,
        'workspace_id' => null,
        'is_active' => false,
    ]);
    $asset = MediaAsset::factory()->ready()->create([
        'workspace_id' => $workspace->id,
        'uploaded_by_user_id' => $owner->id,
    ]);
    MediaAssetUsage::factory()->create([
        'media_asset_id' => $asset->id,
        'usable_type' => Ingredient::class,
        'usable_id' => $source->id,
        'role' => MediaAssetUsageRole::IngredientDocument,
    ]);
    WorkspaceIngredientGuidance::factory()->create([
        'workspace_id' => $workspace->id,
        'ingredient_id' => $source->id,
        'created_by_user_id' => $owner->id,
        'updated_by_user_id' => $owner->id,
    ]);
    WorkspaceIngredientCode::factory()->create([
        'workspace_id' => $workspace->id,
        'ingredient_id' => $source->id,
    ]);
    $owner->forceFill(['active_workspace_id' => $workspace->id])->save();
    $owner->forgetAccessibleWorkspaceIds();
    actingAs($owner);

    $this->getJson(route('ingredients.search-platform').'?q=inactive%20source')
        ->assertSuccessful()
        ->assertJsonCount(0);

    $before = [
        'ingredients' => Ingredient::query()->count(),
        'guidance' => WorkspaceIngredientGuidance::query()->count(),
        'media_usages' => MediaAssetUsage::query()->count(),
        'media_assets' => MediaAsset::query()->count(),
        'codes' => WorkspaceIngredientCode::query()->count(),
    ];
    $signature = hash_hmac(
        'sha256',
        $owner->id.'|'.$workspace->id,
        (string) config('app.key'),
    );

    $this->postJson(route('ingredients.duplicate'), [
        'ingredient_id' => $source->id,
        'destination_workspace_id' => $workspace->id,
        'destination_workspace_signature' => $signature,
    ])->assertForbidden();

    expect([
        'ingredients' => Ingredient::query()->count(),
        'guidance' => WorkspaceIngredientGuidance::query()->count(),
        'media_usages' => MediaAssetUsage::query()->count(),
        'media_assets' => MediaAsset::query()->count(),
        'codes' => WorkspaceIngredientCode::query()->count(),
    ])->toBe($before);
});

it('reports the platform-only alkali blocker and does not create a copy', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $source = Ingredient::factory()->create([
        'display_name' => 'Sodium hydroxide',
        'category' => IngredientCategory::SoapmakingAlkalis,
        'owner_type' => null,
        'owner_id' => null,
        'workspace_id' => null,
        'is_active' => true,
    ]);
    $owner->forceFill(['active_workspace_id' => $workspace->id])->save();
    $owner->forgetAccessibleWorkspaceIds();
    actingAs($owner);

    $this->getJson(route('ingredients.search-platform').'?q=sodium%20hydroxide')
        ->assertSuccessful()
        ->assertJsonPath('0.duplication.available', false)
        ->assertJsonPath(
            '0.duplication.reason',
            __('ingredients.editor.validation.soapmaking_alkalis_platform_only'),
        );

    $signature = hash_hmac(
        'sha256',
        $owner->id.'|'.$workspace->id,
        (string) config('app.key'),
    );

    $this->postJson(route('ingredients.duplicate'), [
        'ingredient_id' => $source->id,
        'destination_workspace_id' => $workspace->id,
        'destination_workspace_signature' => $signature,
    ])
        ->assertStatus(422)
        ->assertJsonPath(
            'errors.category.0',
            __('ingredients.editor.validation.soapmaking_alkalis_platform_only'),
        );

    expect(Ingredient::query()
        ->where('owner_type', OwnerType::Workspace)
        ->where('owner_id', $workspace->id)
        ->exists())->toBeFalse();
});

it('reports the missing lipid SAP blocker and does not create a copy', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $source = Ingredient::factory()->create([
        'display_name' => 'Incomplete platform oil',
        'category' => IngredientCategory::Lipids,
        'owner_type' => null,
        'owner_id' => null,
        'workspace_id' => null,
        'is_active' => true,
    ]);
    $owner->forceFill(['active_workspace_id' => $workspace->id])->save();
    $owner->forgetAccessibleWorkspaceIds();
    actingAs($owner);

    $this->getJson(route('ingredients.search-platform').'?q=incomplete%20platform%20oil')
        ->assertSuccessful()
        ->assertJsonPath('0.duplication.available', false)
        ->assertJsonPath(
            '0.duplication.reason',
            __('ingredients.editor.validation.duplicate_soap_profile_required'),
        );

    $signature = hash_hmac(
        'sha256',
        $owner->id.'|'.$workspace->id,
        (string) config('app.key'),
    );

    $this->postJson(route('ingredients.duplicate'), [
        'ingredient_id' => $source->id,
        'destination_workspace_id' => $workspace->id,
        'destination_workspace_signature' => $signature,
    ])
        ->assertStatus(422)
        ->assertJsonPath(
            'errors.ingredient.0',
            __('ingredients.editor.validation.duplicate_soap_profile_required'),
        );

    expect(Ingredient::query()
        ->where('owner_type', OwnerType::Workspace)
        ->where('owner_id', $workspace->id)
        ->exists())->toBeFalse();
});

it('reports a reached private ingredient quota and does not create a copy', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $owner->forceFill(['active_workspace_id' => $workspace->id])->save();
    $owner->forgetAccessibleWorkspaceIds();
    $plan = Plan::factory()->hasLimit('private_ingredients', 1)->create();
    $owner->entitlements()->create([
        'plan_id' => $plan->id,
        'status' => 'active',
        'starts_at' => now(),
    ]);
    Ingredient::factory()->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
        'visibility' => 'private',
    ]);
    $source = Ingredient::factory()->create([
        'display_name' => 'Quota source',
        'owner_type' => null,
        'owner_id' => null,
        'workspace_id' => null,
        'is_active' => true,
    ]);
    $asset = MediaAsset::factory()->ready()->create([
        'workspace_id' => $workspace->id,
        'uploaded_by_user_id' => $owner->id,
    ]);
    MediaAssetUsage::factory()->create([
        'media_asset_id' => $asset->id,
        'usable_type' => Ingredient::class,
        'usable_id' => $source->id,
        'role' => MediaAssetUsageRole::IngredientMain,
    ]);
    WorkspaceIngredientGuidance::factory()->create([
        'workspace_id' => $workspace->id,
        'ingredient_id' => $source->id,
        'created_by_user_id' => $owner->id,
        'updated_by_user_id' => $owner->id,
    ]);
    WorkspaceIngredientCode::factory()->create([
        'workspace_id' => $workspace->id,
        'ingredient_id' => $source->id,
    ]);
    actingAs($owner);

    $expectedMessage = trans_choice(
        'ingredients.editor.validation.private_ingredient_limit',
        1,
        ['limit' => 1],
    );

    $this->getJson(route('ingredients.search-platform').'?q=quota%20source')
        ->assertSuccessful()
        ->assertJsonPath('0.duplication.available', false)
        ->assertJsonPath('0.duplication.reason', $expectedMessage);

    $before = [
        'ingredients' => Ingredient::query()->count(),
        'guidance' => WorkspaceIngredientGuidance::query()->count(),
        'media_usages' => MediaAssetUsage::query()->count(),
        'media_assets' => MediaAsset::query()->count(),
        'codes' => WorkspaceIngredientCode::query()->count(),
    ];
    $signature = hash_hmac(
        'sha256',
        $owner->id.'|'.$workspace->id,
        (string) config('app.key'),
    );

    $this->postJson(route('ingredients.duplicate'), [
        'ingredient_id' => $source->id,
        'destination_workspace_id' => $workspace->id,
        'destination_workspace_signature' => $signature,
    ])
        ->assertStatus(422)
        ->assertJsonPath('errors.plan.0', $expectedMessage);

    expect([
        'ingredients' => Ingredient::query()->count(),
        'guidance' => WorkspaceIngredientGuidance::query()->count(),
        'media_usages' => MediaAssetUsage::query()->count(),
        'media_assets' => MediaAsset::query()->count(),
        'codes' => WorkspaceIngredientCode::query()->count(),
    ])->toBe($before);
});

it('creates a workspace-owned copy when duplicating a platform ingredient', function () {
    $user = User::factory()->create();

    $source = Ingredient::factory()->create([
        'display_name' => 'Rosemary Oil',
        'inci_name' => 'ROSMARINUS OFFICINALIS OIL',
        'category' => IngredientCategory::AromaticMaterials,
        'owner_type' => null,
        'owner_id' => null,
        'is_active' => true,
    ]);

    actingAs($user);

    $response = $this->postJson(route('ingredients.duplicate'), [
        'ingredient_id' => $source->id,
        'destination_workspace_id' => null,
        'destination_workspace_signature' => hash_hmac(
            'sha256',
            $user->id.'|none',
            (string) config('app.key'),
        ),
    ]);

    $response->assertSuccessful();
    expect($response->json('ok'))->toBeTrue();

    $workspace = $user->fresh()->company();

    $copy = Ingredient::query()
        ->where('owner_type', OwnerType::Workspace)
        ->where('owner_id', $workspace->id)
        ->first();

    expect($copy)->not->toBeNull();
    expect($copy->display_name)->toBe('Rosemary Oil');
    expect($copy->owner_type)->toBe(OwnerType::Workspace);
    expect($copy->owner_id)->toBe($workspace->id);
    expect($copy->workspace_id)->toBe($workspace->id);
    expect($copy->featured_image_path)->toBeNull();
});
