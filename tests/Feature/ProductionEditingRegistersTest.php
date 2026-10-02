<?php

use App\Enums\ProductionRunStatus;
use App\Livewire\ProductionBench\Production\ProductionIndex;
use App\Livewire\ProductionBench\Production\TaskIndex;
use App\Models\ProductionRun;
use App\Models\ProductionTask;
use App\Services\ProductionEditingService;
use Dom\HTMLDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\ProductionEditingFixture;

uses(RefreshDatabase::class);

it('labels the register refresh as refreshing the production list', function (): void {
    $fixture = ProductionEditingFixture::create();
    $page = Livewire::actingAs($fixture->owner)->test(ProductionIndex::class);
    $document = HTMLDocument::createFromString($page->html(), LIBXML_NOERROR);
    $button = collect($document->querySelectorAll('button'))->first(fn ($element): bool => $element->getAttribute('@click') === "run('refreshProductionRegister')");

    expect(trim($button->textContent))->toBe('Refresh productions');
});

it('provides localized request failure feedback inside each register command scope', function (string $component): void {
    $fixture = ProductionEditingFixture::create();
    $page = Livewire::actingAs($fixture->owner)->test($component);
    $document = HTMLDocument::createFromString($page->html(), LIBXML_NOERROR);
    $scope = $document->querySelector('[x-data="productionRegister()"]');

    expect($scope->getAttribute('data-failure-message'))->toBe(__('production_bench.editing.command_failed'));
    $alert = $scope->querySelector('[role="alert"][x-text="message"]');
    expect($alert)->not->toBeNull();
    expect($alert->getAttribute('x-show'))->toBe('message');
})->with(['productions' => [ProductionIndex::class], 'tasks' => [TaskIndex::class]]);

it('renders task register write controls inside their Alpine command scope', function (): void {
    $fixture = ProductionEditingFixture::create();
    $run = ProductionRun::factory()->for($fixture->workspace)->create(['status' => ProductionRunStatus::Scheduled]);
    ProductionTask::factory()->for($fixture->workspace)->for($run, 'productionRun')->create(['scheduled_for' => today()]);
    $page = Livewire::actingAs($fixture->owner)->test(TaskIndex::class);
    $document = HTMLDocument::createFromString($page->html(), LIBXML_NOERROR);
    $scope = $document->querySelector('[x-data="productionRegister()"]');

    expect($scope)->not->toBeNull();
    $handlers = collect($scope->querySelectorAll('select, button'))
        ->map(fn ($element): string => $element->getAttribute('@change').' '.$element->getAttribute('@click'))
        ->implode(' ');
    expect($handlers)->toContain("run('assignDepartment'")->toContain("run('assignEmployee'")->toContain("run('toggleTask'");
});

it('rejects a register delete against its stale displayed revision', function (): void {
    $fixture = ProductionEditingFixture::create();
    $run = ProductionRun::factory()->for($fixture->workspace)->create();
    $page = Livewire::actingAs($fixture->owner)->test(ProductionIndex::class);
    $this->assertDatabaseCount('production_edit_leases', 0);
    DB::table('production_runs')->where('id', $run->id)->update(['edit_revision' => 1]);
    $page->call('deleteProduction', $run->id)->assertHasErrors();
    expect($run->fresh())->not->toBeNull();
    $page->assertSet('displayedProductionRevisions', [$run->id => 0]);
});

it('refuses a same-user detail lease for a task shortcut then permits a temporary command after release', function (): void {
    $fixture = ProductionEditingFixture::create();
    $run = ProductionRun::factory()->for($fixture->workspace)->create(['status' => ProductionRunStatus::Scheduled]);
    $task = ProductionTask::factory()->for($fixture->workspace)->for($run, 'productionRun')->create(['scheduled_for' => today()]);
    $context = $fixture->lease($run);
    $page = Livewire::actingAs($fixture->owner)->test(TaskIndex::class);
    $page->call('toggleTask', $task->id)->assertHasErrors();
    expect($task->fresh()->completed_at)->toBeNull();
    app(ProductionEditingService::class)->release($fixture->owner, $fixture->workspace->id, [$run->id], $context->token);
    $page->call('toggleTask', $task->id)->assertHasNoErrors();
    expect($task->fresh()->completed_at)->not->toBeNull()->and($run->fresh()->edit_revision)->toBe(1);
    $this->assertDatabaseCount('production_edit_leases', 0);
});

it('retains a scheduling modal baseline through a newer register refresh', function (): void {
    $fixture = ProductionEditingFixture::create();
    $run = ProductionRun::factory()->for($fixture->workspace)->create();
    $page = Livewire::actingAs($fixture->owner)->test(ProductionIndex::class)
        ->mountAction('scheduleDraft', ['productionId' => $run->id]);
    $action = $page->instance()->getMountedAction();
    $button = $action->getModalSubmitAction();
    expect(html_entity_decode($button->toHtml()))->toContain("run('callMountedAction')")
        ->and($action->hasFormWrapper())->toBeFalse()->and($button->canSubmitForm())->toBeFalse();
    DB::table('production_runs')->where('id', $run->id)->update(['edit_revision' => 1]);
    $page->call('refreshProductionRegister')->assertSet('schedulingRevision', 0)
        ->setActionData(['planned_for' => today()->toDateString()])->callMountedAction()->assertHasActionErrors();
    expect($run->fresh()->status)->toBe(ProductionRunStatus::Draft)->and($run->fresh()->edit_revision)->toBe(1);
    $this->assertDatabaseCount('production_edit_leases', 0);
});
