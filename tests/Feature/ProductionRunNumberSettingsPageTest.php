<?php

use App\Livewire\ProductionBench\Production\NumberingSettings;
use App\Models\ProductionRunNumberSetting;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Models\WorkspaceProductionEntitlement;
use App\WorkspaceMemberRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Lang;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('renders the numbering settings route, creates defaults, and keeps the setup submenu visible', function (): void {
    $fixture = productionNumberSettingsFixture();

    $this->actingAs($fixture['owner'])
        ->get(route('production-bench.production.settings.numbering'))
        ->assertOk()
        ->assertSee(__('production_bench.settings.numbering'))
        ->assertSee(__('production_bench.settings.presets'))
        ->assertSee(route('production-bench.production.settings.numbering'), false);

    expect(ProductionRunNumberSetting::query()->whereBelongsTo($fixture['workspace'])->sole())
        ->permanent_prefix->toBe('B-')
        ->next_permanent_serial->toBe(1)
        ->permanent_padding->toBe(5)
        ->next_planning_serial->toBe(1);
});

it('lets owners and admins save number settings and emits the standard notification', function (): void {
    $fixture = productionNumberSettingsFixture();
    $admin = User::factory()->create();
    WorkspaceMember::factory()->for($fixture['workspace'])->for($admin)->create(['role' => WorkspaceMemberRole::Admin]);

    Livewire::actingAs($fixture['owner'])
        ->test(NumberingSettings::class)
        ->set('permanentPrefix', 'SOAP-')
        ->set('nextPermanentSerial', '42')
        ->set('permanentPadding', '6')
        ->set('permanentSuffix', '-FR')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('example', 'SOAP-000042-FR')
        ->assertDispatched('app-notification', function (string $event, array $payload): bool {
            return $event === 'app-notification'
                && $payload['message'] === __('production_bench.settings.numbering_saved')
                && $payload['type'] === 'success';
        });

    Livewire::actingAs($admin)
        ->test(NumberingSettings::class)
        ->set('permanentPrefix', 'A-')
        ->call('save')
        ->assertHasNoErrors();

    expect(ProductionRunNumberSetting::query()->whereBelongsTo($fixture['workspace'])->sole())
        ->permanent_prefix->toBe('A-')
        ->permanent_suffix->toBe('-FR')
        ->permanent_padding->toBe(6)
        ->next_permanent_serial->toBe(42);
});

it('shows a live rendered example and validation errors with accessible field descriptions', function (): void {
    $fixture = productionNumberSettingsFixture();

    Livewire::actingAs($fixture['owner'])
        ->test(NumberingSettings::class)
        ->set('permanentPrefix', 'LOT-')
        ->set('nextPermanentSerial', '9')
        ->set('permanentPadding', '4')
        ->set('permanentSuffix', '-EU')
        ->assertSet('example', 'LOT-0009-EU')
        ->set('permanentPrefix', 'contains spaces')
        ->call('save')
        ->assertHasErrors('permanentPrefix')
        ->assertSeeHtml('aria-describedby="permanent-prefix-error"');
});

it('keeps editors and viewers read-only while enforcing owner or admin configuration on the server', function (): void {
    $fixture = productionNumberSettingsFixture();
    $editor = User::factory()->create();
    $viewer = User::factory()->create();
    WorkspaceMember::factory()->for($fixture['workspace'])->for($editor)->create(['role' => WorkspaceMemberRole::Editor]);
    WorkspaceMember::factory()->for($fixture['workspace'])->for($viewer)->create(['role' => WorkspaceMemberRole::Viewer]);

    Livewire::actingAs($editor)
        ->test(NumberingSettings::class)
        ->assertSee(__('production_bench.settings.numbering_future_help'))
        ->assertSeeHtml('readonly')
        ->call('save')
        ->assertForbidden();

    Livewire::actingAs($viewer)
        ->test(NumberingSettings::class)
        ->assertSeeHtml('readonly')
        ->call('save')
        ->assertForbidden();
});

it('does not allow inactive or cancelled production benches to change number settings', function (): void {
    $inactive = productionNumberSettingsFixture(active: false);

    Livewire::actingAs($inactive['owner'])
        ->test(NumberingSettings::class)
        ->set('permanentPrefix', 'INACTIVE-')
        ->call('save')
        ->assertHasErrors('nextPermanentSerial');

    $cancelled = productionNumberSettingsFixture();
    $cancelled['workspace']->productionEntitlement()->update(['status' => 'cancelled', 'cancelled_at' => now()]);

    Livewire::actingAs($cancelled['owner'])
        ->test(NumberingSettings::class)
        ->set('permanentPrefix', 'CANCELLED-')
        ->call('save')
        ->assertHasErrors('nextPermanentSerial');
});

it('provides English numbering translation keys', function (): void {
    foreach ([
        'numbering',
        'numbering_help',
        'number_prefix',
        'next_number',
        'number_digits',
        'number_suffix',
        'number_preview',
        'temporary_counter',
        'temporary_counter_help',
        'numbering_future_help',
        'numbering_saved',
    ] as $key) {
        expect(Lang::has("production_bench.settings.{$key}", 'en'))->toBeTrue();
    }
});

/** @return array{owner: User, workspace: Workspace} */
function productionNumberSettingsFixture(bool $active = true): array
{
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();

    if ($active) {
        WorkspaceProductionEntitlement::factory()->for($workspace)->create();
    }

    return compact('owner', 'workspace');
}
