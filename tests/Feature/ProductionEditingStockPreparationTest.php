<?php

use App\Enums\ProductionRunStatus;
use App\Livewire\ProductionBench\Production\StockPreparation;
use App\Models\ProductionRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\ProductionEditingFixture;

uses(RefreshDatabase::class);

it('previews a whole selection and reserves it only on explicit edit', function (): void {
    $fixture = ProductionEditingFixture::create();
    $first = ProductionRun::factory()->for($fixture->workspace)->create(['status' => ProductionRunStatus::Scheduled]);
    $second = ProductionRun::factory()->for($fixture->workspace)->create(['status' => ProductionRunStatus::Scheduled]);
    $page = Livewire::actingAs($fixture->owner)->withQueryParams(['ids' => $first->id.','.$second->id])->test(StockPreparation::class);
    $page->assertSet('editingOwnsLease', false);
    $this->assertDatabaseCount('production_edit_leases', 0);
    $page->call('beginEditing')->assertSet('editingOwnsLease', true);
    $this->assertDatabaseCount('production_edit_leases', 2);
});

it('does not reserve a subset when any selected production is held', function (): void {
    $fixture = ProductionEditingFixture::create();
    $first = ProductionRun::factory()->for($fixture->workspace)->create(['status' => ProductionRunStatus::Scheduled]);
    $second = ProductionRun::factory()->for($fixture->workspace)->create(['status' => ProductionRunStatus::Scheduled]);
    $fixture->lease($second);
    $page = Livewire::actingAs($fixture->owner)->withQueryParams(['ids' => $first->id.','.$second->id])->test(StockPreparation::class);
    $page->call('beginEditing')->assertSet('editingOwnsLease', false)->assertSet('editingState.status', 'blocked')
        ->assertSee('group_blocked', false)->assertSee(__('production_bench.editing.group_blocked'));
    $this->assertDatabaseCount('production_edit_leases', 1);
});

it('rejects malformed and oversized selections before computing previews', function (string $ids): void {
    $fixture = ProductionEditingFixture::create();
    Livewire::actingAs($fixture->owner)->withQueryParams(['ids' => $ids])->test(StockPreparation::class)->assertStatus(422);
    $this->assertDatabaseCount('production_edit_leases', 0);
})->with(['malformed' => '1,wrong', 'empty' => '', 'oversized' => implode(',', range(1, 101))]);

it('submits automatic allocations through the queued command without requiring manual rows', function (): void {
    $fixture = ProductionEditingFixture::create();
    $run = ProductionRun::factory()->for($fixture->workspace)->create(['status' => ProductionRunStatus::Scheduled]);
    $page = Livewire::actingAs($fixture->owner)->test(StockPreparation::class, ['productionRun' => $run->public_id]);
    $page->call('beginEditing')
        ->call('executeEditingCommand', 'confirm', [], ['manualMode' => [], 'manualQuantities' => []], 'allocations')
        ->assertHasNoErrors()
        ->assertReturned(fn (array $receipt): bool => $receipt['ok'] && $receipt['revisions'][$run->id] === 1);
    expect($run->fresh()->status)->toBe(ProductionRunStatus::Reserved);
});

it('reloads the whole selected group without rebasing pending manual allocations during preparation', function (): void {
    $fixture = ProductionEditingFixture::create();
    $first = ProductionRun::factory()->for($fixture->workspace)->create(['status' => ProductionRunStatus::Scheduled]);
    $second = ProductionRun::factory()->for($fixture->workspace)->create(['status' => ProductionRunStatus::Scheduled]);
    $page = Livewire::actingAs($fixture->owner)->withQueryParams(['ids' => $first->id.','.$second->id])->test(StockPreparation::class);
    $page->set('manualMode', [15 => true])->set('manualQuantities', [15 => [20 => '4']]);
    DB::table('production_runs')->where('id', $first->id)->update(['edit_revision' => 1]);
    DB::table('production_runs')->where('id', $second->id)->update(['edit_revision' => 2]);

    $page->call('reloadProductionEditing')->assertSet('editingExpectedRevisions', [$first->id => 0, $second->id => 0])
        ->assertSet('manualMode', [15 => true])->assertSet('manualQuantities', [15 => [20 => '4']])
        ->assertReturned(fn (array $reply): bool => $reply['revisions'] === [$first->id => 1, $second->id => 2]);
    $page->call('acceptProductionReload', $page->get('editingPendingReload.id'))
        ->assertSet('editingExpectedRevisions', [$first->id => 1, $second->id => 2])
        ->assertSet('manualMode', [])->assertSet('manualQuantities', [])->assertSet('editingPendingReload', null);
});
