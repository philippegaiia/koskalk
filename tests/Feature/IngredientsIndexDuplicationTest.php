<?php

use App\Enums\IngredientCategory;
use App\Enums\MediaAssetUsageRole;
use App\Enums\OwnerType;
use App\Enums\WorkspaceMemberRole;
use App\Models\FattyAcid;
use App\Models\Ingredient;
use App\Models\InterfaceTranslation;
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
use Illuminate\Support\Facades\App;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\mock;

uses(RefreshDatabase::class);

it('shows matching add and duplicate actions together in the ingredient catalog header', function () {
    $user = User::factory()->create();

    actingAs($user);

    $this->get(route('ingredients.index'))
        ->assertSuccessful()
        ->assertSeeInOrder([
            'Ingredient catalog',
            'Duplicate ingredient',
            'Add ingredient',
        ])
        ->assertDontSee('Duplicate a Soapkraft ingredient');
});

it('renders a preview-only accessible duplication dialog with its data disclosures', function (): void {
    $user = User::factory()->create();

    actingAs($user);

    $this->get(route('ingredients.index'))
        ->assertSuccessful()
        ->assertSee('Create a private copy in your private ingredient library. You can edit its details. The source ingredient stays unchanged.')
        ->assertSee('Create private copy')
        ->assertSee('Legacy ingredient images are reset in the private copy.')
        ->assertSee('Documents and media usages are not copied.')
        ->assertSee('Available guidance is copied to the new ingredient.')
        ->assertSee('role="dialog"', false)
        ->assertSee('aria-modal="true"', false)
        ->assertSee('<ul', false)
        ->assertSee('<li>', false)
        ->assertDontSee('role="listbox"', false)
        ->assertDontSee('role="option"', false)
        ->assertSee('for="ingredient-duplication-search"', false)
        ->assertSee('ingredientDuplicationModal', false)
        ->assertSee('JSON.parse(', false)
        ->assertSee('messages.review', false)
        ->assertSee('messages.source', false)
        ->assertSee('!confirming && closeModal()', false)
        ->assertSee('KOH SAP range (g KOH/g oil)')
        ->assertSee('NaOH SAP range (g NaOH/g oil)')
        ->assertSee('Fatty acid total range')
        ->assertSee('selected.duplication.chemistry.koh_sap.minimum', false)
        ->assertSee('selected.duplication.chemistry.fatty_acids', false)
        ->assertDontSee('info_markdown');
});

it('registers the duplication factory and keeps dismissal guarded during confirmation', function (): void {
    $partial = (string) file_get_contents(base_path('resources/views/livewire/dashboard/partials/duplicate-ingredient-modal.blade.php'));
    $app = (string) file_get_contents(base_path('resources/js/app.js'));

    expect($app)->toContain('window.ingredientDuplicationModal = (payload) => createIngredientDuplicationModal(payload);')
        ->and($partial)->toContain('x-data="ingredientDuplicationModal({')
        ->and($partial)->toContain('@click.self="!confirming && closeModal()"')
        ->and($partial)->toContain('@keydown.escape.window="!confirming && closeModal()"')
        ->and($partial)->toContain('selected.duplication.chemistry.koh_sap.minimum')
        ->and($partial)->toContain("'kohSapUnit' => __('ingredients.duplicate.preview.koh_sap_unit')")
        ->and($partial)->toContain("'naohSapUnit' => __('ingredients.duplicate.preview.naoh_sap_unit')")
        ->and($partial)->toContain('selected.duplication.chemistry.koh_sap.original} ${messages.kohSapUnit}')
        ->and($partial)->toContain('selected.duplication.chemistry.naoh_sap.original} ${messages.naohSapUnit}')
        ->and($partial)->not->toContain('g KOH/g oil (${messages.source}')
        ->and($partial)->not->toContain('g NaOH/g oil (${messages.source}')
        ->and($partial)->toContain('selected.duplication.chemistry.fatty_acids')
        ->and($partial)->not->toContain("x-text=\"item.duplication.available ? '{{ __('")
        ->and($partial)->not->toContain("{{ __('ingredients.duplicate.preview.source') }}");
});

it('renders localized chemistry units in the duplication modal contract', function (): void {
    foreach ([
        [
            'group' => 'ingredients',
            'key' => 'duplicate.preview.koh_sap_range',
            'text' => ['fr' => 'Plage SAP KOH (g KOH/g huile)'],
        ],
        [
            'group' => 'ingredients',
            'key' => 'duplicate.preview.naoh_sap_range',
            'text' => ['fr' => 'Plage SAP NaOH (g NaOH/g huile)'],
        ],
        [
            'group' => 'ingredients',
            'key' => 'duplicate.preview.koh_sap_unit',
            'text' => ['fr' => 'g KOH/g huile'],
        ],
        [
            'group' => 'ingredients',
            'key' => 'duplicate.preview.naoh_sap_unit',
            'text' => ['fr' => 'g NaOH/g huile'],
        ],
    ] as $translation) {
        InterfaceTranslation::query()->create($translation);
    }

    $user = User::factory()->create();

    App::setLocale('fr');

    $this->actingAs($user)
        ->get(route('ingredients.index'))
        ->assertSuccessful()
        ->assertSee('Plage SAP KOH (g KOH/g huile)')
        ->assertSee('Plage SAP NaOH (g NaOH/g huile)')
        ->assertSee('g KOH/g huile')
        ->assertSee('g NaOH/g huile')
        ->assertDontSee('g KOH/g oil')
        ->assertDontSee('g NaOH/g oil');
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

it('searches the active workspace ingredients without leaking another workspace', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $otherWorkspace = Workspace::factory()->create();
    $owner->forceFill(['active_workspace_id' => $workspace->id])->save();
    $owner->forgetAccessibleWorkspaceIds();

    $workspaceIngredient = Ingredient::factory()->create([
        'display_name' => 'Workspace duplicate source',
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
        'is_active' => true,
    ]);
    Ingredient::factory()->create([
        'display_name' => 'Workspace duplicate source elsewhere',
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $otherWorkspace->id,
        'workspace_id' => $otherWorkspace->id,
        'is_active' => true,
    ]);

    actingAs($owner);

    $this->getJson(route('ingredients.search-platform').'?q=workspace%20duplicate%20source')
        ->assertSuccessful()
        ->assertJsonCount(1)
        ->assertJsonPath('0.id', $workspaceIngredient->id)
        ->assertJsonPath('0.source', 'workspace');
});

it('searches platform ingredients by curated aliases and identifiers without leaking unrelated workspace rows', function (): void {
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
    $otherWorkspace = Workspace::factory()->create();
    $private = Ingredient::factory()->create([
        'display_name' => 'Private alias ingredient',
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $otherWorkspace->id,
        'workspace_id' => $otherWorkspace->id,
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
    $oleic = FattyAcid::factory()->create(['name' => 'Oleic acid']);
    $palmitic = FattyAcid::factory()->create(['name' => 'Palmitic acid']);
    $platform->fattyAcidEntries()->createMany([
        ['fatty_acid_id' => $oleic->id, 'percentage' => 70],
        ['fatty_acid_id' => $palmitic->id, 'percentage' => 20],
    ]);

    actingAs($user);

    $response = $this->getJson(route('ingredients.search-platform').'?q=olive');

    $response->assertSuccessful()
        ->assertJsonPath('0.id', $platform->id)
        ->assertJsonPath('0.duplication.available', true)
        ->assertJsonPath('0.duplication.reason', null)
        ->assertJsonPath('0.duplication.inherits_soap_chemistry', true)
        ->assertJsonPath('0.duplication.chemistry.koh_sap.minimum', '0.182360')
        ->assertJsonPath('0.duplication.chemistry.koh_sap.maximum', '0.193640')
        ->assertJsonPath('0.duplication.chemistry.naoh_sap.minimum', '0.130023')
        ->assertJsonPath('0.duplication.chemistry.naoh_sap.maximum', '0.138065')
        ->assertJsonPath('0.duplication.chemistry.fatty_acid_total.minimum', '80.0')
        ->assertJsonPath('0.duplication.chemistry.fatty_acid_total.maximum', '100.0');

    $fattyAcids = collect($response->json('0.duplication.chemistry.fatty_acids'))->keyBy('id');

    expect($fattyAcids->get($oleic->id))->toMatchArray([
        'name' => 'Oleic acid',
        'original' => '70.0',
        'minimum' => '56.0',
        'maximum' => '84.0',
    ])->and($fattyAcids->get($palmitic->id))->toMatchArray([
        'name' => 'Palmitic acid',
        'original' => '20.0',
        'minimum' => '16.0',
        'maximum' => '24.0',
    ]);

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

it('does not expose chemistry limits for untrusted, incomplete, or unrelated platform ingredients', function (): void {
    $user = User::factory()->create();

    $untrusted = Ingredient::factory()->create([
        'display_name' => 'Preview source untrusted oil',
        'category' => IngredientCategory::Lipids,
        'owner_type' => null,
        'owner_id' => null,
        'workspace_id' => null,
        'is_active' => true,
        'is_soap_saponification_trusted' => false,
    ]);
    $untrusted->sapProfile()->create(['koh_sap_value' => 0.188]);

    Ingredient::factory()->create([
        'display_name' => 'Preview source missing oil',
        'category' => IngredientCategory::Lipids,
        'owner_type' => null,
        'owner_id' => null,
        'workspace_id' => null,
        'is_active' => true,
        'is_soap_saponification_trusted' => true,
    ]);

    $unrelated = Ingredient::factory()->create([
        'display_name' => 'Preview source aromatic oil',
        'category' => IngredientCategory::AromaticMaterials,
        'owner_type' => null,
        'owner_id' => null,
        'workspace_id' => null,
        'is_active' => true,
        'is_soap_saponification_trusted' => true,
    ]);
    $unrelated->sapProfile()->create(['koh_sap_value' => 0.188]);

    actingAs($user);

    $results = collect($this->getJson(route('ingredients.search-platform').'?q=preview%20source')->json())
        ->keyBy('name');

    expect($results)->toHaveCount(3)
        ->and($results->get('Preview source untrusted oil')['duplication']['inherits_soap_chemistry'])->toBeFalse()
        ->and($results->get('Preview source untrusted oil')['duplication']['chemistry'])->toBeNull()
        ->and($results->get('Preview source missing oil')['duplication']['inherits_soap_chemistry'])->toBeFalse()
        ->and($results->get('Preview source missing oil')['duplication']['chemistry'])->toBeNull()
        ->and($results->get('Preview source aromatic oil')['duplication']['inherits_soap_chemistry'])->toBeFalse()
        ->and($results->get('Preview source aromatic oil')['duplication']['chemistry'])->toBeNull();
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

it('rejects a source owned by another workspace without creating a copy', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $otherWorkspace = Workspace::factory()->create();
    $source = Ingredient::factory()->create([
        'display_name' => 'Other workspace source cannot be duplicated',
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $otherWorkspace->id,
        'workspace_id' => $otherWorkspace->id,
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

it('creates a workspace-owned copy when duplicating an ingredient from that workspace', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $owner->forceFill(['active_workspace_id' => $workspace->id])->save();
    $owner->forgetAccessibleWorkspaceIds();
    $source = Ingredient::factory()->create([
        'display_name' => 'Private rosemary extract',
        'category' => IngredientCategory::BotanicalsExtracts,
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
        'is_active' => true,
    ]);

    actingAs($owner);

    $response = $this->postJson(route('ingredients.duplicate'), [
        'ingredient_id' => $source->id,
        'destination_workspace_id' => $workspace->id,
        'destination_workspace_signature' => hash_hmac(
            'sha256',
            $owner->id.'|'.$workspace->id,
            (string) config('app.key'),
        ),
    ]);

    $response
        ->assertSuccessful()
        ->assertJsonPath('ok', true);

    $copy = Ingredient::query()
        ->where('owner_type', OwnerType::Workspace)
        ->where('owner_id', $workspace->id)
        ->whereKeyNot($source->id)
        ->firstOrFail();

    expect($copy->display_name)->toBe('Private rosemary extract')
        ->and($copy->workspace_id)->toBe($workspace->id)
        ->and($response->json('redirect'))->toBe(route('ingredients.edit', $copy));
});
