<?php

namespace App\Services;

use App\Data\IngredientTranslationWriteIntent;
use App\Enums\IngredientTranslationOrigin;
use App\Models\Ingredient;
use App\Models\IngredientTranslation;
use App\Models\SupportedLocale;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class IngredientTranslationService
{
    public function __construct(
        private readonly IngredientTranslationSourceFingerprint $sourceFingerprint,
    ) {}

    /**
     * @return array<int, array{locale: string, display_name: string|null, saponification_name: string|null, info_markdown: string|null, origin: string, freshness: string, is_stale: bool}>
     */
    public function formData(Ingredient $ingredient): array
    {
        if ($ingredient->owner_type !== null) {
            return [];
        }

        $canonicalFingerprint = $this->sourceFingerprint->forIngredient($ingredient);

        return $ingredient->translations()
            ->orderBy('locale')
            ->get(['locale', 'display_name', 'saponification_name', 'info_markdown', 'source_fingerprint', 'origin'])
            ->map(function (IngredientTranslation $translation) use ($canonicalFingerprint): array {
                $sourceFingerprint = is_string($translation->source_fingerprint)
                    ? $translation->source_fingerprint
                    : '';
                $origin = $translation->origin instanceof IngredientTranslationOrigin
                    ? $translation->origin->value
                    : (string) ($translation->origin ?? IngredientTranslationOrigin::Legacy->value);

                return [
                    'locale' => $translation->locale,
                    'display_name' => $translation->display_name,
                    'saponification_name' => $translation->saponification_name,
                    'info_markdown' => $translation->info_markdown,
                    'origin' => $origin,
                    'freshness' => $sourceFingerprint !== '' && $sourceFingerprint === $canonicalFingerprint
                        ? 'current'
                        : 'outdated',
                    'is_stale' => $sourceFingerprint === '' || $sourceFingerprint !== $canonicalFingerprint,
                ];
            })
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, IngredientTranslationWriteIntent>  $writeIntents
     */
    public function sync(
        Ingredient $ingredient,
        array $rows,
        IngredientTranslationOrigin $origin = IngredientTranslationOrigin::ReviewerEdited,
        ?string $promptVersion = null,
        array $writeIntents = [],
    ): void {
        if ($ingredient->owner_type !== null) {
            if ($rows !== []) {
                throw ValidationException::withMessages([
                    'translations' => __('ingredient_admin.translations.validation.platform_only'),
                ]);
            }

            return;
        }

        $validatedRows = $this->validateRows($rows);
        $validatedWriteIntents = $this->validateWriteIntents($writeIntents, $validatedRows);

        $canonicalFingerprint = $this->sourceFingerprint->forIngredient($ingredient);

        DB::transaction(function () use ($ingredient, $validatedRows, $validatedWriteIntents, $canonicalFingerprint, $origin, $promptVersion): void {
            $locales = collect($validatedRows)->pluck('locale')->all();

            $ingredient->translations()
                ->when(
                    $locales !== [],
                    fn ($query) => $query->whereNotIn('locale', $locales),
                )
                ->delete();

            foreach ($validatedRows as $row) {
                $existing = $ingredient->translations()->where('locale', $row['locale'])->first();
                $sameContent = $existing instanceof IngredientTranslation
                    && $this->normalizeRow([
                        'locale' => $existing->locale,
                        'display_name' => $existing->display_name,
                        'saponification_name' => $existing->saponification_name,
                        'info_markdown' => $existing->info_markdown,
                    ]) === $row;
                $intent = $validatedWriteIntents[$row['locale']] ?? null;
                $metadata = $sameContent && ! ($intent?->refreshMetadata ?? false)
                    ? [
                        'source_fingerprint' => $existing->source_fingerprint,
                        'origin' => $existing->origin ?? IngredientTranslationOrigin::Legacy->value,
                        'prompt_version' => $existing->prompt_version,
                    ]
                    : [
                        'source_fingerprint' => $canonicalFingerprint,
                        'origin' => $intent?->origin->value ?? $origin->value,
                        'prompt_version' => $intent instanceof IngredientTranslationWriteIntent
                            ? $intent->promptVersion
                            : ($origin === IngredientTranslationOrigin::AiGenerated ? $promptVersion : null),
                    ];

                $ingredient->translations()->updateOrCreate(
                    ['locale' => $row['locale']],
                    [
                        'display_name' => $row['display_name'],
                        'saponification_name' => $row['saponification_name'],
                        'info_markdown' => $row['info_markdown'],
                        ...$metadata,
                    ],
                );
            }
        });
    }

    /**
     * @param  array<string, IngredientTranslationWriteIntent>  $writeIntents
     * @param  array<int, array{locale: string, display_name: string|null, saponification_name: string|null, info_markdown: string|null}>  $validatedRows
     * @return array<string, IngredientTranslationWriteIntent>
     */
    private function validateWriteIntents(array $writeIntents, array $validatedRows): array
    {
        if ($writeIntents === []) {
            return [];
        }

        $normalizedEntries = collect($writeIntents)
            ->map(fn (mixed $intent, string|int $locale): array => [
                'locale' => is_string($locale) ? trim($locale) : $locale,
                'intent' => $intent,
            ])
            ->values()
            ->all();
        $localeCandidates = collect($normalizedEntries)
            ->pluck('locale')
            ->filter(fn (mixed $locale): bool => is_string($locale) && $locale !== '')
            ->unique()
            ->values()
            ->all();
        $supportedLocales = $localeCandidates === []
            ? []
            : SupportedLocale::query()
                ->where('code', '!=', 'en')
                ->whereIn('code', $localeCandidates)
                ->pluck('code')
                ->all();
        $rowLocales = collect($validatedRows)->pluck('locale')->all();

        $validator = Validator::make(
            ['write_intents' => $normalizedEntries],
            [
                'write_intents' => ['array'],
                'write_intents.*' => ['array'],
                'write_intents.*.locale' => [
                    'bail',
                    'required',
                    'string',
                    'max:16',
                    'distinct',
                    Rule::in($supportedLocales),
                ],
            ],
            [
                'write_intents.*.locale.required' => __('ingredients.editor.validation.translation_write_intent_locale_required'),
                'write_intents.*.locale.string' => __('ingredients.editor.validation.translation_write_intent_locale_string'),
                'write_intents.*.locale.max' => __('ingredients.editor.validation.translation_write_intent_locale_max'),
                'write_intents.*.locale.distinct' => __('ingredients.editor.validation.translation_write_intent_locale_distinct'),
                'write_intents.*.locale.in' => __('ingredients.editor.validation.translation_write_intent_locale_invalid'),
            ],
            [
                'write_intents.*.locale' => __('ingredients.editor.admin.translations.write_intent_locale'),
                'write_intents.*.intent' => __('ingredients.editor.admin.translations.write_intent_value'),
            ],
        );

        $validator->after(function (ValidatorContract $validator) use ($normalizedEntries, $rowLocales, $supportedLocales): void {
            foreach ($normalizedEntries as $index => $entry) {
                $locale = $entry['locale'] ?? null;
                if (
                    is_string($locale)
                    && in_array($locale, $supportedLocales, true)
                    && ! in_array($locale, $rowLocales, true)
                ) {
                    $validator->errors()->add(
                        "write_intents.{$index}.locale",
                        __('ingredients.editor.validation.translation_write_intent_locale_missing'),
                    );
                }

                if (! ($entry['intent'] ?? null) instanceof IngredientTranslationWriteIntent) {
                    $validator->errors()->add(
                        "write_intents.{$index}.intent",
                        __('ingredients.editor.validation.translation_write_intent_invalid'),
                    );
                }
            }
        });

        $validator->validate();

        return collect($normalizedEntries)
            ->mapWithKeys(fn (array $entry): array => [(string) $entry['locale'] => $entry['intent']])
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array{locale: string, display_name: string|null, saponification_name: string|null, info_markdown: string|null}>
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
                'translations.*.saponification_name' => ['nullable', 'string', 'max:255'],
                'translations.*.info_markdown' => ['nullable', 'string'],
            ],
        );

        $validator->after(function ($validator) use ($normalizedRows): void {
            foreach ($normalizedRows as $index => $row) {
                if (! is_array($row)) {
                    continue;
                }

                if (
                    ($row['display_name'] ?? null) === null
                    && ($row['saponification_name'] ?? null) === null
                    && ($row['info_markdown'] ?? null) === null
                ) {
                    $validator->errors()->add(
                        "translations.{$index}.display_name",
                        __('ingredient_admin.translations.validation.content_required'),
                    );
                }
            }
        });

        /** @var array{translations: array<int, array{locale: string, display_name: string|null, saponification_name: string|null, info_markdown: string|null}>} $validated */
        $validated = $validator->validate();

        return $validated['translations'];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{locale: string, display_name: string|null, saponification_name: string|null, info_markdown: string|null}
     */
    private function normalizeRow(array $row): array
    {
        return [
            'locale' => trim((string) ($row['locale'] ?? '')),
            'display_name' => $this->normalizeText($row['display_name'] ?? null),
            'saponification_name' => $this->normalizeText($row['saponification_name'] ?? null),
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
