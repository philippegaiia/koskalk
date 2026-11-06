<?php

use App\Enums\OwnerType;
use App\Enums\Visibility;
use App\Enums\WorkspaceMemberRole;
use App\Livewire\Dashboard\ProductOrganizationManager;
use App\Models\Brand;
use App\Models\Plan;
use App\Models\ProductCollection;
use App\Models\ProductionRun;
use App\Models\Recipe;
use App\Models\User;
use App\Models\UserEntitlement;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\ProductOrganizationManagementService;
use App\Services\ProductOrganizationService;
use App\Services\RecipeEditingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('reuses renamed groups when creating the same name again', function () {
    $fixture = organizationManagementFixture();
    $management = app(ProductOrganizationManagementService::class);
    $creation = app(ProductOrganizationService::class);
    $management->renameBrand($fixture['user'], $fixture['workspace']->id, $fixture['brand']->id, 'Renamed umbrella');
    $management->saveCollection($fixture['user'], $fixture['workspace']->id, $fixture['collection']->id, 'Renamed range', $fixture['brand']->id);

    expect($creation->createBrand($fixture['user'], ' renamed UMBRELLA ')->id)->toBe($fixture['brand']->id);
    expect($creation->createCollection($fixture['user'], $fixture['brand']->id, ' renamed RANGE ')->id)->toBe($fixture['collection']->id);
});

it('deletes unused groups without requiring product clearing', function () {
    $fixture = organizationManagementFixture();
    $unusedBrand = Brand::factory()->create(['workspace_id' => $fixture['workspace']->id]);
    $unusedCollection = ProductCollection::factory()->create(['workspace_id' => $fixture['workspace']->id, 'brand_id' => $unusedBrand->id]);
    $service = app(ProductOrganizationManagementService::class);
    $service->deleteCollection($fixture['user'], $fixture['workspace']->id, $unusedCollection->id);
    $service->deleteBrand($fixture['user'], $fixture['workspace']->id, $unusedBrand->id);
    $this->assertModelMissing($unusedCollection);
    $this->assertModelMissing($unusedBrand);
});

it('moves a collection and its products together while retaining production labels', function () {
    $fixture = organizationManagementFixture();
    $target = Brand::factory()->create(['workspace_id' => $fixture['workspace']->id, 'name' => 'Target']);
    $run = ProductionRun::factory()->create(['workspace_id' => $fixture['workspace']->id, 'brand_id' => $fixture['brand']->id, 'brand_name_snapshot' => 'Old brand', 'collection_name_snapshot' => 'Old range']);
    $revision = $fixture['product']->edit_revision;

    app(ProductOrganizationManagementService::class)->saveCollection($fixture['user'], $fixture['workspace']->id, $fixture['collection']->id, 'Moved range', $target->id);

    expect($fixture['collection']->fresh()->brand_id)->toBe($target->id);
    expect($fixture['product']->fresh()->brand_id)->toBe($target->id)
        ->and($fixture['product']->fresh()->product_collection_id)->toBe($fixture['collection']->id)
        ->and($fixture['product']->fresh()->edit_revision)->toBe($revision + 1);
    expect($run->fresh()->brand_name_snapshot)->toBe('Old brand')
        ->and($run->fresh()->collection_name_snapshot)->toBe('Old range');
});

it('requires explicit clearing before deleting a used brand and its collections', function () {
    $fixture = organizationManagementFixture();
    $service = app(ProductOrganizationManagementService::class);
    expect(fn () => $service->deleteBrand($fixture['user'], $fixture['workspace']->id, $fixture['brand']->id))->toThrow(ValidationException::class);
    $this->assertModelExists($fixture['brand']);

    $run = ProductionRun::factory()->create(['workspace_id' => $fixture['workspace']->id, 'brand_id' => $fixture['brand']->id, 'product_collection_id' => $fixture['collection']->id, 'brand_name_snapshot' => 'Original umbrella', 'collection_name_snapshot' => 'Original range']);
    $service->deleteBrand($fixture['user'], $fixture['workspace']->id, $fixture['brand']->id, clearAssignments: true);
    $this->assertModelMissing($fixture['brand']);
    $this->assertModelMissing($fixture['collection']);
    expect($run->fresh()->brand_id)->toBeNull()->and($run->fresh()->product_collection_id)->toBeNull()
        ->and($run->fresh()->brand_name_snapshot)->toBe('Original umbrella')->and($run->fresh()->collection_name_snapshot)->toBe('Original range');
    expect($fixture['product']->fresh()->brand_id)->toBeNull()
        ->and($fixture['product']->fresh()->product_collection_id)->toBeNull();
});

it('reassigns products before deleting a used collection', function () {
    $fixture = organizationManagementFixture();
    $targetBrand = Brand::factory()->create(['workspace_id' => $fixture['workspace']->id]);
    $target = ProductCollection::factory()->create(['workspace_id' => $fixture['workspace']->id, 'brand_id' => $targetBrand->id]);
    app(ProductOrganizationManagementService::class)->deleteCollection($fixture['user'], $fixture['workspace']->id, $fixture['collection']->id, targetCollectionId: $target->id);
    $this->assertModelMissing($fixture['collection']);
    expect($fixture['product']->fresh()->brand_id)->toBe($targetBrand->id)
        ->and($fixture['product']->fresh()->product_collection_id)->toBe($target->id);
});

it('rejects bulk organization changes while an affected product is protected', function (string $protection) {
    $fixture = organizationManagementFixture();
    $protectedProduct = Recipe::factory()->create(['workspace_id' => $fixture['workspace']->id, 'owner_type' => OwnerType::Workspace, 'owner_id' => $fixture['workspace']->id, 'visibility' => Visibility::Private, 'brand_id' => $fixture['brand']->id, 'product_collection_id' => $fixture['collection']->id]);
    if ($protection === 'locked') {
        $protectedProduct->update(['locked_at' => now(), 'locked_by' => $fixture['user']->id]);
    } else {
        app(RecipeEditingService::class)->acquire($protectedProduct, $fixture['user'], (string) Str::uuid());
    }
    expect(fn () => app(ProductOrganizationManagementService::class)->deleteBrand($fixture['user'], $fixture['workspace']->id, $fixture['brand']->id, clearAssignments: true))->toThrow(ValidationException::class);
    $this->assertModelExists($fixture['brand']);
    expect($fixture['product']->fresh()->brand_id)->toBe($fixture['brand']->id);
})->with(['locked', 'editing']);

it('rejects duplicate names and foreign workspace groups', function () {
    $fixture = organizationManagementFixture();
    Brand::factory()->create(['workspace_id' => $fixture['workspace']->id, 'name' => 'Existing']);
    $foreign = Brand::factory()->create();
    $service = app(ProductOrganizationManagementService::class);
    expect(fn () => $service->renameBrand($fixture['user'], $fixture['workspace']->id, $fixture['brand']->id, 'existing'))->toThrow(ValidationException::class);
    expect(fn () => $service->saveCollection($fixture['user'], $fixture['workspace']->id, $fixture['collection']->id, 'New range', $foreign->id))->toThrow(ValidationException::class);
    expect($fixture['collection']->fresh()->brand_id)->toBe($fixture['brand']->id);
});

it('creates and edits organization through the management page', function () {
    $fixture = organizationManagementFixture();
    $this->actingAs($fixture['user'])->get(route('product-organization.index'))->assertOk();
    Livewire::actingAs($fixture['user'])->test(ProductOrganizationManager::class)
        ->assertSee($fixture['brand']->name)
        ->call('createBrand')
        ->set('data.name', 'Created umbrella')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('Created umbrella')
        ->call('editBrand', $fixture['brand']->id)
        ->set('data.name', 'Renamed umbrella')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('Renamed umbrella')
        ->call('createCollection', $fixture['brand']->id)
        ->set('data.name', 'Created range')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('Created range');
    $this->assertDatabaseHas('brands', ['workspace_id' => $fixture['workspace']->id, 'name' => 'Created umbrella']);
});

it('requires confirmation in the deletion form and keeps the product brand when clearing a collection', function () {
    $fixture = organizationManagementFixture();
    Livewire::actingAs($fixture['user'])->test(ProductOrganizationManager::class)
        ->call('confirmDelete', 'collection', $fixture['collection']->id)
        ->call('deleteGroup')
        ->assertHasErrors(['deletionData.clear_assignments'])
        ->set('deletionData.clear_assignments', true)
        ->call('deleteGroup')
        ->assertHasNoErrors();
    $this->assertModelMissing($fixture['collection']);
    expect($fixture['product']->fresh()->brand_id)->toBe($fixture['brand']->id)
        ->and($fixture['product']->fresh()->product_collection_id)->toBeNull();
});

it('scopes the management list and escapes group names', function () {
    $fixture = organizationManagementFixture();
    $fixture['brand']->update(['name' => '<img src=x onerror=alert(1)>']);
    Brand::factory()->create(['name' => 'Private foreign umbrella']);
    Livewire::actingAs($fixture['user'])->test(ProductOrganizationManager::class)
        ->assertSee('<img src=x onerror=alert(1)>')
        ->assertDontSee('<img src=x onerror=alert(1)>', false)
        ->assertDontSee('Private foreign umbrella');
});

it('keeps viewers read only and rejects mounted workspace changes', function () {
    $fixture = organizationManagementFixture();
    UserEntitlement::factory()->for($fixture['user'])->for(Plan::factory()->create(['allows_collaboration' => true]))->create();
    $viewer = User::factory()->create(['active_workspace_id' => $fixture['workspace']->id]);
    WorkspaceMember::factory()->create(['workspace_id' => $fixture['workspace']->id, 'user_id' => $viewer->id, 'role' => WorkspaceMemberRole::Viewer]);
    Livewire::actingAs($viewer)->test(ProductOrganizationManager::class)->assertSee($fixture['brand']->name)->call('createBrand')->assertForbidden();
    $component = Livewire::actingAs($fixture['user'])->test(ProductOrganizationManager::class);
    $other = Workspace::factory()->create(['owner_user_id' => $fixture['user']->id]);
    $fixture['user']->forceFill(['active_workspace_id' => $other->id])->save();
    $component->call('createBrand')->assertForbidden();
});

function organizationManagementFixture(): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_user_id' => $user->id]);
    $user->forceFill(['active_workspace_id' => $workspace->id])->save();
    test()->actingAs($user);
    $brand = Brand::factory()->create(['workspace_id' => $workspace->id, 'name' => 'Original umbrella']);
    $collection = ProductCollection::factory()->create(['workspace_id' => $workspace->id, 'brand_id' => $brand->id, 'name' => 'Original range']);
    $product = Recipe::factory()->create(['workspace_id' => $workspace->id, 'owner_type' => OwnerType::Workspace, 'owner_id' => $workspace->id, 'visibility' => Visibility::Private, 'brand_id' => $brand->id, 'product_collection_id' => $collection->id]);

    return compact('user', 'workspace', 'brand', 'collection', 'product');
}
