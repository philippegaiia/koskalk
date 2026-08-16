<?php

namespace App\Services\IngredientIntake;

use App\Data\IngredientIntakeRow;
use Illuminate\Validation\ValidationException;
use SplFileObject;
use SplTempFileObject;

final class IngredientIntakeParser
{
    /**
     * @var array<string, string>
     */
    private const HEADER_ALIASES = [
        'current_name' => 'current_name',
        'current name' => 'current_name',
        'name' => 'current_name',
        'common_name' => 'current_name',
        'common name' => 'current_name',
        'ingredient_name' => 'current_name',
        'ingredient name' => 'current_name',
        'inci' => 'inci_name',
        'inci_name' => 'inci_name',
        'inci name' => 'inci_name',
    ];

    /**
     * @return list<IngredientIntakeRow>
     */
    public function parsePasted(string $contents): array
    {
        $delimiter = $this->detectDelimiter($contents);
        $file = new SplTempFileObject;
        $file->fwrite($contents);
        $file->rewind();

        return $this->parseFile($file, $delimiter);
    }

    /**
     * @return list<IngredientIntakeRow>
     */
    public function parseCsvFile(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw ValidationException::withMessages([
                'file' => __('ingredient_intake_admin.validation.file_unreadable'),
            ]);
        }

        return $this->parseFile(new SplFileObject($path, 'rb'), ',');
    }

    private function detectDelimiter(string $contents): string
    {
        $firstLine = collect(preg_split('/\R/u', $contents) ?: [])
            ->first(fn (string $line): bool => trim($line) !== '');

        return is_string($firstLine) && str_contains($firstLine, "\t") ? "\t" : ',';
    }

    /**
     * @return list<IngredientIntakeRow>
     */
    private function parseFile(SplFileObject $file, string $delimiter): array
    {
        $header = null;
        $rows = [];
        $errors = [];
        $rowNumber = 0;

        while (! $file->eof()) {
            $rowNumber++;
            $fields = $file->fgetcsv($delimiter, '"', '');

            if ($fields === false) {
                break;
            }

            if ($this->isBlankRecord($fields)) {
                continue;
            }

            if ($header === null) {
                $header = $this->parseHeader($fields);

                continue;
            }

            if (count($fields) > count($header)) {
                $errors["rows.{$rowNumber}.columns"] = __('ingredient_intake_admin.validation.too_many_columns');

                continue;
            }

            $currentName = $this->valueAt($fields, $header['current_name'] ?? null);
            $inciName = $this->valueAt($fields, $header['inci_name'] ?? null);
            $originalCurrentName = $this->originalValue($currentName);
            $originalInciName = $this->originalValue($inciName);
            $normalizedCurrentName = $this->normalizeIdentityValue($originalCurrentName);
            $normalizedInciName = $this->normalizeIdentityValue($originalInciName);

            if ($normalizedCurrentName === null && $normalizedInciName === null) {
                $errors["rows.{$rowNumber}.identity"] = __('ingredient_intake_admin.validation.identity_required');

                continue;
            }

            $rows[] = new IngredientIntakeRow(
                rowNumber: $rowNumber,
                originalCurrentName: $originalCurrentName,
                originalInciName: $originalInciName,
                normalizedCurrentName: $normalizedCurrentName,
                normalizedInciName: $normalizedInciName,
            );
        }

        if ($header === null) {
            $errors['headers'] = __('ingredient_intake_admin.validation.headers_required');
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $rows;
    }

    /**
     * @param  list<string|null>  $fields
     * @return array{current_name?: int, inci_name?: int}
     */
    private function parseHeader(array $fields): array
    {
        $header = [];
        $errors = [];

        foreach ($fields as $index => $field) {
            $normalized = $this->normalizeHeader($field);

            if ($normalized === '') {
                $errors['headers'] = __('ingredient_intake_admin.validation.headers_invalid');

                continue;
            }

            $canonical = self::HEADER_ALIASES[$normalized] ?? null;

            if ($canonical === null) {
                $errors['headers'] = __('ingredient_intake_admin.validation.header_unsupported', [
                    'header' => (string) $field,
                ]);

                continue;
            }

            if (array_key_exists($canonical, $header)) {
                $errors['headers'] = __('ingredient_intake_admin.validation.header_duplicate', [
                    'header' => (string) $field,
                ]);

                continue;
            }

            $header[$canonical] = $index;
        }

        if ($header === []) {
            $errors['headers'] ??= __('ingredient_intake_admin.validation.headers_required');
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $header;
    }

    /**
     * @param  list<string|null>  $fields
     */
    private function isBlankRecord(array $fields): bool
    {
        return collect($fields)->every(fn (mixed $field): bool => trim((string) ($field ?? '')) === '');
    }

    /**
     * @param  list<string|null>  $fields
     */
    private function valueAt(array $fields, ?int $index): ?string
    {
        if ($index === null || ! array_key_exists($index, $fields)) {
            return null;
        }

        return $fields[$index];
    }

    private function originalValue(?string $value): ?string
    {
        return $value !== null && trim($value) !== '' ? $value : null;
    }

    public function normalizeIdentityValue(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (class_exists(\Normalizer::class)) {
            $value = \Normalizer::normalize($value, \Normalizer::FORM_C) ?: $value;
        }

        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);
        $value = mb_strtolower($value, 'UTF-8');

        return $value === '' ? null : $value;
    }

    private function normalizeHeader(?string $value): string
    {
        $value = preg_replace('/^\xEF\xBB\xBF/u', '', (string) $value) ?? (string) $value;
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);

        return mb_strtolower($value, 'UTF-8');
    }
}
