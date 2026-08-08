<?php

namespace App\Http\Controllers;

use App\Enums\MediaAssetStatus;
use App\Enums\MediaAssetType;
use App\Models\MediaAsset;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MediaAssetDownloadController extends Controller
{
    public function __invoke(Request $request, MediaAsset $mediaAsset): StreamedResponse
    {
        $user = $request->user();

        abort_unless(
            $user instanceof User
            && $mediaAsset->status === MediaAssetStatus::Ready
            && in_array($mediaAsset->type, [MediaAssetType::Image, MediaAssetType::Pdf], true)
            && $user->can('view', $mediaAsset),
            404,
        );

        $collection = $mediaAsset->type === MediaAssetType::Pdf ? 'document' : 'master';
        $storedMedia = $mediaAsset->getFirstMedia($collection);
        abort_unless($storedMedia !== null, 404);

        $disk = Storage::disk($storedMedia->disk);
        $path = $storedMedia->getPathRelativeToRoot();
        abort_unless($disk->exists($path), 404);

        $isPdf = $mediaAsset->type === MediaAssetType::Pdf;
        $downloadName = $isPdf
            ? (Str::endsWith(Str::lower($mediaAsset->displayName()), '.pdf')
                ? $mediaAsset->displayName()
                : $mediaAsset->displayName().'.pdf')
            : Str::beforeLast($mediaAsset->displayName(), '.').'.webp';

        return $disk->download($path, $downloadName, [
            'Content-Type' => $isPdf ? 'application/pdf' : 'image/webp',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
