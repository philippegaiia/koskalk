<?php

use App\Actions\FormulaSharing\AcceptFormulaShare;
use App\Actions\FormulaSharing\SendFormulaShare;
use App\Enums\FormulaShareStatus;
use App\Enums\OwnerType;
use App\Enums\WorkspaceMemberRole;
use App\Livewire\Dashboard\FormulaShareCreate;
use App\Livewire\Dashboard\FormulaShareReview;
use App\Livewire\Dashboard\FormulaSharesIndex;
use App\Models\CurrentMaterialPrice;
use App\Models\FormulaShare;
use App\Models\IfraAmendment;
use App\Models\IfraProductCategory;
use App\Models\Ingredient;
use App\Models\Plan;
use App\Models\ProductTypeIfraCategory;
use App\Models\Recipe;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\FormulaSharePreview;
use App\Services\FormulaShareSnapshotBuilder;
use App\Services\RecipeContentUpdater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Support\FormulaSharingFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['workspaces.formula_sharing.enabled' => true, 'workspaces.collaboration_enabled' => true]);
});

it('requires authentication and restricts all sharing pages to the selected Workspace', function (): void {
    $fixture = FormulaSharingFixtures::offer();
    $this->get(route('formula-shares.index'))->assertRedirect(route('login'));
    $this->actingAs($fixture['owner'])->get(route('formula-shares.create', $fixture['recipe']))->assertOk()->assertSee('Saved formula');
    $this->get(route('formula-shares.show', $fixture['share']))->assertOk();
    $this->actingAs($fixture['recipient']->owner)->get(route('formula-shares.create', $fixture['recipe']))->assertNotFound();
    $other = Workspace::factory()->create();
    $this->actingAs($other->owner)->get(route('formula-shares.show', $fixture['share']))->assertNotFound();
    config(['workspaces.formula_sharing.enabled' => false]);
    $this->get(route('formula-shares.index'))->assertNotFound();
});

it('sends the persisted Saved formula with all optional content off by default', function (): void {
    $fixture = FormulaSharingFixtures::offer();
    $this->actingAs($fixture['owner']);
    $component = Livewire::test(FormulaShareCreate::class, ['recipe' => $fixture['recipe']])
        ->assertSet('data.include_procedure', false)
        ->assertSet('data.include_description', false)
        ->assertSet('data.include_line_notes', false)
        ->set('data.recipient_address', $fixture['recipient']->public_id)
        ->call('resolveRecipient')->assertSet('recipientName', $fixture['recipient']->name)
        ->call('preview')->assertHasNoErrors()
        ->assertSee('The recipient keeps an independent copy after accepting.')
        ->set('confirmed', true)->call('send')->assertHasNoErrors();
    $share = FormulaShare::query()->latest('id')->firstOrFail();
    expect($share->snapshot['product']['description'])->toBeNull()
        ->and($share->snapshot['formula']['manufacturing_instructions'])->toBeNull();
    $component->assertRedirect(route('formula-shares.show', $share));
});

it('accepts from a sanitized recipient preview with the default content choices', function (): void {
    $fixture = FormulaSharingFixtures::offer(options: []);
    $this->actingAs($fixture['recipient']->owner);
    $component = Livewire::test(FormulaShareReview::class, ['share' => $fixture['share']])
        ->assertSee($fixture['recipe']->name)->assertSet('display.import_count', 2)
        ->set('confirmed', true)->call('accept')->assertHasNoErrors();
    $product = $fixture['share']->refresh()->acceptedRecipe;
    expect($product)->not->toBeNull();
    $component->assertRedirect(route('recipes.saved', $product));
});

it('binds a mounted sharing page to its original Workspace on hydration', function (): void {
    $fixture = FormulaSharingFixtures::offer();
    $this->actingAs($fixture['owner']);
    $component = Livewire::test(FormulaSharesIndex::class);
    $other = Workspace::factory()->for($fixture['owner'], 'owner')->create();
    User::query()->whereKey($fixture['owner']->id)->update(['active_workspace_id' => $other->id]);
    $component->call('$refresh')->assertForbidden();
});

it('invalidates sender confirmation when the Saved material data changes', function (): void {
    $fixture = FormulaSharingFixtures::offer();
    $this->actingAs($fixture['owner']);
    $component = Livewire::test(FormulaShareCreate::class, ['recipe' => $fixture['recipe']])
        ->set('data.recipient_address', $fixture['recipient']->public_id)->call('preview')->set('confirmed', true);
    $fixture['oil']->forceFill(['inci_name' => 'Changed valid declaration'])->save();
    $component->call('send')->assertHasErrors('sharing')->assertSet('expectedHash', null)->assertSet('confirmed', false);
    expect(FormulaShare::query()->count())->toBe(1);
    $component->call('preview')->assertHasNoErrors()->set('confirmed', true)->call('send')->assertHasNoErrors();
    expect(FormulaShare::query()->count())->toBe(2);
});

it('recovers a stale recipient preview without partial imports', function (): void {
    $fixture = FormulaSharingFixtures::offer(options: []);
    $this->actingAs($fixture['recipient']->owner);
    $component = Livewire::test(FormulaShareReview::class, ['share' => $fixture['share']])->set('confirmed', true);
    $platform = Ingredient::withoutGlobalScopes()->where('catalog_key', 'CH1')->firstOrFail();
    $platform->forceFill(['inci_name' => 'Changed platform declaration'])->save();
    $component->call('accept')->assertHasErrors('sharing')->assertSet('expectedHash', null)->assertSet('confirmed', false);
    expect(Ingredient::withoutGlobalScopes()->where('workspace_id', $fixture['recipient']->id)->count())->toBe(0);
    $component->call('refreshPreview')->assertHasNoErrors()->assertSee(__('sharing.warnings.platform_changed'))->set('confirmed', true)->call('accept')->assertHasNoErrors();
});

it('rejects browser-supplied snapshots, chemistry baselines and unsupported content options', function (): void {
    $fixture = FormulaSharingFixtures::offer();
    $this->actingAs($fixture['owner']);
    Livewire::test(FormulaShareCreate::class, ['recipe' => $fixture['recipe']])
        ->set('data.recipient_address', $fixture['recipient']->public_id)->set('data.source_data', ['trusted_koh_sap_value' => '0.3'])
        ->call('preview')->assertHasErrors('sharing');
    Livewire::test(FormulaShareCreate::class, ['recipe' => $fixture['recipe']])
        ->set('data.recipient_address', $fixture['recipient']->public_id)->set('data.include_description', 'true')
        ->call('preview')->assertHasErrors('sharing');
    $this->actingAs($fixture['recipient']->owner);
    Livewire::test(FormulaShareReview::class, ['share' => $fixture['share']])
        ->set('choices.n1', ['mode' => 'import', 'baseline' => '0.3'])->call('refreshPreview')->assertHasErrors('choices');
    expect(FormulaShare::query()->count())->toBe(1);
});

it('locks source and share addresses against browser changes', function (string $component, string $property): void {
    $fixture = FormulaSharingFixtures::offer();
    $this->actingAs($component === 'sender' ? $fixture['owner'] : $fixture['recipient']->owner);
    $test = $component === 'sender' ? Livewire::test(FormulaShareCreate::class, ['recipe' => $fixture['recipe']]) : Livewire::test(FormulaShareReview::class, ['share' => $fixture['share']]);
    expect(fn () => $test->set($property, (string) Str::uuid()))->toThrow(CannotUpdateLockedPropertyException::class);
})->with([['sender', 'recipePublicId'], ['recipient', 'sharePublicId']]);

it('filters embedded media and escapes selected text without exposing source prices or baselines', function (): void {
    $fixture = FormulaSharingFixtures::offer();
    $fixture['oil']->forceFill(['source_data' => array_merge($fixture['oil']->source_data, ['secret' => 'PRIVATE_SOURCE_SENTINEL'])])->save();
    CurrentMaterialPrice::factory()->create(['ingredient_id' => $fixture['oil']->id, 'workspace_id' => $fixture['source']->id, 'price_per_canonical_unit' => '987654.32']);
    app(RecipeContentUpdater::class)->update($fixture['recipe'], ['description' => '<p>&lt;script&gt;alert(1)&lt;/script&gt; description</p>']);
    $fixture['saved']->forceFill(['manufacturing_instructions' => '<p>Mix carefully.</p><img src="https://secret.invalid/PROCEDURE_MEDIA_SENTINEL"><script>SCRIPT_SENTINEL</script>'])->save();
    $this->actingAs($fixture['owner']);
    $component = Livewire::test(FormulaShareCreate::class, ['recipe' => $fixture['recipe']])
        ->set('data.recipient_address', $fixture['recipient']->public_id)->set('data.include_description', true)->set('data.include_procedure', true)->set('data.include_line_notes', true)->call('preview')
        ->assertSee('Mix carefully.')->assertSee('Keep line note')->assertSee(__('sharing.excluded'))->assertSee(__('sharing.warnings.procedure_media_excluded'));
    expect($component->html())->not->toContain('<script>alert(1)</script>');
    $serialized = $component->html(false).json_encode($component->get('display'), JSON_THROW_ON_ERROR);
    foreach (['987654.32', 'PRIVATE_SOURCE_SENTINEL', 'PROCEDURE_MEDIA_SENTINEL', 'SCRIPT_SENTINEL', 'trusted_koh_sap_value', 'is_soap_saponification_trusted'] as $secret) {
        expect($serialized)->not->toContain($secret);
    }
});

it('requires fresh Admin membership on every hydration and denies an Editor endpoint', function (): void {
    $fixture = FormulaSharingFixtures::offer();
    Plan::query()->update(['allows_collaboration' => true]);
    $actor = User::factory()->create(['active_workspace_id' => $fixture['source']->id]);
    $member = WorkspaceMember::factory()->create(['workspace_id' => $fixture['source']->id, 'user_id' => $actor->id, 'role' => WorkspaceMemberRole::Admin]);
    $this->actingAs($actor)->get(route('formula-shares.create', $fixture['recipe']))->assertOk();
    $component = Livewire::test(FormulaShareCreate::class, ['recipe' => $fixture['recipe']]);
    $member->delete();
    $component->call('preview')->assertForbidden();
    $member = WorkspaceMember::factory()->create(['workspace_id' => $fixture['source']->id, 'user_id' => $actor->id, 'role' => WorkspaceMemberRole::Editor]);
    $this->get(route('formula-shares.create', $fixture['recipe']))->assertForbidden();
});

it('shows only the selected Workspace inbox and outbox with allowed page sizes', function (): void {
    $fixture = FormulaSharingFixtures::offer();
    FormulaShare::factory()->count(11)->create(['recipient_workspace_id' => $fixture['source']->id]);
    $this->actingAs($fixture['owner']);
    Livewire::test(FormulaSharesIndex::class)->assertSet('perPage', 25)->assertSee($fixture['source']->public_id)
        ->set('perPage', 10)->call('gotoPage', 2)->assertSee(__('table.pagination.rows_per_page'))
        ->set('direction', 'outbox')->assertSet('paginators.page', 1)->assertSee($fixture['recipe']->name);
    $this->actingAs($fixture['recipient']->owner);
    $component = Livewire::test(FormulaSharesIndex::class)->assertSee($fixture['recipe']->name)->set('direction', 'outbox')->assertSee(__('sharing.empty'));
    $component->set('perPage', 999)->assertHasErrors('sharing');
});

it('closes offers from the correct side and shows expiry without importing anything', function (string $side): void {
    $fixture = FormulaSharingFixtures::offer();
    $this->actingAs($side === 'sender' ? $fixture['owner'] : $fixture['recipient']->owner);
    Livewire::test(FormulaShareReview::class, ['share' => $fixture['share']])->call('close')->assertHasNoErrors()->assertSee(__('sharing.states.'.($side === 'sender' ? 'revoked' : 'declined')));
    expect($fixture['share']->refresh()->status->value)->toBe($side === 'sender' ? 'revoked' : 'declined');
    $fixture['share']->forceFill(['status' => FormulaShareStatus::Pending, 'expires_at' => now()->subSecond()])->save();
    $this->actingAs($fixture['recipient']->owner);
    Livewire::test(FormulaShareReview::class, ['share' => $fixture['share']])->assertSee(__('sharing.states.expired'))->call('accept')->assertHasErrors('sharing');
})->with(['sender', 'recipient']);

it('shows quota failure and never imports a partial Product', function (): void {
    $fixture = FormulaSharingFixtures::offer(recipeLimit: 0);
    $this->actingAs($fixture['recipient']->owner);
    Livewire::test(FormulaShareReview::class, ['share' => $fixture['share']])->set('confirmed', true)->call('accept')->assertHasErrors('plan')->assertSet('expectedHash', null);
    expect($fixture['share']->refresh()->accepted_recipe_id)->toBeNull()
        ->and(Ingredient::withoutGlobalScopes()->where('workspace_id', $fixture['recipient']->id)->count())->toBe(0);
});

/** @param array<string, mixed> $fixture */
function pagesResend(array $fixture): FormulaShare
{
    $builder = app(FormulaShareSnapshotBuilder::class);
    $snapshot = $builder->build($fixture['owner'], $fixture['recipe'], []);

    return app(SendFormulaShare::class)->handle($fixture['owner'], $fixture['recipe'], $fixture['recipient'], [], $builder->previewHash($snapshot, $fixture['recipient']), (string) Str::uuid());
}

it('discloses incoming and selected blend children even when the active import graph prunes them', function (): void {
    $fixture = FormulaSharingFixtures::offer('cosmetic');
    $child = Ingredient::factory()->create(['display_name' => 'Incoming blend child', 'workspace_id' => $fixture['source']->id, 'owner_type' => OwnerType::Workspace, 'owner_id' => $fixture['source']->id]);
    $fixture['oil']->components()->create(['component_ingredient_id' => $child->id, 'percentage_in_parent' => '100.00000', 'sort_order' => 0]);
    $first = pagesResend($fixture);
    $preview = app(FormulaSharePreview::class)->build($fixture['recipient']->owner, $first, []);
    app(AcceptFormulaShare::class)->handle($fixture['recipient']->owner, $first, [], $preview['expected_hash']);
    $again = pagesResend($fixture);
    $this->actingAs($fixture['recipient']->owner);
    $component = Livewire::test(FormulaShareReview::class, ['share' => $again])->assertSet('display.import_count', 0)->assertSee('Incoming blend child');
    expect(count($component->get('display.ingredients')))->toBe(2)
        ->and(data_get($component->get('display.ingredients'), '0.technical.components.0.name'))->toBe('Incoming blend child');
    $localChild = Ingredient::factory()->create(['display_name' => 'Different local blend child', 'workspace_id' => $fixture['recipient']->id, 'owner_type' => OwnerType::Workspace, 'owner_id' => $fixture['recipient']->id]);
    $localParent = Ingredient::factory()->create(['display_name' => 'Local substitute blend', 'workspace_id' => $fixture['recipient']->id, 'owner_type' => OwnerType::Workspace, 'owner_id' => $fixture['recipient']->id]);
    $localParent->components()->create(['component_ingredient_id' => $localChild->id, 'percentage_in_parent' => '100.00000', 'sort_order' => 0]);
    $key = collect($component->get('display.ingredients'))->firstWhere('name', $fixture['oil']->display_name)['key'];
    $component->set('choices.'.$key.'.mode', 'substitute')->set('choices.'.$key.'.ingredient_public_id', $localParent->public_id)->call('refreshPreview')->assertHasErrors('choices')
        ->set('choices.'.$key.'.confirmed', true)->call('refreshPreview')->assertHasNoErrors()->assertSet('display.import_count', 0)
        ->assertSee('Incoming blend child')->assertSee('Different local blend child');
    expect(data_get($component->get('display.ingredients'), '0.local_technical.components.0.name'))->toBe('Different local blend child');
    $displayJson = json_encode($component->get('display'), JSON_THROW_ON_ERROR);
    expect($displayJson)->not->toContain('share_lineage_key', 'baseline', 'component_ingredient_id', 'child_fingerprint');
});

it('shows calculation context, units and the effective changed IFRA codes', function (): void {
    $fixture = FormulaSharingFixtures::offer();
    $amendment = IfraAmendment::factory()->create(['code' => 'IFRA-UI-NEW', 'status' => 'notified', 'notification_date' => today()->subDay()]);
    $category = IfraProductCategory::factory()->create(['code' => 'UI-CATEGORY']);
    ProductTypeIfraCategory::factory()->create(['product_type_id' => $fixture['type']->id, 'ifra_amendment_id' => $amendment->id, 'ifra_product_category_id' => $category->id, 'is_default' => true]);
    $this->actingAs($fixture['recipient']->owner);
    Livewire::test(FormulaShareReview::class, ['share' => $fixture['share']])->assertSee('IFRA-UI-NEW')->assertSee('UI-CATEGORY')
        ->assertSee(__('sharing.settings.superfat'))->assertSee('1000.125')->assertSee(__('sharing.settings.batch_unit'));
});

it('shows the share action only on the latest Saved view for an authorized owner', function (): void {
    $fixture = FormulaSharingFixtures::offer();
    $url = route('formula-shares.create', $fixture['recipe']);
    $this->actingAs($fixture['owner'])->get(route('recipes.saved', $fixture['recipe']))->assertOk()->assertSee($url, false);
    $this->get(route('recipes.version', ['recipe' => $fixture['recipe'], 'version' => $fixture['saved']]))->assertOk()->assertDontSee($url, false);
    config(['workspaces.formula_sharing.enabled' => false]);
    $this->get(route('recipes.saved', $fixture['recipe']))->assertOk()->assertDontSee($url, false)->assertDontSee(route('formula-shares.index'), false);
});

it('replays a completed recipient acceptance without creating another Product', function (): void {
    $fixture = FormulaSharingFixtures::offer(options: []);
    $this->actingAs($fixture['recipient']->owner);
    $component = Livewire::test(FormulaShareReview::class, ['share' => $fixture['share']])->set('confirmed', true)->call('accept')->assertHasNoErrors();
    $product = $fixture['share']->refresh()->acceptedRecipe;
    $component->call('accept')->assertHasNoErrors()->assertRedirect(route('recipes.saved', $product));
    expect(Recipe::withoutGlobalScopes()->where('workspace_id', $fixture['recipient']->id)->count())->toBe(1);
});
