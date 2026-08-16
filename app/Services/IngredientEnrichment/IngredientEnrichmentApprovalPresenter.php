<?php

namespace App\Services\IngredientEnrichment;

use App\Enums\IngredientEnrichmentReplaceField;
use App\Models\IngredientEnrichmentBatchItem;
use Illuminate\Support\Str;

class IngredientEnrichmentApprovalPresenter
{
    public function __construct(
        private readonly IngredientEnrichmentSnapshotBuilder $snapshots,
    ) {}

    /**
     * @return array<string, array{label:string, description:string}>
     */
    public function replacementConflicts(IngredientEnrichmentBatchItem $item): array
    {
        $decisions = collect(data_get($item->plan, 'decisions', []))
            ->filter(fn (mixed $decision): bool => is_array($decision) && is_string($decision['field'] ?? null))
            ->keyBy('field');
        $conflicts = [];

        foreach (IngredientEnrichmentReplaceField::cases() as $field) {
            $decision = $decisions->get($this->decisionPath($field));

            if (! is_array($decision) || ! $this->wouldOverwriteExistingValue($field, $decision)) {
                continue;
            }

            $conflicts[$field->value] = [
                'label' => $field->label(),
                'description' => __('ingredient_enrichment_admin.approval.current_proposed', [
                    'current' => $this->formatValue($decision['current'] ?? null),
                    'proposed' => $this->formatValue($decision['proposed'] ?? null),
                ]),
            ];
        }

        return $conflicts;
    }

    private function decisionPath(IngredientEnrichmentReplaceField $field): string
    {
        return "proposal.{$field->value}";
    }

    /** @param array<string, mixed> $decision */
    private function wouldOverwriteExistingValue(IngredientEnrichmentReplaceField $field, array $decision): bool
    {
        $current = $decision['current'] ?? null;
        $proposed = $decision['proposed'] ?? null;

        if ($this->emptyValue($current) || $this->emptyValue($proposed) || $current === $proposed) {
            return false;
        }

        return match ($field) {
            IngredientEnrichmentReplaceField::Identifiers => $this->removesRows($current, $proposed, fn (array $row): string => implode('|', [
                (string) ($row['scheme'] ?? ''),
                strtoupper((string) ($row['normalized_value'] ?? $row['value'] ?? '')),
            ])),
            IngredientEnrichmentReplaceField::CosingFunctions => $this->removesRows($current, $proposed, fn (array $row): string => (string) ($row['key'] ?? '')),
            IngredientEnrichmentReplaceField::MarketLabels => $this->removesRows($current, $proposed, fn (array $row): string => (string) ($row['market_code'] ?? '')),
            IngredientEnrichmentReplaceField::Translations => $this->translationsWouldBeOverwritten($current, $proposed),
            default => true,
        };
    }

    /**
     * @param  callable(array<string, mixed>): string  $key
     */
    private function removesRows(mixed $current, mixed $proposed, callable $key): bool
    {
        if (! is_array($current) || ! is_array($proposed)) {
            return false;
        }

        $currentKeys = collect($current)->filter(fn (mixed $row): bool => is_array($row))->map($key)->filter()->unique();
        $proposedKeys = collect($proposed)->filter(fn (mixed $row): bool => is_array($row))->map($key)->filter()->unique();

        return $currentKeys->diff($proposedKeys)->isNotEmpty();
    }

    private function translationsWouldBeOverwritten(mixed $current, mixed $proposed): bool
    {
        if (! is_array($current) || ! is_array($proposed)) {
            return false;
        }

        $currentByLocale = collect($current)->filter(fn (mixed $row): bool => is_array($row))->keyBy('locale');
        $proposedByLocale = collect($proposed)->filter(fn (mixed $row): bool => is_array($row))->keyBy('locale');

        foreach ($proposedByLocale as $locale => $proposedRow) {
            $currentRow = $currentByLocale->get($locale);

            if (! is_array($currentRow) || ! is_array($proposedRow)) {
                continue;
            }

            foreach (['display_name', 'saponification_name', 'info_markdown'] as $field) {
                $currentValue = $currentRow[$field] ?? null;
                $proposedValue = $proposedRow[$field] ?? null;

                if (! $this->emptyValue($currentValue) && ! $this->emptyValue($proposedValue) && $currentValue !== $proposedValue) {
                    return true;
                }
            }
        }

        return $currentByLocale
            ->keys()
            ->intersect($this->snapshots->targetLocales())
            ->diff($proposedByLocale->keys())
            ->isNotEmpty();
    }

    private function formatValue(mixed $value): string
    {
        if (is_string($value)) {
            return Str::limit((string) str($value)->squish(), 240);
        }

        if (! is_array($value)) {
            return match (true) {
                $value === null => (string) __('ingredient_enrichment_admin.review.no_value'),
                is_bool($value) => $value ? __('ingredient_enrichment_admin.approval.yes') : __('ingredient_enrichment_admin.approval.no'),
                default => (string) $value,
            };
        }

        return Str::limit(collect($value)
            ->filter(fn (mixed $row): bool => is_array($row))
            ->map(fn (array $row): string => $this->formatRow($row))
            ->filter()
            ->implode('; '), 300);
    }

    /** @param array<string, mixed> $row */
    private function formatRow(array $row): string
    {
        if (isset($row['scheme'])) {
            return strtoupper((string) $row['scheme']).': '.(string) ($row['value'] ?? $row['normalized_value'] ?? '');
        }

        if (isset($row['key'])) {
            return (string) $row['key'];
        }

        if (isset($row['market_code'])) {
            return strtoupper((string) $row['market_code']).': '.(string) ($row['declaration_name'] ?? '');
        }

        if (isset($row['locale'])) {
            $values = collect(['display_name', 'saponification_name', 'info_markdown'])
                ->map(fn (string $field): mixed => $row[$field] ?? null)
                ->filter(fn (mixed $value): bool => ! $this->emptyValue($value))
                ->map(fn (mixed $value): string => Str::limit((string) str((string) $value)->squish(), 80));

            return (string) $row['locale'].': '.$values->implode(' / ');
        }

        return json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    }

    private function emptyValue(mixed $value): bool
    {
        return $value === null || $value === [] || (is_string($value) && trim($value) === '');
    }
}
