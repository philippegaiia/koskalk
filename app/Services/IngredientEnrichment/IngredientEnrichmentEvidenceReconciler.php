<?php

namespace App\Services\IngredientEnrichment;

use App\Enums\IngredientIdentifierScheme;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class IngredientEnrichmentEvidenceReconciler
{
    /**
     * Reconcile source-backed metadata after an Admin edits a proposal.
     *
     * Identifier evidence is matched by its canonical identity so that
     * harmless formatting changes and row reordering do not discard the
     * research audit.
     *
     * @param  array<int, mixed>  $evidence
     * @param  array<int, mixed>  $fieldConfidence
     * @param  array<int, mixed>  $valueProvenance
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array{evidence:list<array<string,mixed>>,field_confidence:list<array<string,mixed>>,value_provenance:list<array<string,mixed>>}
     */
    public function reconcileProposalMetadata(
        array $evidence,
        array $fieldConfidence,
        array $valueProvenance,
        array $before,
        array $after,
    ): array {
        return [
            'evidence' => $this->reconcileSourceEvidence($evidence, $before, $after),
            'field_confidence' => $this->reconcileFieldConfidence($fieldConfidence, $after),
            'value_provenance' => $this->reconcileValueProvenance($valueProvenance, $before, $after),
        ];
    }

    /**
     * Build the stable identity used by proposal, application, and database
     * synchronization code.
     *
     * @param  array<string, mixed>  $row
     */
    public function identifierKey(array $row): string
    {
        $scheme = mb_strtolower(trim((string) ($row['scheme'] ?? '')));

        return $scheme.':'.$this->normalizeIdentifier((string) ($row['value'] ?? ''), $scheme);
    }

    public function normalizeIdentifier(string $value, string $scheme): string
    {
        $normalized = preg_replace('/[\p{Pd}\x{2212}]/u', '-', trim($value)) ?? trim($value);

        return in_array(mb_strtolower(trim($scheme)), [
            IngredientIdentifierScheme::Unii->value,
            IngredientIdentifierScheme::InchiKey->value,
        ], true)
            ? Str::upper($normalized)
            : mb_strtolower($normalized);
    }

    /**
     * Project only the source attributes accepted by enrichment evidence.
     * Sorting makes equivalent source rows compare identically regardless of
     * the order in which an editor submitted their keys.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public function sourceAttributes(array $row): array
    {
        $attributes = collect($row)
            ->only($this->sourceFields())
            ->map(fn (mixed $value): mixed => is_string($value) ? trim($value) : $value)
            ->all();
        ksort($attributes);

        return $attributes;
    }

    /**
     * Project accepted identifier evidence onto the identifiers that will be
     * persisted. Evidence fields remain positional in the result contract,
     * but their identifier lookup is canonical rather than positional.
     *
     * @param  array<int, mixed>  $identifiers
     * @param  array<int, mixed>  $proposalIdentifiers
     * @param  array<int, mixed>  $acceptedEvidence
     * @return list<array{scheme:string,value:string,evidence:list<array<string,mixed>>}>
     */
    public function projectIdentifierEvidence(
        array $identifiers,
        array $proposalIdentifiers,
        array $acceptedEvidence,
    ): array {
        $proposalIndexes = [];
        foreach (array_values($proposalIdentifiers) as $index => $row) {
            if (is_array($row)) {
                $proposalIndexes[$this->identifierKey($row)] ??= $index;
            }
        }

        $evidenceByField = collect($acceptedEvidence)
            ->filter(fn (mixed $row): bool => is_array($row) && is_string($row['field'] ?? null))
            ->groupBy('field');

        return collect($identifiers)
            ->filter(fn (mixed $row): bool => is_array($row))
            ->map(function (array $row) use ($proposalIdentifiers, $proposalIndexes, $evidenceByField): array {
                $proposalIndex = $proposalIndexes[$this->identifierKey($row)] ?? null;
                $proposalRow = is_int($proposalIndex) ? ($proposalIdentifiers[$proposalIndex] ?? null) : null;
                $evidence = is_int($proposalIndex)
                    ? $evidenceByField
                        ->get("proposal.identifiers.{$proposalIndex}", collect())
                        ->map(fn (array $evidenceRow): array => $this->sourceAttributes($evidenceRow))
                        ->values()
                        ->all()
                    : [];

                if ($evidence === [] && is_array($proposalRow)) {
                    $fallbackSource = [
                        ...$row,
                        ...$this->sourceAttributes($proposalRow),
                    ];
                    $evidence = collect([$fallbackSource])
                        ->filter(fn (array $source): bool => is_string($source['source_url'] ?? null)
                            && trim($source['source_url']) !== ''
                            && is_string($source['source_name'] ?? null)
                            && trim($source['source_name']) !== '')
                        ->map(fn (array $source): array => $this->sourceAttributes($source))
                        ->values()
                        ->all();
                }

                return [
                    'scheme' => (string) ($row['scheme'] ?? ''),
                    'value' => (string) ($row['value'] ?? ''),
                    'evidence' => $evidence,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Return all changed paths in a proposal-shaped array.
     *
     * @param  array<string|int, mixed>  $before
     * @param  array<string|int, mixed>  $after
     * @return list<string>
     */
    public function changedPaths(array $before, array $after, string $prefix): array
    {
        $paths = [];
        foreach (collect([...array_keys($before), ...array_keys($after)])->unique() as $key) {
            $path = $prefix.'.'.$key;
            $old = $before[$key] ?? null;
            $new = $after[$key] ?? null;
            if (is_array($old) && is_array($new)) {
                $paths = [...$paths, ...$this->changedPaths($old, $new, $path)];
            } elseif ($old !== $new) {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    /**
     * @param  array<int, mixed>  $evidence
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return list<array<string, mixed>>
     */
    private function reconcileSourceEvidence(array $evidence, array $before, array $after): array
    {
        $preserved = collect($evidence)
            ->filter(fn (mixed $row): bool => is_array($row) && is_string($row['field'] ?? null))
            ->reject(fn (array $row): bool => $this->isSourceBackedCollectionPath($row['field']));
        $sourceBackedRows = $this->sourceBackedRows($after)
            ->reject(fn (array $row): bool => str_starts_with($row['field'], 'proposal.identifiers.'));

        return $preserved->merge($sourceBackedRows->map(
            fn (array $row): array => [
                'field' => $row['field'],
                ...collect($row['source'])->only($this->sourceFields())->all(),
            ],
        ))->merge($this->reconcileIdentifierEvidence($evidence, $before, $after))->values()->all();
    }

    /**
     * @param  array<int, mixed>  $evidence
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return list<array<string, mixed>>
     */
    private function reconcileIdentifierEvidence(array $evidence, array $before, array $after): array
    {
        $beforeIdentifiers = collect(is_array($before['identifiers'] ?? null) ? $before['identifiers'] : [])
            ->values();
        $beforeIndexes = [];
        foreach ($beforeIdentifiers as $index => $row) {
            if (is_array($row)) {
                $beforeIndexes[$this->identifierKey($row)] ??= $index;
            }
        }
        $oldEvidence = collect($evidence)
            ->filter(fn (mixed $row): bool => is_array($row) && is_string($row['field'] ?? null))
            ->filter(fn (array $row): bool => preg_match('/^proposal\.identifiers\.\d+$/', $row['field']) === 1);

        return collect(is_array($after['identifiers'] ?? null) ? $after['identifiers'] : [])
            ->values()
            ->flatMap(function (mixed $row, int $index) use ($beforeIdentifiers, $beforeIndexes, $oldEvidence): array {
                if (! is_array($row)) {
                    return [];
                }

                $key = $this->identifierKey($row);
                $beforeIndex = $beforeIndexes[$key] ?? null;
                $beforeMatch = is_int($beforeIndex) ? $beforeIdentifiers->get($beforeIndex) : null;
                $sameSource = is_array($beforeMatch)
                    && $this->sourceAttributes($beforeMatch) === $this->sourceAttributes($row);

                if ($sameSource && is_int($beforeIndex)) {
                    return $oldEvidence
                        ->filter(fn (array $evidenceRow): bool => $evidenceRow['field'] === "proposal.identifiers.{$beforeIndex}")
                        ->map(fn (array $evidenceRow): array => [
                            ...$evidenceRow,
                            'field' => "proposal.identifiers.{$index}",
                        ])
                        ->values()
                        ->all();
                }

                return [[
                    'field' => "proposal.identifiers.{$index}",
                    ...$this->sourceAttributes($row),
                ]];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<int, mixed>  $fieldConfidence
     * @param  array<string, mixed>  $proposal
     * @return list<array{field:string,confidence:mixed}>
     */
    private function reconcileFieldConfidence(array $fieldConfidence, array $proposal): array
    {
        $preserved = collect($fieldConfidence)
            ->filter(fn (mixed $row): bool => is_array($row) && is_string($row['field'] ?? null))
            ->reject(fn (array $row): bool => $this->isSourceBackedCollectionPath($row['field']));

        return $preserved->merge($this->sourceBackedRows($proposal)->map(fn (array $row): array => [
            'field' => $row['field'],
            'confidence' => $row['source']['confidence'] ?? null,
        ]))->values()->all();
    }

    /**
     * @param  array<string, mixed>  $proposal
     * @return Collection<int, array{field:string,source:array<string,mixed>}>
     */
    private function sourceBackedRows(array $proposal): Collection
    {
        return collect(['aliases', 'identifiers', 'cosing_functions', 'market_labels'])
            ->flatMap(fn (string $collection): array => collect(is_array($proposal[$collection] ?? null) ? $proposal[$collection] : [])
                ->filter(fn (mixed $row): bool => is_array($row))
                ->values()
                ->map(fn (array $row, int $index): array => [
                    'field' => "proposal.{$collection}.{$index}",
                    'source' => $row,
                ])->all())
            ->values();
    }

    /**
     * @param  array<int, mixed>  $provenance
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return list<array<string, mixed>>
     */
    private function reconcileValueProvenance(array $provenance, array $before, array $after): array
    {
        $rows = collect($provenance)
            ->filter(fn (mixed $row): bool => is_array($row) && is_string($row['field'] ?? null))
            ->reject(fn (array $row): bool => preg_match('/^proposal\.identifiers\.\d+$/', $row['field']) === 1)
            ->keyBy('field');
        $beforeIdentifiers = collect(is_array($before['identifiers'] ?? null) ? $before['identifiers'] : [])
            ->values();
        $beforeIndexes = [];
        foreach ($beforeIdentifiers as $index => $row) {
            if (is_array($row)) {
                $beforeIndexes[$this->identifierKey($row)] ??= $index;
            }
        }

        foreach (collect(is_array($after['identifiers'] ?? null) ? $after['identifiers'] : [])->values() as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $key = $this->identifierKey($row);
            $beforeIndex = $beforeIndexes[$key] ?? null;
            $beforeRow = is_int($beforeIndex) ? $beforeIdentifiers->get($beforeIndex) : null;
            $oldProvenance = is_int($beforeIndex) ? $this->provenanceRow($provenance, "proposal.identifiers.{$beforeIndex}") : null;
            if (is_array($beforeRow)
                && $this->sourceAttributes($beforeRow) === $this->sourceAttributes($row)
                && is_array($oldProvenance)) {
                $oldProvenance['field'] = "proposal.identifiers.{$index}";
                $rows->put($oldProvenance['field'], $oldProvenance);

                continue;
            }

            $rows->put("proposal.identifiers.{$index}", [
                'field' => "proposal.identifiers.{$index}",
                'kind' => 'reviewer_supplied',
                'reasoning' => 'Changed explicitly by the reviewing Admin.',
                'source_urls' => [],
            ]);
        }

        foreach ($this->changedPaths($before, $after, 'proposal') as $path) {
            if (preg_match('/^proposal\.identifiers(?:\.\d+)?(?:\.|$)/', $path) === 1) {
                continue;
            }
            if ($this->isFormattingOnlyChange($path, data_get($before, Str::after($path, 'proposal.')), data_get($after, Str::after($path, 'proposal.')))) {
                continue;
            }

            $field = $this->provenancePath($path);
            $rows->put($field, [
                'field' => $field,
                'kind' => 'reviewer_supplied',
                'reasoning' => 'Changed explicitly by the reviewing Admin.',
                'source_urls' => [],
            ]);
        }

        return $rows->values()->all();
    }

    /**
     * @param  array<int, mixed>  $provenance
     * @return array<string, mixed>|null
     */
    private function provenanceRow(array $provenance, string $field): ?array
    {
        foreach ($provenance as $row) {
            if (is_array($row) && ($row['field'] ?? null) === $field) {
                return $row;
            }
        }

        return null;
    }

    private function isFormattingOnlyChange(string $path, mixed $before, mixed $after): bool
    {
        if (! preg_match('/^proposal\.(inci_name|market_labels\.\d+\.declaration_name)$/', $path)) {
            return false;
        }

        return is_string($before) && is_string($after)
            && Str::lower(Str::squish($before)) === Str::lower(Str::squish($after));
    }

    private function provenancePath(string $path): string
    {
        if (preg_match('/^(proposal\.(?:aliases|identifiers|cosing_functions|market_labels|translations)\.\d+)/', $path, $matches) === 1) {
            return $matches[1];
        }

        return $path;
    }

    private function isSourceBackedCollectionPath(string $path): bool
    {
        return preg_match('/^proposal\.(aliases|identifiers|cosing_functions|market_labels)\.\d+$/', $path) === 1;
    }

    /** @return list<string> */
    private function sourceFields(): array
    {
        return [
            'source_name', 'source_url', 'source_tier', 'confidence', 'source_version',
            'source_updated_at', 'retrieved_at',
        ];
    }
}
