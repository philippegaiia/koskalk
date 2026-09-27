<?php

use App\Enums\OwnerType;
use App\Enums\WorkspaceMemberRole;
use App\Livewire\Dashboard\PackagingItemEditor;
use App\Livewire\Dashboard\PackagingItemsIndex;
use App\Models\Ingredient;
use App\Models\PackagingItem;
use App\Models\Plan;
use App\Models\User;
use App\Models\UserEntitlement;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\IngredientFormulaMutationService;
use App\Services\MediaStorage;
use App\Services\PackagingItemAuthoringService;
use App\Services\PackagingItemFormulaMutationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('enforces packaging write and delete roles inside authoring services', function (WorkspaceMemberRole $role, string $operation, bool $allowed): void {
    Storage::fake(MediaStorage::userDisk());
    $workspace = Workspace::factory()->create();
    UserEntitlement::factory()->for($workspace->owner)->for(Plan::factory()->create(['allows_collaboration' => true]))->create();
    $actor = $role === WorkspaceMemberRole::Owner ? $workspace->owner : User::factory()->create();
    if ($role !== WorkspaceMemberRole::Owner) {
        WorkspaceMember::factory()->for($workspace)->for($actor)->create(['role' => $role]);
    }
    $item = PackagingItem::factory()->for($workspace)->create(['name' => 'Original']);
    $service = app(PackagingItemAuthoringService::class);
    $action = fn () => match ($operation) {
        'create' => $service->create(['name' => 'New', 'unit_cost' => '1.20'], $actor),
        'update' => $service->update($item, ['name' => 'Changed', 'unit_cost' => '1.20'], $actor),
        'price' => $service->updateUnitCost($item, $actor, '1.20'),
        'delete' => $service->delete($item, $actor),
        'remove' => app(PackagingItemFormulaMutationService::class)->removeEverywhereAndDelete($actor, $item),
    };

    if (! $allowed) {
        expect($action)->toThrow(AuthorizationException::class);
        expect($item->fresh()->name)->toBe('Original');
        expect(PackagingItem::query()->count())->toBe(1);

        return;
    }

    $action();

    if (in_array($operation, ['delete', 'remove'], true)) {
        $this->assertModelMissing($item);
    } elseif ($operation === 'create') {
        expect(PackagingItem::query()->where('name', 'New')->exists())->toBeTrue();
    } else {
        expect((string) $item->fresh()->unit_cost)->toBe('1.200000000000');
    }
})->with([
    'owner create' => [WorkspaceMemberRole::Owner, 'create', true],
    'admin delete' => [WorkspaceMemberRole::Admin, 'delete', true],
    'editor update' => [WorkspaceMemberRole::Editor, 'update', true],
    'editor price' => [WorkspaceMemberRole::Editor, 'price', true],
    'editor delete' => [WorkspaceMemberRole::Editor, 'delete', false],
    'editor remove' => [WorkspaceMemberRole::Editor, 'remove', false],
    'viewer create' => [WorkspaceMemberRole::Viewer, 'create', false],
    'viewer update' => [WorkspaceMemberRole::Viewer, 'update', false],
    'viewer price' => [WorkspaceMemberRole::Viewer, 'price', false],
    'viewer delete' => [WorkspaceMemberRole::Viewer, 'delete', false],
]);

it('rechecks selection and revoked membership on stale packaging instances', function (): void {
    $workspace = Workspace::factory()->create();
    UserEntitlement::factory()->for($workspace->owner)->for(Plan::factory()->create(['allows_collaboration' => true]))->create();
    $actor = User::factory()->create();
    $membership = WorkspaceMember::factory()->for($workspace)->for($actor)->create(['role' => WorkspaceMemberRole::Editor]);
    $actor->forceFill(['active_workspace_id' => $workspace->id])->save();
    $item = PackagingItem::factory()->for($workspace)->create(['name' => 'Original']);
    $service = app(PackagingItemAuthoringService::class);
    $service->updateUnitCost($item, $actor, '1.20');
    $other = Workspace::factory()->for($actor, 'owner')->create();
    User::query()->whereKey($actor->id)->update(['active_workspace_id' => $other->id]);

    expect(fn () => $service->updateUnitCost($item, $actor, '9.99'))->toThrow(AuthorizationException::class);

    User::query()->whereKey($actor->id)->update(['active_workspace_id' => $workspace->id]);
    $membership->delete();
    expect(fn () => $service->update($item, ['name' => 'Changed', 'unit_cost' => '9.99'], $actor))->toThrow(AuthorizationException::class);
    expect($item->fresh()->name)->toBe('Original')->and((string) $item->fresh()->unit_cost)->toBe('1.200000000000');
});

it('rejects editor ingredient removal and replacement inside the destructive service', function (bool $replace): void {
    $workspace = Workspace::factory()->create();
    UserEntitlement::factory()->for($workspace->owner)->for(Plan::factory()->create(['allows_collaboration' => true]))->create();
    $editor = User::factory()->create();
    WorkspaceMember::factory()->for($workspace)->for($editor)->create(['role' => WorkspaceMemberRole::Editor]);
    $ingredient = Ingredient::factory()->create(['owner_type' => OwnerType::Workspace, 'owner_id' => $workspace->id, 'workspace_id' => $workspace->id, 'visibility' => 'private']);
    $replacement = Ingredient::factory()->create();
    $service = app(IngredientFormulaMutationService::class);

    expect(fn () => $replace
        ? $service->replaceEverywhereAndDelete($editor, $ingredient, $replacement)
        : $service->removeEverywhereAndDelete($editor, $ingredient))->toThrow(AuthorizationException::class);

    $this->assertModelExists($ingredient);
})->with([false, true]);

it('permits viewer packaging downloads only in the selected workspace and forbids creation', function (): void {
    $disk = MediaStorage::userDisk();
    Storage::fake($disk);
    $workspace = Workspace::factory()->create();
    UserEntitlement::factory()->for($workspace->owner)->for(Plan::factory()->create(['allows_collaboration' => true]))->create();
    $viewer = User::factory()->create();
    WorkspaceMember::factory()->for($workspace)->for($viewer)->create(['role' => WorkspaceMemberRole::Viewer]);
    $viewer->forceFill(['active_workspace_id' => $workspace->id])->save();
    $item = PackagingItem::factory()->for($workspace)->create();
    $path = MediaStorage::packagingItemDirectory($item, 'featured-images').'/image.webp';
    $item->update(['featured_image_path' => $path]);
    Storage::disk($disk)->put($path, 'private image');
    $url = route('packaging-items.media', ['packagingItem' => $item, 'path' => $path]);

    $this->actingAs($viewer)->get($url)->assertOk()->assertStreamedContent('private image');
    $this->get(route('packaging-items.create'))->assertForbidden();

    $other = Workspace::factory()->for($viewer, 'owner')->create();
    $viewer->forceFill(['active_workspace_id' => $other->id])->save();

    $this->get($url)->assertNotFound();
    $this->get(route('packaging-items.edit', $item))->assertNotFound();
});

it('renders viewer packaging without authoring controls and rejects a crafted price update', function (): void {
    $workspace = Workspace::factory()->create();
    UserEntitlement::factory()->for($workspace->owner)->for(Plan::factory()->create(['allows_collaboration' => true]))->create();
    $viewer = User::factory()->create(['active_workspace_id' => $workspace->id]);
    WorkspaceMember::factory()->for($workspace)->for($viewer)->create(['role' => WorkspaceMemberRole::Viewer]);
    $item = PackagingItem::factory()->for($workspace)->create(['name' => 'Visible jar']);

    Livewire::actingAs($viewer)->test(PackagingItemsIndex::class)
        ->assertSee('Visible jar')
        ->assertViewHas('canCreateItems', false)
        ->assertViewHas('canUpdateItems', false)
        ->assertViewHas('canDeleteItems', false)
        ->assertDontSeeHtml('wire:change="updateUnitCost(')
        ->assertDontSeeHtml('wire:click="confirmDelete(')
        ->call('updateUnitCost', $item->id, '9.99')
        ->assertForbidden();

    expect($item->fresh()->currentPrice)->toBeNull();
});

it('rejects stale packaging screens after their workspace authority changes', function (string $screen, string $change): void {
    $workspace = Workspace::factory()->create();
    $entitlement = UserEntitlement::factory()->for($workspace->owner)->for(Plan::factory()->create(['allows_collaboration' => true]))->create();
    $actor = User::factory()->create(['active_workspace_id' => $workspace->id]);
    $membership = WorkspaceMember::factory()->for($workspace)->for($actor)->create(['role' => WorkspaceMemberRole::Editor]);
    $item = PackagingItem::factory()->for($workspace)->create(['name' => 'Original jar']);
    $page = Livewire::actingAs($actor)->test(
        $screen === 'index' ? PackagingItemsIndex::class : PackagingItemEditor::class,
        $screen === 'index' ? [] : ['packagingItem' => $screen === 'create' ? null : $item],
    )->assertOk();
    if ($screen !== 'index') {
        $page->fillForm(['name' => 'Changed jar', 'unit_cost' => '9.99']);
    }

    match ($change) {
        'demotion' => $membership->update(['role' => WorkspaceMemberRole::Viewer]),
        'removal' => $membership->delete(),
        'expiry' => $entitlement->update(['ends_at' => now()->subMinute()]),
        'selection' => $actor->forceFill(['active_workspace_id' => Workspace::factory()->for($actor, 'owner')->create()->id])->save(),
    };

    if ($screen === 'index') {
        $page->call('updateUnitCost', $item->id, '9.99')->assertForbidden();
    } else {
        $page->call('save')->assertForbidden();
    }

    expect($item->fresh()->name)->toBe('Original jar')
        ->and($item->fresh()->currentPrice)->toBeNull();
    expect(PackagingItem::query()->count())->toBe(1);
})->with(['index', 'editor', 'create'])->with(['demotion', 'removal', 'expiry', 'selection']);
