<?php

namespace App\Services;

class RecipeCsvExporter
{
    public function __construct(
        private readonly TabularFileExporter $tabularFileExporter,
    ) {}

    /**
     * @param  array<string, mixed>  $exportData
     */
    public function export(array $exportData): string
    {
        return $this->tabularFileExporter->exportCsv([
            'columns' => [
                ['key' => 'section', 'label' => 'Section', 'type' => 'string'],
                ['key' => 'ingredient', 'label' => 'Ingredient', 'type' => 'string'],
                ['key' => 'percentage_basis', 'label' => 'Percentage basis', 'type' => 'string'],
                ['key' => 'percentage', 'label' => 'Percentage', 'type' => 'number'],
                ['key' => 'weight', 'label' => 'Scaled weight', 'type' => 'number'],
                ['key' => 'unit', 'label' => 'Unit', 'type' => 'string'],
                ['key' => 'note', 'label' => 'Note', 'type' => 'string'],
            ],
            'rows' => collect($exportData['ingredientRows'] ?? [])
                ->map(fn (array $row): array => [
                    'section' => $row['section'] ?? '',
                    'ingredient' => $row['ingredient'] ?? '',
                    'percentage_basis' => $row['percentage_basis'] ?? '',
                    'percentage' => $row['percentage'] ?? null,
                    'weight' => $row['weight'] ?? null,
                    'unit' => $row['unit'] ?? '',
                    'note' => $row['note'] ?? '',
                ])
                ->all(),
        ]);
    }
}
