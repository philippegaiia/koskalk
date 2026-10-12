<?php

namespace App\Http\Controllers;

use App\Enums\FileExportType;
use App\Http\Requests\PackagingExportRequest;
use App\Services\QueuedFileExports;
use Illuminate\Http\JsonResponse;

class PackagingExportController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(PackagingExportRequest $request, QueuedFileExports $exports): JsonResponse
    {
        $export = $exports->request($request->user(), FileExportType::Packaging, $request->validated());

        return response()->json($exports->status($export), 202)->header('Cache-Control', 'private, no-store');
    }
}
