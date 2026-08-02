<?php

use App\Actions\Inventory\AttachProductionDocument;
use App\Models\MediaAsset;
use App\Models\StockLot;
use App\Models\User;
use App\Models\Workspace;
use App\ProductionDocumentType;
use App\Services\ProductionBenchAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('attaches typed private assets only inside the same workspace', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    app(ProductionBenchAccess::class)->activate($owner, $workspace);
    $lot = StockLot::factory()->for($workspace)->create();
    $coa = MediaAsset::factory()->for($workspace)->for($owner, 'uploadedBy')->pdf()->ready()->create();

    $document = app(AttachProductionDocument::class)->handle(
        actor: $owner,
        documentable: $lot,
        asset: $coa,
        type: ProductionDocumentType::CertificateOfAnalysis,
        note: 'Released by supplier laboratory',
    );

    expect($document->workspace_id)->toBe($workspace->id)
        ->and($document->documentable->is($lot))->toBeTrue()
        ->and($document->mediaAsset->is($coa))->toBeTrue()
        ->and($document->type)->toBe(ProductionDocumentType::CertificateOfAnalysis);

    $foreignAsset = MediaAsset::factory()->pdf()->ready()->create();

    expect(fn () => app(AttachProductionDocument::class)->handle(
        actor: $owner,
        documentable: $lot,
        asset: $foreignAsset,
        type: ProductionDocumentType::Invoice,
    ))->toThrow(ValidationException::class);
});
