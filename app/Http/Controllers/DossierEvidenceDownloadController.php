<?php

namespace App\Http\Controllers;

use App\Enums\WorkspaceModule;
use App\Models\DossierEvidenceItem;
use App\Models\User;
use App\Services\WorkspaceAuthorization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DossierEvidenceDownloadController extends Controller
{
    public function __invoke(Request $request, DossierEvidenceItem $item, WorkspaceAuthorization $authorization): StreamedResponse
    {
        $user = $request->user();

        abort_unless(
            $user instanceof User && $authorization->canViewModule($user, $item->workspace_id, WorkspaceModule::ProductDossiers),
            404,
        );

        $file = $item->file;
        $disk = Storage::disk($file->disk);
        abort_unless($disk->exists($file->path), 404);

        return $disk->response($file->path, $file->downloadFilename(), [
            'Content-Type' => $file->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ], $request->boolean('inline') ? 'inline' : 'attachment');
    }
}
