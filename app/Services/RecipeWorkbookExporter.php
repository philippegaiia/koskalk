<?php

namespace App\Services;

class RecipeWorkbookExporter
{
    public function __construct(
        private readonly TabularFileExporter $tabularFileExporter,
    ) {}

    /**
     * @param  array<string, mixed>  $exportData
     */
    public function export(array $exportData): string
    {
        $document = $exportData['document'];
        $sheets = [$this->ingredientSheet($exportData)];

        if (($document['family'] ?? null) === 'soap' && is_array($document['soap_output'] ?? null)) {
            $sheets[] = $this->soapOutputSheet($document);
        }

        return $this->tabularFileExporter->exportXlsx($sheets);
    }

    /** @param array<string, mixed> $exportData
     * @return array<string, mixed>
     */
    private function ingredientSheet(array $exportData): array
    {
        $document = $exportData['document'];

        return [
            'name' => __('formula_documents.exports.ingredient_batch'),
            'title' => (string) data_get($document, 'identity.name', 'Formula'),
            'context' => [
                ['label' => 'Product family', 'value' => data_get($document, 'identity.product_family', ''), 'type' => 'string'],
                ['label' => 'Product type', 'value' => data_get($document, 'identity.product_type', ''), 'type' => 'string'],
                ['label' => 'Basis', 'value' => $document['basis_weight'], 'type' => 'number'],
                ['label' => 'Unit', 'value' => $document['unit'], 'type' => 'string'],
            ],
            'columns' => [
                ['key' => 'section', 'label' => 'Section', 'type' => 'string', 'width' => 24],
                ['key' => 'ingredient', 'label' => 'Ingredient', 'type' => 'string', 'width' => 34],
                ['key' => 'percentage_basis', 'label' => 'Percentage basis', 'type' => 'string', 'width' => 20],
                ['key' => 'percentage', 'label' => 'Percentage', 'type' => 'number', 'format' => '0.################', 'width' => 16],
                ['key' => 'weight', 'label' => 'Scaled weight', 'type' => 'number', 'format' => '0.################', 'width' => 18],
                ['key' => 'unit', 'label' => 'Unit', 'type' => 'string', 'width' => 10],
                ['key' => 'note', 'label' => 'Note', 'type' => 'string', 'width' => 40],
            ],
            'rows' => $exportData['ingredientRows'],
        ];
    }

    /** @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    private function soapOutputSheet(array $document): array
    {
        $output = $document['soap_output'];

        return [
            'name' => __('formula_documents.exports.soap_output'),
            'title' => __('formula_documents.exports.soap_output'),
            'context' => [
                ['label' => 'Cured basis', 'value' => $output['basis_weight'], 'type' => 'number'],
                ['label' => 'Unit', 'value' => $document['unit'], 'type' => 'string'],
                ['label' => 'Residual water', 'value' => $output['residual_water_percentage'], 'type' => 'number'],
                ['label' => 'Final INCI', 'value' => $output['inci'], 'type' => 'string'],
            ],
            'columns' => [
                ['key' => 'name', 'label' => 'Component', 'type' => 'string', 'width' => 34],
                ['key' => 'role', 'label' => 'Role', 'type' => 'string', 'width' => 24],
                ['key' => 'percentage', 'label' => '% cured soap', 'type' => 'number', 'format' => '0.################', 'width' => 20],
                ['key' => 'weight', 'label' => 'Weight', 'type' => 'number', 'format' => '0.################', 'width' => 18],
                ['key' => 'sources', 'label' => 'Sources', 'type' => 'string', 'width' => 44],
            ],
            'rows' => collect($output['rows'])
                ->map(fn (array $row): array => [
                    'name' => $row['name'],
                    'role' => str($row['role'])->replace('_', ' ')->title()->toString(),
                    'percentage' => $row['percentage'],
                    'weight' => $row['weight'],
                    'sources' => implode(', ', $row['sources']),
                ])
                ->all(),
        ];
    }
}
