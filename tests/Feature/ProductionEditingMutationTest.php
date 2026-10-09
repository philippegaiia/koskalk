<?php

use App\Actions\Production\AssignProductionBatchNumbers;
use App\Actions\Production\AssignProductionTask;
use App\Actions\Production\CompleteProductionTask;
use App\Actions\Production\IssueFinishedGoods;
use App\Actions\Production\PrepareProductionStock;
use App\Actions\Production\ReleaseOutputLot;
use App\Actions\Production\RescheduleProduction;
use App\Actions\Production\SaveProductionActuals;
use App\Enums\ProductionBenchEntitlementStatus;
use App\Enums\ProductionRunStatus;
use App\Enums\StockMovementType;
use App\Enums\WorkspaceMemberRole;
use App\Models\ProductionJournalEntry;
use App\Models\ProductionRequirement;
use App\Models\ProductionRun;
use App\Models\ProductionTask;
use App\Models\StockLot;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\Production\ProductionEditingContext;
use App\Services\Production\ProductionMutationResult;
use App\Services\Production\ProductionMutationScope;
use App\Services\ProductionMutationGuard;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\ProductionEditingFixture;

uses(RefreshDatabase::class);

it('uses the localized task workspace error before starting a production command', function (string $command): void {
    $fixture = ProductionEditingFixture::create();
    $run = ProductionRun::factory()->for($fixture->workspace)->create();
    $task = ProductionTask::factory()->for($fixture->workspace)->for($run, 'productionRun')->create();
    $context = $fixture->lease($run);
    $task->setRelation('workspace', null);
    app('translator')->addLines(['production_bench.production.validation.task_workspace_missing' => 'Espace de travail introuvable.'], 'fr');
    app()->setLocale('fr');

    expect(fn () => app('App\\Actions\\Production\\'.$command)->handle($fixture->owner, $task, editing: $context))
        ->toThrow(function (ValidationException $exception): void {
            expect($exception->errors())->toBe(['task' => ['Espace de travail introuvable.']]);
        });
    expect($run->fresh()->edit_revision)->toBe(0)->and($context->acknowledgedRevisions())->toBe([]);
})->with(['AssignProductionTask', 'CompleteProductionTask']);

it('rejects unavailable task parents with a specific error before changing production', function (string $command, string $invalidReference): void {
    $fixture = ProductionEditingFixture::create();
    $run = ProductionRun::factory()->for($fixture->workspace)->create();
    $task = ProductionTask::factory()->for($fixture->workspace)->for($run, 'productionRun')->create();
    $context = $fixture->lease($run);
    if ($invalidReference === 'deleted') {
        $task->delete();
    } else {
        $foreign = ProductionRun::factory()->create();
        ProductionTask::query()->whereKey($task->id)->update(['production_run_id' => $foreign->id]);
    }
    $before = $task->fresh()?->getRawOriginal();

    expect(fn () => app('App\\Actions\\Production\\'.$command)->handle($fixture->owner, $task, editing: $context))
        ->toThrow(function (ValidationException $exception): void {
            expect($exception->errors())->toBe(['task' => [__('production_bench.production.validation.task_production_missing')]]);
        });

    expect($task->fresh()?->getRawOriginal())->toBe($before)
        ->and($run->fresh()->edit_revision)->toBe(0)
        ->and($context->acknowledgedRevisions())->toBe([]);
})->with(['AssignProductionTask', 'CompleteProductionTask', 'ReopenProductionTask', 'ResetProductionTaskDate', 'RescheduleProductionTask'])
    ->with(['deleted', 'foreign parent']);

it('rechecks task existence after locking its production without committing partial changes', function (string $command): void {
    $fixture = ProductionEditingFixture::create();
    $run = ProductionRun::factory()->for($fixture->workspace)->create();
    $task = ProductionTask::factory()->for($fixture->workspace)->for($run, 'productionRun')->create();
    $context = $fixture->lease($run);
    $before = $task->fresh()->getRawOriginal();
    $deleted = false;
    $event = 'eloquent.retrieved: '.ProductionRun::class;
    Event::listen($event, function (ProductionRun $retrieved) use ($run, $task, &$deleted): void {
        if ($retrieved->id === $run->id && ! $deleted) {
            $deleted = true;
            ProductionTask::query()->whereKey($task->id)->delete();
        }
    });

    try {
        expect(fn () => app('App\\Actions\\Production\\'.$command)->handle($fixture->owner, $task, editing: $context))
            ->toThrow(function (ValidationException $exception): void {
                expect($exception->errors())->toBe(['task' => [__('production_bench.production.validation.task_production_missing')]]);
            });
    } finally {
        Event::forget($event);
    }

    expect($deleted)->toBeTrue()
        ->and($task->fresh()->getRawOriginal())->toBe($before)
        ->and($run->fresh()->edit_revision)->toBe(0)
        ->and($context->acknowledgedRevisions())->toBe([]);
})->with(['AssignProductionTask', 'CompleteProductionTask', 'ReopenProductionTask', 'ResetProductionTaskDate', 'RescheduleProductionTask']);

it('rechecks changed authority before invoking a production writer', function (string $change): void {
    $fixture = ProductionEditingFixture::create();
    $editor = User::factory()->create(['active_workspace_id' => $fixture->workspace->id]);
    $member = WorkspaceMember::factory()->for($fixture->workspace)->for($editor)->create(['role' => WorkspaceMemberRole::Editor]);
    $run = ProductionRun::factory()->for($fixture->workspace)->create(['notes' => 'Original']);
    $context = $fixture->lease($run, $editor);
    match ($change) {
        'downgrade' => $member->update(['role' => WorkspaceMemberRole::Viewer]),
        'remove' => $member->delete(),
        'workspace' => User::query()->whereKey($editor->id)->update(['active_workspace_id' => Workspace::factory()->for($editor, 'owner')->create()->id]),
        'entitlement' => $fixture->workspace->productionEntitlement()->update(['status' => ProductionBenchEntitlementStatus::Cancelled, 'cancelled_at' => now()]),
    };
    $called = false;

    $write = function () use ($editor, $run, $context, &$called): mixed {
        return app(ProductionMutationGuard::class)->run($editor, [$run->id], $context,
            function (User $actor, Workspace $workspace, Collection $runs) use ($run, &$called): ProductionMutationResult {
                $called = true;
                $runs[$run->id]->update(['notes' => 'Unauthorized']);

                return new ProductionMutationResult(null, [$run->id]);
            });
    };
    expect($write)->toThrow($change === 'entitlement' ? ValidationException::class : AuthorizationException::class);

    expect($called)->toBeFalse()
        ->and($run->fresh()->notes)->toBe('Original')
        ->and($run->fresh()->edit_revision)->toBe(0)
        ->and($context->acknowledgedRevisions())->toBe([]);
})->with(['downgrade', 'remove', 'workspace', 'entitlement']);

it('rejects invalid output lot references with domain errors before any write', function (string $action, string $invalidReference, string $message): void {
    $fixture = ProductionEditingFixture::create();
    $run = ProductionRun::factory()->for($fixture->workspace)->create(['status' => ProductionRunStatus::Completed]);
    $lot = StockLot::factory()->for($fixture->workspace)->released()->create([
        'origin' => 'production_output', 'production_run_id' => $run->id,
    ]);
    $editing = ProductionEditingFixture::command($fixture->owner, $run);
    if ($invalidReference === 'workspace') {
        $lot->setRelation('workspace', null);
    } elseif ($invalidReference === 'link') {
        $replacement = ProductionRun::factory()->for($fixture->workspace)->create();
        StockLot::query()->whereKey($lot->id)->update(['production_run_id' => $replacement->id]);
    } elseif ($invalidReference === 'parent') {
        $replacement = ProductionRun::factory()->create();
        StockLot::query()->whereKey($lot->id)->update(['production_run_id' => $replacement->id]);
        $lot->refresh();
    } else {
        StockLot::query()->whereKey($lot->id)->update(['production_run_id' => null]);
    }

    expect(fn (): StockLot => $action === 'release'
        ? app(ReleaseOutputLot::class)->handle($fixture->owner, $lot, editing: $editing)
        : app(IssueFinishedGoods::class)->handle($fixture->owner, $lot, StockMovementType::Sample, '1', editing: $editing))
        ->toThrow(function (ValidationException $exception) use ($message): void {
            expect($exception->errors())->toBe(['lot' => [__('production_bench.production.validation.'.$message)]]);
        });
    expect($editing->acknowledgedRevisions())->toBe([]);
    expect($run->fresh()->edit_revision)->toBe(0);
    expect($lot->fresh()->movements()->count())->toBe(0);
    expect($lot->fresh()->status->value)->toBe('released');
})->with([
    'release missing workspace' => ['release', 'workspace', 'output_lot_workspace_missing'],
    'issue missing workspace' => ['issue', 'workspace', 'output_lot_workspace_missing'],
    'release changed link' => ['release', 'link', 'output_production_missing'],
    'issue changed link' => ['issue', 'link', 'output_production_missing'],
    'release removed link' => ['release', 'unlinked', 'output_lot_unlinked'],
    'issue removed link' => ['issue', 'unlinked', 'output_lot_unlinked'],
    'release parent absent from workspace' => ['release', 'parent', 'output_production_missing'],
    'issue parent absent from workspace' => ['issue', 'parent', 'output_production_missing'],
]);

it('acknowledges its own commit and refuses the same old draft', function (): void {
    $fixture = ProductionEditingFixture::create();
    $run = ProductionRun::factory()->for($fixture->workspace)->create(['notes' => 'Original']);
    $context = $fixture->lease($run);
    $guard = app(ProductionMutationGuard::class);

    $guard->run($fixture->owner, [$run->id], $context, function (User $actor, Workspace $workspace, Collection $productions, ProductionMutationScope $scope) use ($run): ProductionMutationResult {
        $productions[$run->id]->update(['notes' => 'Saved']);

        return new ProductionMutationResult($productions[$run->id], [$run->id]);
    });

    expect($context->acknowledgedRevisions())->toBe([$run->id => 1]);
    expect(fn () => $guard->run($fixture->owner, [$run->id], $context, fn () => new ProductionMutationResult(null, [])))->toThrow(ValidationException::class);
    expect($run->fresh()->notes)->toBe('Saved')->and($run->fresh()->edit_revision)->toBe(1);
});

it('refuses a missing context without calling the domain writer', function (): void {
    $fixture = ProductionEditingFixture::create();
    $run = ProductionRun::factory()->for($fixture->workspace)->create();
    $called = false;

    expect(fn () => app(ProductionMutationGuard::class)->run($fixture->owner, [$run->id], null, function () use (&$called): ProductionMutationResult {
        $called = true;

        return new ProductionMutationResult(null, []);
    }))->toThrow(ValidationException::class);

    expect($called)->toBeFalse();
    expect($run->fresh()->edit_revision)->toBe(0);
});

it('increments child-only writes once while leaving a no-op revision unchanged', function (): void {
    $fixture = ProductionEditingFixture::create();
    $run = ProductionRun::factory()->for($fixture->workspace)->create();
    $context = $fixture->lease($run);
    $guard = app(ProductionMutationGuard::class);
    $guard->run($fixture->owner, [$run->id], $context, fn () => new ProductionMutationResult(null, []));
    expect($context->acknowledgedRevisions())->toBe([$run->id => 0]);

    $guard->run($fixture->owner, [$run->id], $context, function () use ($run, $fixture): ProductionMutationResult {
        ProductionJournalEntry::factory()->for($run, 'productionRun')->create(['created_by_user_id' => $fixture->owner->id]);

        return new ProductionMutationResult(null, [$run->id, $run->id]);
    });

    expect($run->fresh()->edit_revision)->toBe(1);
    $this->assertDatabaseCount('production_journal_entries', 1);
});

it('rolls back a child and revision when the reservation expires during the command', function (): void {
    $this->freezeTime();
    $fixture = ProductionEditingFixture::create();
    $run = ProductionRun::factory()->for($fixture->workspace)->create();
    $context = $fixture->lease($run);

    expect(fn () => app(ProductionMutationGuard::class)->run($fixture->owner, [$run->id], $context, function () use ($run, $fixture): ProductionMutationResult {
        ProductionJournalEntry::factory()->for($run, 'productionRun')->create(['created_by_user_id' => $fixture->owner->id]);
        $this->travel(90)->seconds();

        return new ProductionMutationResult(null, [$run->id]);
    }))->toThrow(ValidationException::class);

    $this->assertDatabaseCount('production_journal_entries', 0);
    expect($run->fresh()->edit_revision)->toBe(0);
    expect($context->acknowledgedRevisions())->toBe([]);
});

it('returns a null revision for deletion and invalidates retained command scopes', function (): void {
    $fixture = ProductionEditingFixture::create();
    $run = ProductionRun::factory()->for($fixture->workspace)->create();
    $context = $fixture->lease($run);
    $retained = null;

    app(ProductionMutationGuard::class)->run($fixture->owner, [$run->id], $context, function (User $actor, Workspace $workspace, Collection $productions, ProductionMutationScope $scope) use ($run, &$retained): ProductionMutationResult {
        $retained = $scope;
        $productions[$run->id]->delete();

        return new ProductionMutationResult(true, [$run->id]);
    });

    expect($context->acknowledgedRevisions())->toBe([$run->id => null]);
    expect(fn () => $retained->assertFor($fixture->owner, $run, $fixture->workspace))->toThrow(LogicException::class);
});

it('uses short reservations without leaving ownership and blocks another page even for the same actor', function (): void {
    $fixture = ProductionEditingFixture::create();
    $run = ProductionRun::factory()->for($fixture->workspace)->create();
    $temporary = new ProductionEditingContext($fixture->workspace->id, (string) Str::uuid(), [$run->id => 0], temporary: true);
    $guard = app(ProductionMutationGuard::class);

    $guard->run($fixture->owner, [$run->id], $temporary, fn () => new ProductionMutationResult('ok', []));
    $this->assertDatabaseCount('production_edit_leases', 0);
    $fixture->lease($run);
    expect(fn () => $guard->run($fixture->owner, [$run->id], $temporary, fn () => new ProductionMutationResult(null, [])))->toThrow(ValidationException::class);
});

it('refuses stale direct production commands before any domain writes', function (string $command, array $arguments): void {
    $fixture = ProductionEditingFixture::create();
    $run = ProductionRun::factory()->for($fixture->workspace)->create();
    $context = $fixture->lease($run);
    $run->forceFill(['edit_revision' => 1])->save();
    $before = $run->fresh()->getRawOriginal();
    $args = match ($command) {
        'PrepareProductionStock' => [$fixture->owner, [$run->id], (string) Str::uuid()],
        'AssignProductionBatchNumbers' => [$fixture->owner, $fixture->workspace, [$run->id]],
        default => [$fixture->owner, $run, ...$arguments],
    };

    try {
        app('App\\Actions\\Production\\'.$command)->handle(...[...$args, 'editing' => $context]);
        $this->fail('Stale command unexpectedly succeeded.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('production_editing');
    }

    expect($run->fresh()->getRawOriginal())->toBe($before);
    $this->assertDatabaseCount('stock_movements', 0);
    $this->assertDatabaseCount('stock_reservations', 0);
    $this->assertDatabaseCount('production_tasks', 0);
})->with([
    'cancel' => ['CancelProduction', ['Postponed']],
    'start' => ['StartProduction', []],
    'abort' => ['AbortProduction', ['Stopped']],
    'complete' => ['CompleteProduction', ['10', '2026-10-05']],
    'actuals' => ['SaveProductionActuals', [[]]],
    'schedule' => ['ScheduleProduction', ['2026-10-05']],
    'reschedule' => ['RescheduleProduction', ['2026-10-05']],
    'location' => ['AssignProductionLocation', [null]],
    'release stock' => ['ReleaseProductionStock', []],
    'prepare stock' => ['PrepareProductionStock', []],
    'numbering' => ['AssignProductionBatchNumbers', []],
    'delete' => ['DeleteProductionRun', []],
    'plan' => ['UpdateProductionPlan', ['1', 'kg', 10, '2026-10-05']],
    'generate tasks' => ['GenerateProductionTasks', []],
]);

it('protects direct task writers using the durable parent revision', function (string $command): void {
    $fixture = ProductionEditingFixture::create();
    $run = ProductionRun::factory()->for($fixture->workspace)->create();
    $task = ProductionTask::factory()->for($fixture->workspace)->for($run, 'productionRun')->create();
    $context = $fixture->lease($run);
    $run->forceFill(['edit_revision' => 1])->save();
    $before = $task->fresh()->getRawOriginal();

    try {
        app('App\\Actions\\Production\\'.$command)->handle($fixture->owner, $task, editing: $context);
        $this->fail('Stale task command unexpectedly succeeded.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('production_editing');
    }

    expect($task->fresh()->getRawOriginal())->toBe($before);
})->with(['AssignProductionTask', 'CompleteProductionTask', 'ReopenProductionTask', 'ResetProductionTaskDate', 'RescheduleProductionTask']);

it('advances the parent once for completion while an unchanged assignment remains a no-op', function (): void {
    $fixture = ProductionEditingFixture::create();
    $run = ProductionRun::factory()->for($fixture->workspace)->create();
    $task = ProductionTask::factory()->for($fixture->workspace)->for($run, 'productionRun')->create(['employee_id' => null, 'department_id' => null]);
    $context = $fixture->lease($run);
    app(AssignProductionTask::class)->handle($fixture->owner, $task, editing: $context);
    expect($context->acknowledgedRevisions())->toBe([$run->id => 0]);

    app(CompleteProductionTask::class)->handle($fixture->owner, $task, editing: $context);

    expect($task->fresh()->completed_at)->not->toBeNull();
    expect($context->acknowledgedRevisions())->toBe([$run->id => 1]);
});

it('protects output handling through its linked production', function (string $command): void {
    $fixture = ProductionEditingFixture::create();
    $run = ProductionRun::factory()->for($fixture->workspace)->create();
    $lot = StockLot::factory()->for($fixture->workspace)->forRecipe()->create(['production_run_id' => $run->id, 'origin' => 'production_output']);
    $context = $fixture->lease($run);
    $run->forceFill(['edit_revision' => 1])->save();
    $before = $lot->fresh()->getRawOriginal();

    try {
        if ($command === 'ReleaseOutputLot') {
            app(ReleaseOutputLot::class)->handle($fixture->owner, $lot, editing: $context);
        } else {
            app(IssueFinishedGoods::class)->handle($fixture->owner, $lot, StockMovementType::Shipment, '1', editing: $context);
        }
        $this->fail('Stale output command unexpectedly succeeded.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('production_editing');
    }

    expect($lot->fresh()->getRawOriginal())->toBe($before);
    $this->assertDatabaseCount('stock_movements', 0);
})->with(['ReleaseOutputLot', 'IssueFinishedGoods']);

it('treats saving the same reserved date as a no-op without releasing stock', function (): void {
    $fixture = ProductionEditingFixture::create();
    $run = ProductionRun::factory()->for($fixture->workspace)->create([
        'status' => ProductionRunStatus::Reserved, 'planned_for' => today(),
    ]);
    $context = $fixture->lease($run);
    app(RescheduleProduction::class)->handle($fixture->owner, $run, today()->toDateString(), editing: $context);
    expect($run->fresh()->status)->toBe(ProductionRunStatus::Reserved)
        ->and($run->fresh()->edit_revision)->toBe(0);
});

it('rolls back deletion when its lease expires during the command', function (): void {
    $this->freezeTime();
    $fixture = ProductionEditingFixture::create();
    $run = ProductionRun::factory()->for($fixture->workspace)->create();
    $context = $fixture->lease($run);
    expect(fn () => app(ProductionMutationGuard::class)->run($fixture->owner, [$run->id], $context, function (User $actor, Workspace $workspace, Collection $productions) use ($run): ProductionMutationResult {
        $productions[$run->id]->delete();
        $this->travel(90)->seconds();

        return new ProductionMutationResult(true, [$run->id]);
    }))->toThrow(ValidationException::class);
    expect($run->fresh())->not->toBeNull()->and($context->acknowledgedRevisions())->toBe([]);
});

it('leaves actuals attribution timestamps and revision unchanged when saved twice', function (): void {
    $this->freezeTime();
    $fixture = ProductionEditingFixture::create();
    $run = ProductionRun::factory()->for($fixture->workspace)->create(['status' => ProductionRunStatus::InProduction]);
    $requirement = ProductionRequirement::factory()->for($run, 'productionRun')->create();
    $lot = StockLot::factory()->released()->for($fixture->workspace)->create(['ingredient_id' => $requirement->ingredient_id]);
    $context = $fixture->lease($run);
    $rows = [['production_requirement_id' => $requirement->id, 'stock_lot_id' => $lot->id, 'quantity' => '5', 'note' => 'Bench note']];
    app(SaveProductionActuals::class)->handle($fixture->owner, $run, $rows, editing: $context);
    $before = $run->consumption()->firstOrFail()->getRawOriginal();
    $this->travel(20)->seconds();
    $next = new ProductionEditingContext($fixture->workspace->id, $context->token, $context->acknowledgedRevisions());
    app(SaveProductionActuals::class)->handle($fixture->owner, $run, $rows, editing: $next);
    expect($run->consumption()->firstOrFail()->getRawOriginal())->toBe($before)
        ->and($next->acknowledgedRevisions())->toBe([$run->id => 1]);
});

it('bounds raw group commands before deduplication or stock and numbering work', function (string $command): void {
    $fixture = ProductionEditingFixture::create();
    $run = ProductionRun::factory()->for($fixture->workspace)->create(['status' => ProductionRunStatus::Scheduled, 'planned_for' => today()]);
    $context = $fixture->lease($run);
    $ids = array_fill(0, 101, $run->id);
    expect(function () use ($command, $fixture, $context, $ids): void {
        if ($command === 'stock') {
            app(PrepareProductionStock::class)->handle($fixture->owner, $ids, (string) Str::uuid(), editing: $context);
        } else {
            app(AssignProductionBatchNumbers::class)->handle($fixture->owner, $fixture->workspace, $ids, editing: $context);
        }
    })->toThrow(ValidationException::class);
    expect($run->fresh()->edit_revision)->toBe(0)->and($run->fresh()->batch_number)->toBeNull();
    $this->assertDatabaseCount('stock_reservations', 0);
})->with(['stock', 'numbering']);
