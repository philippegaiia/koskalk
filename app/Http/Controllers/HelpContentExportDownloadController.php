<?php

namespace App\Http\Controllers;

use App\Enums\HelpContentExportStatus;
use App\Models\HelpContentExport;
use App\Models\HelpTopic;
use App\Services\ContextualHelp\HelpContentManifest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class HelpContentExportDownloadController extends Controller
{
    public function __invoke(HelpContentExport $helpContentExport): StreamedResponse
    {
        Gate::authorize('export', HelpTopic::class);
        abort_unless($helpContentExport->status === HelpContentExportStatus::Succeeded && $helpContentExport->disk && $helpContentExport->path && $helpContentExport->checksum, 404);
        $disk = Storage::disk($helpContentExport->disk);
        abort_unless($disk->exists($helpContentExport->path), 404);
        $size = $disk->size($helpContentExport->path);
        abort_unless($size === $helpContentExport->size_bytes && $size <= HelpContentManifest::MAX_BYTES, 409, 'Backup integrity verification failed.');
        $json = $disk->get($helpContentExport->path);
        abort_unless(strlen($json) === $size && hash_equals($helpContentExport->checksum, hash('sha256', $json)), 409, 'Backup integrity verification failed.');

        return response()->streamDownload(function () use ($json): void {
            echo $json;
        }, 'help-content-'.$helpContentExport->public_id.'.json', [
            'Content-Type' => 'application/json',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
