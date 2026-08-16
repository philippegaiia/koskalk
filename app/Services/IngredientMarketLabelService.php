<?php

namespace App\Services;

use App\Enums\IngredientEvidenceConfidence;
use App\Enums\IngredientLabelMarket;
use App\Enums\IngredientSourceTier;
use App\Models\Ingredient;
use App\Models\IngredientMarketLabel;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class IngredientMarketLabelService
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function formData(Ingredient $ingredient): array
    {
        return $ingredient->marketLabels()
            ->whereIn('market_code', $this->supportedMarketCodes())
            ->get()
            ->map(fn (IngredientMarketLabel $label): array => [
                'market_code' => $label->market_code->value,
                'declaration_name' => $label->declaration_name,
                'source_name' => $label->source_name,
                'source_url' => $label->source_url,
                'effective_from' => $label->effective_from?->toDateString(),
                'effective_until' => $label->effective_until?->toDateString(),
                'reviewed_at' => $label->reviewed_at?->toIso8601String(),
                'source_tier' => $label->source_tier?->value,
                'confidence' => $label->confidence?->value,
                'source_version' => $label->source_version,
                'source_updated_at' => $label->source_updated_at?->toDateString(),
                'retrieved_at' => $label->retrieved_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function replaceReviewed(Ingredient $ingredient, array $rows, User $actor): void
    {
        $validatedRows = $this->validatedRows($ingredient, $rows);

        DB::transaction(function () use ($ingredient, $validatedRows, $actor): void {
            $lockedIngredient = Ingredient::withoutGlobalScopes()
                ->lockForUpdate()
                ->findOrFail($ingredient->id);

            $this->assertPlatformIngredient($lockedIngredient);
            $marketCodes = collect($validatedRows)
                ->pluck('market_code')
                ->map(fn (IngredientLabelMarket $market): string => $market->value)
                ->all();

            $lockedIngredient->marketLabels()
                ->whereIn('market_code', $this->supportedMarketCodes())
                ->when(
                    $marketCodes !== [],
                    fn ($query) => $query->whereNotIn('market_code', $marketCodes),
                )
                ->delete();

            foreach ($validatedRows as $row) {
                $lockedIngredient->marketLabels()->updateOrCreate(
                    ['market_code' => $row['market_code']->value],
                    [
                        ...$this->rowAttributes($row),
                        'reviewed_at' => now(),
                        'reviewed_by_user_id' => $actor->id,
                    ],
                );
            }
        }, attempts: 5);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function mergeImported(Ingredient $ingredient, array $rows): void
    {
        $validatedRows = $this->validatedRows($ingredient, $rows);

        DB::transaction(function () use ($ingredient, $validatedRows): void {
            $lockedIngredient = Ingredient::withoutGlobalScopes()
                ->lockForUpdate()
                ->findOrFail($ingredient->id);

            $this->assertPlatformIngredient($lockedIngredient);
            $reviewedMarketCodes = $lockedIngredient->marketLabels()
                ->whereNotNull('reviewed_by_user_id')
                ->get(['market_code'])
                ->pluck('market_code')
                ->map(fn (IngredientLabelMarket $market): string => $market->value);

            foreach ($validatedRows as $row) {
                if ($reviewedMarketCodes->contains($row['market_code']->value)) {
                    continue;
                }

                $lockedIngredient->marketLabels()->updateOrCreate(
                    ['market_code' => $row['market_code']->value],
                    [
                        ...$this->rowAttributes($row),
                        'reviewed_by_user_id' => null,
                    ],
                );
            }
        }, attempts: 5);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function replaceImported(Ingredient $ingredient, array $rows): void
    {
        $validatedRows = $this->validatedRows($ingredient, $rows);

        DB::transaction(function () use ($ingredient, $validatedRows): void {
            $lockedIngredient = Ingredient::withoutGlobalScopes()
                ->lockForUpdate()
                ->findOrFail($ingredient->id);

            $this->assertPlatformIngredient($lockedIngredient);
            $marketCodes = collect($validatedRows)
                ->pluck('market_code')
                ->map(fn (IngredientLabelMarket $market): string => $market->value)
                ->all();

            $lockedIngredient->marketLabels()
                ->whereIn('market_code', $this->supportedMarketCodes())
                ->when(
                    $marketCodes !== [],
                    fn ($query) => $query->whereNotIn('market_code', $marketCodes),
                )
                ->delete();

            foreach ($validatedRows as $row) {
                $lockedIngredient->marketLabels()->updateOrCreate(
                    ['market_code' => $row['market_code']->value],
                    [
                        ...$this->rowAttributes($row),
                        'reviewed_by_user_id' => null,
                    ],
                );
            }
        }, attempts: 5);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array{market_code: IngredientLabelMarket, declaration_name: string, source_name: string, source_url: string, effective_from: ?string, effective_until: ?string, reviewed_at: ?string}>
     */
    private function validatedRows(Ingredient $ingredient, array $rows): array
    {
        $this->assertPlatformIngredient($ingredient);

        $normalizedRows = collect($rows)
            ->filter(fn (mixed $row): bool => is_array($row))
            ->map(fn (array $row): array => [
                ...$row,
                'market_code' => is_string($row['market_code'] ?? null)
                    ? trim($row['market_code'])
                    : $row['market_code'] ?? null,
                'declaration_name' => trim((string) ($row['declaration_name'] ?? '')),
                'source_name' => trim((string) ($row['source_name'] ?? '')),
                'source_url' => trim((string) ($row['source_url'] ?? '')),
                'effective_from' => $this->normalizeDate($row['effective_from'] ?? null),
                'effective_until' => $this->normalizeDate($row['effective_until'] ?? null),
                'reviewed_at' => $this->normalizeDateTime($row['reviewed_at'] ?? null),
                'source_tier' => $row['source_tier'] ?? null,
                'confidence' => $row['confidence'] ?? null,
                'source_version' => $row['source_version'] ?? null,
                'source_updated_at' => $this->normalizeDate($row['source_updated_at'] ?? null),
                'retrieved_at' => $this->normalizeDateTime($row['retrieved_at'] ?? null),
            ])
            ->values()
            ->all();

        $validator = Validator::make(
            ['rows' => $normalizedRows],
            [
                'rows' => ['array', 'max:2'],
                'rows.*' => ['array'],
                'rows.*.market_code' => ['required', Rule::enum(IngredientLabelMarket::class)],
                'rows.*.declaration_name' => ['required', 'string', 'max:255'],
                'rows.*.source_name' => ['required', 'string', 'max:255'],
                'rows.*.source_url' => ['required', 'url:http,https', 'max:2000'],
                'rows.*.effective_from' => ['nullable', 'date_format:Y-m-d'],
                'rows.*.effective_until' => ['nullable', 'date_format:Y-m-d'],
                'rows.*.reviewed_at' => ['nullable', 'date'],
                'rows.*.source_tier' => ['nullable', Rule::enum(IngredientSourceTier::class)],
                'rows.*.confidence' => ['nullable', Rule::enum(IngredientEvidenceConfidence::class)],
                'rows.*.source_version' => ['nullable', 'string', 'max:100'],
                'rows.*.source_updated_at' => ['nullable', 'date_format:Y-m-d'],
                'rows.*.retrieved_at' => ['nullable', 'date'],
            ],
        );

        $validator->after(function ($validator) use ($normalizedRows): void {
            $marketCodes = collect($normalizedRows)
                ->pluck('market_code')
                ->filter(fn (mixed $market): bool => is_string($market))
                ->all();

            if (count($marketCodes) !== count(array_unique($marketCodes))) {
                $validator->errors()->add('rows', __('ingredient_admin.market_labels.validation.one_per_market'));
            }

            foreach ($normalizedRows as $index => $row) {
                if (
                    ($row['market_code'] ?? null) === IngredientLabelMarket::Us->value
                    && preg_match('/^CI\\s*[0-9]{5}$/i', (string) ($row['declaration_name'] ?? '')) === 1
                ) {
                    $validator->errors()->add(
                        "rows.{$index}.declaration_name",
                        __('ingredient_admin.market_labels.validation.us_bare_ci'),
                    );
                }

                if (
                    filled($row['effective_from'] ?? null)
                    && filled($row['effective_until'] ?? null)
                    && $row['effective_until'] < $row['effective_from']
                ) {
                    $validator->errors()->add(
                        "rows.{$index}.effective_until",
                        __('ingredient_admin.market_labels.validation.date_order'),
                    );
                }
            }
        });

        /** @var array<int, array<string, mixed>> $validated */
        $validated = $validator->validate()['rows'] ?? [];

        return collect($validated)
            ->map(fn (array $row): array => [
                'market_code' => IngredientLabelMarket::from($row['market_code']),
                'declaration_name' => $row['declaration_name'],
                'source_name' => $row['source_name'],
                'source_url' => $row['source_url'],
                'effective_from' => $row['effective_from'] ?? null,
                'effective_until' => $row['effective_until'] ?? null,
                'reviewed_at' => $row['reviewed_at'] ?? null,
                'source_tier' => $row['source_tier'] ?? null,
                'confidence' => $row['confidence'] ?? null,
                'source_version' => $row['source_version'] ?? null,
                'source_updated_at' => $row['source_updated_at'] ?? null,
                'retrieved_at' => $row['retrieved_at'] ?? null,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array{market_code: IngredientLabelMarket, declaration_name: string, source_name: string, source_url: string, effective_from: ?string, effective_until: ?string, reviewed_at: ?string}  $row
     * @return array<string, mixed>
     */
    private function rowAttributes(array $row): array
    {
        return [
            'declaration_name' => $row['declaration_name'],
            'source_name' => $row['source_name'],
            'source_url' => $row['source_url'],
            'effective_from' => $row['effective_from'],
            'effective_until' => $row['effective_until'],
            'reviewed_at' => $row['reviewed_at'] !== null
                ? CarbonImmutable::parse($row['reviewed_at'])
                : null,
            'source_tier' => $row['source_tier'],
            'confidence' => $row['confidence'],
            'source_version' => $row['source_version'],
            'source_updated_at' => $row['source_updated_at'],
            'retrieved_at' => $row['retrieved_at'] !== null
                ? CarbonImmutable::parse($row['retrieved_at'])
                : null,
        ];
    }

    private function assertPlatformIngredient(Ingredient $ingredient): void
    {
        if ($ingredient->owner_type !== null || $ingredient->owner_id !== null) {
            throw ValidationException::withMessages([
                'ingredient' => __('ingredient_admin.market_labels.validation.platform_only'),
            ]);
        }
    }

    /**
     * @return list<string>
     */
    private function supportedMarketCodes(): array
    {
        return collect(IngredientLabelMarket::cases())
            ->map(fn (IngredientLabelMarket $market): string => $market->value)
            ->all();
    }

    private function normalizeDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return trim((string) $value);
    }

    private function normalizeDateTime(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        return trim((string) $value);
    }
}
