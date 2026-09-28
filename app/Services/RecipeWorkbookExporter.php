<?php

namespace App\Services;

class RecipeWorkbookExporter
{
    public function __construct(
        private readonly TabularFileExporter $tabularFileExporter,
        private readonly RecipeCostingExportDataBuilder $costingExportDataBuilder,
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

    /** @param array<string, mixed> $exportData */
    public function exportCosting(array $exportData): string
    {
        return $this->tabularFileExporter->exportXlsx($this->costingExportDataBuilder->workbookSheets($exportData));
    }

    /** @param array<string, mixed> $exportData */
    public function writeTemporaryCosting(array $exportData): string
    {
        return $this->tabularFileExporter->writeTemporaryXlsx($this->costingExportDataBuilder->workbookSheets($exportData));
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
                ['label' => __('formula_documents.exports.formula'), 'value' => data_get($document, 'identity.name', ''), 'type' => 'string'],
                ['label' => __('formula_documents.exports.product_family'), 'value' => data_get($document, 'identity.product_family', ''), 'type' => 'string'],
                ['label' => __('formula_documents.exports.product_type'), 'value' => data_get($document, 'identity.product_type', ''), 'type' => 'string'],
                ['label' => __('formula_documents.exports.source'), 'value' => __('formula_documents.states.'.data_get($document, 'identity.state', 'current')), 'type' => 'string'],
                ['label' => __('formula_documents.exports.version'), 'value' => data_get($document, 'metadata.version_number'), 'type' => data_get($document, 'metadata.version_number') === null ? 'string' : 'number'],
                ['label' => __('formula_documents.exports.generated_at'), 'value' => data_get($document, 'metadata.generated_at', ''), 'type' => 'string'],
                ['label' => __('formula_documents.exports.basis'), 'value' => $document['basis_weight'], 'type' => 'number'],
                ['label' => __('formula_documents.exports.unit'), 'value' => $document['unit'], 'type' => 'string'],
                ['label' => __('formula_documents.exports.percentage_basis'), 'value' => __('formula_documents.exports.basis_'.$document['percentage_basis']), 'type' => 'string'],
            ],
            'columns' => [
                ['key' => 'section', 'label' => __('formula_documents.exports.section'), 'type' => 'string', 'width' => 24],
                ['key' => 'ingredient', 'label' => __('formula_documents.exports.ingredient'), 'type' => 'string', 'width' => 34],
                ['key' => 'percentage_basis', 'label' => __('formula_documents.exports.percentage_basis'), 'type' => 'string', 'width' => 20],
                ['key' => 'percentage', 'label' => __('formula_documents.exports.percentage_'.$document['percentage_basis']), 'type' => 'number', 'format' => '0.################', 'width' => 24],
                ['key' => 'weight', 'label' => __('formula_documents.exports.weight'), 'type' => 'number', 'format' => '0.################', 'width' => 18],
                ['key' => 'unit', 'label' => __('formula_documents.exports.unit'), 'type' => 'string', 'width' => 10],
                ['key' => 'note', 'label' => __('formula_documents.exports.note'), 'type' => 'string', 'width' => 40],
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
