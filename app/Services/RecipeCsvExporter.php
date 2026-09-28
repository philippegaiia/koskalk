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
        $document = $exportData['document'];

        return $this->tabularFileExporter->exportCsv([
            'columns' => [
                ['key' => 'formula', 'label' => __('formula_documents.exports.formula'), 'type' => 'string'],
                ['key' => 'product_family', 'label' => __('formula_documents.exports.product_family'), 'type' => 'string'],
                ['key' => 'product_type', 'label' => __('formula_documents.exports.product_type'), 'type' => 'string'],
                ['key' => 'source', 'label' => __('formula_documents.exports.source'), 'type' => 'string'],
                ['key' => 'version', 'label' => __('formula_documents.exports.version'), 'type' => 'number'],
                ['key' => 'generated_at', 'label' => __('formula_documents.exports.generated_at'), 'type' => 'string'],
                ['key' => 'basis_weight', 'label' => __('formula_documents.exports.basis'), 'type' => 'number'],
                ['key' => 'basis_unit', 'label' => __('formula_documents.exports.formula_basis_unit'), 'type' => 'string'],
                ['key' => 'percentage_basis', 'label' => __('formula_documents.exports.percentage_basis'), 'type' => 'string'],
                ['key' => 'section', 'label' => __('formula_documents.exports.section'), 'type' => 'string'],
                ['key' => 'ingredient', 'label' => __('formula_documents.exports.ingredient'), 'type' => 'string'],
                ['key' => 'percentage', 'label' => __('formula_documents.exports.percentage_'.$document['percentage_basis']), 'type' => 'number'],
                ['key' => 'weight', 'label' => __('formula_documents.exports.weight'), 'type' => 'number'],
                ['key' => 'unit', 'label' => __('formula_documents.exports.unit'), 'type' => 'string'],
                ['key' => 'note', 'label' => __('formula_documents.exports.note'), 'type' => 'string'],
            ],
            'rows' => collect($exportData['ingredientRows'] ?? [])
                ->map(fn (array $row): array => [
                    'formula' => data_get($document, 'identity.name', ''),
                    'product_family' => data_get($document, 'identity.product_family', ''),
                    'product_type' => data_get($document, 'identity.product_type', ''),
                    'source' => __('formula_documents.states.'.data_get($document, 'identity.state', 'current')),
                    'version' => data_get($document, 'metadata.version_number'),
                    'generated_at' => data_get($document, 'metadata.generated_at', ''),
                    'basis_weight' => $document['basis_weight'] ?? null,
                    'basis_unit' => $document['unit'] ?? '',
                    'percentage_basis' => __('formula_documents.exports.basis_'.$document['percentage_basis']),
                    'section' => $row['section'] ?? '',
                    'ingredient' => $row['ingredient'] ?? '',
                    'percentage' => $row['percentage'] ?? null,
                    'weight' => $row['weight'] ?? null,
                    'unit' => $row['unit'] ?? '',
                    'note' => $row['note'] ?? '',
                ])
                ->all(),
        ]);
    }
}
