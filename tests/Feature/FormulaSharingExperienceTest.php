<?php

use App\Enums\HelpContentImportMode;
use App\Enums\OwnerType;
use App\Livewire\Dashboard\FormulaShareCreate;
use App\Livewire\Dashboard\FormulaShareReview;
use App\Livewire\Dashboard\FormulaSharesIndex;
use App\Models\FormulaShare;
use App\Models\HelpTopicLocale;
use App\Models\Ingredient;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ContextualHelp\HelpContentImporter;
use App\Services\ContextualHelp\HelpContentManifest;
use App\Services\FormulaShareSnapshotBuilder;
use Database\Seeders\SupportedLocaleSeeder;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\FormulaSharingFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['workspaces.formula_sharing.enabled' => true, 'workspaces.collaboration_enabled' => true]);
});

it('starts a share from the sharing page using a selected Saved Product', function (): void {
    $fixture = FormulaSharingFixtures::offer();
    $this->actingAs($fixture['owner']);

    Livewire::test(FormulaSharesIndex::class)->assertSee('Share a formula')
        ->callAction('shareFormula', data: ['product' => $fixture['recipe']->public_id])
        ->assertHasNoActionErrors()->assertRedirect(route('formula-shares.create', $fixture['recipe']));
    expect(FormulaShare::query()->count())->toBe(1);
});

it('rejects foreign, unsaved and manufactured Products in the sharing picker', function (string $case): void {
    $fixture = FormulaSharingFixtures::offer();
    match ($case) {
        'foreign' => $fixture['recipe']->forceFill(['workspace_id' => $fixture['recipient']->id])->save(),
        'unsaved' => $fixture['recipe']->versions()->withoutGlobalScopes()->where('is_current', false)->delete(),
        'manufactured' => $fixture['recipe']->forceFill(['production_output_type' => 'manufactured_ingredient'])->save(),
    };
    $this->actingAs($fixture['owner']);

    Livewire::test(FormulaSharesIndex::class)
        ->callAction('shareFormula', data: ['product' => $fixture['recipe']->public_id])
        ->assertHasActionErrors(['product']);
    expect(FormulaShare::query()->count())->toBe(1);
})->with(['foreign', 'unsaved', 'manufactured']);

it('resolves a trimmed case-insensitive verified owner email and sends to its Workspace', function (): void {
    $fixture = FormulaSharingFixtures::offer(options: []);
    $fixture['recipient']->owner->update(['email' => 'Recipient@Example.test']);
    $this->actingAs($fixture['owner']);

    Livewire::test(FormulaShareCreate::class, ['recipe' => $fixture['recipe']])
        ->set('data.recipient_address', '  RECIPIENT@example.test  ')
        ->call('resolveRecipient')->assertHasNoErrors()->assertSet('recipientName', $fixture['recipient']->name)
        ->call('preview')->assertHasNoErrors()->set('confirmed', true)->call('send')->assertHasNoErrors();
    expect(FormulaShare::query()->latest('id')->firstOrFail()->recipient_workspace_id)->toBe($fixture['recipient']->id);
});

it('does not resolve unknown, unverified or member-only email addresses', function (string $case): void {
    $fixture = FormulaSharingFixtures::offer();
    $email = match ($case) {
        'unknown' => 'unknown@example.test',
        'unverified' => User::factory()->unverified()->has(Workspace::factory(), 'ownedWorkspaces')->create()->email,
        'member-only' => User::factory()->create(['active_workspace_id' => $fixture['recipient']->id])->email,
    };

    expect(fn () => app(FormulaShareSnapshotBuilder::class)->resolveRecipient($fixture['owner'], $fixture['recipe'], $email))
        ->toThrow(ValidationException::class, __('sharing.validation.recipient'));
})->with(['unknown', 'unverified', 'member-only']);

it('requires a Workspace address when an owner email is ambiguous', function (): void {
    $fixture = FormulaSharingFixtures::offer();
    Workspace::factory()->for($fixture['recipient']->owner, 'owner')->create();

    expect(fn () => app(FormulaShareSnapshotBuilder::class)->resolveRecipient($fixture['owner'], $fixture['recipe'], $fixture['recipient']->owner->email))
        ->toThrow(ValidationException::class, __('sharing.validation.recipient_ambiguous'));
    expect(app(FormulaShareSnapshotBuilder::class)->resolveRecipient($fixture['owner'], $fixture['recipe'], $fixture['recipient']->public_id)->id)
        ->toBe($fixture['recipient']->id);
});

it('invalidates email preview confirmation when the owner changes before sending', function (): void {
    $fixture = FormulaSharingFixtures::offer();
    $other = Workspace::factory()->create();
    $email = $fixture['recipient']->owner->email;
    $this->actingAs($fixture['owner']);
    $page = Livewire::test(FormulaShareCreate::class, ['recipe' => $fixture['recipe']])
        ->set('data.recipient_address', $email)->call('preview')->assertHasNoErrors()->set('confirmed', true);
    $fixture['recipient']->update(['owner_user_id' => $other->owner_user_id]);
    $other->update(['owner_user_id' => $fixture['recipient']->owner->id]);

    $page->call('send')->assertHasErrors()->assertSet('confirmed', false)->assertSet('expectedHash', null);
    expect(FormulaShare::query()->count())->toBe(1);
});

it('localizes disclosure decimals without rounding away technical differences', function (): void {
    $fixture = FormulaSharingFixtures::offer();
    $fixture['owner']->update(['number_locale' => 'fr_FR']);
    $this->actingAs($fixture['owner']);

    Livewire::test(FormulaShareCreate::class, ['recipe' => $fixture['recipe']])
        ->set('data.recipient_address', $fixture['recipient']->public_id)->call('preview')->assertHasNoErrors()
        ->assertSee('1000,125')->assertSee('1,2345')->assertSee('0,190123')->assertDontSee('1000.1250');
    expect($fixture['share']->snapshot['formula']['batch_size'])->toBe('1000.125');
});

it('shows local selection only for explicit reuse or substitution and requires a refreshed preview', function (): void {
    $fixture = FormulaSharingFixtures::offer('cosmetic', options: []);
    $local = Ingredient::factory()->create(['workspace_id' => $fixture['recipient']->id, 'owner_type' => OwnerType::Workspace, 'owner_id' => $fixture['recipient']->id, 'display_name' => 'My local material']);
    $this->actingAs($fixture['recipient']->owner);
    $page = Livewire::test(FormulaShareReview::class, ['share' => $fixture['share']]);
    $key = collect($page->get('display.ingredients'))->firstWhere('name', $fixture['oil']->display_name)['key'];
    $page->assertDontSee(__('sharing.local_ingredient'))
        ->set('choices.'.$key.'.mode', 'substitute')->assertSee(__('sharing.local_ingredient'))
        ->set('choices.'.$key.'.ingredient_public_id', $local->public_id)
        ->assertSee(__('sharing.preview_needed'))
        ->set('choices.'.$key.'.confirmed', true)->call('refreshPreview')->assertHasNoErrors()
        ->assertSee('My local material')->assertSet('display.import_count', 1)
        ->set('confirmed', true)->call('accept')->assertHasNoErrors();
    $received = $fixture['share']->fresh()->acceptedRecipe;
    expect($received->latestPublishedVersion->items()->pluck('ingredient_id')->all())->toContain($local->id);
    expect($local->fresh()->display_name)->toBe('My local material');
});

it('accepts the default proposal when an irrelevant substitution toggle is checked', function (): void {
    $fixture = FormulaSharingFixtures::offer(options: []);
    $this->actingAs($fixture['recipient']->owner);
    $page = Livewire::test(FormulaShareReview::class, ['share' => $fixture['share']]);
    $key = collect($page->get('display.ingredients'))->firstWhere('kind', 'private')['key'];
    $page->set('choices.'.$key.'.mode', 'automatic')
        ->set('choices.'.$key.'.confirmed', true)
        ->set('confirmed', true)->call('accept')->assertHasNoErrors();
    expect($fixture['share']->fresh()->status->value)->toBe('accepted');
});

it('searches only eligible Products in the original selected Workspace', function (): void {
    $fixture = FormulaSharingFixtures::offer();
    $fixture['recipe']->update(['name' => 'Lavender soap']);
    $fixture['recipe']->forceFill(['locked_at' => now(), 'locked_by' => $fixture['owner']->id])->save();
    $this->actingAs($fixture['owner']);
    $page = Livewire::test(FormulaSharesIndex::class)->mountAction('shareFormula');
    $field = collect($page->instance()->getSchema($page->instance()->getMountedActionSchemaName())->getFlatComponents())->first(fn ($field): bool => $field instanceof Select);

    expect($field->getSearchResults('lavender'))->toHaveKey($fixture['recipe']->public_id);
    $fixture['recipe']->forceFill(['archived_at' => now()])->save();
    expect($field->getSearchResults('lavender'))->toBe([]);
    $other = Workspace::factory()->for($fixture['owner'], 'owner')->create();
    User::query()->whereKey($fixture['owner']->id)->update(['active_workspace_id' => $other->id]);
    $page->call('callMountedAction')->assertForbidden();
});

it('bounds recipient lookup attempts and rejects self addresses', function (): void {
    $fixture = FormulaSharingFixtures::offer();
    config(['workspaces.formula_sharing.rate_limits.recipient.actor_per_minute' => 1]);
    $builder = app(FormulaShareSnapshotBuilder::class);
    expect(fn () => $builder->resolveRecipient($fixture['owner'], $fixture['recipe'], $fixture['source']->public_id))
        ->toThrow(ValidationException::class, __('sharing.validation.recipient'));
    expect(fn () => $builder->resolveRecipient($fixture['owner'], $fixture['recipe'], $fixture['recipient']->public_id))
        ->toThrow(ValidationException::class);
});

it('shows all numeric precision in a technical comparison but keeps reference codes unchanged', function (): void {
    $this->actingAs(User::factory()->create(['number_locale' => 'fr_FR']));
    $html = view('formula-shares.partials.technical-value', ['numberLocale' => 'fr_FR', 'value' => [
        'koh_sap_value' => '0.190123', 'concentration_percent' => '0.000001', 'max_percentage' => '0.000000',
        'value' => '1.20', 'code' => '1.20', 'concentration_source' => null,
    ]])->render();

    expect($html)->toContain('0,190123', '0,000001', '1.20', '—')->not->toContain('0.190123');
});

it('bootstraps sharing help and shows only published guidance on each sharing surface', function (): void {
    $fixture = FormulaSharingFixtures::offer();
    $this->seed(SupportedLocaleSeeder::class);
    $manifest = app(HelpContentManifest::class)->decode(file_get_contents(database_path('seeders/data/contextual-help.sharing.en.json')));
    $importer = app(HelpContentImporter::class);
    $preview = $importer->preview($manifest, HelpContentImportMode::Bootstrap);
    $importer->apply($manifest, HelpContentImportMode::Bootstrap, $preview['selection'], $preview['manifest_hash']);
    $this->actingAs($fixture['owner']);
    Livewire::test(FormulaSharesIndex::class)->assertDontSee('data-help-index', false);
    foreach (HelpTopicLocale::query()->whereHas('topic', fn ($query) => $query->whereIn('key', ['sharing.sending', 'sharing.receiving', 'sharing.ingredients']))->get() as $locale) {
        $locale->update(['published_revision_id' => $locale->latest_revision_id]);
    }

    Livewire::test(FormulaSharesIndex::class)->assertSee('data-help-index', false)
        ->assertSee('Understand Ingredient reuse and substitution')
        ->assertViewHas('contextualHelp', fn (array $help): bool => count($help['tabs']['page']) === 3);
    Livewire::test(FormulaShareCreate::class, ['recipe' => $fixture['recipe']])
        ->assertViewHas('contextualHelp', fn (array $help): bool => $help['tabs']['page'] === ['sharing.sending']);
    $this->actingAs($fixture['recipient']->owner);
    Livewire::test(FormulaShareReview::class, ['share' => $fixture['share']])
        ->assertViewHas('contextualHelp', fn (array $help): bool => $help['tabs']['page'] === ['sharing.receiving', 'sharing.ingredients']);
    config(['contextual-help.enabled' => false]);
    Livewire::test(FormulaShareReview::class, ['share' => $fixture['share']])->assertDontSee('data-help-index', false);
});
