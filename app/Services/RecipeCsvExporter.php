<?php

namespace App\Services;

class RecipeCsvExporter
{
    /**
     * @param  array<string, mixed>  $exportData
     */
    public function export(array $exportData): string
    {
        $stream = fopen('php://temp', 'r+');

        abort_if($stream === false, 500, 'Unable to create CSV export.');

        fputcsv($stream, ['Section', 'Ingredient', 'Percentage basis', 'Percentage', 'Scaled weight', 'Unit', 'Note']);

        foreach ($exportData['ingredientRows'] ?? [] as $row) {
            fputcsv($stream, [
                $row['section'] ?? '',
                $row['ingredient'] ?? '',
                $row['percentage_basis'] ?? '',
                $row['percentage'] ?? '',
                $row['weight'] ?? '',
                $row['unit'] ?? '',
                $row['note'] ?? '',
            ]);
        }

        rewind($stream);

        $contents = stream_get_contents($stream);
        fclose($stream);

        return $contents === false ? '' : $contents;
    }
}
