<?php

use App\Actions\Dossiers\StoreDossierEvidence;
use App\Models\SupplierQualification;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceDossierGrant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('downloads evidence for the workspace owner and hides it from everyone else', function (): void {
    Storage::fake(config('dossiers.evidence_disk'));
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    WorkspaceDossierGrant::factory()->for($workspace)->create();
    $item = app(StoreDossierEvidence::class)->handle($owner, $workspace, UploadedFile::fake()->createWithContent('FT olive.pdf', '%PDF-1.4 olive'), [
        'type' => 'technical_sheet',
        'supplier_qualification_id' => SupplierQualification::factory()->create(['workspace_id' => $workspace->id])->id,
    ]);

    $this->actingAs($owner)->get(route('dossiers.evidence.download', $item))
        ->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertDownload('FT olive.pdf');

    $this->actingAs(User::factory()->create())->get(route('dossiers.evidence.download', $item))->assertNotFound();
});
