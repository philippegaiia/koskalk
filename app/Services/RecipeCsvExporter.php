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

        fputcsv($stream, ['Phase', 'Ingredient', 'INCI name', 'Percentage', 'Weight', 'Note']);

        foreach ($exportData['formulaRows'] ?? [] as $row) {
            fputcsv($stream, [
                $row['phase'] ?? '',
                $row['ingredient'] ?? '',
                $row['inci_name'] ?? '',
                $row['percentage'] ?? '',
                $row['weight'] ?? '',
                $row['note'] ?? '',
            ]);
        }

        rewind($stream);

        $contents = stream_get_contents($stream);
        fclose($stream);

        return $contents === false ? '' : $contents;
    }
}
