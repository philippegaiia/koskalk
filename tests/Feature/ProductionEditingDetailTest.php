<?php

use App\Enums\ProductionRunStatus;
use App\Livewire\ProductionBench\Production\ProductionDetail;
use App\Models\ProductionLocation;
use App\Models\ProductionRun;
use App\Models\ProductionTask;
use App\Models\Workspace;
use App\Services\ProductionEditingService;
use Dom\Element;
use Dom\HTMLDocument;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\ProductionEditingFixture;

uses(RefreshDatabase::class);

it('shows the legacy intermediate ingredient selector through the local completion draft', function (): void {
    $fixture = ProductionEditingFixture::create();
    $run = ProductionRun::factory()->for($fixture->workspace)->create(['status' => ProductionRunStatus::InProduction, 'production_output_type' => null]);
    $page = Livewire::actingAs($fixture->owner)->test(ProductionDetail::class, ['productionId' => $run->public_id]);
    $document = HTMLDocument::createFromString($page->html(), LIBXML_NOERROR);
    $selector = $document->querySelector('[data-production-intermediate-ingredient]');

    expect($selector)->not->toBeNull();
    expect($selector->getAttribute('x-show'))->toBe("value('completion', 'outputMode') === 'intermediate'");
});

it('rejects legacy intermediate completion without a selected ingredient', function (): void {
    $fixture = ProductionEditingFixture::create();
    $run = ProductionRun::factory()->for($fixture->workspace)->create(['status' => ProductionRunStatus::InProduction, 'production_output_type' => null]);
    Livewire::actingAs($fixture->owner)->test(ProductionDetail::class, ['productionId' => $run->public_id])
        ->call('beginEditing')
        ->call('executeEditingCommand', 'complete', [], ['outputMode' => 'intermediate', 'outputIngredientId' => null, 'manufactureDate' => '2026-10-02', 'actualOutputQuantity' => '25', 'estimatedReadyOn' => ''], 'completion')
        ->assertHasErrors('output_ingredient_id')
        ->assertReturned(fn (array $reply): bool => ! $reply['ok'] && $reply['revisions'] === []);
    expect($run->fresh()->status)->toBe(ProductionRunStatus::InProduction);
    $this->assertDatabaseCount('stock_lots', 0);
});

it('saves the date after checking and renewing an expired uncontested editing reservation', function (): void {
    $fixture = ProductionEditingFixture::create();
    $run = ProductionRun::factory()->for($fixture->workspace)->create(['status' => ProductionRunStatus::Scheduled, 'planned_for' => '2026-10-02']);
    $page = Livewire::actingAs($fixture->owner)->test(ProductionDetail::class, ['productionId' => $run->public_id]);
    $page->call('beginEditing')->set('scheduleDate', '2026-10-05');
    $this->travel(91)->seconds();

    $page->call('heartbeatEditing')->assertReturned(fn (array $reply): bool => $reply['status'] === 'available')
        ->assertSet('scheduleDate', '2026-10-05')
        ->call('beginEditing')->assertSet('editingOwnsLease', true)
        ->call('executeEditingCommand', 'rescheduleProduction', [], ['scheduleDate' => '2026-10-05'], 'planning')
        ->assertHasNoErrors()
        ->assertReturned(fn (array $reply): bool => $reply['ok'] && $reply['canonical'] === ['scheduleDate' => '2026-10-05'])
        ->assertSet('editingOwnsLease', true);
    expect($run->fresh()->planned_for->format('Y-m-d'))->toBe('2026-10-05');
});
it('requires explicit editing and advances only a committed command revision', function (): void {
    $fixture = ProductionEditingFixture::create();
    $run = ProductionRun::factory()->for($fixture->workspace)->create();
    $page = Livewire::actingAs($fixture->owner)->test(ProductionDetail::class, ['productionId' => $run->public_id]);
    $page->assertSet('editingOwnsLease', false);
    $this->assertDatabaseCount('production_edit_leases', 0);
    $page->set('journalBody', 'first')->call('saveJournalEntry')->assertHasErrors();
    $this->assertDatabaseCount('production_journal_entries', 0);
    $page->call('beginEditing')->assertSet('editingOwnsLease', true);
    $page->set('journalBody', 'first')->call('saveJournalEntry')->assertHasNoErrors()
        ->assertSet('editingExpectedRevisions', [$run->id => 1]);
    $page->set('journalBody', 'second')->call('saveJournalEntry')->assertHasNoErrors()
        ->assertSet('editingExpectedRevisions', [$run->id => 2]);
    $this->assertDatabaseCount('production_journal_entries', 2);
});
it('keeps drafts and mount baseline when a production changes or disappears', function (): void {
    $fixture = ProductionEditingFixture::create();
    $run = ProductionRun::factory()->for($fixture->workspace)->create();
    $page = Livewire::actingAs($fixture->owner)->test(ProductionDetail::class, ['productionId' => $run->public_id]);
    $page->set('journalBody', 'unsaved');
    DB::table('production_runs')->where('id', $run->id)->update(['edit_revision' => 1]);
    $page->call('pollEditing')->assertSet('editingState.status', 'stale')
        ->assertSet('journalBody', 'unsaved')->assertSet('editingExpectedRevisions', [$run->id => 0]);
    DB::table('production_runs')->where('id', $run->id)->delete();
    $page->call('pollEditing')->assertSet('editingState.status', 'unavailable')->assertSet('journalBody', 'unsaved');
});
it('does not turn another tab into an owner or accept a wrong production task', function (): void {
    $fixture = ProductionEditingFixture::create();
    $run = ProductionRun::factory()->for($fixture->workspace)->create();
    app(ProductionEditingService::class)->acquire($fixture->owner, $fixture->workspace->id, [$run->id => 0], (string) Str::uuid());
    $page = Livewire::actingAs($fixture->owner)->test(ProductionDetail::class, ['productionId' => $run->public_id]);
    $page->call('beginEditing')->assertSet('editingState.status', 'blocked')->assertSet('editingOwnsLease', false);
});

it('initializes completed output from its durable units or mass', function (array $output, string $quantity): void {
    $fixture = ProductionEditingFixture::create();
    $run = ProductionRun::factory()->for($fixture->workspace)->create($output);
    Livewire::actingAs($fixture->owner)->test(ProductionDetail::class, ['productionId' => $run->public_id])
        ->assertSet('actualOutputQuantity', $quantity);
})->with([
    'units' => [['actual_output_units' => 12], '12'],
    'mass' => [['actual_output_mass_grams' => '250.000000000'], '250.000000000'],
]);

it('returns exact queued acknowledgments and preserves a failed submitted journal', function (): void {
    $fixture = ProductionEditingFixture::create();
    $run = ProductionRun::factory()->for($fixture->workspace)->create();
    $page = Livewire::actingAs($fixture->owner)->test(ProductionDetail::class, ['productionId' => $run->public_id]);
    $page->call('beginEditing')->call('executeEditingCommand', 'saveJournalEntry', [], ['journalBody' => 'Saved entry'], 'journal')
        ->assertReturned(fn (array $reply): bool => $reply['ok'] && $reply['revisions'][$run->id] === 1 && $reply['canonical'] === ['journalBody' => '']);
    DB::table('production_runs')->where('id', $run->id)->update(['edit_revision' => 2]);
    $page->call('executeEditingCommand', 'saveJournalEntry', [], ['journalBody' => 'Pending entry'], 'journal')
        ->assertReturned(fn (array $reply): bool => ! $reply['ok'] && $reply['revisions'] === [] && $reply['state']['status'] === 'stale')
        ->assertSet('journalBody', 'Pending entry')->assertSet('editingExpectedRevisions', [$run->id => 1]);
    $this->assertDatabaseCount('production_journal_entries', 1);
});

it('refreshes only through a coherent explicit reload and rejects a foreign task', function (): void {
    $fixture = ProductionEditingFixture::create();
    $run = ProductionRun::factory()->for($fixture->workspace)->create();
    $other = ProductionRun::factory()->for($fixture->workspace)->create();
    $task = ProductionTask::factory()->for($fixture->workspace)->for($other, 'productionRun')->create();
    $page = Livewire::actingAs($fixture->owner)->test(ProductionDetail::class, ['productionId' => $run->public_id]);
    DB::table('production_runs')->where('id', $run->id)->update(['edit_revision' => 1, 'notes' => 'Changed']);
    $page->call('reloadProductionEditing')->assertSet('editingExpectedRevisions', [$run->id => 0]);
    $page->call('acceptProductionReload', $page->get('editingPendingReload.id'))->assertSet('editingExpectedRevisions', [$run->id => 1])
        ->assertSet('editingPresentation.'.$run->id.'.attributes.notes', 'Changed');
    $page->call('beginEditing');
    expect(fn () => $page->call('toggleTask', $task->id))->toThrow(ModelNotFoundException::class);
    expect($task->fresh()->completed_at)->toBeNull();
});

it('saves a location independently of an unsaved planned date', function (): void {
    $fixture = ProductionEditingFixture::create();
    $fixture->workspace->update(['uses_production_locations' => true]);
    $run = ProductionRun::factory()->for($fixture->workspace)->create(['planned_for' => null]);
    $location = ProductionLocation::factory()->for($fixture->workspace)->create();
    $page = Livewire::actingAs($fixture->owner)->test(ProductionDetail::class, ['productionId' => $run->public_id]);
    $page->call('beginEditing')->set('scheduleDate', '2026-10-19')
        ->call('executeEditingCommand', 'assignProductionLocation', [], ['productionLocationId' => (string) $location->id], 'location')
        ->assertHasNoErrors()->assertSet('scheduleDate', '2026-10-19')
        ->assertReturned(fn (array $reply): bool => $reply['ok'] && $reply['canonical'] === ['productionLocationId' => (string) $location->id]);
    expect($run->fresh()->production_location_id)->toBe($location->id)->and($run->fresh()->planned_for)->toBeNull();
});

it('escapes a holder name and keeps repeated blocked polls quiet', function (): void {
    $fixture = ProductionEditingFixture::create();
    $fixture->owner->update(['name' => '<img src=x onerror=alert(1)>']);
    $run = ProductionRun::factory()->for($fixture->workspace)->create();
    $fixture->lease($run);
    $page = Livewire::actingAs($fixture->owner)->test(ProductionDetail::class, ['productionId' => $run->public_id]);
    $page->assertDontSeeHtml('<img src=x onerror=alert(1)>')->call('pollEditing')->call('pollEditing')
        ->assertSet('editingState.status', 'blocked')->assertNotDispatched('app-notification');
});

it('keeps date draft synchronization and preview locking inside complete Alpine attributes', function (): void {
    $fixture = ProductionEditingFixture::create();
    $run = ProductionRun::factory()->for($fixture->workspace)->create();
    $page = Livewire::actingAs($fixture->owner)->test(ProductionDetail::class, ['productionId' => $run->public_id]);
    $document = HTMLDocument::createFromString($page->html(), LIBXML_NOERROR);
    $picker = collect($document->querySelectorAll('[x-init]'))->first(
        fn (Element $element): bool => str_starts_with($element->getAttribute('x-init'), 'state = value('),
    );

    expect($picker)->not->toBeNull();
    expect($picker->getAttribute('x-init'))
        ->toContain('field("planning", "scheduleDate", date)')
        ->toContain("\$watch('forms.planning'");
    expect($picker->getAttribute('x-effect'))->toContain('querySelector("input")')->toContain('input.disabled = !canWrite');
    expect($document->body->textContent)->not->toContain("\$watch('forms.planning'");
});

it('gives finishing a visible button and separates compact editing status from longer notices', function (): void {
    $document = HTMLDocument::createFromString(view('components.production-bench.editing-status')->render(), LIBXML_NOERROR);
    $finish = $document->querySelector('[data-production-finish-editing]');
    $badge = $document->querySelector('[data-production-editing-badge]');
    $notice = $document->querySelector('[data-production-editing-notice]');

    expect($finish)->not->toBeNull();
    expect($finish->getAttribute('class'))->toContain('sk-btn-outline');
    expect(trim($finish->textContent))->toBe('Finish editing');
    expect($badge)->not->toBeNull();
    expect($badge->textContent)->toContain('Viewing')->toContain('Editing');
    expect($badge->getAttribute(':class'))->toContain('--color-success-soft');
    expect($badge->querySelector('[x-text]')->getAttribute('x-text'))->toContain('holder_name');
    expect($notice)->not->toBeNull();
    expect($notice->querySelector('[x-text="message"]'))->not->toBeNull();
    expect($badge->querySelector('[x-text="message"]'))->toBeNull();
});

it('labels an existing production date save and binds attachment readiness to the uploaded file', function (): void {
    $fixture = ProductionEditingFixture::create();
    $run = ProductionRun::factory()->for($fixture->workspace)->create(['status' => ProductionRunStatus::Scheduled]);
    $page = Livewire::actingAs($fixture->owner)->test(ProductionDetail::class, ['productionId' => $run->public_id]);
    $document = HTMLDocument::createFromString($page->html(), LIBXML_NOERROR);
    $dateSave = $document->querySelector('[data-production-save-date]');
    $attach = $document->querySelector('[data-production-attach-document]');

    expect($dateSave)->not->toBeNull();
    expect(trim($dateSave->textContent))->toBe('Save production date');
    expect($attach)->not->toBeNull();
    expect($attach->getAttribute(':disabled'))->toBe('!canAttach');
    $upload = $document->querySelector('input[type="file"]');
    expect($upload->getAttribute('x-on:livewire-upload-finish'))->toBe('uploadFinished()');
    expect($upload->getAttribute('x-on:livewire-upload-error'))->toBe('uploadErrored()');
    expect($upload->hasAttribute('data-production-document-input'))->toBeTrue();
    $clear = $document->querySelector('[data-production-clear-document]');
    expect($clear)->not->toBeNull();
    expect(trim($clear->textContent))->toBe('Clear');
    expect($clear->getAttribute('@click'))->toBe('clearDocument()');
    expect($document->querySelector('[data-production-upload-state]'))->not->toBeNull();
    expect($document->querySelector('[data-production-document-errors]')->getAttribute('x-text'))->toBe("documentErrors.join(' ')");
    expect($document->querySelector('[data-production-document-attached]')->textContent)->toContain('Journal document attached.');
    expect($document->body->textContent)->toContain('PDFs up to 180 KB');
    $expressions = [];
    foreach ($document->querySelectorAll('*') as $element) {
        foreach ($element->attributes as $attribute) {
            if (str_starts_with($attribute->name, 'x-') || str_starts_with($attribute->name, ':') || str_starts_with($attribute->name, '@')) {
                $expressions[] = $attribute->value;
            }
        }
    }
    expect(implode('\n', $expressions))->not->toContain('@js(');
});

it('uses a standard select height without stretching the production location row', function (): void {
    $fixture = ProductionEditingFixture::create();
    $fixture->workspace->update(['uses_production_locations' => true]);
    $run = ProductionRun::factory()->for($fixture->workspace)->create();
    $page = Livewire::actingAs($fixture->owner)->test(ProductionDetail::class, ['productionId' => $run->public_id]);
    $document = HTMLDocument::createFromString($page->html(), LIBXML_NOERROR);
    $select = $document->querySelector('#production-detail-location');
    expect($select->getAttribute('class'))->toContain('h-10')->toContain('py-2');
    expect($select->parentElement->getAttribute('class'))->toContain('sm:items-center');
});

it('places the production date section between stock preparation and the production location', function (): void {
    $fixture = ProductionEditingFixture::create();
    $fixture->workspace->update(['uses_production_locations' => true]);
    $run = ProductionRun::factory()->for($fixture->workspace)->create(['status' => ProductionRunStatus::Scheduled]);
    $page = Livewire::actingAs($fixture->owner)->test(ProductionDetail::class, ['productionId' => $run->public_id]);
    $document = HTMLDocument::createFromString($page->html(), LIBXML_NOERROR);
    $order = [];
    foreach ($document->querySelectorAll('[data-testid]') as $element) {
        $order[] = $element->getAttribute('data-testid');
    }
    $date = array_search('production-date-section', $order, true);

    expect($order)->toContain('production-stock-preparation-section', 'production-date-section', 'production-location-section');
    expect($date)->toBeGreaterThan(array_search('production-stock-preparation-section', $order, true));
    expect($date)->toBeLessThan(array_search('production-location-section', $order, true));
    expect($document->querySelector('[data-production-date-field] .fi-fo-field-label-content'))->not->toBeNull();
});

it('prepares a reload without rebasing the server or clearing any mounted draft', function (): void {
    $fixture = ProductionEditingFixture::create();
    $run = ProductionRun::factory()->for($fixture->workspace)->create(['planned_for' => '2026-10-02']);
    $page = Livewire::actingAs($fixture->owner)->test(ProductionDetail::class, ['productionId' => $run->public_id]);
    $page->set('scheduleDate', '2026-10-09')->set('journalBody', 'Keep this note');
    DB::table('production_runs')->where('id', $run->id)->update(['edit_revision' => 1, 'planned_for' => '2026-10-05']);

    $page->call('reloadProductionEditing')
        ->assertReturned(fn (array $reply): bool => $reply['revisions'] === [$run->id => 1] && $reply['groups']['planning']['scheduleDate'] === '2026-10-05')
        ->assertSet('editingExpectedRevisions', [$run->id => 0])
        ->assertSet('scheduleDate', '2026-10-09')->assertSet('journalBody', 'Keep this note')
        ->assertSet('editingPresentation.'.$run->id.'.attributes.planned_for', '2026-10-02 00:00:00');
});

it('accepts only the prepared reload baseline and remains stale after a subsequent write', function (): void {
    $fixture = ProductionEditingFixture::create();
    $run = ProductionRun::factory()->for($fixture->workspace)->create(['planned_for' => '2026-10-02']);
    $page = Livewire::actingAs($fixture->owner)->test(ProductionDetail::class, ['productionId' => $run->public_id]);
    DB::table('production_runs')->where('id', $run->id)->update(['edit_revision' => 1, 'planned_for' => '2026-10-05']);
    $page->call('reloadProductionEditing');
    $receipt = $page->get('editingPendingReload.id');
    DB::table('production_runs')->where('id', $run->id)->update(['edit_revision' => 2, 'planned_for' => '2026-10-06']);

    $page->call('acceptProductionReload', $receipt)
        ->assertSet('editingExpectedRevisions', [$run->id => 1])->assertSet('scheduleDate', '2026-10-05')
        ->assertSet('editingState.status', 'stale')->assertSet('editingPendingReload', null)
        ->assertSee('2026-10-05')->assertDontSee('2026-10-06');
});

it('rejects an unknown reload receipt without changing the mounted baseline', function (): void {
    $fixture = ProductionEditingFixture::create();
    $run = ProductionRun::factory()->for($fixture->workspace)->create();
    $page = Livewire::actingAs($fixture->owner)->test(ProductionDetail::class, ['productionId' => $run->public_id]);
    DB::table('production_runs')->where('id', $run->id)->update(['edit_revision' => 1]);
    $page->call('reloadProductionEditing');
    $component = $page->instance();

    expect(fn () => $component->acceptProductionReload((string) Str::uuid()))->toThrow(HttpException::class);
    expect($component->editingExpectedRevisions)->toBe([$run->id => 0]);
});

it('does not accept a prepared reload after the production disappears', function (): void {
    $fixture = ProductionEditingFixture::create();
    $run = ProductionRun::factory()->for($fixture->workspace)->create();
    $page = Livewire::actingAs($fixture->owner)->test(ProductionDetail::class, ['productionId' => $run->public_id]);
    DB::table('production_runs')->where('id', $run->id)->update(['edit_revision' => 1]);
    $page->call('reloadProductionEditing');
    $receipt = $page->get('editingPendingReload.id');
    DB::table('production_runs')->where('id', $run->id)->delete();
    $page->call('acceptProductionReload', $receipt)->assertSet('editingState.status', 'unavailable')
        ->assertSet('editingExpectedRevisions', [$run->id => 0]);
});

it('exposes reload recovery for a failed display refresh or an unconfirmed reload', function (): void {
    $document = HTMLDocument::createFromString(view('components.production-bench.editing-status')->render(), LIBXML_NOERROR);
    $reload = collect($document->querySelectorAll('button'))->first(fn (Element $element): bool => $element->getAttribute('@click') === 'reload()');
    $begin = collect($document->querySelectorAll('button'))->first(fn (Element $element): bool => $element->getAttribute('@click') === 'begin()');

    expect($reload->getAttribute('x-show'))->toContain('presentationFailed')->toContain('reloadUnconfirmed');
    expect($begin->getAttribute('x-show'))->toContain('!reloadUnconfirmed');
});

it('rechecks workspace access before accepting a prepared reload', function (): void {
    $fixture = ProductionEditingFixture::create();
    $run = ProductionRun::factory()->for($fixture->workspace)->create();
    $page = Livewire::actingAs($fixture->owner)->test(ProductionDetail::class, ['productionId' => $run->public_id]);
    $page->call('reloadProductionEditing');
    $receipt = $page->get('editingPendingReload.id');
    $otherWorkspace = Workspace::factory()->for($fixture->owner, 'owner')->create();
    $fixture->owner->forceFill(['active_workspace_id' => $otherWorkspace->id])->save();

    $page->call('acceptProductionReload', $receipt)->assertForbidden();
});
