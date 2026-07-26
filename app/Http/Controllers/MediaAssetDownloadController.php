<?php

namespace App\Http\Controllers;

use App\MediaAssetStatus;
use App\MediaAssetType;
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
            && $mediaAsset->type === MediaAssetType::Pdf
            && $mediaAsset->status === MediaAssetStatus::Ready
            && $user->can('view', $mediaAsset),
            404,
        );

        $document = $mediaAsset->getFirstMedia('document');
        abort_unless($document !== null, 404);

        $disk = Storage::disk($document->disk);
        $path = $document->getPathRelativeToRoot();
        abort_unless($disk->exists($path), 404);

        $downloadName = Str::endsWith(Str::lower($mediaAsset->displayName()), '.pdf')
            ? $mediaAsset->displayName()
            : $mediaAsset->displayName().'.pdf';

        return $disk->download($path, $downloadName, [
            'Content-Type' => 'application/pdf',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
