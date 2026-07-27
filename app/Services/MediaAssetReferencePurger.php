<?php

namespace App\Services;

use App\Models\MediaAsset;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Support\RichContentAttachmentPaths;
use Illuminate\Database\Eloquent\Model;

class MediaAssetReferencePurger
{
    public function purge(MediaAsset $asset): void
    {
        $identity = RichContentAttachmentPaths::mediaAssetIdentity($asset->public_id);

        foreach ([Recipe::class, RecipeVersion::class] as $modelClass) {
            $this->removeFromManufacturingInstructions(
                $modelClass,
                $asset->workspace_id,
                $asset->public_id,
                $identity,
            );
        }

        $asset->usages()->delete();
    }

    /**
     * @param  class-string<Recipe|RecipeVersion>  $modelClass
     */
    private function removeFromManufacturingInstructions(
        string $modelClass,
        int $workspaceId,
        string $publicId,
        string $identity,
    ): void {
        $modelClass::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->where('manufacturing_instructions', 'like', "%{$identity}%")
            ->lockForUpdate()
            ->get(['id', 'manufacturing_instructions'])
            ->each(function (Model $record) use ($publicId): void {
                $instructions = RichContentAttachmentPaths::removeMediaAssetImages(
                    $record->getAttribute('manufacturing_instructions'),
                    $publicId,
                );

                if ($instructions !== $record->getAttribute('manufacturing_instructions')) {
                    $record->setAttribute('manufacturing_instructions', $instructions);
                    $record->save();
                }
            });
    }
}
