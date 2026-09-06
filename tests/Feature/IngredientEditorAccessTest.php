<?php

use App\Enums\MediaAssetUsageRole;
use App\Enums\OwnerType;
use App\Enums\Visibility;
use App\Enums\WorkspaceMemberRole;
use App\Livewire\Dashboard\IngredientEditor;
use App\Livewire\Dashboard\IngredientsIndex;
use App\Models\Ingredient;
use App\Models\MediaAsset;
use App\Models\MediaAssetUsage;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceIngredientCode;
use App\Models\WorkspaceIngredientGuidance;
use App\Models\WorkspaceMember;
use App\Policies\IngredientPolicy;
use App\Services\UserIngredientAuthoringService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('keeps the initial workspace ingredient edit request within its query budget', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $owner->forceFill(['active_workspace_id' => $workspace->id])->save();
    $owner->forgetAccessibleWorkspaceIds();
    $ingredient = Ingredient::factory()->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
        'visibility' => Visibility::Private,
    ]);

    $this->actingAs($owner);

    DB::flushQueryLog();
    DB::enableQueryLog();

    $response = $this->get(route('ingredients.edit', $ingredient));
    $queries = collect(DB::getQueryLog());

    DB::disableQueryLog();

    $ingredientReloads = $queries->filter(fn (array $query): bool => str_contains(
        $query['query'],
        'from "ingredients" where "ingredients"."id" = ? limit 1',
    ));

    $response->assertSuccessful();

    expect($queries->count())->toBeLessThan(55)
        ->and($ingredientReloads->count())->toBeLessThan(3);
});

it('loads platform reference data and guidance without repeated reads', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $owner->forceFill(['active_workspace_id' => $workspace->id])->save();
    $ingredient = Ingredient::factory()->create([
        'owner_type' => null,
        'owner_id' => null,
        'workspace_id' => null,
        'is_active' => true,
        'info_markdown' => 'Platform reference guidance.',
    ]);
    WorkspaceIngredientGuidance::factory()->create([
        'workspace_id' => $workspace->id,
        'ingredient_id' => $ingredient->id,
        'guidance_html' => '<p>Workspace reference guidance.</p>',
        'is_active' => true,
    ]);
    $this->actingAs($owner);

    DB::flushQueryLog();
    DB::enableQueryLog();
    try {
        $response = $this->get(route('ingredients.edit', $ingredient));
        $queries = collect(DB::getQueryLog());
    } finally {
        DB::disableQueryLog();
    }

    $response->assertSuccessful()->assertSee('Workspace reference guidance.');

    expect($queries->filter(fn (array $query): bool => str_contains($query['query'], 'from "ingredient_translations"'))->count())->toBe(1)
        ->and($queries->filter(fn (array $query): bool => str_contains($query['query'], 'from "workspace_ingredient_guidances"'))->count())->toBeLessThanOrEqual(2)
        ->and($queries->count())->toBeLessThan(40);
});

it('allows workspace editors to author ingredients through the dedicated ability', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $editor = User::factory()->create();

    WorkspaceMember::factory()->for($workspace)->for($editor)->create([
        'role' => WorkspaceMemberRole::Editor,
    ]);

    $ingredient = Ingredient::factory()->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
        'visibility' => Visibility::Private,
    ]);

    expect(Gate::forUser($owner)->allows('createInWorkspace', [Ingredient::class, $workspace]))
        ->toBeTrue()
        ->and(Gate::forUser($editor)->allows('editWorkspaceIngredient', $ingredient))
        ->toBeTrue();
});

it('authorizes duplication with the source ingredient as the policy receiver', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $source = Ingredient::factory()->create([
        'owner_type' => null,
        'owner_id' => null,
        'is_active' => true,
    ]);
    $policy = Mockery::mock(IngredientPolicy::class)->makePartial();

    $policy->shouldReceive('duplicateIntoWorkspace')
        ->twice()
        ->withArgs(fn (User $actor, Ingredient $candidate, ?Workspace $destination): bool => $actor->is($owner)
            && $candidate->is($source)
            && $destination?->is($workspace) === true)
        ->andReturnTrue();
    app()->instance(IngredientPolicy::class, $policy);

    $copy = app(UserIngredientAuthoringService::class)->duplicateIntoWorkspace(
        $source,
        $owner,
        $workspace,
    );

    expect($copy->owner_id)->toBe($workspace->id);
});

it('requires a real no-workspace context for null destinations and platform duplication', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $malformedPlatform = Ingredient::factory()->create([
        'owner_type' => null,
        'owner_id' => $workspace->id,
        'workspace_id' => null,
        'is_active' => true,
    ]);

    expect(Gate::forUser($owner)->allows('createInWorkspace', [Ingredient::class, null]))
        ->toBeFalse()
        ->and(Gate::forUser($owner)->allows('duplicateIntoWorkspace', [$malformedPlatform, $workspace]))
        ->toBeFalse();

    $foreignWorkspace = Workspace::factory()->create();
    $noWorkspaceUser = User::factory()->create(['active_workspace_id' => $foreignWorkspace->id]);

    expect(Gate::forUser($noWorkspaceUser)->allows('createInWorkspace', [Ingredient::class, null]))
        ->toBeFalse();

    expect(fn (): Ingredient => app(UserIngredientAuthoringService::class)->createInWorkspace([
        'name' => 'Must not use the current workspace',
        'category' => 'other',
    ], $owner, null))->toThrow(AuthorizationException::class);

    expect(Ingredient::query()->where('display_name', 'Must not use the current workspace')->exists())
        ->toBeFalse();
});

it('does not expose platform customization for a tenant-owned ingredient with a null owner type', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $malformedPlatform = Ingredient::factory()->create([
        'owner_type' => null,
        'owner_id' => $workspace->id,
        'workspace_id' => null,
        'is_active' => true,
    ]);
    $owner->forceFill(['active_workspace_id' => $workspace->id])->save();
    $owner->forgetAccessibleWorkspaceIds();

    $this->actingAs($owner);

    $editor = Livewire::test(IngredientEditor::class, ['ingredient' => $malformedPlatform]);

    $editor->assertSee('You can view this ingredient, but you do not have permission to edit it.');

    expect($editor->instance()->canEditWorkspaceGuidance())->toBeFalse()
        ->and($editor->instance()->canEditWorkspaceMaterialCode())->toBeFalse();
});

it('keeps the workspace authoring matrix independent of app administrator status', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $privateIngredient = Ingredient::factory()->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
        'visibility' => Visibility::Private,
    ]);
    $platformIngredient = Ingredient::factory()->create([
        'owner_type' => null,
        'owner_id' => null,
        'is_active' => true,
    ]);
    $publicAuthoredIngredient = Ingredient::factory()->create([
        'owner_type' => OwnerType::User,
        'owner_id' => User::factory(),
        'visibility' => Visibility::Public,
    ]);
    $roles = [
        'owner' => [$owner, true],
        'member-owner' => [User::factory()->create(), true],
        'admin' => [User::factory()->create(), true],
        'editor' => [User::factory()->create(), true],
        'viewer' => [User::factory()->create(), false],
        'non-member' => [User::factory()->create(), false],
        'app-admin' => [User::factory()->admin()->create(), false],
    ];

    WorkspaceMember::factory()->for($workspace)->for($roles['member-owner'][0])->create([
        'role' => WorkspaceMemberRole::Owner,
    ]);
    WorkspaceMember::factory()->for($workspace)->for($roles['admin'][0])->create([
        'role' => WorkspaceMemberRole::Admin,
    ]);
    WorkspaceMember::factory()->for($workspace)->for($roles['editor'][0])->create([
        'role' => WorkspaceMemberRole::Editor,
    ]);
    WorkspaceMember::factory()->for($workspace)->for($roles['viewer'][0])->create([
        'role' => WorkspaceMemberRole::Viewer,
    ]);

    foreach ($roles as [$user, $canWrite]) {
        expect(Gate::forUser($user)->allows('createInWorkspace', [Ingredient::class, $workspace]))
            ->toBe($canWrite)
            ->and(Gate::forUser($user)->allows('duplicateIntoWorkspace', [$platformIngredient, $workspace]))
            ->toBe($canWrite)
            ->and(Gate::forUser($user)->allows('editWorkspaceIngredient', $privateIngredient))
            ->toBe($canWrite)
            ->and(Gate::forUser($user)->allows('editWorkspaceIngredient', $publicAuthoredIngredient))
            ->toBeFalse();
    }

    expect(Gate::forUser($owner)->allows('duplicateIntoWorkspace', [$platformIngredient, $workspace]))
        ->toBeTrue()
        ->and(Gate::forUser($owner)->allows('duplicateIntoWorkspace', [$platformIngredient->forceFill(['is_active' => false]), $workspace]))
        ->toBeFalse();
});

it('renders edit controls only for workspace writers and references for readers', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $viewer = User::factory()->create();
    WorkspaceMember::factory()->for($workspace)->for($viewer)->create([
        'role' => WorkspaceMemberRole::Viewer,
    ]);
    $privateIngredient = Ingredient::factory()->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
        'visibility' => Visibility::Private,
    ]);
    $publicAuthoredIngredient = Ingredient::factory()->create([
        'owner_type' => OwnerType::User,
        'owner_id' => $owner->id,
        'visibility' => Visibility::Public,
    ]);

    $this->actingAs($owner);
    Livewire::test(IngredientEditor::class, ['ingredient' => $privateIngredient])
        ->assertSee('Edit '.$privateIngredient->localizedDisplayName())
        ->assertSee('Save changes');

    $viewer->forceFill(['active_workspace_id' => $workspace->id])->save();
    $viewer->forgetAccessibleWorkspaceIds();
    $this->actingAs($viewer);
    Livewire::test(IngredientEditor::class, ['ingredient' => $privateIngredient])
        ->assertSee('Ingredient reference')
        ->assertSee('You can view this ingredient, but you do not have permission to edit it.')
        ->assertDontSee('Save changes');

    $outsider = User::factory()->create();
    $this->actingAs($outsider)
        ->get(route('ingredients.edit', $privateIngredient))
        ->assertNotFound();

    Livewire::test(IngredientEditor::class, ['ingredient' => $publicAuthoredIngredient])
        ->assertSee('Ingredient reference')
        ->assertDontSee('Save changes');
});

it('denies a viewer before creating an ingredient in the supplied workspace', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $viewer = User::factory()->create();

    WorkspaceMember::factory()->for($workspace)->for($viewer)->create([
        'role' => WorkspaceMemberRole::Viewer,
    ]);

    expect(fn (): Ingredient => app(UserIngredientAuthoringService::class)->createInWorkspace([
        'name' => 'Viewer draft',
        'category' => 'other',
    ], $viewer, $workspace))
        ->toThrow(AuthorizationException::class);

    expect(Ingredient::query()->where('display_name', 'Viewer draft')->exists())->toBeFalse();
});

it('does not create a replacement when an opened ingredient is deleted', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $ingredient = app(UserIngredientAuthoringService::class)->createInWorkspace([
        'name' => 'Opened ingredient',
        'category' => 'other',
    ], $owner, $workspace);

    $this->actingAs($owner);

    $component = Livewire::test(IngredientEditor::class, ['ingredient' => $ingredient])
        ->set('data.name', 'Replacement must not be created');

    $ingredient->delete();

    $component->call('save')->assertHasErrors();

    expect(Ingredient::query()->where('display_name', 'Replacement must not be created')->exists())
        ->toBeFalse();
});

it('rejects a save when the active workspace changed after the editor opened', function (): void {
    $owner = User::factory()->create();
    $workspaceA = Workspace::factory()->for($owner, 'owner')->create();
    $workspaceB = Workspace::factory()->create();
    WorkspaceMember::factory()->for($workspaceB)->for($owner)->create([
        'role' => WorkspaceMemberRole::Editor,
    ]);
    $owner->forceFill(['active_workspace_id' => $workspaceA->id])->save();

    $this->actingAs($owner);

    $component = Livewire::test(IngredientEditor::class)
        ->assertSet('destinationWorkspaceId', $workspaceA->id)
        ->set('data.name', 'Stale workspace draft')
        ->set('data.category', 'other');

    $owner->forceFill(['active_workspace_id' => $workspaceB->id])->save();

    $component->call('save')->assertHasErrors();

    expect(Ingredient::query()->where('display_name', 'Stale workspace draft')->exists())
        ->toBeFalse();
});

it('does not grant a new ingredient draft to an app administrator without a workspace role', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin);

    $component = Livewire::test(IngredientEditor::class)
        ->assertSet('destinationWorkspaceId', null);

    expect($component->instance()->canEditIngredientData())->toBeFalse();

    $component
        ->set('data.name', 'Admin-only draft')
        ->set('data.category', 'other')
        ->call('save')
        ->assertHasErrors();

    expect(Ingredient::query()->where('display_name', 'Admin-only draft')->exists())->toBeFalse();
});

it('disables the ingredient form when the create capability is absent', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $viewer = User::factory()->create(['active_workspace_id' => $workspace->id]);
    WorkspaceMember::factory()->for($workspace)->for($viewer)->create([
        'role' => WorkspaceMemberRole::Viewer,
    ]);

    $this->actingAs($viewer);

    $component = Livewire::test(IngredientEditor::class);

    expect($component->html())->toMatch('/<input disabled="disabled" id="form\\.name"/');
});

it('hides catalogue write actions from app administrators without a customer workspace role', function (): void {
    $admin = User::factory()->admin()->create();
    $platform = Ingredient::factory()->create([
        'owner_type' => null,
        'owner_id' => null,
        'workspace_id' => null,
        'is_active' => true,
        'display_name' => 'Platform reference',
    ]);

    $this->actingAs($admin);

    Livewire::test(IngredientsIndex::class)
        ->assertSee('Platform reference')
        ->assertDontSee('Duplicate ingredient')
        ->assertDontSee('Add ingredient');
});

it('keeps platform capabilities tied to the active workspace while preserving the owning workspace edit path', function (): void {
    $user = User::factory()->create();
    $otherOwner = User::factory()->create();
    $workspaceA = Workspace::factory()->for($user, 'owner')->create();
    $workspaceB = Workspace::factory()->for($otherOwner, 'owner')->create();
    WorkspaceMember::factory()->for($workspaceB)->for($user)->create([
        'role' => WorkspaceMemberRole::Viewer,
    ]);
    $platform = Ingredient::factory()->create([
        'owner_type' => null,
        'owner_id' => null,
        'is_active' => true,
    ]);
    $workspaceIngredient = Ingredient::factory()->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspaceA->id,
        'workspace_id' => $workspaceA->id,
        'visibility' => Visibility::Private,
        'display_name' => 'A name',
    ]);

    $user->forceFill(['active_workspace_id' => $workspaceB->id])->save();
    $user->forgetAccessibleWorkspaceIds();
    $this->actingAs($user);

    $viewerEditor = Livewire::test(IngredientEditor::class, ['ingredient' => $platform]);
    expect($viewerEditor->instance()->canEditIngredientData())->toBeFalse()
        ->and($viewerEditor->instance()->canEditWorkspaceGuidance())->toBeFalse()
        ->and($viewerEditor->instance()->canEditWorkspaceMaterialCode())->toBeFalse();

    expect(fn (): Ingredient => app(UserIngredientAuthoringService::class)->createInWorkspace([
        'name' => 'B denied',
        'category' => 'other',
    ], $user, $workspaceB))->toThrow(AuthorizationException::class);
    expect(fn (): Ingredient => app(UserIngredientAuthoringService::class)->duplicateIntoWorkspace(
        $platform,
        $user,
        $workspaceB,
    ))->toThrow(AuthorizationException::class);

    $user->forceFill(['active_workspace_id' => $workspaceA->id])->save();
    $user->forgetAccessibleWorkspaceIds();
    $ownerEditor = Livewire::test(IngredientEditor::class, ['ingredient' => $platform]);
    expect($ownerEditor->instance()->canEditWorkspaceGuidance())->toBeTrue()
        ->and($ownerEditor->instance()->canEditWorkspaceMaterialCode())->toBeTrue();

    $user->forceFill(['active_workspace_id' => $workspaceB->id])->save();
    $user->forgetAccessibleWorkspaceIds();
    Livewire::test(IngredientEditor::class, ['ingredient' => $workspaceIngredient])
        ->set('data.name', 'Edited from owning workspace')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSeeText('Edited from owning workspace');

    expect($workspaceIngredient->refresh()->display_name)->toBe('Edited from owning workspace');
});

it('rejects stale editor, guidance, code, inline, and duplicate contexts without writing', function (): void {
    $user = User::factory()->create();
    $otherOwner = User::factory()->create();
    $workspaceA = Workspace::factory()->for($user, 'owner')->create();
    $workspaceB = Workspace::factory()->for($otherOwner, 'owner')->create();
    WorkspaceMember::factory()->for($workspaceB)->for($user)->create([
        'role' => WorkspaceMemberRole::Editor,
    ]);
    $platform = Ingredient::factory()->create([
        'owner_type' => null,
        'owner_id' => null,
        'is_active' => true,
    ]);
    $user->forceFill(['active_workspace_id' => $workspaceA->id])->save();
    $user->forgetAccessibleWorkspaceIds();
    $this->actingAs($user);

    $newEditor = Livewire::test(IngredientEditor::class)
        ->set('data.name', 'Stale draft')
        ->set('data.category', 'other');
    $platformEditor = Livewire::test(IngredientEditor::class, ['ingredient' => $platform]);
    $inlineEditor = Livewire::test(IngredientEditor::class)
        ->set('data.ingredient_structure', 'blend')
        ->set('quickComponentName', 'Stale component')
        ->set('quickComponentCategory', 'other');
    $index = Livewire::test(IngredientsIndex::class);
    $signature = $index->instance()->duplicateDestinationSignature();

    $user->forceFill(['active_workspace_id' => $workspaceB->id])->save();
    $user->forgetAccessibleWorkspaceIds();

    $newEditor->call('save')->assertHasErrors('data');
    $platformEditor
        ->set('workspaceMaterialCode', 'STALE-01')
        ->call('saveWorkspaceMaterialCode')
        ->assertHasErrors('workspaceMaterialCode')
        ->set('workspaceGuidance.html', '<p>Stale guidance</p>')
        ->call('saveWorkspaceGuidance')
        ->assertHasErrors('workspaceGuidance.html');
    $inlineEditor
        ->call('createAndAddComponent')
        ->assertHasErrors('quickComponentName');

    $response = $this->postJson(route('ingredients.duplicate'), [
        'ingredient_id' => $platform->id,
        'destination_workspace_id' => $workspaceA->id,
        'destination_workspace_signature' => $signature,
    ]);
    $response->assertForbidden();

    expect(Ingredient::query()->where('display_name', 'Stale draft')->exists())->toBeFalse()
        ->and(Ingredient::query()->where('display_name', 'Stale component')->exists())->toBeFalse()
        ->and(WorkspaceIngredientCode::query()
            ->where('workspace_id', $workspaceA->id)
            ->where('ingredient_id', $platform->id)
            ->exists())->toBeFalse()
        ->and(WorkspaceIngredientGuidance::query()
            ->where('workspace_id', $workspaceA->id)
            ->where('ingredient_id', $platform->id)
            ->exists())->toBeFalse()
        ->and(Ingredient::query()->where('owner_type', OwnerType::Workspace)
            ->where('owner_id', $workspaceA->id)
            ->count())->toBe(0);
});

it('rejects destination tampering and access changes after an editor is mounted', function (): void {
    $owner = User::factory()->create();
    $workspaceA = Workspace::factory()->for($owner, 'owner')->create();
    $workspaceB = Workspace::factory()->create();
    WorkspaceMember::factory()->for($workspaceB)->for($owner)->create([
        'role' => WorkspaceMemberRole::Editor,
    ]);
    $owner->forceFill(['active_workspace_id' => $workspaceA->id])->save();
    $this->actingAs($owner);

    $tampered = Livewire::test(IngredientEditor::class)
        ->set('data.name', 'Tampered destination')
        ->set('data.category', 'other');
    expect(fn () => $tampered->set('destinationWorkspaceId', $workspaceB->id))
        ->toThrow(CannotUpdateLockedPropertyException::class);

    expect(Ingredient::query()->where('display_name', 'Tampered destination')->exists())
        ->toBeFalse();

    $member = User::factory()->create();
    WorkspaceMember::factory()->for($workspaceA)->for($member)->create([
        'role' => WorkspaceMemberRole::Editor,
    ]);
    $member->forceFill(['active_workspace_id' => $workspaceA->id])->save();
    $member->forgetAccessibleWorkspaceIds();
    $ingredient = Ingredient::factory()->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspaceA->id,
        'workspace_id' => $workspaceA->id,
        'visibility' => Visibility::Private,
        'display_name' => 'Before access loss',
    ]);
    $this->actingAs($member);
    $mounted = Livewire::test(IngredientEditor::class, ['ingredient' => $ingredient])
        ->set('data.name', 'Must not persist');
    WorkspaceMember::withoutGlobalScopes()
        ->where('workspace_id', $workspaceA->id)
        ->where('user_id', $member->id)
        ->delete();

    expect(WorkspaceMember::withoutGlobalScopes()
        ->where('workspace_id', $workspaceA->id)
        ->where('user_id', $member->id)
        ->exists())->toBeFalse();

    $mounted->call('save')->assertHasErrors('data');

    expect($ingredient->refresh()->display_name)->toBe('Before access loss');
});

it('rejects every editor write after a member is downgraded without creating replacements', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $editor = User::factory()->create(['active_workspace_id' => $workspace->id]);
    $membership = WorkspaceMember::factory()->for($workspace)->for($editor)->create([
        'role' => WorkspaceMemberRole::Editor,
    ]);
    $platform = Ingredient::factory()->create([
        'owner_type' => null,
        'owner_id' => null,
        'workspace_id' => null,
        'is_active' => true,
    ]);
    $privateIngredient = Ingredient::factory()->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
        'visibility' => Visibility::Private,
        'display_name' => 'Before downgrade',
    ]);

    $this->actingAs($editor);

    $platformEditor = Livewire::test(IngredientEditor::class, ['ingredient' => $platform])
        ->set('workspaceMaterialCode', 'DOWNGRADE-01')
        ->set('workspaceGuidance.html', '<p>Downgrade guidance</p>');
    $privateEditor = Livewire::test(IngredientEditor::class, ['ingredient' => $privateIngredient])
        ->set('data.name', 'Must not persist');
    $inlineEditor = Livewire::test(IngredientEditor::class)
        ->set('data.ingredient_structure', 'blend')
        ->set('quickComponentName', 'Must not create')
        ->set('quickComponentCategory', 'other');
    $index = Livewire::test(IngredientsIndex::class);
    $signature = $index->instance()->duplicateDestinationSignature();

    WorkspaceMember::withoutGlobalScopes()
        ->whereKey($membership->id)
        ->update(['role' => WorkspaceMemberRole::Viewer->value]);

    $privateEditor->call('save')->assertHasErrors('data');
    $platformEditor
        ->call('saveWorkspaceMaterialCode')
        ->assertHasErrors('workspaceMaterialCode')
        ->call('saveWorkspaceGuidance')
        ->assertHasErrors('workspaceGuidance.html');
    $inlineEditor->call('createAndAddComponent')->assertHasErrors('quickComponentName');

    $this->postJson(route('ingredients.duplicate'), [
        'ingredient_id' => $platform->id,
        'destination_workspace_id' => $workspace->id,
        'destination_workspace_signature' => $signature,
    ])->assertForbidden();

    expect($privateIngredient->refresh()->display_name)->toBe('Before downgrade')
        ->and(Ingredient::query()->where('display_name', 'Must not create')->exists())->toBeFalse()
        ->and(WorkspaceIngredientCode::query()
            ->where('workspace_id', $workspace->id)
            ->where('ingredient_id', $platform->id)
            ->exists())->toBeFalse()
        ->and(WorkspaceIngredientGuidance::query()
            ->where('workspace_id', $workspace->id)
            ->where('ingredient_id', $platform->id)
            ->exists())->toBeFalse();
});

it('rejects an opened platform ingredient after it is deactivated without creating a replacement', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $platform = Ingredient::factory()->create([
        'owner_type' => null,
        'owner_id' => null,
        'workspace_id' => null,
        'is_active' => true,
    ]);

    $owner->forceFill(['active_workspace_id' => $workspace->id])->save();
    $owner->forgetAccessibleWorkspaceIds();
    $this->actingAs($owner);

    $editor = Livewire::test(IngredientEditor::class, ['ingredient' => $platform])
        ->set('data.name', 'Deactivated replacement')
        ->set('workspaceMaterialCode', 'INACTIVE-01')
        ->set('workspaceGuidance.html', '<p>Inactive guidance</p>');
    $index = Livewire::test(IngredientsIndex::class);
    $signature = $index->instance()->duplicateDestinationSignature();

    $platform->update(['is_active' => false]);

    $editor->call('save')->assertHasErrors('data');
    $editor
        ->call('saveWorkspaceMaterialCode')
        ->assertHasErrors('workspaceMaterialCode')
        ->call('saveWorkspaceGuidance')
        ->assertHasErrors('workspaceGuidance.html');

    $this->postJson(route('ingredients.duplicate'), [
        'ingredient_id' => $platform->id,
        'destination_workspace_id' => $workspace->id,
        'destination_workspace_signature' => $signature,
    ])->assertForbidden();

    expect(Ingredient::query()->where('display_name', 'Deactivated replacement')->exists())->toBeFalse()
        ->and(WorkspaceIngredientCode::query()
            ->where('workspace_id', $workspace->id)
            ->where('ingredient_id', $platform->id)
            ->exists())->toBeFalse()
        ->and(WorkspaceIngredientGuidance::query()
            ->where('workspace_id', $workspace->id)
            ->where('ingredient_id', $platform->id)
            ->exists())->toBeFalse();
});

it('rejects duplicate requests that omit the captured destination after a workspace switch', function (): void {
    $user = User::factory()->create();
    $workspaceA = Workspace::factory()->for($user, 'owner')->create();
    $workspaceB = Workspace::factory()->create();
    WorkspaceMember::factory()->for($workspaceB)->for($user)->create([
        'role' => WorkspaceMemberRole::Editor,
    ]);
    $platform = Ingredient::factory()->create([
        'owner_type' => null,
        'owner_id' => null,
        'workspace_id' => null,
        'is_active' => true,
        'display_name' => 'Omitted destination source',
    ]);
    $asset = MediaAsset::factory()->ready()->create([
        'workspace_id' => $workspaceA->id,
        'uploaded_by_user_id' => $user->id,
    ]);
    MediaAssetUsage::factory()->create([
        'media_asset_id' => $asset->id,
        'usable_type' => Ingredient::class,
        'usable_id' => $platform->id,
        'role' => MediaAssetUsageRole::IngredientMain,
    ]);
    WorkspaceIngredientGuidance::factory()->create([
        'workspace_id' => $workspaceA->id,
        'ingredient_id' => $platform->id,
        'created_by_user_id' => $user->id,
        'updated_by_user_id' => $user->id,
    ]);
    WorkspaceIngredientCode::factory()->create([
        'workspace_id' => $workspaceA->id,
        'ingredient_id' => $platform->id,
    ]);

    $user->forceFill(['active_workspace_id' => $workspaceA->id])->save();
    $user->forgetAccessibleWorkspaceIds();
    $this->actingAs($user);

    Livewire::test(IngredientsIndex::class)
        ->assertSet('destinationWorkspaceId', $workspaceA->id);

    $user->forceFill(['active_workspace_id' => $workspaceB->id])->save();
    $user->forgetAccessibleWorkspaceIds();

    $before = [
        'ingredients' => Ingredient::query()->count(),
        'guidance' => WorkspaceIngredientGuidance::query()->count(),
        'media_usages' => MediaAssetUsage::query()->count(),
        'media_assets' => MediaAsset::query()->count(),
        'codes' => WorkspaceIngredientCode::query()->count(),
    ];

    $this->postJson(route('ingredients.duplicate'), [
        'ingredient_id' => $platform->id,
    ])
        ->assertForbidden()
        ->assertJsonPath('message', __('ingredients.editor.validation.stale_workspace'));

    expect(Ingredient::query()
        ->where('owner_type', OwnerType::Workspace)
        ->where('owner_id', $workspaceB->id)
        ->where('display_name', 'Omitted destination source')
        ->exists())->toBeFalse()
        ->and([
            'ingredients' => Ingredient::query()->count(),
            'guidance' => WorkspaceIngredientGuidance::query()->count(),
            'media_usages' => MediaAssetUsage::query()->count(),
            'media_assets' => MediaAsset::query()->count(),
            'codes' => WorkspaceIngredientCode::query()->count(),
        ])->toBe($before);
});

it('binds a first-workspace inline creation before allowing more writes', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $editor = Livewire::test(IngredientEditor::class)
        ->set('data.ingredient_structure', 'blend')
        ->set('data.name', 'First Workspace Blend')
        ->set('data.category', 'other')
        ->set('quickComponentName', 'First Inline Component')
        ->set('quickComponentCategory', 'other')
        ->call('createAndAddComponent')
        ->assertHasNoErrors()
        ->set('quickComponentName', 'Second Inline Component')
        ->set('quickComponentCategory', 'other')
        ->call('createAndAddComponent')
        ->assertHasNoErrors();

    $editor
        ->set('data.components.0.percentage_in_parent', 50)
        ->set('data.components.1.percentage_in_parent', 50)
        ->call('save')
        ->assertHasNoErrors();

    $workspace = $user->refresh()->company();

    expect($workspace)->toBeInstanceOf(Workspace::class)
        ->and($editor->instance()->destinationWorkspaceId)->toBe($workspace->id)
        ->and(Ingredient::query()->where('display_name', 'First Workspace Blend')->exists())->toBeTrue();
});
