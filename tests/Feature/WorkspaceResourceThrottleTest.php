<?php

use App\Enums\OwnerType;
use App\Enums\ProcurementStage;
use App\Enums\WorkspaceMemberRole;
use App\Models\Plan;
use App\Models\ProductFamily;
use App\Models\ProductionBatch;
use App\Models\PurchaseOrder;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\User;
use App\Models\UserEntitlement;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Models\WorkspaceProductionEntitlement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['workspaces.collaboration_enabled' => true]);
});

/** @return array{owner: User, member: User, workspace: Workspace, recipe: Recipe, version: RecipeVersion} */
function resourceThrottleCompany(): array
{
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $owner->forceFill(['active_workspace_id' => $workspace->id])->save();
    $plan = Plan::factory()->hasLimit('saved_formula_history', 10)->create(['allows_collaboration' => true]);
    UserEntitlement::factory()->for($owner)->for($plan)->create();
    WorkspaceProductionEntitlement::factory()->for($workspace)->create();
    $member = User::factory()->create(['active_workspace_id' => $workspace->id]);
    WorkspaceMember::factory()->for($workspace)->for($member, 'user')->create(['role' => WorkspaceMemberRole::Editor]);
    $family = ProductFamily::query()->where('slug', 'soap')->first() ?? ProductFamily::factory()->create(['slug' => 'soap']);
    $recipe = Recipe::factory()->create(['product_family_id' => $family->id, 'workspace_id' => $workspace->id, 'owner_type' => OwnerType::Workspace, 'owner_id' => $workspace->id]);
    $version = RecipeVersion::factory()->for($recipe)->create(['workspace_id' => $workspace->id, 'owner_type' => OwnerType::Workspace, 'owner_id' => $workspace->id, 'is_current' => false, 'saved_at' => now()]);

    return compact('owner', 'member', 'workspace', 'recipe', 'version');
}

it('shares export capacity across members and formats while isolating companies and resetting the window', function (): void {
    $this->freezeTime();
    config(['workspaces.resource_limits.exports.workspace_per_minute' => 1]);
    $first = resourceThrottleCompany();
    $other = resourceThrottleCompany();
    $response = $this->actingAs($first['owner'])->get(route('recipes.export.csv', $first['recipe']))->assertOk();
    expect($response->streamedContent())->not->toBeEmpty();
    $this->actingAs($first['member'])->get(route('recipes.export.xlsx', $first['recipe']))->assertStatus(429)->assertHeader('Retry-After');
    $this->actingAs($other['owner'])->get(route('recipes.export.csv', $other['recipe']))->assertOk();
    $this->travel(61)->seconds();
    $this->actingAs($first['member'])->get(route('recipes.export.xlsx', $first['recipe']))->assertOk();
});

it('preserves the actor export limit across formats independently of a larger company budget', function (): void {
    config(['workspaces.resource_limits.exports.actor_per_minute' => 1, 'workspaces.resource_limits.exports.workspace_per_minute' => 10]);
    $company = resourceThrottleCompany();
    $this->actingAs($company['owner'])->get(route('recipes.export.csv', $company['recipe']))->assertOk();
    $this->get(route('recipes.export.xlsx', $company['recipe']))->assertStatus(429);
    $this->actingAs($company['member'])->get(route('recipes.export.xlsx', $company['recipe']))->assertOk();
});

it('never charges a foreign company from a requested record identifier', function (): void {
    config(['workspaces.resource_limits.exports.workspace_per_minute' => 1]);
    $first = resourceThrottleCompany();
    $other = resourceThrottleCompany();
    $this->actingAs($first['owner'])->get(route('recipes.export.csv', $other['recipe']))->assertNotFound();
    $this->actingAs($other['owner'])->get(route('recipes.export.csv', $other['recipe']))->assertOk();
});

it('shares print capacity across members and every existing print route family', function (string $routeName): void {
    config(['workspaces.resource_limits.prints.workspace_per_minute' => 1]);
    $company = resourceThrottleCompany();
    $parameters = ['recipe' => $company['recipe'], 'version' => $company['version']];
    if ($routeName === 'production-batches.print') {
        $parameters = ['productionBatch' => ProductionBatch::factory()->create(['workspace_id' => $company['workspace']->id, 'user_id' => $company['owner']->id, 'recipe_id' => $company['recipe']->id, 'recipe_version_id' => $company['version']->id])];
    } elseif ($routeName === 'production-bench.purchasing.documents.print') {
        $parameters = ['purchaseOrder' => PurchaseOrder::factory()->for($company['workspace'])->create(['stage' => ProcurementStage::PurchaseOrder, 'issued_at' => now(), 'purchase_order_snapshot' => ['reference' => 'PO-TEST', 'supplier' => ['name' => 'Supplier'], 'lines' => []]])];
    }
    $this->actingAs($company['owner'])->get(route('recipes.print.recipe', $company['recipe']))->assertOk();
    $this->actingAs($company['member'])->get(route($routeName, $parameters))->assertStatus(429);
    $this->get(route('recipes.export.csv', $company['recipe']))->assertOk();
})->with([
    'recipes.print.recipe', 'recipes.print.production', 'recipes.print.details', 'recipes.print.technical', 'recipes.print.costing',
    'recipes.legacy.print.recipe', 'recipes.legacy.print.details', 'production-batches.print', 'production-bench.purchasing.documents.print',
]);

it('throttles temporary upload storage across members before another source is written', function (): void {
    $this->freezeTime();
    Storage::fake('tmp-for-tests');
    config(['livewire.temporary_file_upload.disk' => 'local', 'workspaces.resource_limits.temporary_uploads.workspace_per_minute' => 1]);
    $company = resourceThrottleCompany();
    $url = URL::temporarySignedRoute('livewire.upload-file', now()->addMinutes(5), absolute: false);
    $this->actingAs($company['owner'])->post($url, ['files' => [UploadedFile::fake()->image('one.png')]])->assertOk()->assertJsonCount(1, 'paths');
    $storedFiles = Storage::disk('tmp-for-tests')->allFiles();
    expect($storedFiles)->not->toBeEmpty();
    $this->actingAs($company['member'])->post($url, ['files' => [UploadedFile::fake()->image('two.png')]])->assertStatus(429);
    expect(Storage::disk('tmp-for-tests')->allFiles())->toBe($storedFiles);
    $this->travel(61)->seconds();
    $this->post($url, ['files' => [UploadedFile::fake()->image('later.png')]])->assertOk();
    expect(count(Storage::disk('tmp-for-tests')->allFiles()))->toBeGreaterThan(count($storedFiles));
});

it('keeps the signed temporary upload endpoint bounded for guests', function (): void {
    Storage::fake('tmp-for-tests');
    config(['livewire.temporary_file_upload.disk' => 'local', 'workspaces.resource_limits.temporary_uploads.guest_per_minute' => 1]);
    $url = URL::temporarySignedRoute('livewire.upload-file', now()->addMinutes(5), absolute: false);
    $this->post($url, ['files' => [UploadedFile::fake()->image('one.png')]])->assertOk();
    $storedFiles = Storage::disk('tmp-for-tests')->allFiles();
    expect($storedFiles)->not->toBeEmpty();
    $this->post($url, ['files' => [UploadedFile::fake()->image('two.png')]])->assertStatus(429);
    expect(Storage::disk('tmp-for-tests')->allFiles())->toBe($storedFiles);
});
