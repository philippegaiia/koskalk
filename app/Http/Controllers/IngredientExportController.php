<?php

namespace App\Http\Controllers;

use App\Enums\FileExportType;
use App\Http\Requests\IngredientExportRequest;
use App\Services\QueuedFileExports;
use Illuminate\Http\JsonResponse;

final class IngredientExportController extends Controller
{
    public function __invoke(IngredientExportRequest $request, QueuedFileExports $exports): JsonResponse
    {
        $export = $exports->request($request->user(), FileExportType::Ingredients, $request->validated());

        return response()->json($exports->status($export), 202)->header('Cache-Control', 'private, no-store');
    }
}
