<?php

namespace App\Services\Dossiers;

use App\Models\Substance;
use App\Models\ToxicologyProfile;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class ToxicologyProfilePublisher
{
    public function __construct(private readonly AppendOnlyVersionPublisher $versions) {}

    /**
     * @param  array<string, mixed>  $data  Value columns, `references` (list of number/citation/url) and
     *                                      `value_references` (value column => "1, 2" or list of numbers).
     */
    public function publish(Substance $substance, array $data, User $publisher): ToxicologyProfile
    {
        $references = $this->references($data['references'] ?? []);
        $valueReferences = $this->valueReferences($data['value_references'] ?? []);
        $values = collect(Arr::only($data, ToxicologyProfile::VALUE_COLUMNS))
            ->map(fn (mixed $value): mixed => $value === '' ? null : $value);

        $errors = [...$this->sourcingErrors($values, $references, $valueReferences), ...$this->reasoningErrors($values)];

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        /** @var ToxicologyProfile */
        return $this->versions->publish(ToxicologyProfile::class, [
            ...$values->all(),
            'substance_id' => $substance->id,
            'references' => $references->values()->all(),
            'value_references' => $valueReferences->all(),
            'change_note' => $data['change_note'] ?? null,
        ], $publisher);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return Collection<int, array{number: int, citation: string, url: string|null}>
     */
    private function references(array $rows): Collection
    {
        $references = collect($rows)->map(fn (array $row): array => [
            'number' => (int) ($row['number'] ?? 0),
            'citation' => trim((string) ($row['citation'] ?? '')),
            'url' => filled($row['url'] ?? null) ? (string) $row['url'] : null,
        ]);

        if ($references->contains(fn (array $row): bool => $row['number'] < 1 || $row['citation'] === '')
            || $references->pluck('number')->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages([
                'references' => 'Each reference needs a unique number of 1 or more and a citation.',
            ]);
        }

        return $references->keyBy('number');
    }

    /**
     * @param  array<string, mixed>  $map
     * @return Collection<string, list<int>>
     */
    private function valueReferences(array $map): Collection
    {
        $unknown = array_diff(array_keys($map), ToxicologyProfile::REFERENCED_VALUES);

        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'value_references' => 'Unknown value fields: '.implode(', ', $unknown).'.',
            ]);
        }

        return collect($map)->map(fn (mixed $numbers): array => collect(is_array($numbers) ? $numbers : explode(',', (string) $numbers))
            ->map(fn (mixed $number): string => trim((string) $number))
            ->filter(fn (string $number): bool => $number !== '')
            ->map(fn (string $number): int => (int) $number)
            ->unique()
            ->values()
            ->all());
    }

    /**
     * @param  Collection<string, mixed>  $values
     * @param  Collection<int, array{number: int, citation: string, url: string|null}>  $references
     * @param  Collection<string, list<int>>  $valueReferences
     * @return array<string, string>
     */
    private function sourcingErrors(Collection $values, Collection $references, Collection $valueReferences): array
    {
        $errors = collect(ToxicologyProfile::REFERENCED_VALUES)
            ->filter(fn (string $field): bool => $values->get($field) !== null)
            ->mapWithKeys(function (string $field) use ($references, $valueReferences): array {
                $cited = $valueReferences->get($field, []);

                if ($cited === []) {
                    return ["value_references.{$field}" => "Cite at least one reference for {$field}."];
                }

                $missing = array_diff($cited, $references->keys()->all());

                return $missing === []
                    ? []
                    : ["value_references.{$field}" => 'Unknown reference numbers: '.implode(', ', $missing).'.'];
            });

        return $errors->all();
    }

    /**
     * @param  Collection<string, mixed>  $values
     * @return array<string, string>
     */
    private function reasoningErrors(Collection $values): array
    {
        $errors = [];

        if ($values->get('noael_mg_kg_bw_day') !== null && blank($values->get('noael_route'))) {
            $errors['noael_route'] = 'A NOAEL needs its route.';
        }

        if ($values->get('adi_mg_kg_bw_day') !== null && blank($values->get('adi_reasoning'))) {
            $errors['adi_reasoning'] = 'Explain why the ADI applies to this use.';
        }

        if ($values->get('ttc_mg_kg_bw_day') !== null
            && (blank($values->get('ttc_reasoning')) || blank($values->get('ttc_class')))) {
            $errors['ttc_reasoning'] = 'A TTC needs its class and the reason it applies.';
        }

        return $errors;
    }
}
