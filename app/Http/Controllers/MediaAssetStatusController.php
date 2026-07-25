<?php

namespace App\Http\Controllers;

use App\MediaAssetStatus;
use App\Models\MediaAsset;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MediaAssetStatusController extends Controller
{
    public function __invoke(Request $request, MediaAsset $mediaAsset): JsonResponse
    {
        $user = $request->user();

        abort_unless(
            $user instanceof User && $user->can('view', $mediaAsset),
            404,
        );

        return response()->json([
            'status' => $mediaAsset->status->value,
            'progress' => $mediaAsset->progress,
            'failure_reason' => $mediaAsset->failure_reason,
            'retry_url' => $mediaAsset->status === MediaAssetStatus::Failed && $user->can('update', $mediaAsset)
                ? route('media.retry', $mediaAsset)
                : null,
            'remove_url' => $mediaAsset->status === MediaAssetStatus::Failed && $user->can('delete', $mediaAsset)
                ? route('media.remove', $mediaAsset)
                : null,
        ]);
    }
}
