<?php

namespace App\Actions\Inventory;

use App\Enums\MediaAssetStatus;
use App\Enums\MediaAssetType;
use App\Enums\ProductionDocumentType;
use App\Models\MediaAsset;
use App\Models\ProductionDocument;
use App\Models\ProductionRun;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Production\ProductionEditingContext;
use App\Services\Production\ProductionMutationResult;
use App\Services\ProductionBenchAccess;
use App\Services\ProductionMutationGuard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class AttachProductionDocument
{
    public function __construct(private readonly ProductionMutationGuard $guard, private readonly ProductionBenchAccess $access) {}

    public function handle(
        User $actor,
        Model $documentable,
        MediaAsset $asset,
        ProductionDocumentType $type,
        ?string $note = null,
        ?ProductionEditingContext $editing = null,
    ): ProductionDocument {
        $workspaceId = $documentable->getAttribute('workspace_id');
        $workspace = is_numeric($workspaceId)
            ? Workspace::withoutGlobalScopes()->find($workspaceId)
            : null;

        if (
            ! $workspace instanceof Workspace
            || (int) $asset->workspace_id !== $workspace->id
        ) {
            throw ValidationException::withMessages([
                'document' => 'The document and its record must belong to the same workspace.',
            ]);
        }

        $this->access->assertWritable($actor, $workspace);

        if ($documentable instanceof ProductionRun) {
            return $this->guard->run($actor, [$documentable->id], $editing,
                function (User $actor, Workspace $workspace, Collection $productions) use ($documentable, $asset, $type, $note): ProductionMutationResult {
                    $freshAsset = MediaAsset::query()->where('workspace_id', $workspace->id)->lockForUpdate()->findOrFail($asset->id);
                    $document = $this->attach($actor, $workspace, $productions[$documentable->id], $freshAsset, $type, $note);

                    return new ProductionMutationResult($document, $document->wasRecentlyCreated ? [$documentable->id] : []);
                });
        }

        return $this->attach($actor, $workspace, $documentable, $asset, $type, $note);
    }

    private function attach(User $actor, Workspace $workspace, Model $documentable, MediaAsset $asset, ProductionDocumentType $type, ?string $note): ProductionDocument
    {
        if (
            $asset->getRawOriginal('status') !== MediaAssetStatus::Ready->value
            || ! in_array($asset->getRawOriginal('type'), [MediaAssetType::Image->value, MediaAssetType::Pdf->value], true)
        ) {
            throw ValidationException::withMessages([
                'document' => __('production_bench.receipt.document_asset_invalid'),
            ]);
        }

        return ProductionDocument::query()->firstOrCreate([
            'media_asset_id' => $asset->id,
            'documentable_type' => $documentable->getMorphClass(),
            'documentable_id' => $documentable->getKey(),
            'type' => $type,
        ], [
            'workspace_id' => $workspace->id,
            'attached_by_user_id' => $actor->id,
            'note' => $note,
        ]);
    }
}
