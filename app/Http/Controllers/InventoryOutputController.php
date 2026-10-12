<?php

namespace App\Http\Controllers;

use App\Enums\FileExportType;
use App\Http\Requests\InventoryOutputRequest;
use App\Services\InventoryFileExporter;
use App\Services\QueuedFileExports;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

final class InventoryOutputController extends Controller
{
    public function exportMaterials(InventoryOutputRequest $request, QueuedFileExports $exports): JsonResponse
    {
        $export = $exports->request($request->user(), FileExportType::InventoryMaterials, $request->validated());

        return response()->json($exports->status($export), 202)->header('Cache-Control', 'private, no-store');
    }

    public function exportStockLots(InventoryOutputRequest $request, QueuedFileExports $exports): JsonResponse
    {
        $export = $exports->request($request->user(), FileExportType::StockLots, $request->validated());

        return response()->json($exports->status($export), 202)->header('Cache-Control', 'private, no-store');
    }

    public function printCount(InventoryOutputRequest $request, InventoryFileExporter $exporter): View
    {
        return $exporter->printCount($request->user(), $request->validated());
    }
}
