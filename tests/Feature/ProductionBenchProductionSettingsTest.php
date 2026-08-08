<?php

use App\Enums\OwnerType;
use App\Enums\Visibility;
use App\Livewire\ProductionBench\Production\SettingsIndex;
use App\Models\Employee;
use App\Models\ProductFamily;
use App\Models\ProductionBatchPreset;
use App\Models\ProductionHoliday;
use App\Models\ProductionTaskSet;
use App\Models\ProductionTaskType;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceProductionEntitlement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Lang;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('renders the setup workspace and saves employee, task, task set, and calendar settings', function (): void {
    $fixture = productionSettingsFixture();

    $page = Livewire::actingAs($fixture['owner'])->test(SettingsIndex::class);

    $page->assertSee(__('production_bench.settings.title'))
        ->assertSee(__('production_bench.settings.presets'))
        ->set('employeeFirstName', 'Ana')
        ->set('employeeLastName', 'Maker')
        ->call('saveEmployee')
        ->assertHasNoErrors()
        ->set('taskTypeName', 'Pour')
        ->set('taskTypeDuration', '45')
        ->call('saveTaskType')
        ->assertHasNoErrors();

    $employee = Employee::query()->where('workspace_id', $fixture['workspace']->id)->firstOrFail();
    $taskType = ProductionTaskType::query()->where('workspace_id', $fixture['workspace']->id)->firstOrFail();

    $page->set('taskSetName', 'Soap workflow')
        ->set('taskSetRecipeIds', [(string) $fixture['recipe']->id])
        ->set('taskSetDefaultRecipeIds', [(string) $fixture['recipe']->id])
        ->set('taskSetItems', [[
            'task_type_id' => $taskType->id,
            'days_after_production' => 0,
            'duration_minutes' => '',
        ]])
        ->call('saveTaskSet')
        ->assertHasNoErrors()
        ->set('holidayName', 'Summer closure')
        ->set('holidayDate', '2026-08-15')
        ->set('holidayIsRecurring', true)
        ->call('saveHoliday')
        ->assertHasNoErrors()
        ->set('worksOnWeekends', true)
        ->call('saveCalendar')
        ->assertHasNoErrors();

    expect($employee->fresh()->first_name)->toBe('Ana')
        ->and($taskType->fresh()->default_duration_minutes)->toBe(45)
        ->and(ProductionTaskSet::query()->where('workspace_id', $fixture['workspace']->id)->first()->name)->toBe('Soap workflow')
        ->and($fixture['recipe']->fresh()->defaultProductionTaskSets()->exists())->toBeTrue()
        ->and(ProductionHoliday::query()->where('workspace_id', $fixture['workspace']->id)->first()->is_recurring)->toBeTrue()
        ->and($fixture['workspace']->fresh()->production_works_on_weekends)->toBeTrue();
});

it('saves editable batch presets and keeps workspace data isolated', function (): void {
    $fixture = productionSettingsFixture();
    $other = productionSettingsFixture();

    Livewire::actingAs($fixture['owner'])->test(SettingsIndex::class)
        ->set('presetRecipeIds', [(string) $fixture['recipe']->id])
        ->set('presetName', 'Default soap batch')
        ->set('presetBasisInputValue', '12')
        ->set('presetBasisInputUnit', 'kg')
        ->set('presetExpectedUnits', '100')
        ->set('presetDefaultRecipeIds', [(string) $fixture['recipe']->id])
        ->call('savePreset')
        ->assertHasNoErrors();

    $preset = ProductionBatchPreset::query()->where('workspace_id', $fixture['workspace']->id)->firstOrFail();

    expect($preset->recipes()->whereKey($fixture['recipe']->id)->exists())->toBeTrue()
        ->and($preset->basis_quantity_grams)->toBe('12000.000000000')
        ->and($preset->fresh()->defaultRecipes()->whereKey($fixture['recipe']->id)->exists())->toBeTrue();

    Livewire::actingAs($other['owner'])->test(SettingsIndex::class)
        ->assertDontSee($preset->name);
});

it('keeps the setup page read-only when the entitlement is cancelled', function (): void {
    $fixture = productionSettingsFixture();
    $fixture['workspace']->productionEntitlement()->update([
        'status' => 'cancelled',
        'cancelled_at' => now(),
    ]);

    Livewire::actingAs($fixture['owner'])->test(SettingsIndex::class)
        ->set('employeeFirstName', 'Blocked')
        ->set('employeeLastName', 'Maker')
        ->call('saveEmployee')
        ->assertHasErrors('employeeFirstName');

    expect(Employee::query()->where('workspace_id', $fixture['workspace']->id)->count())->toBe(0);
});

it('has English labels for the setup workspace', function (): void {
    expect(Lang::has('production_bench.settings.title', 'en'))->toBeTrue()
        ->and(Lang::get('production_bench.navigation.production', [], 'en'))->toBe('Production setup');
});

it('deletes unused templates while protecting task types used by a task set', function (): void {
    $fixture = productionSettingsFixture();
    $page = Livewire::actingAs($fixture['owner'])->test(SettingsIndex::class);
    $taskType = ProductionTaskType::factory()->for($fixture['workspace'])->create();
    $taskSet = ProductionTaskSet::factory()->for($fixture['workspace'])->create();
    $taskSet->items()->create([
        'production_task_type_id' => $taskType->id,
        'position' => 1,
        'days_after_production' => 0,
    ]);
    $preset = ProductionBatchPreset::factory()->for($fixture['workspace'])->create();

    $page->call('deleteTaskType', $taskType->id)
        ->assertHasErrors('taskTypeName');

    Livewire::actingAs($fixture['owner'])->test(SettingsIndex::class)
        ->call('deleteTaskSet', $taskSet->id)
        ->call('deleteTaskType', $taskType->id)
        ->call('deletePreset', $preset->id)
        ->assertHasNoErrors();

    expect(ProductionTaskSet::query()->find($taskSet->id))->toBeNull()
        ->and(ProductionTaskType::query()->find($taskType->id))->toBeNull()
        ->and(ProductionBatchPreset::query()->find($preset->id))->toBeNull();
});

it('deletes employees from the setup list', function (): void {
    $fixture = productionSettingsFixture();
    $employee = Employee::factory()->for($fixture['workspace'])->create();

    Livewire::actingAs($fixture['owner'])
        ->test(SettingsIndex::class)
        ->call('deleteEmployee', $employee->id)
        ->assertHasNoErrors();

    expect(Employee::query()->find($employee->id))->toBeNull();
});

it('organizes production setup into focused submenu routes', function (): void {
    $fixture = productionSettingsFixture();
    $sections = [
        'presets' => 'preset-heading',
        'employees' => 'employee-heading',
        'task-types' => 'task-type-heading',
        'task-sets' => 'task-set-heading',
        'calendar' => 'holiday-heading',
    ];

    foreach ($sections as $section => $heading) {
        $response = $this->actingAs($fixture['owner'])
            ->get(route("production-bench.production.settings.{$section}"));

        $response->assertOk()
            ->assertSee("id=\"{$heading}\"", false);

        foreach (array_diff(array_values($sections), [$heading]) as $otherHeading) {
            $response->assertDontSee("id=\"{$otherHeading}\"", false);
        }
    }

    $this->actingAs($fixture['owner'])
        ->get(route('production-bench.production.settings.employees'))
        ->assertSeeHtml('lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_minmax(0,1fr)_minmax(13rem,1fr)_auto_auto]')
        ->assertSeeHtml('lg:self-end')
        ->assertSeeHtml('lg:pb-5')
        ->assertDontSeeHtml('max-w-5xl')
        ->assertDontSeeHtml('lg:grid-cols-2');

    $this->actingAs($fixture['owner'])
        ->get(route('production-bench.production.settings.task-types'))
        ->assertSeeHtml('wire:submit="saveTaskType" class="sk-card grid gap-4 p-5"')
        ->assertDontSeeHtml('sm:grid-cols-2')
        ->assertDontSeeHtml('lg:grid-cols-2');
});

/** @return array{owner: User, workspace: Workspace, recipe: Recipe, version: RecipeVersion} */
function productionSettingsFixture(): array
{
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    WorkspaceProductionEntitlement::factory()->for($workspace)->create();
    $family = ProductFamily::factory()->create([
        'slug' => 'settings-soap-'.fake()->unique()->numberBetween(1, 999999),
        'calculation_basis' => 'initial_oils',
    ]);
    $recipe = Recipe::factory()->for($family, 'productFamily')->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
        'visibility' => Visibility::Private,
        'name' => 'Workshop soap',
    ]);
    $version = RecipeVersion::factory()->for($recipe)->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
        'visibility' => Visibility::Private,
        'is_current' => false,
        'batch_mass_grams' => '1000.000000000',
    ]);

    return compact('owner', 'workspace', 'recipe', 'version');
}
