<?php

use App\Enums\IngredientCategory;
use App\Enums\OwnerType;
use App\Models\Ingredient;
use App\Models\IngredientSapProfile;
use App\Models\ProductFamily;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\RecipeVersionCosting;
use App\Models\User;
use App\Models\Workspace;
use App\Services\LiveCostingPricePropagationService;
use App\Services\RecipeControlMutationGuard;
use App\Services\RecipeEditingService;
use App\Services\RecipeMutationGuard;
use App\Services\RecipeWorkbenchService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

it('rejects an old save after a competing transaction commits', function (string $winner): void {
    if (DB::getDriverName() !== 'pgsql' || ! function_exists('pcntl_fork')) {
        $this->markTestSkipped('Requires an explicitly permitted disposable PostgreSQL database and pcntl.');
    }
    $this->artisan('migrate', ['--no-interaction' => true])->assertSuccessful();
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $recipe = Recipe::factory()->create(['owner_type' => OwnerType::Workspace, 'owner_id' => $workspace->id, 'workspace_id' => $workspace->id]);
    $version = RecipeVersion::factory()->for($recipe)->create(['owner_type' => OwnerType::Workspace, 'owner_id' => $workspace->id, 'workspace_id' => $workspace->id]);
    $ingredient = Ingredient::factory()->create(['catalog_key' => (string) Str::uuid()]);
    $costing = RecipeVersionCosting::query()->create(['recipe_version_id' => $version->id, 'user_id' => $owner->id, 'currency' => 'EUR']);
    $costing->items()->create(['ingredient_id' => $ingredient->id, 'phase_key' => 'oils', 'position' => 0, 'price_per_kg' => '2']);
    $token = (string) Str::uuid();
    app(RecipeEditingService::class)->acquire($recipe, $owner, $token);
    $save = fn (string $name): mixed => app(RecipeMutationGuard::class)->run(
        $recipe, $owner, $token, 0, $version->id,
        function (Recipe $locked) use ($name): int {
            $locked->update(['name' => $name]);

            return $locked->id;
        },
        expectedCostingRevision: 0,
    );

    $result = editingCompetingSession(
        $workspace->id,
        fn (): int => $save('Stale loser'),
        function () use ($winner, $save, $recipe, $owner, $workspace, $ingredient): void {
            if ($winner === 'save') {
                $save('Winning save');
            } elseif ($winner === 'lock') {
                app(RecipeControlMutationGuard::class)->run($recipe, $owner, 0, 'manageLock',
                    fn (Recipe $locked): bool => $locked->update(['locked_at' => now(), 'locked_by' => $owner->id]),
                    invalidateLease: true, allowLocked: true);
            } elseif ($winner === 'price') {
                app(LiveCostingPricePropagationService::class)->ingredientPriceChanged($workspace, $ingredient->id, '5');
            } else {
                app(RecipeEditingService::class)->takeover($recipe, $owner, (string) Str::uuid(), 'Explicit second tab takeover');
            }
        },
    );

    expect($result['status'])->toBe('validation')
        ->and($result['fields'])->toContain(in_array($winner, ['save', 'price'], true) ? 'edit_revision' : 'editing_lease')
        ->and($recipe->fresh()->name)->not->toBe('Stale loser');
    if ($winner === 'save') {
        expect($recipe->fresh()->name)->toBe('Winning save');
    }
    if ($winner === 'lock') {
        expect($recipe->fresh()->isLocked())->toBeTrue();
    }
})->with(['save', 'lock', 'takeover', 'price']);

it('preserves the winning formula when a real publish or restore contends with a stale save', function (string $operation): void {
    if (DB::getDriverName() !== 'pgsql' || ! function_exists('pcntl_fork')) {
        $this->markTestSkipped('Requires an explicitly permitted disposable PostgreSQL database and pcntl.');
    }
    $this->artisan('migrate', ['--no-interaction' => true])->assertSuccessful();
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $family = ProductFamily::query()->firstOrCreate(['slug' => 'soap'], ['name' => 'Soap', 'calculation_basis' => 'initial_oils', 'is_active' => true]);
    $oil = Ingredient::factory()->create([
        'catalog_key' => (string) Str::uuid(),
        'category' => IngredientCategory::Lipids,
        'is_soap_saponification_trusted' => true,
        'is_active' => true,
    ]);
    IngredientSapProfile::factory()->create(['ingredient_id' => $oil->id, 'koh_sap_value' => 0.188]);
    $payload = [
        'name' => 'Saved backup',
        'product_type_id' => testProductTypeIdForFamily('soap'),
        'oil_unit' => 'g', 'oil_weight' => 1000,
        'manufacturing_mode' => 'saponify_in_formula', 'exposure_mode' => 'rinse_off',
        'regulatory_regime' => 'eu', 'editing_mode' => 'percentage',
        'lye_type' => 'naoh', 'koh_purity_percentage' => 90, 'dual_lye_koh_percentage' => 40,
        'water_mode' => 'percent_of_oils', 'water_value' => 38, 'superfat' => 5,
        'phase_items' => [
            'saponified_oils' => [['ingredient_id' => $oil->id, 'percentage' => 100, 'weight' => 1000]],
            'additives' => [], 'fragrance' => [],
        ],
    ];
    $service = app(RecipeWorkbenchService::class);
    $current = $service->publish($owner, $family, $payload);
    $recipe = Recipe::withoutGlobalScopes()->findOrFail($current->recipe_id);
    $backup = RecipeVersion::withoutGlobalScopes()->where('recipe_id', $recipe->id)->where('is_current', false)->firstOrFail();
    $current = $service->save($owner, $family, [...$payload, 'name' => 'Unsaved work', 'water_value' => 33], $recipe);
    $token = (string) Str::uuid();
    $baseline = app(RecipeEditingService::class)->acquire($recipe, $owner, $token);

    $result = editingCompetingSession($workspace->id,
        fn (): RecipeVersion => app(RecipeMutationGuard::class)->run(
            $recipe, $owner, $token, $baseline['recipe_revision'], $current->id,
            fn (Recipe $locked): RecipeVersion => $service->save($owner, $family, [...$payload, 'name' => 'Stale loser'], $locked),
            expectedCostingRevision: $baseline['costing_revision'],
        ),
        function () use ($operation, $recipe, $owner, $token, $baseline, $current, $service, $family, $payload, $backup): void {
            if ($operation === 'publish') {
                app(RecipeMutationGuard::class)->run(
                    $recipe, $owner, $token, $baseline['recipe_revision'], $current->id,
                    fn (Recipe $locked): RecipeVersion => $service->publish($owner, $family, [...$payload, 'name' => 'Winning publication', 'water_value' => 30], $locked),
                    expectedCostingRevision: $baseline['costing_revision'],
                );
            } else {
                app(RecipeEditingService::class)->release($recipe, $owner, $token);
                app(RecipeControlMutationGuard::class)->run(
                    $recipe, $owner, $baseline['recipe_revision'], 'update',
                    fn (Recipe $locked): RecipeVersion => $service->restoreCurrentVersion($owner, $locked, $backup->id),
                );
            }
        });

    expect($result['status'])->toBe('validation')
        ->and($result['fields'])->toContain($operation === 'publish' ? 'edit_revision' : 'editing_lease');
    $versions = RecipeVersion::withoutGlobalScopes()->where('recipe_id', $recipe->id)->get();
    expect($versions->where('is_current', true))->toHaveCount(1);
    expect($versions->where('name', 'Stale loser'))->toHaveCount(0);
    expect($versions->firstWhere('is_current', true)->name)->toBe($operation === 'publish' ? 'Winning publication' : 'Saved backup');
    expect($service->currentVersionPayload($recipe->fresh())['waterValue'])->toEqual($operation === 'publish' ? 30 : 38);
    expect($versions->where('is_current', false))->toHaveCount(1);
    expect($versions->firstWhere('is_current', false)->name)->toBe($operation === 'publish' ? 'Winning publication' : 'Saved backup');
    expect($recipe->fresh()->edit_revision)->toBe($baseline['recipe_revision'] + 1);
})->with(['publish', 'restore']);

it('grants only one reservation when two sessions acquire an absent lease', function (): void {
    if (DB::getDriverName() !== 'pgsql' || ! function_exists('pcntl_fork')) {
        $this->markTestSkipped('Requires an explicitly permitted disposable PostgreSQL database and pcntl.');
    }
    $this->artisan('migrate', ['--no-interaction' => true])->assertSuccessful();
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $recipe = Recipe::factory()->create(['owner_type' => OwnerType::Workspace, 'owner_id' => $workspace->id, 'workspace_id' => $workspace->id]);
    $winnerToken = (string) Str::uuid();
    $loserToken = (string) Str::uuid();
    $result = editingCompetingSession($workspace->id,
        fn (): array => app(RecipeEditingService::class)->acquire($recipe, $owner, $loserToken),
        fn (): array => app(RecipeEditingService::class)->acquire($recipe, $owner, $winnerToken));
    expect($result['status'])->toBe('saved')
        ->and($result['id']['status'])->toBe('blocked')
        ->and(DB::table('recipe_edit_leases')->where('recipe_id', $recipe->id)->count())->toBe(1)
        ->and(app(RecipeEditingService::class)->status($recipe, $owner, $winnerToken)['status'])->toBe('acquired');
});

/**
 * Hold the formula workspace lock until PostgreSQL confirms the other
 * session is waiting for a lock, then commit a real competing editing action.
 *
 * @return array<string, mixed>
 */
function editingCompetingSession(int $workspaceId, Closure $waitingAction, Closure $winningAction): array
{
    DB::disconnect();
    [$parentSocket, $childSocket] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    $pid = pcntl_fork();
    if ($pid === -1) {
        throw new RuntimeException('Unable to fork the competing database session.');
    }
    if ($pid === 0) {
        fclose($parentSocket);
        stream_set_timeout($childSocket, 15);
        try {
            DB::reconnect();
            DB::statement("SET statement_timeout = '12s'");
            fwrite($childSocket, DB::selectOne('SELECT pg_backend_pid() AS pid')->pid."\n");
            if (trim((string) fgets($childSocket)) !== 'go') {
                throw new RuntimeException('Parent did not release the competing session.');
            }
            $result = ['status' => 'saved', 'id' => $waitingAction()];
        } catch (ValidationException $exception) {
            $result = ['status' => 'validation', 'fields' => array_keys($exception->errors())];
        } catch (Throwable $exception) {
            $result = ['status' => 'error', 'message' => $exception->getMessage()];
        }
        fwrite($childSocket, json_encode($result, JSON_THROW_ON_ERROR)."\n");
        fclose($childSocket);
        DB::disconnect();
        exit(0);
    }

    fclose($childSocket);
    stream_set_timeout($parentSocket, 15);
    try {
        $backendPid = (int) fgets($parentSocket);
        DB::reconnect();
        DB::beginTransaction();
        Workspace::withoutGlobalScopes()->whereKey($workspaceId)->lockForUpdate()->firstOrFail();
        fwrite($parentSocket, "go\n");
        $deadline = microtime(true) + 5;
        do {
            $waiting = DB::selectOne('SELECT wait_event_type FROM pg_stat_activity WHERE pid = ?', [$backendPid]);
            if ($waiting?->wait_event_type === 'Lock') {
                break;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        if ($waiting?->wait_event_type !== 'Lock') {
            throw new RuntimeException('Competing action did not wait on the workspace lock.');
        }
        $winningAction();
        DB::commit();

        return json_decode((string) fgets($parentSocket), true, flags: JSON_THROW_ON_ERROR);
    } finally {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        fclose($parentSocket);
        pcntl_waitpid($pid, $status);
    }
}
