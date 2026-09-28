<?php

namespace App\Services;

class RecipeCostingExportDataBuilder
{
    /**
     * Builds presentation rows from a formula document and an authorized costing projection.
     * This service performs no model lookups and has no persistence responsibilities.
     *
     * @param  array<string, mixed>  $document
     * @param  array<string, mixed>  $projection
     * @return array<string, mixed>
     */
    public function build(array $document, array $projection): array
    {
        $identity = is_array($document['identity'] ?? null) ? $document['identity'] : [];
        $metadata = is_array($document['metadata'] ?? null) ? $document['metadata'] : [];
        $currency = (string) ($projection['currency'] ?? 'EUR');
        $source = (string) ($identity['state'] ?? 'current');
        $versionNumber = $metadata['version_number'] ?? null;
        $percentageBasis = (string) ($document['percentage_basis'] ?? 'formula');
        $context = [
            ['label' => __('formula_documents.exports.formula'), 'value' => (string) ($identity['name'] ?? ''), 'type' => 'string'],
            ['label' => __('formula_documents.exports.product_family'), 'value' => (string) ($identity['product_family'] ?? ''), 'type' => 'string'],
            ['label' => __('formula_documents.exports.product_type'), 'value' => (string) ($identity['product_type'] ?? ''), 'type' => 'string'],
            ['label' => __('formula_documents.exports.source'), 'value' => __('formula_documents.states.'.$source), 'type' => 'string'],
            ['label' => __('formula_documents.exports.version'), 'value' => $versionNumber, 'type' => $versionNumber === null ? 'string' : 'number'],
            ['label' => __('formula_documents.exports.generated_at'), 'value' => (string) ($metadata['generated_at'] ?? ''), 'type' => 'string'],
            ['label' => __('formula_documents.exports.currency'), 'value' => $currency, 'type' => 'string'],
            ['label' => __('formula_documents.exports.costing_basis'), 'value' => $projection['basis_weight'] ?? 0, 'type' => 'number'],
            ['label' => __('formula_documents.exports.unit'), 'value' => $projection['basis_unit'] ?? ($document['unit'] ?? 'g'), 'type' => 'string'],
            ['label' => __('formula_documents.exports.units_produced'), 'value' => $projection['units_produced'] ?? null, 'type' => 'number'],
        ];

        return [
            'document' => $document,
            'currency' => $currency,
            'context' => $context,
            'source' => $source,
            'version_number' => $versionNumber,
            'generated_at' => (string) ($metadata['generated_at'] ?? ''),
            'basis_weight' => $projection['basis_weight'] ?? 0,
            'basis_unit' => $projection['basis_unit'] ?? ($document['unit'] ?? 'g'),
            'percentage_heading' => __('formula_documents.exports.percentage_'.($percentageBasis === 'oils' ? 'oils' : 'formula')),
            'units_produced' => $projection['units_produced'] ?? null,
            'ingredientRows' => collect($projection['ingredient_rows'] ?? [])
                ->map(fn (array $row): array => [
                    'phase' => $row['phase_name'] ?? '',
                    'ingredient' => $row['ingredient_name'] ?? '',
                    'percentage_basis' => $row['percentage_basis'] ?? '',
                    'percentage' => $row['percentage'] ?? null,
                    'quantity' => $row['quantity'] ?? null,
                    'unit' => $row['unit'] ?? ($projection['basis_unit'] ?? 'g'),
                    'price_per_kg' => $row['price_per_kg'] ?? null,
                    'price_currency' => $currency,
                    'line_cost' => (bool) ($row['is_unpriced'] ?? true) ? null : ($row['line_cost'] ?? null),
                    'line_currency' => $currency,
                    'cost_status' => (bool) ($row['is_unpriced'] ?? true)
                        ? __('formula_documents.exports.unpriced')
                        : __('formula_documents.exports.priced'),
                ])
                ->all(),
            'packagingRows' => collect($projection['packaging_rows'] ?? [])
                ->map(fn (array $row): array => [
                    'packaging' => $row['name'] ?? '',
                    'material_code' => $row['material_code'] ?? '',
                    'components_per_unit' => $row['components_per_unit'] ?? null,
                    'unit_cost' => $row['unit_cost'] ?? null,
                    'currency' => $currency,
                    'cost_per_finished_unit' => $row['cost_per_finished_unit'] ?? null,
                    'batch_cost' => $row['line_cost'] ?? null,
                    'status' => (bool) ($row['is_unpriced'] ?? true)
                        ? __('formula_documents.exports.unpriced')
                        : (($row['line_cost'] ?? null) === null
                            ? __('formula_documents.exports.units_required')
                            : __('formula_documents.exports.priced')),
                ])
                ->all(),
            'summary' => [
                'ingredient_total' => $projection['ingredient_total'] ?? null,
                'ingredient_currency' => $currency,
                'packaging_total' => $projection['packaging_total'] ?? null,
                'packaging_currency' => $currency,
                'total_cost' => $projection['total_cost'] ?? null,
                'total_currency' => $currency,
                'cost_per_unit' => $projection['cost_per_unit'] ?? null,
                'unit_currency' => $currency,
                'has_unpriced_rows' => (bool) ($projection['has_unpriced_rows'] ?? false),
                'has_incomplete_packaging_total' => (bool) ($projection['has_incomplete_packaging_total'] ?? false),
            ],
        ];
    }

    /** @param array<string, mixed> $data
     * @return list<array<string, mixed>>
     */
    public function workbookSheets(array $data): array
    {
        return [
            [
                'name' => __('formula_documents.exports.costing_ingredients'),
                'title' => __('formula_documents.exports.costing_ingredients'),
                'context' => $data['context'],
                'columns' => [
                    ['key' => 'phase', 'label' => __('formula_documents.exports.phase'), 'type' => 'string', 'width' => 24],
                    ['key' => 'ingredient', 'label' => __('formula_documents.exports.ingredient'), 'type' => 'string', 'width' => 34],
                    ['key' => 'percentage_basis', 'label' => __('formula_documents.exports.percentage_basis'), 'type' => 'string', 'width' => 18],
                    ['key' => 'percentage', 'label' => $data['percentage_heading'], 'type' => 'number', 'format' => '0.################', 'width' => 16],
                    ['key' => 'quantity', 'label' => __('formula_documents.exports.quantity'), 'type' => 'number', 'format' => '0.################', 'width' => 18],
                    ['key' => 'unit', 'label' => __('formula_documents.exports.unit'), 'type' => 'string', 'width' => 10],
                    ['key' => 'price_per_kg', 'label' => __('formula_documents.exports.price_per_kg'), 'type' => 'number', 'format' => '0.################', 'width' => 18],
                    ['key' => 'price_currency', 'label' => __('formula_documents.exports.price_currency'), 'type' => 'string', 'width' => 14],
                    ['key' => 'line_cost', 'label' => __('formula_documents.exports.line_cost'), 'type' => 'number', 'format' => '0.################', 'width' => 18],
                    ['key' => 'line_currency', 'label' => __('formula_documents.exports.line_currency'), 'type' => 'string', 'width' => 14],
                    ['key' => 'cost_status', 'label' => __('formula_documents.exports.status'), 'type' => 'string', 'width' => 20],
                ],
                'rows' => $data['ingredientRows'],
            ],
            [
                'name' => __('formula_documents.exports.costing_packaging'),
                'title' => __('formula_documents.exports.costing_packaging'),
                'context' => $data['context'],
                'columns' => [
                    ['key' => 'packaging', 'label' => __('formula_documents.exports.packaging'), 'type' => 'string', 'width' => 34],
                    ['key' => 'material_code', 'label' => __('formula_documents.exports.material_code'), 'type' => 'string', 'width' => 18],
                    ['key' => 'components_per_unit', 'label' => __('formula_documents.exports.components_per_unit'), 'type' => 'number', 'format' => '0.################', 'width' => 20],
                    ['key' => 'unit_cost', 'label' => __('formula_documents.exports.unit_cost'), 'type' => 'number', 'format' => '0.################', 'width' => 16],
                    ['key' => 'currency', 'label' => __('formula_documents.exports.currency'), 'type' => 'string', 'width' => 14],
                    ['key' => 'cost_per_finished_unit', 'label' => __('formula_documents.exports.cost_per_finished_unit'), 'type' => 'number', 'format' => '0.################', 'width' => 24],
                    ['key' => 'batch_cost', 'label' => __('formula_documents.exports.batch_cost'), 'type' => 'number', 'format' => '0.################', 'width' => 18],
                    ['key' => 'status', 'label' => __('formula_documents.exports.status'), 'type' => 'string', 'width' => 20],
                ],
                'rows' => $data['packagingRows'],
            ],
            [
                'name' => __('formula_documents.exports.costing_summary'),
                'title' => __('formula_documents.exports.costing_summary'),
                'context' => $data['context'],
                'columns' => [
                    ['key' => 'item', 'label' => __('formula_documents.exports.cost_item'), 'type' => 'string', 'width' => 34],
                    ['key' => 'amount', 'label' => __('formula_documents.exports.amount'), 'type' => 'number', 'format' => '0.################', 'width' => 20],
                    ['key' => 'currency', 'label' => __('formula_documents.exports.currency'), 'type' => 'string', 'width' => 14],
                    ['key' => 'status', 'label' => __('formula_documents.exports.status'), 'type' => 'string', 'width' => 24],
                ],
                'rows' => $this->summaryRows($data),
            ],
        ];
    }

    /** @param array<string, mixed> $data
     * @return list<array{item: string, amount: scalar|null, currency: string, status: string}>
     */
    private function summaryRows(array $data): array
    {
        $summary = $data['summary'];
        $items = [
            ['ingredient_total', __('formula_documents.exports.ingredient_total')],
            ['packaging_total', __('formula_documents.exports.packaging_total')],
            ['total_cost', __('formula_documents.exports.total_cost')],
            ['cost_per_unit', __('formula_documents.exports.cost_per_unit')],
        ];

        return collect($items)->map(function (array $item) use ($summary, $data): array {
            $key = $item[0];
            $amount = $summary[$key];
            $status = $amount === null
                ? __('formula_documents.exports.units_required')
                : ($summary['has_unpriced_rows']
                    ? __('formula_documents.exports.includes_unpriced_rows')
                    : __('formula_documents.exports.complete'));

            if ($key === 'total_cost' && $summary['has_incomplete_packaging_total']) {
                $status = __('formula_documents.exports.packaging_total_incomplete');
            }

            return [
                'item' => $item[1],
                'amount' => $amount,
                'currency' => $data['currency'],
                'status' => $status,
            ];
        })->all();
    }
}
