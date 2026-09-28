<?php

namespace App\Http\Controllers;

use App\Http\Requests\RecipeWorkbenchOutputRequest;
use App\Services\RecipeCsvExporter;
use App\Services\RecipeWorkbenchOutputBuilder;
use App\Services\RecipeWorkbookExporter;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RecipeWorkbenchOutputController extends Controller
{
    public function print(
        RecipeWorkbenchOutputRequest $request,
        RecipeWorkbenchOutputBuilder $builder,
    ): View {
        $data = $builder->build($request->user(), $request->validated());

        return view('recipes.print', $data);
    }

    public function export(
        RecipeWorkbenchOutputRequest $request,
        RecipeWorkbenchOutputBuilder $builder,
        RecipeWorkbookExporter $workbookExporter,
        RecipeCsvExporter $csvExporter,
    ): StreamedResponse {
        $data = $builder->build($request->user(), $request->validated());
        $extension = $request->validated('format');
        $filename = $data['filename'].'.'.$extension;

        if ($extension === 'csv') {
            return response()->streamDownload(
                fn (): int => print $csvExporter->export($data['exportData']),
                $filename,
                ['Content-Type' => 'text/csv; charset=UTF-8'],
            );
        }

        return response()->streamDownload(
            fn (): int => print $workbookExporter->export($data['exportData']),
            $filename,
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }
}
