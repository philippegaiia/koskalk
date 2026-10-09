<?php

namespace App\Services;

use App\Models\Ingredient;
use App\Models\IngredientFattyAcid;
use App\SoapSap;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class IngredientSoapTrustValidator
{
    private const KOH_TOLERANCE = 0.03;

    /** @return array{koh_sap_value: mixed, fatty_acid_profile: array<int|string, mixed>} */
    public function storedBaseline(Ingredient $ingredient): array
    {
        return [
            'koh_sap_value' => data_get($ingredient->source_data, 'user_authoring.trusted_koh_sap_value'),
            'fatty_acid_profile' => data_get($ingredient->source_data, 'user_authoring.trusted_fatty_acid_profile', []),
        ];
    }

    public function assertTransferable(Ingredient $ingredient): void
    {
        $isPlatform = $ingredient->owner_type === null && $ingredient->owner_id === null && $ingredient->workspace_id === null;
        if ($isPlatform || ! $ingredient->is_soap_saponification_trusted) {
            return;
        }

        $baseline = $this->storedBaseline($ingredient);
        if (! is_numeric($baseline['koh_sap_value']) || ! is_finite((float) $baseline['koh_sap_value'])
            || (float) $baseline['koh_sap_value'] <= 0
            || ! is_array(data_get($ingredient->source_data, 'user_authoring.trusted_fatty_acid_profile'))) {
            throw ValidationException::withMessages([
                'ingredient' => __('sharing.validation.missing_trusted_baseline'),
            ]);
        }

        foreach ($baseline['fatty_acid_profile'] as $value) {
            if (! is_numeric($value) || ! is_finite((float) $value) || $value < 0 || $value > 100) {
                throw ValidationException::withMessages(['ingredient' => __('sharing.validation.missing_trusted_baseline')]);
            }
        }

        $state = [
            'sap_profile' => ['koh_sap_value' => $ingredient->sapProfile?->koh_sap_value],
            'fatty_acid_entries' => $ingredient->fattyAcidEntries->map(fn (IngredientFattyAcid $entry): array => [
                'fatty_acid_id' => $entry->fatty_acid_id,
                'percentage' => $entry->percentage,
            ])->all(),
        ];
        $this->validateKohSapValue($baseline, $state);
        $this->validateFattyAcidProfile($baseline, $state);
    }

    /**
     * @param  array{koh_sap_value: mixed, fatty_acid_profile: array<int|string, mixed>}  $baseline
     * @param  array<string, mixed>  $state
     */
    public function validateKohSapValue(array $baseline, array $state): void
    {
        $value = Arr::get($state, 'sap_profile.koh_sap_value');
        if (! is_numeric($value) || ! is_finite((float) $value)) {
            throw ValidationException::withMessages([
                'sap_profile.koh_sap_value' => __('ingredients.editor.validation.soap_koh_required'),
            ]);
        }

        $value = SoapSap::normalizeKohSapInput((float) $value);
        $range = $this->kohSapRange((float) $baseline['koh_sap_value']);
        if ($value < $range['minimum'] || $value > $range['maximum']) {
            throw ValidationException::withMessages([
                'sap_profile.koh_sap_value' => __('ingredients.editor.validation.soap_koh_tolerance', ['tolerance' => self::KOH_TOLERANCE * 100]),
            ]);
        }
    }

    /**
     * @param  array{koh_sap_value: mixed, fatty_acid_profile: array<int|string, mixed>}  $baseline
     * @param  array<string, mixed>  $state
     */
    public function validateFattyAcidProfile(array $baseline, array $state): void
    {
        $original = collect($baseline['fatty_acid_profile'])->mapWithKeys(fn (mixed $value, mixed $key): array => [(int) $key => (float) $value]);
        $current = collect(Arr::get($state, 'fatty_acid_entries', []))
            ->filter(fn (mixed $row): bool => is_array($row) && filled($row['fatty_acid_id'] ?? null))
            ->mapWithKeys(function (array $row): array {
                $value = $row['percentage'] ?? 0;
                if (! is_numeric($value) || ! is_finite((float) $value)) {
                    throw ValidationException::withMessages(['fatty_acid_entries' => __('ingredients.editor.validation.fatty_acid_total')]);
                }

                return [(int) $row['fatty_acid_id'] => (float) $value];
            });
        if ($original->isEmpty() && $current->isEmpty()) {
            return;
        }

        $total = $current->sum();
        if ($total < 80 || $total > 100) {
            throw ValidationException::withMessages(['fatty_acid_entries' => __('ingredients.editor.validation.fatty_acid_total')]);
        }

        foreach ($original->keys()->merge($current->keys())->unique() as $id) {
            [$minimum, $maximum] = $this->fattyAcidRange((float) $original->get($id, 0));
            if ($current->get($id, 0) < $minimum || $current->get($id, 0) > $maximum) {
                throw ValidationException::withMessages(['fatty_acid_entries' => __('ingredients.editor.validation.fatty_acid_range', [
                    'minimum' => $this->formatRangeValue($minimum), 'maximum' => $this->formatRangeValue($maximum),
                ])]);
            }
        }
    }

    /** @return array{float, float} */
    public function fattyAcidRange(float $original): array
    {
        return $original < 5 ? [0.0, 5.0] : [max(0, $original * 0.8), min(100, $original * 1.2)];
    }

    /** @return array{minimum: float, maximum: float, original: float} */
    public function kohSapRange(float $original): array
    {
        return ['minimum' => $original * (1 - self::KOH_TOLERANCE), 'maximum' => $original * (1 + self::KOH_TOLERANCE), 'original' => $original];
    }

    private function formatRangeValue(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
