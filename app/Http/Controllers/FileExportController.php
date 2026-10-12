<?php

namespace App\Http\Controllers;

use App\Models\FileExport;
use App\Services\QueuedFileExports;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FileExportController extends Controller
{
    public function status(Request $request, FileExport $fileExport, QueuedFileExports $exports): JsonResponse
    {
        $exports->authorize($request->user(), $fileExport);

        return response()->json($exports->status($fileExport))->header('Cache-Control', 'private, no-store');
    }

    public function download(Request $request, FileExport $fileExport, QueuedFileExports $exports): StreamedResponse
    {
        $exports->authorize($request->user(), $fileExport);
        abort_unless($fileExport->status === 'ready' && $fileExport->path && Storage::disk($fileExport->disk)->exists($fileExport->path), 404);

        return Storage::disk($fileExport->disk)->download($fileExport->path, $fileExport->filename, [
            'Content-Type' => $fileExport->parameters['format'] === 'csv'
                ? 'text/csv; charset=UTF-8'
                : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
