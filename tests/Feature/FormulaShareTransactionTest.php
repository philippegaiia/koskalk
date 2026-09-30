<?php

use App\Models\Recipe;
use App\Models\User;
use App\Models\Workspace;
use App\Services\EntitlementService;
use App\Services\FormulaShareTransaction;
use App\Services\RecipeEditingService;
use App\Services\WorkspaceWriteLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('loads workspace write authority freshly without changing business timestamps or emitting update events', function (): void {
    $workspace = Workspace::factory()->create(['name' => 'Old name', 'updated_at' => now()->subDay()]);
    DB::table('workspaces')->where('id', $workspace->id)->update(['name' => 'Fresh name']);
    Event::fake(['eloquent.updated: '.Workspace::class]);
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });
    $locked = DB::transaction(fn (): Workspace => app(WorkspaceWriteLock::class)->acquire($workspace->id));

    expect($locked->name)->toBe('Fresh name')
        ->and($locked->updated_at->equalTo($workspace->updated_at))->toBeTrue()
        ->and(implode(' ', $queries))->not->toContain('SET TRANSACTION', 'update "workspaces"');
    Event::assertNotDispatched('eloquent.updated: '.Workspace::class);
});

it('keeps the shared quota and locked Product paths compatible with the workspace write lock', function (): void {
    $workspace = Workspace::factory()->create(['updated_at' => now()->subDay()]);
    $recipe = Recipe::factory()->create(['workspace_id' => $workspace->id]);
    Event::fake(['eloquent.updated: '.Workspace::class]);
    $quotaOwner = app(EntitlementService::class)->withinWorkspaceQuotaLock($workspace, fn (Workspace $fresh): int => $fresh->owner->id);
    $productWorkspace = app(RecipeEditingService::class)->withLockedRecipe($recipe, fn (Recipe $fresh): int => $fresh->workspace_id);

    expect($quotaOwner)->toBe($workspace->owner_user_id)
        ->and($productWorkspace)->toBe($workspace->id)
        ->and($workspace->fresh()->updated_at->equalTo($workspace->updated_at))->toBeTrue();
    Event::assertNotDispatched('eloquent.updated: '.Workspace::class);
});

it('reloads the actor orders workspace locks and rolls back all caller writes on failure in a nested SQLite transaction', function (): void {
    $actor = User::factory()->create();
    $first = Workspace::factory()->for($actor, 'owner')->create();
    $second = Workspace::factory()->create();
    User::query()->whereKey($actor->id)->update(['active_workspace_id' => $first->id]);
    $count = Recipe::withoutGlobalScopes()->count();
    expect(fn () => app(FormulaShareTransaction::class)->run($actor, [$second->id, $first->id], function (User $fresh, array $workspaces) use ($actor, $first): void {
        expect($fresh)->not->toBe($actor)
            ->and($fresh->active_workspace_id)->toBe($first->id)
            ->and(array_keys($workspaces))->toBe(collect(array_keys($workspaces))->sort()->values()->all());
        Recipe::factory()->create(['workspace_id' => $first->id]);
        throw ValidationException::withMessages(['sharing' => 'Late validation failure']);
    }))->toThrow(ValidationException::class);
    expect(Recipe::withoutGlobalScopes()->count())->toBe($count);
});
