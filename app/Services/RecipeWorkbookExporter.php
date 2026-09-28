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
            $sheets[] = $this->calculatedOutputSheet($document['soap_output'], (string) $document['unit']);
        }

        return $this->tabularFileExporter->exportXlsx($sheets);
    }

    /** @param array<string, mixed> $exportData */
    public function exportCosting(array $exportData): string
    {
        return $this->tabularFileExporter->exportXlsx($this->costingExportDataBuilder->workbookSheets($exportData));
    }

    /** @param array<string, mixed> $composition */
    public function exportComposition(array $composition): string
    {
        $incorporated = $composition['incorporated'] ?? [];
        $family = (string) ($composition['family'] ?? 'soap');
        $rawRows = collect($incorporated['raw_material_rows'] ?? [])
            ->map(fn (array $row): array => [
                'phase' => $row['phase'] ?? '',
                'ingredient_id' => $row['ingredient_id'] ?? null,
                'ingredient' => $row['name'] ?? '',
                'recorded_inci' => $row['recorded_inci'] ?? '',
                'quantity' => $row['quantity'] ?? null,
                'unit' => $row['unit'] ?? ($composition['unit'] ?? 'g'),
                'percentage' => $row['percentage'] ?? null,
                'role' => __('formula_documents.composition.roles.'.($row['role'] ?? 'ingredient')),
                'note' => $row['note'] ?? '',
            ])
            ->all();
        $inciRows = collect($incorporated['inci_rows'] ?? [])
            ->map(fn (array $row): array => [
                'ingredient' => $row['name'] ?? '',
                'quantity' => $row['quantity'] ?? null,
                'unit' => $composition['unit'] ?? 'g',
                'percentage' => $row['percentage'] ?? null,
                'kind' => __('formula_documents.composition.inci_kinds.'.($row['kind'] ?? 'ingredient')),
                'sources' => implode(', ', $row['sources'] ?? []),
            ])
            ->all();
        $tables = [[
            'title' => __('formula_documents.composition.raw_material_inputs'),
            'columns' => $this->rawMaterialColumns($family),
            'rows' => $rawRows,
        ]];

        if ($inciRows !== []) {
            $tables[] = [
                'title' => __('formula_documents.composition.incorporated_inci_breakdown'),
                'columns' => $this->inciColumns(),
                'rows' => $inciRows,
            ];
        }

        $sheets = [[
            'name' => __('formula_documents.composition.sheet'),
            'title' => (string) data_get($composition, 'identity.name', __('formula_documents.title')),
            'context' => $this->compositionContext($composition),
            'columns' => $this->rawMaterialColumns($family),
            'rows' => $rawRows,
            'tables' => $tables,
        ]];

        if (is_array($composition['calculated_output'] ?? null)) {
            $sheets[] = $this->calculatedOutputSheet($composition['calculated_output'], (string) ($composition['unit'] ?? 'g'));
        }

        return $this->tabularFileExporter->exportXlsx($sheets);
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

    /** @return array<string, mixed> */
    public function calculatedOutputSheet(array $output, string $unit): array
    {
        return [
            'name' => __('formula_documents.exports.calculated_output'),
            'title' => __('formula_documents.exports.calculated_output'),
            'context' => [
                ['label' => __('formula_documents.composition.cured_basis'), 'value' => $output['basis_weight'], 'type' => 'number'],
                ['label' => __('formula_documents.exports.unit'), 'value' => $unit, 'type' => 'string'],
                ['label' => __('formula_documents.composition.retained_dilution_liquid'), 'value' => $output['residual_water_percentage'], 'type' => 'number'],
                ['label' => __('formula_documents.composition.calculated_inci'), 'value' => $output['inci'], 'type' => 'string'],
            ],
            'columns' => [
                ['key' => 'name', 'label' => __('formula_documents.composition.component'), 'type' => 'string', 'width' => 34],
                ['key' => 'role', 'label' => __('formula_documents.exports.role'), 'type' => 'string', 'width' => 24],
                ['key' => 'percentage', 'label' => __('formula_documents.composition.percentage_cured'), 'type' => 'number', 'format' => '0.################', 'width' => 26],
                ['key' => 'weight', 'label' => __('formula_documents.exports.weight'), 'type' => 'number', 'format' => '0.################', 'width' => 18],
                ['key' => 'sources', 'label' => __('formula_documents.composition.sources'), 'type' => 'string', 'width' => 44],
            ],
            'rows' => collect($output['rows'])
                ->map(fn (array $row): array => [
                    'name' => $row['name'],
                    'role' => __('formula_documents.composition.roles.'.($row['role'] ?? 'ingredient')),
                    'percentage' => $row['percentage'],
                    'weight' => $row['weight'],
                    'sources' => implode(', ', $row['sources']),
                ])
                ->all(),
        ];
    }

    /** @return list<array{key: string, label: string, type: 'string'|'number', format?: string, width?: float}> */
    private function rawMaterialColumns(string $family): array
    {
        $percentageLabel = $family === 'cosmetic'
            ? __('formula_documents.composition.percentage_authored')
            : __('formula_documents.composition.percentage_fresh');

        return [
            ['key' => 'phase', 'label' => __('formula_documents.exports.phase'), 'type' => 'string', 'width' => 24],
            ['key' => 'ingredient_id', 'label' => __('formula_documents.composition.ingredient_id'), 'type' => 'number', 'format' => '0', 'width' => 16],
            ['key' => 'ingredient', 'label' => __('formula_documents.exports.ingredient'), 'type' => 'string', 'width' => 34],
            ['key' => 'recorded_inci', 'label' => __('formula_documents.composition.recorded_inci'), 'type' => 'string', 'width' => 34],
            ['key' => 'quantity', 'label' => __('formula_documents.exports.quantity'), 'type' => 'number', 'format' => '0.################', 'width' => 18],
            ['key' => 'unit', 'label' => __('formula_documents.exports.unit'), 'type' => 'string', 'width' => 10],
            ['key' => 'percentage', 'label' => $percentageLabel, 'type' => 'number', 'format' => '0.################', 'width' => 30],
            ['key' => 'role', 'label' => __('formula_documents.exports.role'), 'type' => 'string', 'width' => 24],
            ['key' => 'note', 'label' => __('formula_documents.exports.note'), 'type' => 'string', 'width' => 40],
        ];
    }

    /** @return list<array{key: string, label: string, type: 'string'|'number', format?: string, width?: float}> */
    private function inciColumns(): array
    {
        return [
            ['key' => 'ingredient', 'label' => __('formula_documents.composition.inci_ingredient'), 'type' => 'string', 'width' => 34],
            ['key' => 'quantity', 'label' => __('formula_documents.exports.quantity'), 'type' => 'number', 'format' => '0.################', 'width' => 18],
            ['key' => 'unit', 'label' => __('formula_documents.exports.unit'), 'type' => 'string', 'width' => 10],
            ['key' => 'percentage', 'label' => __('formula_documents.composition.percentage_accounted'), 'type' => 'number', 'format' => '0.################', 'width' => 28],
            ['key' => 'kind', 'label' => __('formula_documents.composition.kind'), 'type' => 'string', 'width' => 22],
            ['key' => 'sources', 'label' => __('formula_documents.composition.sources'), 'type' => 'string', 'width' => 44],
        ];
    }

    /** @param array<string, mixed> $composition
     * @return list<array{label: string, value: scalar|null, type?: 'string'|'number'}>
     */
    private function compositionContext(array $composition): array
    {
        $incorporated = $composition['incorporated'] ?? [];
        $identity = $composition['identity'] ?? [];
        $metadata = $composition['metadata'] ?? [];
        $context = [
            ['label' => __('formula_documents.exports.formula'), 'value' => (string) ($identity['name'] ?? ''), 'type' => 'string'],
            ['label' => __('formula_documents.exports.product_family'), 'value' => (string) ($identity['product_family'] ?? ''), 'type' => 'string'],
            ['label' => __('formula_documents.exports.product_type'), 'value' => (string) ($identity['product_type'] ?? ''), 'type' => 'string'],
            ['label' => __('formula_documents.exports.source'), 'value' => __('formula_documents.states.'.($identity['state'] ?? 'current')), 'type' => 'string'],
            ['label' => __('formula_documents.exports.version'), 'value' => $metadata['version_number'] ?? null, 'type' => isset($metadata['version_number']) ? 'number' : 'string'],
            ['label' => __('formula_documents.exports.generated_at'), 'value' => (string) ($metadata['generated_at'] ?? ''), 'type' => 'string'],
            ['label' => (string) ($incorporated['basis_label'] ?? ''), 'value' => $incorporated['basis_weight'] ?? null, 'type' => 'number'],
            ['label' => (string) ($incorporated['actual_mass_label'] ?? ''), 'value' => $incorporated['actual_mass'] ?? null, 'type' => 'number'],
            ['label' => __('formula_documents.composition.accounted_inci_weight'), 'value' => $incorporated['accounted_inci_weight'] ?? null, 'type' => 'number'],
            ['label' => __('formula_documents.composition.accounted_inci_percentage'), 'value' => $incorporated['accounted_inci_percentage'] ?? null, 'type' => 'number'],
            ['label' => __('formula_documents.exports.unit'), 'value' => (string) ($composition['unit'] ?? 'g'), 'type' => 'string'],
            ['label' => __('formula_documents.composition.selected_label_context'), 'value' => (string) data_get($composition, 'label_context.default_variant_key', ''), 'type' => 'string'],
            ['label' => __('formula_documents.composition.generated_inci'), 'value' => (string) data_get($composition, 'label_context.generated_inci_text', ''), 'type' => 'string'],
            ['label' => __('formula_documents.composition.authored_label'), 'value' => (string) data_get($composition, 'label_context.authored_label_text', ''), 'type' => 'string'],
            ['label' => __('formula_documents.composition.settings'), 'value' => collect($composition['settings'] ?? [])->map(fn (array $setting): string => $setting['label'].': '.$setting['value'])->implode(' | '), 'type' => 'string'],
            ['label' => __('formula_documents.composition.assumption'), 'value' => $composition['assumption_note'] ?? null, 'type' => 'string'],
            ['label' => __('formula_documents.composition.review_note'), 'value' => $composition['review_note'] ?? '', 'type' => 'string'],
        ];

        foreach ($composition['warnings'] ?? [] as $warning) {
            $key = 'formula_documents.composition.warnings.'.$warning;
            $message = __($key);
            $context[] = [
                'label' => __('formula_documents.composition.warning'),
                'value' => $message === $key ? (string) $warning : $message,
                'type' => 'string',
            ];
        }

        return $context;
    }
}
