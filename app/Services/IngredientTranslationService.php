<?php

namespace App\Services;

use App\Models\Ingredient;
use App\Models\SupportedLocale;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class IngredientTranslationService
{
    /**
     * @return array<int, array{locale: string, display_name: string|null, info_markdown: string|null}>
     */
    public function formData(Ingredient $ingredient): array
    {
        if ($ingredient->owner_type !== null) {
            return [];
        }

        return $ingredient->translations()
            ->orderBy('locale')
            ->get(['locale', 'display_name', 'info_markdown'])
            ->map(fn ($translation): array => [
                'locale' => $translation->locale,
                'display_name' => $translation->display_name,
                'info_markdown' => $translation->info_markdown,
            ])
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function sync(Ingredient $ingredient, array $rows): void
    {
        if ($ingredient->owner_type !== null) {
            if ($rows !== []) {
                throw ValidationException::withMessages([
                    'translations' => 'Only platform ingredients can have managed translations.',
                ]);
            }

            return;
        }

        $validatedRows = $this->validateRows($rows);

        DB::transaction(function () use ($ingredient, $validatedRows): void {
            $locales = collect($validatedRows)->pluck('locale')->all();

            $ingredient->translations()
                ->when(
                    $locales !== [],
                    fn ($query) => $query->whereNotIn('locale', $locales),
                )
                ->delete();

            foreach ($validatedRows as $row) {
                $ingredient->translations()->updateOrCreate(
                    ['locale' => $row['locale']],
                    [
                        'display_name' => $row['display_name'],
                        'info_markdown' => $row['info_markdown'],
                    ],
                );
            }
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array{locale: string, display_name: string|null, info_markdown: string|null}>
     */
    public function validateRows(array $rows): array
    {
        $normalizedRows = array_map(
            fn (mixed $row): mixed => is_array($row) ? $this->normalizeRow($row) : $row,
            $rows,
        );

        $validator = Validator::make(
            ['translations' => $normalizedRows],
            [
                'translations' => ['array'],
                'translations.*' => ['array'],
                'translations.*.locale' => [
                    'required',
                    'string',
                    'max:16',
                    'distinct',
                    Rule::exists(SupportedLocale::class, 'code')
                        ->where(fn ($query) => $query->where('code', '!=', 'en')),
                ],
                'translations.*.display_name' => ['nullable', 'string', 'max:255'],
                'translations.*.info_markdown' => ['nullable', 'string'],
            ],
        );

        $validator->after(function ($validator) use ($normalizedRows): void {
            foreach ($normalizedRows as $index => $row) {
                if (! is_array($row)) {
                    continue;
                }

                if (($row['display_name'] ?? null) === null && ($row['info_markdown'] ?? null) === null) {
                    $validator->errors()->add(
                        "translations.{$index}.display_name",
                        'Enter a translated name or translated guidance.',
                    );
                }
            }
        });

        /** @var array{translations: array<int, array{locale: string, display_name: string|null, info_markdown: string|null}>} $validated */
        $validated = $validator->validate();

        return $validated['translations'];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{locale: string, display_name: string|null, info_markdown: string|null}
     */
    private function normalizeRow(array $row): array
    {
        return [
            'locale' => trim((string) ($row['locale'] ?? '')),
            'display_name' => $this->normalizeText($row['display_name'] ?? null),
            'info_markdown' => $this->normalizeText($row['info_markdown'] ?? null),
        ];
    }

    private function normalizeText(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
