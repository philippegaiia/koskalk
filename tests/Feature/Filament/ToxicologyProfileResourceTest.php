<?php

use App\Filament\Resources\ToxicologyProfiles\Pages\CreateToxicologyProfile;
use App\Filament\Resources\ToxicologyProfiles\ToxicologyProfileResource;
use App\Models\Substance;
use App\Models\ToxicologyProfile;
use App\Models\User;
use App\Services\Dossiers\ToxicologyProfilePublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('publishes a profile version from the admin create page', function (): void {
    $admin = User::factory()->admin()->create();
    $substance = Substance::factory()->create(['name' => 'Glycerin']);
    $this->actingAs($admin);

    Livewire::test(CreateToxicologyProfile::class)
        ->fillForm([
            'substance_id' => $substance->id,
            'noael_mg_kg_bw_day' => '1180',
            'noael_route' => 'oral',
            'dermal_absorption_percent' => '7.34',
            'references' => [['number' => 1, 'citation' => 'OECD SIDS Glycerol', 'url' => null]],
            'value_references' => ['noael_mg_kg_bw_day' => '1', 'dermal_absorption_percent' => '1'],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $profile = ToxicologyProfile::query()->sole();

    expect($profile->version)->toBe(1)
        ->and($profile->published_by_user_id)->toBe($admin->id)
        ->and($profile->value_references['dermal_absorption_percent'])->toBe([1]);
});

it('pre-fills a new version from an existing one', function (): void {
    $admin = User::factory()->admin()->create();
    $profile = app(ToxicologyProfilePublisher::class)->publish(Substance::factory()->create(), [
        'noael_mg_kg_bw_day' => '150',
        'noael_route' => 'oral',
        'references' => [['number' => 1, 'citation' => 'Source A', 'url' => null]],
        'value_references' => ['noael_mg_kg_bw_day' => '1'],
    ], $admin);
    $this->actingAs($admin);

    Livewire::withQueryParams(['from' => $profile->id])
        ->test(CreateToxicologyProfile::class)
        ->assertSchemaStateSet([
            'substance_id' => $profile->substance_id,
            'noael_mg_kg_bw_day' => '150.000000000',
            'value_references' => ['noael_mg_kg_bw_day' => '1'],
        ]);
});

it('keeps toxicology profiles out of reach of non-admin users', function (): void {
    $this->actingAs(User::factory()->create());

    $this->get(ToxicologyProfileResource::getUrl(panel: 'admin'))->assertForbidden();
});
