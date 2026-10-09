<?php

use App\Actions\FormulaSharing\AcceptFormulaShare;
use App\Actions\FormulaSharing\CloseFormulaShare;
use App\Actions\FormulaSharing\SendFormulaShare;
use App\Enums\FormulaShareStatus;
use App\Enums\IngredientCategory;
use App\Enums\OwnerType;
use App\Enums\WorkspaceMemberRole;
use App\Models\FormulaShare;
use App\Models\Ingredient;
use App\Models\IngredientShareMapping;
use App\Models\Recipe;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\EntitlementService;
use App\Services\FormulaSharePreview;
use App\Services\FormulaShareSnapshotBuilder;
use App\Services\IngredientShareGraph;
use App\Services\RecipeEditingService;
use App\Services\RecipeWorkbenchService;
use App\Services\UserIngredientAuthoringService;
use App\Services\WorkspaceMembershipService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FormulaSharePostgresDatabase;
use Tests\Support\FormulaSharePostgresRace;
use Tests\Support\FormulaSharingFixtures;

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql' || ! filter_var(env('VERIFY_FORMULA_SHARING_POSTGRES', false), FILTER_VALIDATE_BOOL) || ! function_exists('pcntl_fork')) {
        $this->markTestSkipped('Requires explicit disposable formula-sharing PostgreSQL opt-in and pcntl.');
    }
    FormulaSharePostgresDatabase::reset();
    $this->artisan('migrate', ['--no-interaction' => true])->assertSuccessful();
    config(['workspaces.formula_sharing.enabled' => true, 'workspaces.collaboration_enabled' => true]);
});

/** @return array<string, mixed> */
function formulaShareRaceContext(): array
{
    $context = FormulaSharingFixtures::offer('cosmetic');
    $workspace = $context['recipient'];
    app(EntitlementService::class)->planForWorkspace($workspace)->update(['allows_collaboration' => true]);
    $admin = User::factory()->create(['active_workspace_id' => $workspace->id]);
    WorkspaceMember::factory()->create(['workspace_id' => $workspace->id, 'user_id' => $admin->id, 'role' => WorkspaceMemberRole::Admin]);
    $context['admin'] = $admin;

    return $context;
}

/** @param array<string, mixed> $context */
function formulaShareRaceOther(array $context, bool $different = false): FormulaShare
{
    $recipe = $context['recipe'];
    if ($different) {
        $payload = $context['payload'];
        $replacements = [];
        foreach ([$context['oil'], $context['liquid']] as $source) {
            $copy = Ingredient::factory()->create(['workspace_id' => $context['source']->id, 'owner_type' => OwnerType::Workspace, 'owner_id' => $context['source']->id, 'inci_name' => $source->inci_name]);
            $replacements[$source->id] = $copy->id;
        }
        foreach ($payload['phase_items'] as &$rows) {
            foreach ($rows as &$row) {
                $row['ingredient_id'] = $replacements[$row['ingredient_id']];
            }
            unset($row);
        }
        unset($rows);
        $working = app(RecipeWorkbenchService::class)->publish($context['owner'], $context['family'], $payload);
        $recipe = Recipe::withoutGlobalScopes()->findOrFail($working->recipe_id);
    }
    $builder = app(FormulaShareSnapshotBuilder::class);
    $snapshot = $builder->build($context['owner'], $recipe, $context['share']->options);

    return app(SendFormulaShare::class)->handle($context['owner'], $recipe, $context['recipient'], $context['share']->options, $builder->previewHash($snapshot, $context['recipient']), (string) Str::uuid());
}

/** @param array<string, mixed> $context */
function formulaShareRaceLocks(array $context, ?User $actor = null): void
{
    if ($actor !== null) {
        User::withoutGlobalScopes()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
    }
    Workspace::withoutGlobalScopes()->whereIn('id', [$context['source']->id, $context['recipient']->id])->orderBy('id')->lockForUpdate()->get();
}

it('serializes distinct actor replays of one grant without duplicate Products or private Ingredients', function (): void {
    $context = formulaShareRaceContext();
    extract($context);
    $receiver = $recipient->owner;
    $hash = app(FormulaSharePreview::class)->build($admin, $share, [])['expected_hash'];
    $acceptedId = null;
    $result = FormulaSharePostgresRace::run(
        fn (): int => app(AcceptFormulaShare::class)->handle($admin, $share, [], $hash)->id,
        fn () => formulaShareRaceLocks($context, $receiver),
        function () use ($receiver, $share, $hash, &$acceptedId): void {
            $acceptedId = app(AcceptFormulaShare::class)->handle($receiver, $share, [], $hash)->id;
        },
    );
    expect($result)->toBe(['status' => 'ok', 'value' => $acceptedId])
        ->and(Recipe::withoutGlobalScopes()->where('workspace_id', $recipient->id)->count())->toBe(1)
        ->and(Ingredient::withoutGlobalScopes()->where('workspace_id', $recipient->id)->count())->toBe(2);
});

it('serializes different grants for the same private lineage and requires a fresh review before exact reuse', function (): void {
    $context = formulaShareRaceContext();
    extract($context);
    $other = formulaShareRaceOther($context);
    $receiver = $recipient->owner;
    $hash = app(FormulaSharePreview::class)->build($admin, $other, [])['expected_hash'];
    $result = FormulaSharePostgresRace::run(
        fn (): int => app(AcceptFormulaShare::class)->handle($admin, $other, [], $hash)->id,
        fn () => formulaShareRaceLocks($context, $receiver),
        fn () => app(AcceptFormulaShare::class)->handle($receiver, $share, [], app(FormulaSharePreview::class)->build($receiver, $share, [])['expected_hash']),
    );
    expect($result['status'])->toBe('validation')->and($other->fresh()->status)->toBe(FormulaShareStatus::Pending);
    $fresh = app(FormulaSharePreview::class)->build($admin, $other, []);
    expect($fresh['import_count'])->toBe(0);
    app(AcceptFormulaShare::class)->handle($admin, $other, [], $fresh['expected_hash']);
    expect(Recipe::withoutGlobalScopes()->where('workspace_id', $recipient->id)->count())->toBe(2)
        ->and(Ingredient::withoutGlobalScopes()->where('workspace_id', $recipient->id)->count())->toBe(2)->and(IngredientShareMapping::query()->count())->toBe(2);
});

it('reserves only the final destination quota capacity across distinct actors and unrelated grants', function (string $quota): void {
    $context = formulaShareRaceContext();
    extract($context);
    $other = formulaShareRaceOther($context, different: true);
    app(EntitlementService::class)->planForWorkspace($recipient)->limits()->where('key', $quota)->update(['value' => $quota === 'saved_recipes' ? 1 : 3]);
    $receiver = $recipient->owner;
    $hash = app(FormulaSharePreview::class)->build($admin, $other, [])['expected_hash'];
    $result = FormulaSharePostgresRace::run(
        fn (): int => app(AcceptFormulaShare::class)->handle($admin, $other, [], $hash)->id,
        fn () => formulaShareRaceLocks($context, $receiver),
        fn () => app(AcceptFormulaShare::class)->handle($receiver, $share, [], app(FormulaSharePreview::class)->build($receiver, $share, [])['expected_hash']),
    );
    expect($result['status'])->toBe('validation')->and($share->fresh()->status)->toBe(FormulaShareStatus::Accepted)->and($other->fresh()->status)->toBe(FormulaShareStatus::Pending)
        ->and(Recipe::withoutGlobalScopes()->where('workspace_id', $recipient->id)->count())->toBe(1)
        ->and(Ingredient::withoutGlobalScopes()->where('workspace_id', $recipient->id)->count())->toBe(2)->and(IngredientShareMapping::query()->count())->toBe(2);
})->with(['saved_recipes', 'private_ingredients']);

it('orders acceptance against revocation without partial import in either winning order', function (bool $revokeWins): void {
    $context = formulaShareRaceContext();
    extract($context);
    $receiver = $recipient->owner;
    $hash = app(FormulaSharePreview::class)->build($receiver, $share, [])['expected_hash'];
    $result = FormulaSharePostgresRace::run(
        $revokeWins ? fn (): int => app(AcceptFormulaShare::class)->handle($receiver, $share, [], $hash)->id : fn () => app(CloseFormulaShare::class)->handle($owner, $share, FormulaShareStatus::Revoked),
        fn () => formulaShareRaceLocks($context, $revokeWins ? $owner : $receiver),
        $revokeWins ? fn () => app(CloseFormulaShare::class)->handle($owner, $share, FormulaShareStatus::Revoked) : fn () => app(AcceptFormulaShare::class)->handle($receiver, $share, [], $hash),
    );
    expect($result['status'])->toBe($revokeWins ? 'authorization' : 'validation')->and($share->fresh()->status)->toBe($revokeWins ? FormulaShareStatus::Revoked : FormulaShareStatus::Accepted)
        ->and(Recipe::withoutGlobalScopes()->where('workspace_id', $recipient->id)->count())->toBe($revokeWins ? 0 : 1)
        ->and(Ingredient::withoutGlobalScopes()->where('workspace_id', $recipient->id)->count())->toBe($revokeWins ? 0 : 2);
})->with([true, false]);

it('observes ordinary read committed creation before checking destination capacity even from an older sharing snapshot', function (string $kind, bool $noWait): void {
    $context = formulaShareRaceContext();
    extract($context);
    $receiver = $recipient->owner;
    $quota = $kind === 'product' ? 'saved_recipes' : 'private_ingredients';
    app(EntitlementService::class)->planForWorkspace($recipient)->limits()->where('key', $quota)->update(['value' => $kind === 'product' ? 1 : 2]);
    $hash = app(FormulaSharePreview::class)->build($admin, $share, [])['expected_hash'];
    $platform = Ingredient::factory()->create();
    $ordinaryPayload = $payload;
    foreach ($ordinaryPayload['phase_items'] as &$rows) {
        foreach ($rows as &$row) {
            $row['ingredient_id'] = $platform->id;
        }
        unset($row);
    }
    unset($rows);
    $result = FormulaSharePostgresRace::run(
        fn (): int => app(AcceptFormulaShare::class)->handle($admin, $share, [], $hash)->id,
        fn () => formulaShareRaceLocks($context, $receiver),
        function () use ($kind, $receiver, $family, $ordinaryPayload): void {
            if ($kind === 'product') {
                app(RecipeWorkbenchService::class)->publish($receiver, $family, $ordinaryPayload);
            } else {
                $authoring = app(UserIngredientAuthoringService::class);
                $state = $authoring->blankState();
                $state['name'] = 'Ordinary local material';
                $state['inci_name'] = 'ORDINARY LOCAL MATERIAL';
                $state['category'] = IngredientCategory::Other->value;
                $authoring->create($state, $receiver);
            }
        },
        repeatableParent: false,
        pauseAfterActor: $noWait,
    );
    expect($result['status'])->toBe('validation')->and($share->fresh()->status)->toBe(FormulaShareStatus::Pending)
        ->and(Recipe::withoutGlobalScopes()->where('workspace_id', $recipient->id)->count())->toBe($kind === 'product' ? 1 : 0)
        ->and(Ingredient::withoutGlobalScopes()->where('workspace_id', $recipient->id)->count())->toBe($kind === 'product' ? 0 : 1)
        ->and(IngredientShareMapping::query()->count())->toBe(0);
})->with([['product', false], ['ingredient', false], ['product', true]]);

it('observes an ordinary restoration becoming active before checking the last Product allowance', function (): void {
    $context = formulaShareRaceContext();
    extract($context);
    $receiver = $recipient->owner;
    $platform = Ingredient::factory()->create();
    $ordinaryPayload = $payload;
    foreach ($ordinaryPayload['phase_items'] as &$rows) {
        foreach ($rows as &$row) {
            $row['ingredient_id'] = $platform->id;
        }
        unset($row);
    }
    unset($rows);
    $working = app(RecipeWorkbenchService::class)->publish($receiver, $family, $ordinaryPayload);
    $archived = Recipe::withoutGlobalScopes()->findOrFail($working->recipe_id);
    app(RecipeEditingService::class)->withLockedRecipe($archived, fn (Recipe $locked) => $locked->update(['archived_at' => now()]));
    app(EntitlementService::class)->planForWorkspace($recipient)->limits()->where('key', 'saved_recipes')->update(['value' => 1]);
    $hash = app(FormulaSharePreview::class)->build($admin, $share, [])['expected_hash'];
    $result = FormulaSharePostgresRace::run(
        fn (): int => app(AcceptFormulaShare::class)->handle($admin, $share, [], $hash)->id,
        fn () => formulaShareRaceLocks($context, $receiver),
        fn () => app(RecipeEditingService::class)->withLockedRecipe($archived, fn (Recipe $locked) => $locked->update(['archived_at' => null])),
        repeatableParent: false,
    );
    expect($result['status'])->toBe('validation')->and($archived->fresh()->archived_at)->toBeNull()
        ->and(Recipe::withoutGlobalScopes()->where('workspace_id', $recipient->id)->count())->toBe(1)
        ->and(Ingredient::withoutGlobalScopes()->where('workspace_id', $recipient->id)->count())->toBe(0)->and($share->fresh()->status)->toBe(FormulaShareStatus::Pending);
});

it('reauthorizes an Admin demoted after its first actor query and leaves no imported rows', function (): void {
    $context = formulaShareRaceContext();
    extract($context);
    $receiver = $recipient->owner;
    $member = WorkspaceMember::withoutGlobalScopes()->where('workspace_id', $recipient->id)->where('user_id', $admin->id)->firstOrFail();
    $hash = app(FormulaSharePreview::class)->build($admin, $share, [])['expected_hash'];
    $result = FormulaSharePostgresRace::run(
        fn (): int => app(AcceptFormulaShare::class)->handle($admin, $share, [], $hash)->id,
        fn () => formulaShareRaceLocks($context, $receiver),
        fn () => app(WorkspaceMembershipService::class)->updateRole($receiver, $member, WorkspaceMemberRole::Editor),
        repeatableParent: false,
    );
    expect($result['status'])->toBe('authorization')->and($member->fresh()->role)->toBe(WorkspaceMemberRole::Editor)
        ->and(Recipe::withoutGlobalScopes()->where('workspace_id', $recipient->id)->count())->toBe(0)
        ->and(Ingredient::withoutGlobalScopes()->where('workspace_id', $recipient->id)->count())->toBe(0)->and($share->fresh()->status)->toBe(FormulaShareStatus::Pending);
});

it('captures a coherent platform dependency snapshot across a child-only catalogue transaction', function (): void {
    $context = formulaShareRaceContext();
    extract($context);
    $child = Ingredient::factory()->create();
    $child->sapProfile()->create(['koh_sap_value' => '0.180000']);
    $parent = Ingredient::factory()->create();
    $component = $parent->components()->create(['component_ingredient_id' => $child->id, 'percentage_in_parent' => '100.00000', 'sort_order' => 1]);
    $saved->items()->withoutGlobalScopes()->where('ingredient_id', $oil->id)->update(['ingredient_id' => $parent->id]);
    $result = FormulaSharePostgresRace::run(
        function () use ($owner, $recipe, $child, $parent): array {
            $snapshot = app(FormulaShareSnapshotBuilder::class)->build($owner, $recipe, []);
            $nodes = collect($snapshot['ingredients']['nodes']);
            $childNode = $nodes->first(fn (array $node): bool => data_get($node, 'platform_reference.public_id') === $child->public_id);
            $parentNode = $nodes->first(fn (array $node): bool => data_get($node, 'platform_reference.public_id') === $parent->public_id);

            return ['child_sap' => $childNode['technical']['sap_profile']['koh_sap_value'], 'component_percentage' => $parentNode['technical']['components'][0]['percentage_in_parent'], 'fingerprint' => $parentNode['fingerprint']];
        },
        fn () => Workspace::withoutGlobalScopes()->whereKey($source->id)->lockForUpdate()->firstOrFail(),
        function () use ($child, $component): void {
            $child->sapProfile()->update(['koh_sap_value' => '0.250000']);
            $component->update(['percentage_in_parent' => '50.00000']);
        },
        repeatableParent: false,
    );
    expect($result['status'])->toBe('ok')->and($result['value']['child_sap'])->toBe('0.180000')->and($result['value']['component_percentage'])->toBe('100.00000');
    $current = app(IngredientShareGraph::class)->current($source, [$parent->id]);
    expect($current['nodes'][$current['root_keys'][0]]['fingerprint'])->not->toBe($result['value']['fingerprint']);
});
