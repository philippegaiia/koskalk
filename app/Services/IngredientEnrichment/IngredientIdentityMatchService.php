<?php

namespace App\Services\IngredientEnrichment;

class IngredientIdentityMatchService
{
    /**
     * Material product forms that must agree between the input and the
     * matched registry record. An oil input must not settle for an acid,
     * extract, ester, or fraction record that merely shares its stem.
     */
    private const array FORM_TOKENS = [
        'oil', 'butter', 'tallow', 'fat', 'wax', 'lard', 'suet', 'ghee',
        'acid', 'extract', 'esters', 'olein', 'stearin', 'hydrolate',
        'distillate', 'unsaponifiables', 'meal', 'husk', 'shell', 'flour',
    ];

    /**
     * @param  list<array<string, mixed>>  $candidates
     * @param  array{inci_name?: string|null, display_name?: string|null, identifiers?: list<array{value?: string}>}  $record
     * @return array{candidate: array<string, mixed>|null, conflicts: list<string>}
     */
    public function select(array $candidates, array $record): array
    {
        $inciName = $this->normalize((string) ($record['inci_name'] ?? ''));
        $displayName = $this->normalize((string) ($record['display_name'] ?? ''));
        // The catalogue display name states the intended material form; the INCI
        // name only supplies the form when the display name has none. Taking the
        // last form token of a display+INCI concatenation let a trailing INCI
        // sibling form (e.g. ... BUTTER) override the display form (... OIL) and
        // silently matched the wrong substance.
        $inputForm = $this->formToken($displayName) ?? $this->formToken($inciName);
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

        $formExcluded = false;
        $scored = collect($candidates)
            ->map(function (array $candidate) use ($inciName, $displayName, $identifiers, $inputForm, &$formExcluded): array {
                $row = $this->scoreCandidate($candidate, $inciName, $displayName, $identifiers, $inputForm);
                if (in_array('material_form_mismatch', $row['reasons'], true)) {
                    $formExcluded = true;
                }

                return $row;
            })
            ->filter(fn (array $row): bool => $row['score'] > 0)
            ->sortByDesc('score')
            ->values();

        if ($scored->isEmpty()) {
            if ($formExcluded) {
                return [
                    'candidate' => null,
                    'conflicts' => [
                        'Identity candidate material form does not match the ingredient.',
                        ...$this->candidateConflicts($candidates, $inciName),
                    ],
                ];
            }

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
    private function scoreCandidate(array $candidate, string $inciName, string $displayName, array $identifiers, ?string $inputForm): array
    {
        $primaryName = $this->normalize((string) ($candidate['inci_name'] ?? ($candidate['inci_names'][0] ?? null) ?? $candidate['common_name'] ?? ''));
        $candidateForm = $primaryName === '' ? null : $this->formToken($primaryName);
        if ($inputForm !== null && $candidateForm !== null && $inputForm !== $candidateForm) {
            return [
                'candidate' => $candidate,
                'score' => 0,
                'reasons' => ['material_form_mismatch'],
            ];
        }

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

        if ($inciName !== '') {
            $exact = $candidateNames->contains($inciName);
            $variant = ! $exact
                && $candidateNames->contains(fn (string $name): bool => $this->namesMatch($name, $inciName));
            if ($exact || $variant) {
                $score = max($score, 100);
                $reasons[] = $exact ? 'exact_inci' : 'exact_inci_variant';
            }
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
            $candidateInci = $this->normalize((string) ($candidate['inci_name'] ?? ($candidate['inci_names'][0] ?? null) ?? $candidate['common_name'] ?? ''));
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

        return collect(['hydrogenated', 'unsaponifiables', 'extract', 'oil', 'kernel', 'seed', 'leaf', 'root', 'hydrate', 'acid', 'butter', 'tallow', 'wax', 'sodium', 'potassium'])
            ->filter(fn (string $token): bool => str_contains($candidate, $token) !== str_contains($current, $token))
            ->values()
            ->all();
    }

    /**
     * Accepts registry-name variants of the same material: parenthetical
     * qualifiers are ignored and kernel/seed are treated as equivalent
     * (registries index the seed-oil form).
     */
    private function namesMatch(string $a, string $b): bool
    {
        $canonical = static function (string $value): string {
            $value = preg_replace('/\s*\([^)]*\)\s*/u', ' ', $value) ?? $value;
            $value = preg_replace('/\bKernels?\b/i', 'Seed', $value) ?? $value;
            $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

            return mb_strtolower(trim($value));
        };

        return $canonical($a) === $canonical($b);
    }

    private function formToken(string $normalized): ?string
    {
        $found = null;
        $position = -1;

        foreach (self::FORM_TOKENS as $token) {
            if (preg_match('/\b'.preg_quote($token, '/').'s?\b/u', $normalized, $match, PREG_OFFSET_CAPTURE) === 1) {
                $offset = $match[0][1];
                if ($offset > $position) {
                    $position = $offset;
                    $found = $token;
                }
            }
        }

        return $found;
    }

    private function normalize(string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value) ?? ''));
    }
}
