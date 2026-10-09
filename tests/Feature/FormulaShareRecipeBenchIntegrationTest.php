<?php

use App\Actions\FormulaSharing\AcceptFormulaShare;
use App\Enums\WorkspaceMemberRole;
use App\Livewire\Dashboard\FormulaShareCreate;
use App\Livewire\Dashboard\RecipeWorkbench;
use App\Models\FormulaShare;
use App\Models\RecipeVersionCosting;
use App\Models\User;
use App\Models\WorkspaceMember;
use App\Services\FormulaSharePreview;
use App\Services\RecipeEditingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\FormulaSharingFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['workspaces.formula_sharing.enabled' => true, 'workspaces.collaboration_enabled' => true]);
});

it('shares only the latest Saved formula while preserving source locks and editing reservations', function (string $family, bool $locked): void {
    $fixture = FormulaSharingFixtures::offer($family, options: []);
    $source = $fixture['recipe'];
    $owner = $fixture['owner'];
    $token = (string) Str::uuid();
    $editing = app(RecipeEditingService::class);
    $editing->acquire($source, $owner, $token);
    $source->versions()->withoutGlobalScopes()->where('is_current', true)->update(['batch_size' => '9999.000']);
    if ($locked) {
        $source->forceFill(['locked_at' => now(), 'locked_by' => $owner->id])->save();
    }
    $revision = $source->fresh()->edit_revision;
    $this->actingAs($owner);
    $this->get(route('recipes.saved', $source))->assertSee(route('formula-shares.create', $source));

    Livewire::test(FormulaShareCreate::class, ['recipe' => $source])
        ->set('data.recipient_address', $fixture['recipient']->public_id)
        ->call('resolveRecipient')->call('preview')->assertHasNoErrors()
        ->set('confirmed', true)->call('send')->assertHasNoErrors();

    $offer = FormulaShare::query()->latest('id')->firstOrFail();
    expect($offer->source_version_id)->toBe($fixture['saved']->id)
        ->and($offer->snapshot['formula']['batch_size'])->toBe('1000.125')
        ->and($source->fresh()->isLocked())->toBe($locked)
        ->and($source->fresh()->edit_revision)->toBe($revision)
        ->and($editing->status($source, $owner, $token)['status'])->toBe('acquired');
    $recipient = $fixture['recipient']->owner;
    $preview = app(FormulaSharePreview::class)->build($recipient, $offer, []);
    $received = app(AcceptFormulaShare::class)->handle($recipient, $offer, [], $preview['expected_hash']);
    expect($received->isLocked())->toBeFalse()
        ->and($received->versions()->withoutGlobalScopes()->where('is_current', false)->firstOrFail()->batch_size)->toBe('1000.125')
        ->and($editing->status($source, $owner, $token)['status'])->toBe('acquired');
})->with([
    'editable soap' => ['soap', false],
    'locked soap' => ['soap', true],
    'editable cosmetic' => ['cosmetic', false],
    'locked cosmetic' => ['cosmetic', true],
]);

it('protects an accepted Product with ordinary Recipe Bench leases and token-checked departure for its local editors', function (WorkspaceMemberRole $role): void {
    $fixture = FormulaSharingFixtures::offer('cosmetic', options: []);
    $owner = $fixture['recipient']->owner;
    $preview = app(FormulaSharePreview::class)->build($owner, $fixture['share'], []);
    $received = app(AcceptFormulaShare::class)->handle($owner, $fixture['share'], [], $preview['expected_hash']);
    $actor = $owner;
    if ($role !== WorkspaceMemberRole::Owner) {
        $owner->entitlements()->first()->plan->update(['allows_collaboration' => true]);
        $actor = User::factory()->create(['active_workspace_id' => $fixture['recipient']->id]);
        WorkspaceMember::factory()->for($fixture['recipient'])->for($actor)->create(['role' => $role]);
    }
    $this->actingAs($actor);
    $component = Livewire::test(RecipeWorkbench::class, ['recipe' => $received]);
    $component->set('data.description', '<p>Local editing.</p>')->call('saveRecipeContent')
        ->assertReturned(fn (array $response): bool => ! $response['ok'] && isset($response['errors']['editing_lease']));
    expect($received->fresh()->description)->toBeNull();
    $token = (string) Str::uuid();

    $component->call('beginEditing', $token)
        ->assertReturned(fn (array $response): bool => $response['editing']['status'] === 'acquired'
            && $response['editing']['release_url'] === route('recipes.editing.release', $received))
        ->call('saveRecipeContent')->assertReturned(fn (array $response): bool => $response['ok'])
        ->call('$refresh')->assertViewHas('workbench', fn (array $workbench): bool => $workbench['editing']['recipe_revision'] === 1);

    expect($received->fresh()->description)->toBe('<p>Local editing.</p>')
        ->and($received->fresh()->edit_revision)->toBe(1);
    $editing = app(RecipeEditingService::class);
    $this->postJson(route('recipes.editing.release', $received), ['token' => (string) Str::uuid()])->assertNoContent();
    expect($editing->status($received, $actor, $token)['status'])->toBe('acquired');
    $this->postJson(route('recipes.editing.release', $received), ['token' => $token])->assertNoContent();
    expect($editing->status($received, $actor, $token)['status'])->toBe('available');
    $component->set('data.description', '<p>Unreserved changes.</p>')->call('saveRecipeContent')
        ->assertReturned(fn (array $response): bool => ! $response['ok'] && isset($response['errors']['editing_lease']));
    expect($received->fresh()->description)->toBe('<p>Local editing.</p>')
        ->and($fixture['recipe']->fresh()->description)->toBe('<p>Description &amp; details.</p>');
})->with([WorkspaceMemberRole::Owner, WorkspaceMemberRole::Editor]);

it('keeps an accepted locked Product readable for Viewers without granting settings or costing writes', function (): void {
    $fixture = FormulaSharingFixtures::offer(options: []);
    $owner = $fixture['recipient']->owner;
    $preview = app(FormulaSharePreview::class)->build($owner, $fixture['share'], []);
    $received = app(AcceptFormulaShare::class)->handle($owner, $fixture['share'], [], $preview['expected_hash']);
    $owner->entitlements()->first()->plan->update(['allows_collaboration' => true]);
    $viewer = User::factory()->create(['active_workspace_id' => $fixture['recipient']->id]);
    WorkspaceMember::factory()->for($fixture['recipient'])->for($viewer)->create(['role' => WorkspaceMemberRole::Viewer]);
    $received->forceFill(['locked_at' => now(), 'locked_by' => $owner->id])->save();
    $this->actingAs($viewer);
    $component = Livewire::test(RecipeWorkbench::class, ['recipe' => $received])
        ->assertViewHas('canEditRecipe', false)->assertViewHas('workbench', fn (array $workbench): bool => $workbench['editing'] === null);
    $document = new DOMDocument;
    $document->loadHTML($component->html(), LIBXML_NOERROR | LIBXML_NOWARNING);
    $xpath = new DOMXPath($document);
    $disclosure = $xpath->query('//button[@aria-controls="formula-settings-panel"]')->item(0);
    expect($disclosure)->not->toBeNull()
        ->and($disclosure->hasAttribute('disabled'))->toBeFalse()
        ->and($xpath->query('ancestor::fieldset', $disclosure)->length)->toBe(0);
    $setting = $xpath->query('//*[@id="formula-settings-panel"]//input[@inputmode="decimal"]')->item(0);
    expect($xpath->query('ancestor::fieldset', $setting)->item(0)->getAttribute(':disabled'))->toContain('!canWriteRecipe');
    $component->call('loadCosting')->assertReturned(fn (array $response): bool => $response['ok']);
    expect(RecipeVersionCosting::query()->whereIn('recipe_version_id', $received->versions()->withoutGlobalScopes()->pluck('id'))->count())->toBe(0);
    $component->set('data.description', '<p>Forbidden.</p>')->call('saveRecipeContent')->assertForbidden();
    expect($received->fresh()->description)->toBeNull()->and($received->fresh()->edit_revision)->toBe(0);
});
