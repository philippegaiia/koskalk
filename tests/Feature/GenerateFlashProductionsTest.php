<?php

use App\Actions\Production\GenerateFlashProductions;
use App\Enums\MassUnit;
use App\Enums\OwnerType;
use App\Enums\ProductionRunSource;
use App\Enums\Visibility;
use App\Models\Ingredient;
use App\Models\IngredientSapProfile;
use App\Models\ProductFamily;
use App\Models\ProductionLocation;
use App\Models\ProductionRun;
use App\Models\ProductionRunNumberSetting;
use App\Models\ProductionTaskSet;
use App\Models\ProductionTaskSetItem;
use App\Models\ProductionTaskType;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Models\RecipePhase;
use App\Models\RecipeVersion;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceProductionEntitlement;
use App\Services\Production\FlashDateProposalService;
use App\Services\Production\FlashPlanFingerprint;
use App\Services\Production\FlashProductionSimulator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('creates one planned production per flash batch with tasks and is idempotent', function (): void {
    $fixture = generateFlashFixture();
    $lines = [
        [
            'recipe_id' => $fixture['recipe']->id,
            'desired_units' => '250',
            'expected_units_per_batch' => '100',
            'basis_input_value' => '12',
            'basis_input_unit' => 'kg',
            'task_set_id' => $fixture['taskSet']->id,
        ],
    ];

    $action = app(GenerateFlashProductions::class);
    $first = $action->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        lines: $lines,
        firstDate: '2026-08-10',
        batchesPerDay: 2,
        idempotencyKey: 'flash-demo-1',
    );
    $second = $action->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        lines: $lines,
        firstDate: '2026-08-10',
        batchesPerDay: 2,
        idempotencyKey: 'flash-demo-1',
    );

    expect($first)->toHaveCount(3)
        ->and($second)->toHaveCount(3)
        ->and(ProductionRun::query()->where('workspace_id', $fixture['workspace']->id)->count())->toBe(3)
        ->and(ProductionRun::query()->where('source', ProductionRunSource::Flash)->count())->toBe(3)
        ->and($first->pluck('planning_batch_number')->all())->toBe(['T00001', 'T00002', 'T00003'])
        ->and($second->pluck('planning_batch_number')->all())->toBe(['T00001', 'T00002', 'T00003'])
        ->and($first->pluck('planned_for')->map(fn ($date): string => $date->toDateString())->all())->toBe(['2026-08-10', '2026-08-10', '2026-08-11'])
        ->and($first->pluck('estimated_ready_on')->map(fn ($date): string => $date->toDateString())->all())->toBe(['2026-08-31', '2026-08-31', '2026-09-01'])
        ->and($first->every(fn (ProductionRun $production): bool => $production->tasks()->count() === 1))->toBeTrue()
        ->and(ProductionRunNumberSetting::query()->whereBelongsTo($fixture['workspace'])->sole()->next_planning_serial)->toBe(4);

    $firstProduction = $first->first();

    expect($firstProduction->recipe_name_snapshot)->toBe($fixture['recipe']->name)
        ->and($firstProduction->source_formula_version_number)->toBe($fixture['version']->version_number)
        ->and($firstProduction->formula_snapshot_completed_at)->not->toBeNull()
        ->and($firstProduction->formulaLines()->count())->toBe(3)
        ->and($firstProduction->formulaLines()->where('component', 'ingredient')->count())->toBe(1)
        ->and($firstProduction->formulaLines()->where('component', 'naoh')->count())->toBe(1)
        ->and($firstProduction->formulaLines()->where('component', 'water')->count())->toBe(1)
        ->and($firstProduction->requirements()->count())->toBe(2);
});

it('leaves generated flash runs unnumbered and does not advance the permanent counter', function (): void {
    $fixture = generateFlashFixture();
    ProductionRunNumberSetting::query()->create([
        'workspace_id' => $fixture['workspace']->id,
        'next_permanent_serial' => 17,
    ]);

    $productions = app(GenerateFlashProductions::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        lines: [[
            'recipe_id' => $fixture['recipe']->id,
            'desired_units' => '250',
            'expected_units_per_batch' => '100',
            'basis_input_value' => '12',
            'basis_input_unit' => 'kg',
            'task_set_id' => $fixture['taskSet']->id,
        ]],
        firstDate: '2026-08-10',
        batchesPerDay: 2,
        idempotencyKey: 'flash-numbering-1',
    );

    expect($productions->every(fn (ProductionRun $production): bool => $production->source === ProductionRunSource::Flash
        && $production->batch_number === null
        && $production->batch_number_serial === null
        && $production->batch_number_assigned_at === null
        && $production->batch_number_assigned_by_user_id === null))->toBeTrue()
        ->and($fixture['workspace']->fresh()->productionRunNumberSetting->next_permanent_serial)->toBe(17);
});

it('rolls back every generated production when a later flash line is invalid', function (): void {
    $fixture = generateFlashFixture();
    ProductionRunNumberSetting::query()->create([
        'workspace_id' => $fixture['workspace']->id,
        'next_planning_serial' => 17,
    ]);
    $foreignOwner = User::factory()->create();
    $foreignWorkspace = Workspace::factory()->for($foreignOwner, 'owner')->create();
    $invalidTaskSet = ProductionTaskSet::factory()->for($fixture['workspace'])->create([
        'name' => 'Foreign task type',
    ]);
    ProductionTaskSetItem::factory()
        ->for($invalidTaskSet, 'taskSet')
        ->for(ProductionTaskType::factory()->for($foreignWorkspace), 'taskType')
        ->create([
            'position' => 1,
            'days_after_production' => 0,
        ]);
    $invalidTaskSet->recipes()->attach($fixture['recipe']->id, ['is_default' => false]);

    expect(fn () => app(GenerateFlashProductions::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        lines: [
            [
                'recipe_id' => $fixture['recipe']->id,
                'desired_units' => '100',
                'expected_units_per_batch' => '100',
                'basis_input_value' => '12',
                'basis_input_unit' => 'kg',
                'task_set_id' => $fixture['taskSet']->id,
            ],
            [
                'recipe_id' => $fixture['recipe']->id,
                'desired_units' => '100',
                'expected_units_per_batch' => '100',
                'basis_input_value' => '12',
                'basis_input_unit' => 'kg',
                'task_set_id' => $invalidTaskSet->id,
            ],
        ],
        firstDate: '2026-08-10',
        batchesPerDay: 1,
        idempotencyKey: 'flash-atomic-1',
    ))->toThrow(ValidationException::class);

    expect(ProductionRun::query()->where('workspace_id', $fixture['workspace']->id)->count())->toBe(0)
        ->and(ProductionRunNumberSetting::query()->whereBelongsTo($fixture['workspace'])->sole()->next_planning_serial)->toBe(17);
});

it('rejects reusing a flash key for a different number of batches', function (): void {
    $fixture = generateFlashFixture();
    $action = app(GenerateFlashProductions::class);
    $baseLine = [
        'recipe_id' => $fixture['recipe']->id,
        'expected_units_per_batch' => '100',
        'basis_input_value' => '12',
        'basis_input_unit' => 'kg',
    ];

    $action->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        lines: [$baseLine + ['desired_units' => '250']],
        firstDate: '2026-08-10',
        batchesPerDay: 1,
        idempotencyKey: 'flash-conflict-1',
    );

    expect(fn () => $action->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        lines: [$baseLine + ['desired_units' => '150']],
        firstDate: '2026-08-10',
        batchesPerDay: 1,
        idempotencyKey: 'flash-conflict-1',
    ))->toThrow(ValidationException::class);
});

/** @return array{owner: User, workspace: Workspace, recipe: Recipe, taskSet: ProductionTaskSet} */
function generateFlashFixture(): array
{
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    WorkspaceProductionEntitlement::factory()->for($workspace)->create();
    $family = ProductFamily::factory()->create([
        'calculation_basis' => 'initial_oils',
        'slug' => 'generate-flash-family-'.fake()->unique()->numberBetween(1, 999999),
    ]);
    $recipe = Recipe::factory()->for($family, 'productFamily')->create([
        'workspace_id' => $workspace->id,
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'visibility' => Visibility::Private,
    ]);
    RecipeVersion::factory()->for($recipe)->create([
        'workspace_id' => $workspace->id,
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'visibility' => Visibility::Private,
        'is_current' => false,
    ]);
    $ingredient = Ingredient::factory()->create(['display_name' => 'Olive oil']);
    Ingredient::factory()->create([
        'catalog_key' => 'CH1',
        'display_name' => 'Sodium hydroxide',
        'inci_name' => 'SODIUM HYDROXIDE',
        'is_soap_saponification_trusted' => false,
        'requires_admin_review' => false,
    ]);
    IngredientSapProfile::factory()->create(['ingredient_id' => $ingredient->id, 'koh_sap_value' => 0.188]);
    $version = RecipeVersion::withoutGlobalScopes()
        ->where('recipe_id', $recipe->id)
        ->where('is_current', false)
        ->firstOrFail();
    $phase = RecipePhase::factory()->for($version)->create([
        'workspace_id' => $workspace->id,
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'visibility' => Visibility::Private,
        'name' => 'Saponified Oils',
        'slug' => 'saponified_oils',
        'sort_order' => 1,
    ]);
    RecipeItem::factory()->for($version)->for($phase, 'recipePhase')->for($ingredient)->create([
        'workspace_id' => $workspace->id,
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'visibility' => Visibility::Private,
        'position' => 1,
        'percentage' => '100.0000',
        'weight' => null,
    ]);
    $taskType = ProductionTaskType::factory()->for($workspace)->create(['name' => 'Make']);
    $taskSet = ProductionTaskSet::factory()->for($workspace)->create(['name' => 'Flash set']);
    ProductionTaskSetItem::factory()->for($taskSet, 'taskSet')->for($taskType, 'taskType')->create([
        'position' => 1,
        'days_after_production' => 0,
    ]);
    $taskSet->recipes()->attach($recipe->id, ['is_default' => true]);

    return compact('owner', 'workspace', 'recipe', 'version', 'taskSet');
}

it('replays flash after other work is scheduled without creating or moving runs', function (): void {
    $fixture = generateFlashFixture();
    $lines = [['recipe_id' => $fixture['recipe']->id, 'desired_units' => '100', 'expected_units_per_batch' => '100', 'basis_input_value' => '12', 'basis_input_unit' => 'kg', 'task_set_id' => $fixture['taskSet']->id]];
    $action = app(GenerateFlashProductions::class);
    $first = $action->handle($fixture['owner'], $fixture['workspace'], $lines, '2026-09-21', 1, 'stable-flash');
    $other = $action->handle($fixture['owner'], $fixture['workspace'], $lines, '2026-09-21', 1, 'other-flash');
    $retry = $action->handle($fixture['owner'], $fixture['workspace'], $lines, '2026-09-21', 1, 'stable-flash');
    expect($other->first()->planned_for->toDateString())->toBe('2026-09-22');
    expect($retry->pluck('id')->all())->toBe($first->pluck('id')->all());
    expect(ProductionRun::query()->count())->toBe(2);
});

it('replays a completed submission after its recipe and location are archived', function (): void {
    $fixture = generateFlashFixture();
    $fixture['workspace']->update(['uses_production_locations' => true]);
    $location = ProductionLocation::factory()->for($fixture['workspace'])->create();
    $lines = [['recipe_id' => $fixture['recipe']->id, 'desired_units' => '100', 'expected_units_per_batch' => '100', 'basis_input_value' => '12', 'basis_input_unit' => 'kg', 'production_location_id' => $location->id]];
    $action = app(GenerateFlashProductions::class);
    $first = $action->handle($fixture['owner'], $fixture['workspace'], $lines, '2026-09-21', 1, 'archived-replay');
    $location->update(['is_active' => false]);
    $fixture['recipe']->update(['archived_at' => now()]);
    $fixture['workspace']->update(['uses_production_locations' => false]);
    $retry = $action->handle($fixture['owner'], $fixture['workspace'], $lines, '2026-09-21', 1, 'archived-replay');
    expect($retry->pluck('id')->all())->toBe($first->pluck('id')->all());
});

it('rejects a partially deleted submission without recreating missing batches', function (): void {
    $fixture = generateFlashFixture();
    $lines = [['recipe_id' => $fixture['recipe']->id, 'desired_units' => '200', 'expected_units_per_batch' => '100', 'basis_input_value' => '12', 'basis_input_unit' => 'kg']];
    $action = app(GenerateFlashProductions::class);
    $first = $action->handle($fixture['owner'], $fixture['workspace'], $lines, '2026-09-21', 1, 'partial-replay');
    $first->last()->delete();
    expect(fn () => $action->handle($fixture['owner'], $fixture['workspace'], $lines, '2026-09-21', 1, 'partial-replay'))->toThrow(ValidationException::class);
    expect(ProductionRun::query()->count())->toBe(1);
});

it('does not recreate a submission after every generated run was deleted', function (): void {
    $fixture = generateFlashFixture();
    $lines = [['recipe_id' => $fixture['recipe']->id, 'desired_units' => '100', 'expected_units_per_batch' => '100', 'basis_input_value' => '12', 'basis_input_unit' => 'kg']];
    $action = app(GenerateFlashProductions::class);
    $first = $action->handle($fixture['owner'], $fixture['workspace'], $lines, '2026-09-21', 1, 'deleted-replay');
    $first->first()->delete();
    expect(fn () => $action->handle($fixture['owner'], $fixture['workspace'], $lines, '2026-09-21', 1, 'deleted-replay'))->toThrow(ValidationException::class);
    expect(ProductionRun::query()->count())->toBe(0);
});

it('replays equivalent numeric ids and enum values without treating them as changed input', function (): void {
    $fixture = generateFlashFixture();
    $line = ['recipe_id' => $fixture['recipe']->id, 'desired_units' => 100, 'expected_units_per_batch' => 100, 'basis_input_value' => '12', 'basis_input_unit' => MassUnit::Kilogram, 'task_set_id' => $fixture['taskSet']->id];
    $action = app(GenerateFlashProductions::class);
    $first = $action->handle($fixture['owner'], $fixture['workspace'], [$line], '2026-09-21', 1, 'normalized-replay');
    $line['recipe_id'] = '0'.$fixture['recipe']->id;
    $line['task_set_id'] = '0'.$fixture['taskSet']->id;
    $line['basis_input_unit'] = 'kg';
    $line['desired_units'] = ' 100 ';
    $retry = $action->handle($fixture['owner'], $fixture['workspace'], [$line], '2026-09-21', 1, 'normalized-replay');
    expect($retry->pluck('id')->all())->toBe($first->pluck('id')->all());
});

it('keeps an explicitly cleared flash task set consistent with its accepted preview', function (): void {
    $fixture = generateFlashFixture();
    $lines = [['recipe_id' => $fixture['recipe']->id, 'desired_units' => '100', 'expected_units_per_batch' => '100', 'basis_input_value' => '12', 'basis_input_unit' => 'kg', 'task_set_id' => null]];
    $simulation = app(FlashProductionSimulator::class)->simulate($fixture['workspace'], $lines);
    $proposal = app(FlashDateProposalService::class)->propose($fixture['workspace'], $simulation['lines'], '2026-09-21', 1);
    $fingerprint = app(FlashPlanFingerprint::class)->proposal($simulation, $proposal, $fixture['workspace'], 1);
    expect($proposal[0]['tasks'])->toBe([]);
    $runs = app(GenerateFlashProductions::class)->handle($fixture['owner'], $fixture['workspace'], $lines, '2026-09-21', 1, 'cleared-task-set', $fingerprint);
    expect($runs->first()->tasks)->toHaveCount(0)->and($runs->first()->production_task_set_id)->toBeNull();
});

it('ignores stale location input on retries of submissions created with locations disabled', function (): void {
    $fixture = generateFlashFixture();
    $lines = [['recipe_id' => $fixture['recipe']->id, 'desired_units' => '100', 'expected_units_per_batch' => '100', 'basis_input_value' => '12', 'basis_input_unit' => 'kg', 'production_location_id' => 999999]];
    $action = app(GenerateFlashProductions::class);
    $first = $action->handle($fixture['owner'], $fixture['workspace'], $lines, '2026-09-21', 1, 'disabled-location-replay');
    $lines[0]['production_location_id'] = null;
    $retry = $action->handle($fixture['owner'], $fixture['workspace'], $lines, '2026-09-21', 1, 'disabled-location-replay');
    expect($retry->pluck('id')->all())->toBe($first->pluck('id')->all());
    $fixture['workspace']->update(['uses_production_locations' => true]);
    $retry = $action->handle($fixture['owner'], $fixture['workspace'], $lines, '2026-09-21', 1, 'disabled-location-replay');
    expect($retry->pluck('id')->all())->toBe($first->pluck('id')->all());
});
