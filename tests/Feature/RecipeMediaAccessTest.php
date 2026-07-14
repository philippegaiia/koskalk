<?php

use App\Models\ProductFamily;
use App\Models\Recipe;
use App\Models\User;
use App\Models\Workspace;
use App\OwnerType;
use App\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'media.recipe_disk' => 'local',
        'media.recipe_visibility' => 'private',
    ]);
    Storage::fake('local');
});

it('serves formula media only to the workspace owner', function () {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $recipe = Recipe::withoutGlobalScopes()->create([
        'product_family_id' => ProductFamily::factory()->create()->id,
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
        'created_by' => $owner->id,
        'visibility' => Visibility::Private,
        'name' => 'Confidential formula',
    ]);
    $path = 'recipes/'.$recipe->public_id.'/featured-images/private.webp';
    $recipe->update(['featured_image_path' => $path]);
    Storage::disk('local')->put($path, 'private-image');

    $mediaUrl = route('recipes.media', ['recipe' => $recipe, 'path' => $path]);

    expect($recipe->featuredImageUrl())->toBe($mediaUrl)
        ->and($mediaUrl)->not->toContain('/storage/');

    $response = $this->actingAs($owner)
        ->get($mediaUrl)
        ->assertOk();

    expect($response->streamedContent())->toBe('private-image');

    $this->actingAs(User::factory()->create())
        ->get($mediaUrl)
        ->assertNotFound();
});

it('rejects numeric recipe keys and unreferenced private paths', function () {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $recipe = Recipe::withoutGlobalScopes()->create([
        'product_family_id' => ProductFamily::factory()->create()->id,
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
        'created_by' => $owner->id,
        'visibility' => Visibility::Private,
        'name' => 'Confidential formula',
    ]);
    $unreferencedPath = 'recipes/'.$recipe->public_id.'/featured-images/unreferenced.webp';
    Storage::disk('local')->put($unreferencedPath, 'secret');

    $this->actingAs($owner);

    $this->get('/dashboard/recipes/'.$recipe->id.'/media/'.$unreferencedPath)
        ->assertNotFound();
    $this->get(route('recipes.media', [
        'recipe' => $recipe,
        'path' => $unreferencedPath,
    ]))->assertNotFound();
});
