<?php

namespace App\Http\Controllers;

use App\Enums\MediaAssetStatus;
use App\Models\MediaAsset;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MediaAssetController extends Controller
{
    public function __invoke(
        Request $request,
        MediaAsset $mediaAsset,
        string $conversion,
    ): StreamedResponse {
        $user = $request->user();

        abort_unless(
            $user instanceof User
            && $mediaAsset->status === MediaAssetStatus::Ready
            && $user->can('view', $mediaAsset),
            404,
        );

        abort_unless(in_array($conversion, [
            'master',
            'recipe-index',
            'catalog',
            'thumbnail',
            'icon',
        ], true), 404);

        $media = $mediaAsset->getFirstMedia('master');
        abort_unless($media !== null, 404);

        $conversionName = $conversion === 'master' ? '' : $conversion;
        abort_unless(
            $conversionName === '' || $media->hasGeneratedConversion($conversionName),
            404,
        );

        $diskName = $conversionName === '' ? $media->disk : $media->conversions_disk;
        $path = $media->getPathRelativeToRoot($conversionName);
        $disk = Storage::disk($diskName);

        abort_unless($disk->exists($path), 404);

        return $disk->response($path, null, [
            'Content-Disposition' => 'inline',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=300',
        ]);
    }
}
