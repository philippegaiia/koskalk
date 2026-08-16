<?php

namespace App\Services\IngredientEnrichment;

class IngredientIdentityMatchService
{
    /**
     * @param  list<array<string, mixed>>  $candidates
     * @param  array{inci_name?: string|null, identifiers?: list<array{value?: string}>}  $record
     * @return array{candidate: array<string, mixed>|null, conflicts: list<string>}
     */
    public function select(array $candidates, array $record): array
    {
        $inciName = $this->normalize((string) ($record['inci_name'] ?? ''));
        $displayName = $this->normalize((string) ($record['display_name'] ?? ''));
        $identifiers = collect($record['identifiers'] ?? [])
            ->filter(fn (mixed $identifier): bool => is_array($identifier)
                && is_string($identifier['value'] ?? null)
                && trim((string) $identifier['value']) !== '')
            ->mapWithKeys(fn (array $identifier): array => [
                $this->normalize((string) ($identifier['scheme'] ?? '')) => $this->normalize((string) $identifier['value']),
            ])
            ->all();

        if ($candidates !== [] && $inciName === '' && $displayName === '' && $identifiers === []) {
            return [
                'candidate' => null,
                'conflicts' => ['Identity could not be verified from INCI or identifiers.'],
            ];
        }

        $scored = collect($candidates)
            ->map(fn (array $candidate): array => $this->scoreCandidate($candidate, $inciName, $displayName, $identifiers))
            ->filter(fn (array $row): bool => $row['score'] > 0)
            ->sortByDesc('score')
            ->values();

        if ($scored->isEmpty()) {
            return [
                'candidate' => null,
                'conflicts' => $inciName === '' && $identifiers === []
                    ? ['Identity could not be verified from INCI or identifiers.']
                    : $this->candidateConflicts($candidates, $inciName),
            ];
        }

        $best = $scored->first();
        $runnerUp = $scored->get(1);
        if ($best['score'] < 80 || ($runnerUp !== null && ($best['score'] - $runnerUp['score']) < 10)) {
            return [
                'candidate' => null,
                'conflicts' => [
                    'Identity candidates remain ambiguous and require human review.',
                    ...$this->candidateConflicts($candidates, $inciName),
                ],
            ];
        }

        return [
            'candidate' => [
                ...$best['candidate'],
                'match_score' => $best['score'],
                'match_reasons' => $best['reasons'],
            ],
            'conflicts' => [],
        ];
    }

    /**
     * @param  array<string, string>  $identifiers
     * @return array{candidate: array<string, mixed>, score: int, reasons: list<string>}
     */
    private function scoreCandidate(array $candidate, string $inciName, string $displayName, array $identifiers): array
    {
        $score = 0;
        $reasons = [];
        $candidateNames = collect([
            $candidate['inci_name'] ?? null,
            ...($candidate['inci_names'] ?? []),
        ])
            ->filter(fn (mixed $value): bool => is_string($value) && trim($value) !== '')
            ->map(fn (string $value): string => $this->normalize($value))
            ->unique()
            ->values();

        if ($inciName !== '' && $candidateNames->contains($inciName)) {
            $score = max($score, 100);
            $reasons[] = 'exact_inci';
        }

        $candidateIdentifiers = [
            'cosing_ref' => [$candidate['cosing_ref'] ?? null],
            'cas' => $candidate['cas'] ?? [],
            'ec' => $candidate['ec'] ?? [],
            'unii' => [$candidate['unii'] ?? null],
        ];
        foreach ($candidateIdentifiers as $scheme => $values) {
            foreach ($values as $value) {
                if (is_string($value) && $value !== ''
                    && ($identifiers[$scheme] ?? null) === $this->normalize($value)) {
                    $score = max($score, 110);
                    $reasons[] = "exact_{$scheme}";
                }
            }
        }

        $commonNames = collect([$candidate['common_name'] ?? null, ...($candidate['names'] ?? [])])
            ->filter(fn (mixed $value): bool => is_string($value) && trim($value) !== '')
            ->map(fn (string $value): string => $this->normalize($value));
        if ($displayName !== '' && $commonNames->contains($displayName)) {
            $score = max($score, 90);
            $reasons[] = 'exact_common_name';
        }

        return [
            'candidate' => $candidate,
            'score' => $score,
            'reasons' => array_values(array_unique($reasons)),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $candidates
     * @return list<string>
     */
    private function candidateConflicts(array $candidates, string $inciName): array
    {
        $conflicts = [];
        foreach ($candidates as $candidate) {
            $candidateInci = $this->normalize((string) ($candidate['inci_name'] ?? ''));
            foreach ($this->materialDifferences($candidateInci, $inciName) as $difference) {
                $conflicts[] = "Material difference: {$difference}.";
            }
        }

        return array_values(array_unique($conflicts));
    }

    /**
     * @return list<string>
     */
    private function materialDifferences(string $candidate, string $current): array
    {
        $candidate = $this->normalize($candidate);
        $current = $this->normalize($current);

        return collect(['hydrogenated', 'unsaponifiables', 'extract', 'oil', 'kernel', 'seed', 'leaf', 'root', 'hydrate', 'sodium', 'potassium'])
            ->filter(fn (string $token): bool => str_contains($candidate, $token) !== str_contains($current, $token))
            ->values()
            ->all();
    }

    private function normalize(string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value) ?? ''));
    }
}
