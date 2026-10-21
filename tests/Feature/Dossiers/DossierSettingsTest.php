<?php

use App\Actions\Dossiers\SaveDossierSettings;
use App\Enums\WorkspaceMemberRole;
use App\Models\Plan;
use App\Models\User;
use App\Models\UserEntitlement;
use App\Models\Workspace;
use App\Models\WorkspaceDossierGrant;
use App\Models\WorkspaceDossierSetting;
use App\Models\WorkspaceMember;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @return array<string, string|null>
 */
function dossierSettingsPayload(): array
{
    return [
        'responsible_person_name' => 'La Grande Vague SAS',
        'responsible_person_address' => "17 rue des Tilleuls\n26120 Montélier\nFrance",
        'responsible_person_siret' => '12345678901234',
        'manufacturer_name' => null,
        'manufacturer_address' => null,
        'packer_name' => null,
        'packer_address' => null,
        'numbering_prefix' => 'GA',
        'assessor_name' => 'Jane Assessor',
        'assessor_address' => 'Nantes, France',
        'assessor_qualification' => 'PharmD, toxicology',
    ];
}

it('saves and updates the dossier settings of a granted workspace for its owner', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    WorkspaceDossierGrant::factory()->for($workspace)->create();

    app(SaveDossierSettings::class)->handle($owner, $workspace, dossierSettingsPayload());
    app(SaveDossierSettings::class)->handle($owner, $workspace, [...dossierSettingsPayload(), 'numbering_prefix' => 'GB']);

    $setting = WorkspaceDossierSetting::query()->sole();

    expect($setting->workspace_id)->toBe($workspace->id)
        ->and($setting->numbering_prefix)->toBe('GB')
        ->and($setting->responsible_person_siret)->toBe('12345678901234');
});

it('refuses to save settings without the module grant', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();

    app(SaveDossierSettings::class)->handle($owner, $workspace, dossierSettingsPayload());
})->throws(AuthorizationException::class);

it('refuses to save settings for an editor of a granted workspace', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    UserEntitlement::factory()->for($owner)->for(Plan::factory()->create(['allows_collaboration' => true]))->create();
    WorkspaceDossierGrant::factory()->for($workspace)->create();
    $editor = User::factory()->create();
    WorkspaceMember::factory()->for($workspace)->for($editor)->create(['role' => WorkspaceMemberRole::Editor]);

    app(SaveDossierSettings::class)->handle($editor, $workspace, dossierSettingsPayload());
})->throws(AuthorizationException::class);
