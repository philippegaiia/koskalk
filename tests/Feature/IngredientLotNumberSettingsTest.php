<?php

use App\Enums\WorkspaceMemberRole;
use App\Livewire\ProductionBench\Production\IngredientLotNumberSettings;
use App\Models\IngredientLotNumberCounter;
use App\Models\IngredientLotNumberSetting;
use App\Models\InterfaceTranslation;
use App\Models\ProductionRunNumberSetting;
use App\Models\SupportedLocale;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Models\WorkspaceProductionEntitlement;
use App\Services\Translations\EnglishTranslationSource;
use App\Services\Translations\InterfaceTranslationCatalogue;
use Database\Seeders\SupportedLocaleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('renders numbering settings and validation in each supported language', function (string $locale, string $heading, string $noDate): void {
    $this->seed(SupportedLocaleSeeder::class);
    SupportedLocale::query()->where('code', $locale)->update(['is_active' => true]);
    expect(app(EnglishTranslationSource::class)->get('lot_numbering', 'title'))->toBe('Ingredient internal lot numbers');
    $rows = collect(app(InterfaceTranslationCatalogue::class)->read(database_path('seeders/data/interface-translations.json'))['translations'])
        ->where('group', 'lot_numbering')->keyBy('key');
    foreach ($rows as $row) {
        InterfaceTranslation::query()->create($row);
    }
    [$owner] = lotSettingsWorkspace();
    $owner->update(['locale' => $locale]);
    app()->setLocale($locale);

    $this->actingAs($owner)->get(route('production-bench.production.settings.numbering'))
        ->assertSeeText($heading)
        ->assertSeeText($rows['production_title']['text'][$locale])
        ->assertDontSeeText('Ingredient internal lot numbers');

    Livewire::actingAs($owner)->test(IngredientLotNumberSettings::class)
        ->set('data.date_format', 'none')
        ->assertSee($noDate)
        ->assertSee($rows['no_date_help']['text'][$locale])
        ->assertSee($rows['counter_change_help']['text'][$locale])
        ->set('data.prefix', '<script>')->call('save')
        ->assertHasErrors('data.prefix')
        ->assertSee($rows['validation.affix']['text'][$locale]);

    foreach ($rows as $key => $row) {
        expect(__('lot_numbering.'.$key))->toBe($row['text'][$locale]);
    }
})->with([
    ['de', 'Interne Chargennummern für Zutaten', 'Kein Datum'],
    ['es', 'Números internos de lote de ingredientes', 'Sin fecha'],
    ['fr', 'Numéros de lot internes des ingrédients', 'Sans date'],
    ['it', 'Numeri di lotto interni degli ingredienti', 'Senza data'],
    ['nl', 'Interne lotnummers van ingrediënten', 'Geen datum'],
    ['pt_BR', 'Números internos de lote de ingredientes', 'Sem data'],
]);

function lotSettingsWorkspace(): array
{
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    WorkspaceProductionEntitlement::factory()->for($workspace)->create();

    return [$owner, $workspace];
}

it('shows two independent numbering sections and a formatted planning reference', function (): void {
    [$owner, $workspace] = lotSettingsWorkspace();
    ProductionRunNumberSetting::query()->create(['workspace_id' => $workspace->id, 'next_planning_serial' => 46]);
    $this->actingAs($owner)->get(route('production-bench.production.settings.numbering'))
        ->assertOk()->assertSee('Production run batch numbers')->assertSee('Ingredient internal lot numbers')->assertSee('T00046');
});

it('previews and saves ingredient settings without changing production numbering or another workspace', function (): void {
    $this->travelTo(now()->setDate(2026, 9, 23));
    [$owner, $workspace] = lotSettingsWorkspace();
    [, $other] = lotSettingsWorkspace();
    $production = ProductionRunNumberSetting::query()->create(['workspace_id' => $workspace->id, 'permanent_prefix' => 'BATCH-', 'next_permanent_serial' => 89]);
    Livewire::actingAs($owner)->test(IngredientLotNumberSettings::class)
        ->assertSet('data.padding', 1)
        ->set('data.prefix', 'LOT')->set('data.date_format', 'Ymd')
        ->set('data.include_material_code', true)->set('data.next_number', '42')
        ->assertSee('LOT-20260923-OLIVE-42')->assertSee(__('lot_numbering.unsaved'))
        ->call('save')->assertHasNoErrors()->assertDontSee(__('lot_numbering.unsaved'))
        ->assertDispatched('app-notification');
    expect(IngredientLotNumberSetting::query()->where('workspace_id', $workspace->id)->sole()->prefix)->toBe('LOT');
    expect(IngredientLotNumberSetting::query()->where('workspace_id', $other->id)->exists())->toBeFalse();
    expect($production->refresh()->permanent_prefix)->toBe('BATCH-');
    expect($production->next_permanent_serial)->toBe(89);
});

it('preserves the saved minimum counter digits', function (): void {
    [$owner, $workspace] = lotSettingsWorkspace();
    IngredientLotNumberSetting::query()->create(['workspace_id' => $workspace->id, 'padding' => 4]);

    Livewire::actingAs($owner)->test(IngredientLotNumberSettings::class)
        ->assertSet('data.padding', 4)
        ->call('save')->assertHasNoErrors();

    expect(IngredientLotNumberSetting::query()->where('workspace_id', $workspace->id)->sole()->padding)->toBe(4);
});

it('reports incompatible reset and date settings at the reset control', function (): void {
    [$owner] = lotSettingsWorkspace();
    Livewire::actingAs($owner)->test(IngredientLotNumberSettings::class)
        ->set('data.date_format', 'Y')->call('save')->assertHasErrors('data.reset_period')
        ->assertSee(__('lot_numbering.validation.reset_date'));
});

it('explains the counter change when removing the date before saving', function (?int $previousContinuousCounter): void {
    $this->travelTo(now()->setDate(2026, 9, 23));
    [$owner, $workspace] = lotSettingsWorkspace();
    $settings = IngredientLotNumberSetting::query()->create(['workspace_id' => $workspace->id]);
    IngredientLotNumberCounter::query()->create(['workspace_id' => $workspace->id, 'period' => 'daily:20260923', 'next_serial' => 43]);
    if ($previousContinuousCounter !== null) {
        IngredientLotNumberCounter::query()->create(['workspace_id' => $workspace->id, 'period' => 'never', 'next_serial' => $previousContinuousCounter]);
    }
    $expectedCounter = $previousContinuousCounter ?? 1;

    $component = Livewire::actingAs($owner)->test(IngredientLotNumberSettings::class)
        ->assertSet('data.next_number', 43)
        ->set('data.date_format', 'none')
        ->assertSet('data.reset_period', 'never')
        ->assertSet('data.next_number', $expectedCounter)
        ->assertSee('SK-'.$expectedCounter)
        ->assertSee(__('lot_numbering.counter_change', ['before' => 'Every day', 'after' => 'Never', 'number' => $expectedCounter]))
        ->assertSee(__('lot_numbering.no_date_help'));

    expect($settings->refresh()->date_format)->toBe('ymd');
    expect(IngredientLotNumberCounter::query()->where('workspace_id', $workspace->id)->where('period', 'never')->value('next_serial'))->toBe($previousContinuousCounter);

    $component->set('data.next_number', 43)
        ->assertSee(__('lot_numbering.counter_change', ['before' => 'Every day', 'after' => 'Never', 'number' => 43]))
        ->call('save')->assertHasNoErrors()
        ->assertDontSee(__('lot_numbering.counter_change_help'));

    expect($settings->refresh())->date_format->toBe('none')->reset_period->toBe('never');
    expect(IngredientLotNumberCounter::query()->where('workspace_id', $workspace->id)->where('period', 'never')->sole()->next_serial)->toBe(43);
})->with([null, 8]);

it('restores the saved period counter when a reset change is undone', function (): void {
    $this->travelTo(now()->setDate(2026, 9, 23));
    [$owner, $workspace] = lotSettingsWorkspace();
    IngredientLotNumberCounter::query()->create(['workspace_id' => $workspace->id, 'period' => 'daily:20260923', 'next_serial' => 43]);

    Livewire::actingAs($owner)->test(IngredientLotNumberSettings::class)
        ->set('data.reset_period', 'monthly')
        ->assertSet('data.next_number', 1)
        ->assertSee(__('lot_numbering.counter_change', ['before' => 'Every day', 'after' => 'Every month', 'number' => 1]))
        ->set('data.reset_period', 'daily')
        ->assertSet('data.next_number', 43)
        ->assertDontSee(__('lot_numbering.counter_change_help'));
});

it('rejects invalid format fields without saving settings', function (string $field, mixed $value): void {
    [$owner, $workspace] = lotSettingsWorkspace();
    Livewire::actingAs($owner)->test(IngredientLotNumberSettings::class)
        ->set('data.'.$field, $value)->call('save')->assertHasErrors('data.'.$field);
    expect(IngredientLotNumberSetting::query()->where('workspace_id', $workspace->id)->exists())->toBeFalse();
})->with([
    ['prefix', '<script>'], ['padding', '0'], ['padding', '999999999999'],
    ['next_number', '1.5'], ['date_source', 'expires'], ['date_format', 'invalid'],
]);

it('allows administrators to configure ingredient numbering', function (): void {
    [, $workspace] = lotSettingsWorkspace();
    $admin = User::factory()->create();
    WorkspaceMember::factory()->for($workspace)->for($admin)->create(['role' => WorkspaceMemberRole::Admin]);
    Livewire::actingAs($admin)->test(IngredientLotNumberSettings::class)
        ->set('data.prefix', 'ADMIN')->call('save')->assertHasNoErrors();
    expect(IngredientLotNumberSetting::query()->where('workspace_id', $workspace->id)->sole()->prefix)->toBe('ADMIN');
});

it('prevents editors and viewers from saving ingredient numbering', function (WorkspaceMemberRole $role): void {
    [, $workspace] = lotSettingsWorkspace();
    $member = User::factory()->create();
    WorkspaceMember::factory()->for($workspace)->for($member)->create(['role' => $role]);
    Livewire::actingAs($member)->test(IngredientLotNumberSettings::class)
        ->set('data.prefix', 'DENIED')->call('save')->assertForbidden();
    expect(IngredientLotNumberSetting::query()->where('workspace_id', $workspace->id)->exists())->toBeFalse();
})->with([WorkspaceMemberRole::Editor, WorkspaceMemberRole::Viewer]);

it('blocks ingredient settings while production bench is inactive or cancelled', function (string $status): void {
    [$owner, $workspace] = lotSettingsWorkspace();
    if ($status === 'inactive') {
        $workspace->productionEntitlement()->delete();
    } else {
        $workspace->productionEntitlement()->update(['status' => $status]);
    }
    Livewire::actingAs($owner)->test(IngredientLotNumberSettings::class)
        ->set('data.prefix', 'BLOCKED')->call('save')->assertHasErrors('production_bench');
    expect(IngredientLotNumberSetting::query()->where('workspace_id', $workspace->id)->exists())->toBeFalse();
})->with(['inactive', 'cancelled']);
