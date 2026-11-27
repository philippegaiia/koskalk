<?php

namespace App\Services;

use App\Models\Allergen;
use App\Models\IngredientAllergenEntry;
use App\Models\RegulatoryRegime;
use App\Models\RegulatoryRegimeAllergen;
use App\Models\Substance;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Aligns the allergen catalog with the fragrance allergens individually
 * labelled under Regulation (EU) 2023/1545, one catalog row per Annex III
 * entry, read from the committed reference file.
 *
 * Existing rows are updated in place so ingredient compositions keep their
 * links. Rows the regulation folds into one entry are merged, adding
 * concentrations where an ingredient lists more than one of them. A row the
 * regulation does not list is deleted only when no ingredient uses it; a used
 * one is kept and its label rules are switched off for review.
 */
class EuFragranceAllergenCatalogSynchronizer
{
    public const SOURCE_FILE = 'database/seeders/data/eu_fragrance_allergens.json';

    /**
     * Label markets whose allergen list is the EU list.
     */
    public const REGIME_CODES = ['eu', 'canada_2026'];

    /**
     * Plans the alignment and, when asked, writes it.
     *
     * @return array{
     *     entries: list<array{annex_ref: string, label: string, status: 'create'|'rename'|'update'|'unchanged', previous_name: string|null, merges: list<string>}>,
     *     removals: list<array{inci_name: string, status: 'delete'|'keep', ingredient_count: int}>
     * }
     */
    public function synchronize(bool $write): array
    {
        $reference = $this->reference();
        $existing = Allergen::query()->withCount('ingredientEntries')->get();
        $claimedIds = [];
        $entries = [];

        foreach ($reference['entries'] as $entry) {
            $names = collect([$entry['label'], ...($entry['legacy_names'] ?? [])])->map(fn (string $name): string => $this->normalize($name));
            $matches = $existing
                ->reject(fn (Allergen $allergen): bool => in_array($allergen->id, $claimedIds, true))
                ->filter(fn (Allergen $allergen): bool => $names->contains($this->normalize($allergen->inci_name)))
                ->sortBy(fn (Allergen $allergen): int => (int) $names->search($this->normalize($allergen->inci_name)))
                ->values();
            $target = $matches->first();
            $merged = $matches->slice(1)->values();
            $attributes = $this->attributes($entry, $reference);

            array_push($claimedIds, ...$matches->pluck('id')->all());
            $entries[] = [
                'annex_ref' => $entry['annex_ref'],
                'label' => $entry['label'],
                'status' => $this->status($target, $attributes),
                'previous_name' => $target instanceof Allergen && $target->inci_name !== $entry['label'] ? $target->inci_name : null,
                'merges' => $merged->pluck('inci_name')->all(),
                'target' => $target,
                'merged' => $merged,
                'attributes' => $attributes,
            ];
        }

        $removals = $existing
            ->reject(fn (Allergen $allergen): bool => in_array($allergen->id, $claimedIds, true))
            ->map(fn (Allergen $allergen): array => [
                'inci_name' => $allergen->inci_name,
                'status' => $allergen->ingredient_entries_count > 0 ? 'keep' : 'delete',
                'ingredient_count' => (int) $allergen->ingredient_entries_count,
                'allergen' => $allergen,
            ])
            ->values()
            ->all();

        if ($write) {
            DB::transaction(fn () => $this->write($entries, $removals));
        }

        return [
            'entries' => array_map(fn (array $row): array => collect($row)->only(['annex_ref', 'label', 'status', 'previous_name', 'merges'])->all(), $entries),
            'removals' => array_map(fn (array $row): array => collect($row)->only(['inci_name', 'status', 'ingredient_count'])->all(), $removals),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @param  list<array<string, mixed>>  $removals
     */
    private function write(array $entries, array $removals): void
    {
        $alignedIds = [];

        foreach ($entries as $row) {
            $target = $row['target'];

            if ($target instanceof Allergen) {
                $row['merged']->each(fn (Allergen $merged) => $this->mergeInto($merged, $target));
                $target->fill($row['attributes'])->save();
            } else {
                $target = Allergen::query()->create($row['attributes']);
            }

            $alignedIds[] = $target->id;
        }

        foreach ($removals as $removal) {
            if ($removal['status'] === 'delete') {
                $removal['allergen']->delete();
            }
        }

        $this->syncRegimeRules($alignedIds);
    }

    /**
     * Moves ingredient compositions and substance links onto the entry that
     * absorbs this row, then deletes it.
     */
    private function mergeInto(Allergen $merged, Allergen $target): void
    {
        IngredientAllergenEntry::query()
            ->where('allergen_id', $merged->id)
            ->get()
            ->each(function (IngredientAllergenEntry $entry) use ($target): void {
                $existing = IngredientAllergenEntry::query()
                    ->where('ingredient_id', $entry->ingredient_id)
                    ->where('allergen_id', $target->id)
                    ->first();

                if (! $existing instanceof IngredientAllergenEntry) {
                    $entry->update(['allergen_id' => $target->id]);

                    return;
                }

                $existing->update([
                    'concentration_percent' => bcadd((string) $existing->concentration_percent, (string) $entry->concentration_percent, 5),
                ]);
                $entry->delete();
            });

        Substance::query()->where('allergen_id', $merged->id)->update(['allergen_id' => $target->id]);

        $merged->delete();
    }

    /**
     * Gives every aligned allergen a label rule in the EU-list markets,
     * leaving existing rules as an administrator set them, and switches off
     * rules for allergens the regulation does not list.
     *
     * @param  list<int>  $alignedIds
     */
    private function syncRegimeRules(array $alignedIds): void
    {
        RegulatoryRegime::query()
            ->whereIn('code', self::REGIME_CODES)
            ->get()
            ->each(function (RegulatoryRegime $regime) use ($alignedIds): void {
                foreach ($alignedIds as $allergenId) {
                    RegulatoryRegimeAllergen::query()->firstOrCreate(
                        ['regulatory_regime_id' => $regime->id, 'allergen_id' => $allergenId],
                        self::defaultRuleAttributes(),
                    );
                }

                RegulatoryRegimeAllergen::query()
                    ->where('regulatory_regime_id', $regime->id)
                    ->whereNotIn('allergen_id', $alignedIds)
                    ->update(['is_active' => false]);
            });
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaultRuleAttributes(): array
    {
        return [
            'declaration_label' => null,
            'rinse_off_threshold_percent' => 0.01000,
            'leave_on_threshold_percent' => 0.00100,
            'threshold_operator' => 'greater_than_or_equal',
            'group_key' => null,
            'group_label' => null,
            'is_active' => true,
            'effective_from' => null,
            'effective_until' => null,
            'source_reference' => 'Regulation (EU) 2023/1545',
            'source_data' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return 'create'|'rename'|'update'|'unchanged'
     */
    private function status(?Allergen $target, array $attributes): string
    {
        if (! $target instanceof Allergen) {
            return 'create';
        }

        if ($target->inci_name !== $attributes['inci_name']) {
            return 'rename';
        }

        $current = collect($attributes)->map(fn (mixed $value, string $key): mixed => $target->getAttribute($key))->all();

        return $current == $attributes ? 'unchanged' : 'update';
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  array<string, mixed>  $reference
     * @return array<string, mixed>
     */
    private function attributes(array $entry, array $reference): array
    {
        return [
            'source_name' => $reference['source_name'],
            'source_file' => self::SOURCE_FILE,
            'inci_name' => $entry['label'],
            'cas_number' => implode(' / ', $entry['cas']),
            'ec_number' => $entry['ec'] === [] ? null : implode(' / ', $entry['ec']),
            'common_name_en' => $entry['common_name_en'] ?? null,
            'common_name_fr' => $entry['common_name_fr'] ?? null,
            'source_data' => [
                'annex_ref' => $entry['annex_ref'],
                'glossary_names' => $entry['glossary_names'],
                'cas' => $entry['cas'],
                'ec' => $entry['ec'],
                'source_url' => $reference['source_url'],
            ],
        ];
    }

    /**
     * @return array{source_name: string, source_url: string, entries: list<array<string, mixed>>}
     */
    private function reference(): array
    {
        $contents = file_get_contents(base_path(self::SOURCE_FILE));

        if ($contents === false) {
            throw new RuntimeException('Unable to read the EU fragrance allergen reference file.');
        }

        return json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
    }

    private function normalize(string $name): string
    {
        return mb_strtoupper(preg_replace('/\s+/u', ' ', trim($name)) ?? '');
    }
}
