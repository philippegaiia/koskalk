<?php

namespace App\Services\IngredientEnrichment;

use App\Data\IngredientSourceStageResult;
use Illuminate\Support\Str;

class IngredientEnrichmentFactsBuilder
{
    public function __construct(private readonly IngredientIdentityMatchService $identityMatcher) {}

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    public function build(
        array $record,
        IngredientSourceStageResult $euStructured,
        IngredientSourceStageResult $euOfficial,
        IngredientSourceStageResult $usIdentity,
        IngredientSourceStageResult $usDeclaration,
    ): array {
        $officialName = $euOfficial->data['matched'] ?? false
            ? $euOfficial->data['common_ingredient_name']
            : null;
        $identityRecord = is_string($officialName) && $officialName !== ''
            ? [...$record, 'inci_name' => $officialName]
            : $record;
        $identity = $this->identityMatcher->select($euStructured->data['candidates'] ?? [], $identityRecord);
        $candidate = $identity['candidate'];
        $inciName = is_string($officialName) ? $officialName : ($candidate['inci_name'] ?? $record['inci_name'] ?? null);
        $usCandidate = $this->selectUsCandidate($usIdentity->data['candidates'] ?? [], $identityRecord);
        $euEvidence = $this->sourceEvidenceForCandidate($euStructured->evidence, $candidate);
        $officialFieldEvidence = is_string($officialName)
            ? $this->sourceEvidenceFor('proposal.inci_name', $officialName, $euOfficial->evidence)
            : null;
        $officialEvidence = $officialFieldEvidence ?? $euEvidence;
        $inciConfidence = $officialFieldEvidence !== null
            ? 'verified'
            : ($euEvidence === null ? 'unresolved' : 'supported');
        $soapInciNaohName = $this->nonEmptyString($euOfficial->data['soap_inci_naoh_name'] ?? null);
        $soapInciKohName = $this->nonEmptyString($euOfficial->data['soap_inci_koh_name'] ?? null);
        $soapInciNaohEvidence = $this->sourceEvidenceFor(
            'proposal.soap_inci_naoh_name',
            $soapInciNaohName,
            $euOfficial->evidence,
        );
        $soapInciKohEvidence = $this->sourceEvidenceFor(
            'proposal.soap_inci_koh_name',
            $soapInciKohName,
            $euOfficial->evidence,
        );
        $usIdentityEvidence = $this->sourceEvidenceForReference(
            'source.us_identity',
            $usCandidate['unii'] ?? null,
            $usIdentity->evidence,
        );
        $usDeclarationEvidence = $this->sourceEvidenceFor(
            'proposal.market_labels.us.declaration_name',
            $usDeclaration->data['declaration_name'] ?? null,
            $usDeclaration->evidence,
        );
        $identifiers = $this->identifiers($candidate, $usCandidate, $euEvidence, $usIdentityEvidence);
        $aliases = $this->aliases(
            $record,
            $usCandidate,
            $usDeclaration,
            $inciName,
            $usIdentityEvidence,
        );
        $marketLabels = is_string($inciName) && $inciName !== '' && is_array($officialEvidence) ? [[
            'market_code' => 'eu',
            'declaration_name' => $inciName,
            'reviewed_at' => null,
            'effective_from' => null,
            'effective_until' => null,
            ...($officialEvidence ?? []),
        ]] : [];

        if (is_string($usDeclaration->data['declaration_name'] ?? null)) {
            $marketLabels[] = [
                'market_code' => 'us',
                'declaration_name' => $usDeclaration->data['declaration_name'],
                'reviewed_at' => null,
                'effective_from' => null,
                'effective_until' => null,
                ...($usDeclarationEvidence ?? []),
            ];
        }

        $isLipid = ($record['category'] ?? null) === 'lipids'
            || ($record['research_family'] ?? null) === 'lipids';
        $functions = collect(is_array($candidate['functions'] ?? null) ? $candidate['functions'] : [])
            ->values()
            ->map(fn (string $key): array => [
                'key' => $key,
                ...($euEvidence ?? []),
            ])
            ->all();
        $fieldConfidence = collect([
            ['field' => 'proposal.inci_name', 'confidence' => $inciConfidence],
            ...($isLipid ? [
                ['field' => 'proposal.soap_inci_naoh_name', 'confidence' => $soapInciNaohName === null || $soapInciNaohEvidence === null ? 'unresolved' : 'verified'],
                ['field' => 'proposal.soap_inci_koh_name', 'confidence' => $soapInciKohName === null || $soapInciKohEvidence === null ? 'unresolved' : 'verified'],
            ] : []),
            ...collect($marketLabels)->keys()->map(fn (int $index): array => [
                'field' => "proposal.market_labels.{$index}",
                'confidence' => (string) ($marketLabels[$index]['confidence'] ?? 'unresolved'),
            ])->all(),
            ...collect($identifiers)->keys()->map(fn (int $index): array => [
                'field' => "proposal.identifiers.{$index}",
                'confidence' => (string) ($identifiers[$index]['confidence'] ?? 'supported'),
            ])->all(),
            ...collect($aliases)->keys()->map(fn (int $index): array => [
                'field' => "proposal.aliases.{$index}",
                'confidence' => (string) ($aliases[$index]['confidence'] ?? 'supported'),
            ])->all(),
            ...collect($functions)->keys()->map(fn (int $index): array => [
                'field' => "proposal.cosing_functions.{$index}",
                'confidence' => (string) ($functions[$index]['confidence'] ?? 'supported'),
            ])->all(),
        ])->filter()->values()->all();
        $evidence = collect([
            $this->evidenceForField('proposal.inci_name', $officialEvidence),
            $soapInciNaohName === null ? null : $this->evidenceForField('proposal.soap_inci_naoh_name', $soapInciNaohEvidence),
            $soapInciKohName === null ? null : $this->evidenceForField('proposal.soap_inci_koh_name', $soapInciKohEvidence),
            ...collect($identifiers)->keys()->map(fn (int $index): ?array => $this->evidenceForField("proposal.identifiers.{$index}", $identifiers[$index]))->all(),
            ...collect($aliases)->keys()->map(fn (int $index): ?array => $this->evidenceForField("proposal.aliases.{$index}", $aliases[$index]))->all(),
            ...collect($functions)->keys()->map(fn (int $index): ?array => $this->evidenceForField("proposal.cosing_functions.{$index}", $functions[$index]))->all(),
            ...collect($marketLabels)->keys()->map(fn (int $index): ?array => $this->evidenceForField("proposal.market_labels.{$index}", $marketLabels[$index]))->all(),
        ])->filter()->values()->all();

        return [
            'proposal' => [
                'display_name' => $record['display_name'] ?? null,
                'inci_name' => $inciName,
                'soap_inci_naoh_name' => $soapInciNaohName,
                'soap_inci_koh_name' => $soapInciKohName,
                'category' => $record['category'] ?? null,
                'subcategory' => $record['subcategory'] ?? null,
                'aliases' => $aliases,
                'identifiers' => $identifiers,
                'cosing_functions' => $functions,
                'market_labels' => $marketLabels,
            ],
            'field_confidence' => $fieldConfidence,
            'evidence' => $evidence,
            'regulatory_findings' => $this->regulatoryFindings($usDeclaration, $usDeclarationEvidence),
            'warnings' => [...$euStructured->warnings, ...$euOfficial->warnings, ...$usIdentity->warnings, ...$usDeclaration->warnings],
            'unresolved_questions' => [
                ...$euStructured->unresolvedQuestions,
                ...$euOfficial->unresolvedQuestions,
                ...$usIdentity->unresolvedQuestions,
                ...$usDeclaration->unresolvedQuestions,
                ...($officialEvidence === null ? [__('ingredient_enrichment.warnings.eu_identity_unresolved')] : []),
                ...($isLipid && $soapInciNaohName === null
                    ? [__('ingredient_enrichment.warnings.soap_inci_naoh_unresolved')]
                    : []),
                ...($isLipid && $soapInciKohName === null
                    ? [__('ingredient_enrichment.warnings.soap_inci_koh_unresolved')]
                    : []),
            ],
            'conflicts' => $identity['conflicts'],
            'editorial_context' => [
                'identity_description' => $this->nonEmptyString($candidate['description'] ?? null),
                'cosing_functions' => collect($candidate['functions'] ?? [])
                    ->filter(fn (mixed $function): bool => is_string($function) && $function !== '')
                    ->values()
                    ->all(),
                'material_class' => collect([
                    $record['category'] ?? null,
                    $record['subcategory'] ?? null,
                ])->filter(fn (mixed $value): bool => is_string($value) && $value !== '')->values()->all(),
                ...(is_array($record['trusted_soap_chemistry'] ?? null)
                    ? ['trusted_soap_chemistry' => $record['trusted_soap_chemistry']]
                    : []),
            ],
        ];
    }

    private function nonEmptyString(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    /**
     * @param  array<string, mixed>|null  $euCandidate
     * @param  array<string, mixed>|null  $usCandidate
     * @param  array<string, mixed>|null  $euEvidence
     * @param  array<string, mixed>|null  $usEvidence
     * @return list<array<string, mixed>>
     */
    private function identifiers(?array $euCandidate, ?array $usCandidate, ?array $euEvidence, ?array $usEvidence): array
    {
        $rows = [];

        foreach ([
            ['cosing_ref', $euCandidate['cosing_ref'] ?? null, $euEvidence],
            ['cas', $euCandidate['cas'] ?? [], $euEvidence],
            ['ec', $euCandidate['ec'] ?? [], $euEvidence],
            ['unii', $usCandidate['unii'] ?? null, $usEvidence],
            ['cas', $usCandidate['cas'] ?? [], $usEvidence],
        ] as [$scheme, $values, $evidence]) {
            foreach (is_array($values) ? $values : [$values] as $value) {
                if (! is_string($value) || $value === '') {
                    continue;
                }

                $rows[] = ['scheme' => $scheme, 'value' => $value, ...($evidence ?? [])];
            }
        }

        return collect($rows)
            ->unique(fn (array $row): string => $row['scheme'].':'.mb_strtolower($row['value']))
            ->groupBy('scheme')
            ->flatMap(fn ($group): array => $group->values()->map(
                fn (array $row, int $index): array => [...$row, 'is_primary' => $index === 0],
            )->all())
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  array<string, mixed>|null  $usCandidate
     * @param  array<string, mixed>|null  $source
     * @return list<array<string, mixed>>
     */
    private function aliases(
        array $record,
        ?array $usCandidate,
        IngredientSourceStageResult $usDeclaration,
        mixed $inciName,
        ?array $source,
    ): array {
        if (! is_array($usCandidate) || ! is_array($source)) {
            return [];
        }

        $excluded = collect([
            $record['display_name'] ?? null,
            $record['inci_name'] ?? null,
            $inciName,
            $usDeclaration->data['declaration_name'] ?? null,
            ...collect($record['aliases'] ?? [])->pluck('name')->all(),
        ])
            ->filter(fn (mixed $name): bool => is_string($name) && trim($name) !== '')
            ->map(fn (string $name): string => $this->normalizedName($name))
            ->all();

        $existingNeutralAliases = collect($record['aliases'] ?? [])
            ->filter(fn (mixed $alias): bool => is_array($alias) && ($alias['locale'] ?? null) === 'und')
            ->count();

        return collect($usCandidate['names'] ?? [])
            ->filter(fn (mixed $name): bool => is_string($name) && trim($name) !== '')
            ->map(fn (string $name): string => Str::squish($name))
            ->reject(fn (string $name): bool => in_array($this->normalizedName($name), $excluded, true))
            ->unique(fn (string $name): string => $this->normalizedName($name))
            ->take(max(0, 5 - $existingNeutralAliases))
            ->map(fn (string $name): array => [
                'locale' => 'und',
                'name' => $name,
                'kind' => 'common',
                ...$source,
            ])
            ->values()
            ->all();
    }

    private function normalizedName(string $name): string
    {
        return Str::lower(Str::squish($name));
    }

    /**
     * @param  list<array<string, mixed>>  $candidates
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>|null
     */
    private function selectUsCandidate(array $candidates, array $record): ?array
    {
        $selected = $this->identityMatcher->select($candidates, $record);

        return is_array($selected['candidate'] ?? null) ? $selected['candidate'] : null;
    }

    /**
     * @param  list<array<string, mixed>>  $evidence
     * @param  array<string, mixed>|null  $candidate
     */
    private function sourceEvidenceForCandidate(array $evidence, ?array $candidate): ?array
    {
        $cosingReference = $candidate['cosing_ref'] ?? null;
        if (! is_string($cosingReference) || $cosingReference === '') {
            return null;
        }

        $candidateEvidence = collect($evidence)
            ->filter(fn (mixed $row): bool => is_array($row)
                && ($row['field'] ?? null) === "source.eu_structured.{$cosingReference}")
            ->values()
            ->all();

        $row = collect($candidateEvidence)->first(fn (mixed $item): bool => is_array($item));

        return is_array($row) ? $this->sourceFields($row) : null;
    }

    /**
     * @param  list<array<string, mixed>>  $evidence
     */
    private function sourceEvidenceFor(string $field, mixed $value, array $evidence): ?array
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $row = collect($evidence)->first(function (mixed $item) use ($field, $value): bool {
            if (! is_array($item) || ($item['field'] ?? null) !== $field) {
                return false;
            }

            return ! array_key_exists('value', $item)
                || $this->normalizeEvidenceValue($item['value']) === $this->normalizeEvidenceValue($value);
        });
        if (! is_array($row)) {
            return null;
        }

        return $this->sourceFields($row);
    }

    /**
     * @param  list<array<string, mixed>>  $evidence
     */
    private function sourceEvidenceForReference(string $prefix, mixed $reference, array $evidence): ?array
    {
        if (! is_string($reference) || trim($reference) === '') {
            return null;
        }

        $row = collect($evidence)->first(
            fn (mixed $item): bool => is_array($item)
                && ($item['field'] ?? null) === $prefix.'.'.$reference,
        );

        return is_array($row) ? $this->sourceFields($row) : null;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function sourceFields(array $row): array
    {
        return collect($row)->only([
            'source_name', 'source_url', 'source_tier', 'confidence', 'source_version', 'source_updated_at', 'retrieved_at',
        ])->all();
    }

    private function normalizeEvidenceValue(mixed $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $value) ?? ''));
    }

    /** @param array<string, mixed>|null $source */
    private function evidenceForField(string $field, ?array $source): ?array
    {
        if (! is_array($source) || ! is_string($source['source_url'] ?? null)) {
            return null;
        }

        return ['field' => $field, ...collect($source)->only([
            'source_name', 'source_url', 'source_tier', 'confidence', 'source_version', 'source_updated_at', 'retrieved_at',
        ])->all()];
    }

    /** @param array<string, mixed>|null $source @return list<array<string, mixed>> */
    private function regulatoryFindings(IngredientSourceStageResult $stage, ?array $source): array
    {
        if (! is_array($source)) {
            return [];
        }

        return collect($stage->data['regulatory_findings'] ?? [])
            ->filter(fn (mixed $finding): bool => is_array($finding) || is_string($finding))
            ->map(fn (array|string $finding): array => [
                'market_code' => 'us',
                'finding' => is_string($finding) ? $finding : json_encode($finding, JSON_THROW_ON_ERROR),
                ...$source,
            ])
            ->values()
            ->all();
    }
}
