<?php

use App\Actions\Dossiers\WithdrawDossierEvidence;
use App\Models\DossierEvidenceItem;
use App\Models\SupplierQualification;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceDossierGrant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

/**
 * @return array{0: User, 1: Workspace, 2: DossierEvidenceItem}
 */
function withdrawableEvidence(): array
{
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    WorkspaceDossierGrant::factory()->for($workspace)->create();
    $item = DossierEvidenceItem::factory()->create([
        'supplier_qualification_id' => SupplierQualification::factory()->create(['workspace_id' => $workspace->id])->id,
    ]);

    return [$owner, $workspace, $item];
}

it('withdraws a document uploaded by mistake and keeps its record and file', function (): void {
    [$owner, $workspace, $item] = withdrawableEvidence();

    app(WithdrawDossierEvidence::class)->handle($owner, $workspace, $item, 'Wrong document type');

    $item->refresh();

    expect($item->isActive())->toBeFalse()
        ->and($item->withdrawn_reason)->toBe('Wrong document type')
        ->and($item->withdrawn_by_user_id)->toBe($owner->id)
        ->and($item->replaced_by_id)->toBeNull()
        ->and($item->file)->not->toBeNull();
});

it('requires a reason and refuses an item that is no longer current', function (): void {
    [$owner, $workspace, $item] = withdrawableEvidence();

    expect(fn () => app(WithdrawDossierEvidence::class)->handle($owner, $workspace, $item, '  '))->toThrow(ValidationException::class);

    $item->update(['retired_at' => now()]);

    expect(fn () => app(WithdrawDossierEvidence::class)->handle($owner, $workspace, $item, 'Duplicate'))->toThrow(ValidationException::class);
});
