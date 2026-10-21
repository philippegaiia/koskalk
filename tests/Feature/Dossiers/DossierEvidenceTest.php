<?php

use App\Actions\Dossiers\ReplaceDossierEvidence;
use App\Actions\Dossiers\StoreDossierEvidence;
use App\Enums\EvidenceType;
use App\Models\DossierEvidenceFile;
use App\Models\DossierEvidenceItem;
use App\Models\Recipe;
use App\Models\SupplierQualification;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceDossierGrant;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake(config('dossiers.evidence_disk'));
});

/**
 * @return array{0: User, 1: Workspace, 2: SupplierQualification}
 */
function evidenceWorkspace(): array
{
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    WorkspaceDossierGrant::factory()->for($workspace)->create();

    return [$owner, $workspace, SupplierQualification::factory()->create(['workspace_id' => $workspace->id])];
}

it('stores a supplier document with its fingerprint on the private evidence disk', function (): void {
    [$owner, $workspace, $qualification] = evidenceWorkspace();
    $pdf = UploadedFile::fake()->createWithContent('FDS HE Cannelle.pdf', '%PDF-1.4 safety data sheet');

    $item = app(StoreDossierEvidence::class)->handle($owner, $workspace, $pdf, [
        'type' => 'safety_data_sheet',
        'issued_on' => '2021-03-01',
        'revision_due_on' => '2026-03-01',
        'supplier_qualification_id' => $qualification->id,
    ]);

    expect($item->type)->toBe(EvidenceType::SafetyDataSheet)
        ->and($item->type->annexLetter())->toBe('a')
        ->and($item->file->sha256)->toBe(hash('sha256', '%PDF-1.4 safety data sheet'))
        ->and($item->file->original_filename)->toBe('FDS HE Cannelle.pdf');

    Storage::disk(config('dossiers.evidence_disk'))->assertExists($item->file->path);
});

it('rejects files that are too large or of the wrong kind', function (UploadedFile $file): void {
    [$owner, $workspace, $qualification] = evidenceWorkspace();

    app(StoreDossierEvidence::class)->handle($owner, $workspace, $file, [
        'type' => 'technical_sheet',
        'supplier_qualification_id' => $qualification->id,
    ]);
})->with([
    'too large' => [fn () => UploadedFile::fake()->create('big.pdf', 10241, 'application/pdf')],
    'wrong kind' => [fn () => UploadedFile::fake()->create('notes.docx', 10, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document')],
])->throws(ValidationException::class);

it('requires the attachment that matches the evidence level', function (): void {
    [$owner, $workspace] = evidenceWorkspace();
    $recipe = Recipe::factory()->create(['workspace_id' => $workspace->id]);

    expect(fn () => app(StoreDossierEvidence::class)->handle($owner, $workspace, UploadedFile::fake()->create('ft.pdf', 10, 'application/pdf'), [
        'type' => 'technical_sheet',
        'recipe_ids' => [$recipe->id],
    ]))->toThrow(ValidationException::class);

    $stability = app(StoreDossierEvidence::class)->handle($owner, $workspace, UploadedFile::fake()->create('stab.pdf', 10, 'application/pdf'), [
        'type' => 'stability_test',
        'recipe_ids' => [$recipe->id],
    ]);

    expect($stability->recipes->pluck('id')->all())->toBe([$recipe->id])
        ->and($stability->type->annexLetter())->toBe('c');
});

it('replaces evidence instead of deleting it', function (): void {
    [$owner, $workspace, $qualification] = evidenceWorkspace();
    $original = app(StoreDossierEvidence::class)->handle($owner, $workspace, UploadedFile::fake()->createWithContent('ft-v1.pdf', '%PDF-1.4 version 1'), [
        'type' => 'technical_sheet',
        'supplier_qualification_id' => $qualification->id,
    ]);

    $replacement = app(ReplaceDossierEvidence::class)->handle($owner, $workspace, $original, UploadedFile::fake()->createWithContent('ft-v2.pdf', '%PDF-1.4 version 2'), [
        'issued_on' => '2024-01-10',
    ]);

    expect($original->refresh()->retired_at)->not->toBeNull()
        ->and($original->replaced_by_id)->toBe($replacement->id)
        ->and($replacement->type)->toBe(EvidenceType::TechnicalSheet)
        ->and($replacement->supplier_qualification_id)->toBe($qualification->id)
        ->and(DossierEvidenceFile::query()->count())->toBe(2)
        ->and(fn () => $original->delete())->toThrow(LogicException::class)
        ->and(fn () => $original->file->delete())->toThrow(LogicException::class);
});

it('refuses evidence for another workspace qualification or without the module', function (): void {
    [$owner, $workspace] = evidenceWorkspace();
    $foreign = SupplierQualification::factory()->create();

    expect(fn () => app(StoreDossierEvidence::class)->handle($owner, $workspace, UploadedFile::fake()->create('x.pdf', 10, 'application/pdf'), [
        'type' => 'technical_sheet',
        'supplier_qualification_id' => $foreign->id,
    ]))->toThrow(ValidationException::class);

    $outsider = User::factory()->create();
    $plain = Workspace::factory()->for($outsider, 'owner')->create();

    expect(fn () => app(StoreDossierEvidence::class)->handle($outsider, $plain, UploadedFile::fake()->create('x.pdf', 10, 'application/pdf'), [
        'type' => 'gmp_declaration',
    ]))->toThrow(AuthorizationException::class)
        ->and(DossierEvidenceItem::query()->count())->toBe(0);
});

it('rejects PDFs above 2 MB but keeps accepting larger images for compression', function (): void {
    [$owner, $workspace, $qualification] = evidenceWorkspace();

    expect(fn () => app(StoreDossierEvidence::class)->handle($owner, $workspace, UploadedFile::fake()->create('big.pdf', 2049, 'application/pdf'), [
        'type' => 'technical_sheet',
        'supplier_qualification_id' => $qualification->id,
    ]))->toThrow(ValidationException::class);

    $item = app(StoreDossierEvidence::class)->handle($owner, $workspace, UploadedFile::fake()->create('ok.pdf', 2048, 'application/pdf'), [
        'type' => 'technical_sheet',
        'supplier_qualification_id' => $qualification->id,
    ]);

    expect($item->file->storage_processing)->toBe('as_received')
        ->and($item->file->original_sha256)->toBe($item->file->sha256);
});

it('compresses images to WebP and keeps the original fingerprint for provenance', function (): void {
    [$owner, $workspace, $qualification] = evidenceWorkspace();
    $scan = UploadedFile::fake()->image('IFRA certificate.png', 3000, 1500);
    $originalHash = hash_file('sha256', $scan->getRealPath());
    $originalSize = filesize($scan->getRealPath());

    $item = app(StoreDossierEvidence::class)->handle($owner, $workspace, $scan, [
        'type' => 'ifra_certificate',
        'supplier_qualification_id' => $qualification->id,
    ]);

    $file = $item->file;
    $stored = Storage::disk(config('dossiers.evidence_disk'))->get($file->path);
    [$width, $height] = getimagesizefromstring($stored);

    expect($file->storage_processing)->toBe('compressed_webp')
        ->and($file->mime_type)->toBe('image/webp')
        ->and($file->path)->toEndWith('.webp')
        ->and(max($width, $height))->toBe(2000)
        ->and($file->sha256)->toBe(hash('sha256', $stored))
        ->and($file->size_bytes)->toBe(strlen($stored))
        ->and($file->original_sha256)->toBe($originalHash)
        ->and($file->original_size_bytes)->toBe($originalSize)
        ->and($file->original_filename)->toBe('IFRA certificate.png')
        ->and($file->downloadFilename())->toBe('IFRA certificate.webp');
});

it('rejects images above the upload limit', function (): void {
    [$owner, $workspace, $qualification] = evidenceWorkspace();

    app(StoreDossierEvidence::class)->handle($owner, $workspace, UploadedFile::fake()->create('huge.jpg', 10241, 'image/jpeg'), [
        'type' => 'ifra_certificate',
        'supplier_qualification_id' => $qualification->id,
    ]);
})->throws(ValidationException::class);
