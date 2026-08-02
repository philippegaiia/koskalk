<?php

namespace App\Actions\Inventory;

use App\Models\MediaAsset;
use App\Models\ProductionDocument;
use App\Models\User;
use App\Models\Workspace;
use App\ProductionDocumentType;
use App\Services\ProductionBenchAccess;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class AttachProductionDocument
{
    public function __construct(private readonly ProductionBenchAccess $access) {}

    public function handle(
        User $actor,
        Model $documentable,
        MediaAsset $asset,
        ProductionDocumentType $type,
        ?string $note = null,
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

        return ProductionDocument::query()->create([
            'workspace_id' => $workspace->id,
            'media_asset_id' => $asset->id,
            'documentable_type' => $documentable->getMorphClass(),
            'documentable_id' => $documentable->getKey(),
            'type' => $type,
            'attached_by_user_id' => $actor->id,
            'note' => $note,
        ]);
    }
}
