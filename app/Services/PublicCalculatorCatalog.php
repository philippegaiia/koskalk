<?php

namespace App\Services;

use App\Models\Ingredient;
use App\Models\ProductFamily;
use App\Models\SupportedLocale;
use App\Support\NumberLocale;
use Illuminate\Database\Eloquent\Collection;

class PublicCalculatorCatalog
{
    private const array FAMILIES = ['solid-soap' => 'soap', 'liquid-soap' => 'liquid-soap', 'cosmetics' => 'cosmetic'];

    public function numberLocale(): string
    {
        return NumberLocale::resolve(SupportedLocale::query()->where('code', app()->getLocale())->value('number_locale'));
    }

    /** @return list<array<string, mixed>> */
    public function calculators(): array
    {
        $families = ProductFamily::query()->whereIn('slug', array_values(self::FAMILIES))->get()->keyBy('slug');

        return collect(self::FAMILIES)->map(function (string $slug, string $key) use ($families): array {
            $prefix = match ($key) {
                'solid-soap' => 'solid', 'liquid-soap' => 'liquid', default => 'cosmetics'
            };

            return ['key' => $key, 'title' => __("calculators.{$prefix}_title"), 'description' => __("calculators.{$prefix}_description"), 'available' => (bool) $families->get($slug)?->is_active];
        })->values()->all();
    }

    public function family(string $key): ProductFamily
    {
        abort_unless(isset(self::FAMILIES[$key]), 404);

        return ProductFamily::query()->where('slug', self::FAMILIES[$key])->where('is_active', true)->firstOrFail();
    }

    /**
     * @param  list<int>|null  $ingredientIds
     * @return Collection<int, Ingredient>
     */
    public function ingredients(?array $ingredientIds = null): Collection
    {
        $query = fn () => Ingredient::query()->whereNull('owner_type')->whereNull('owner_id')->whereNull('workspace_id')
            ->where('is_active', true)
            ->with(['sapProfile', 'fattyAcidEntries.fattyAcid', 'components', 'allergenEntries.allergen', 'marketLabels', 'translations']);
        if ($ingredientIds === null) {
            return $query()->get()->keyBy('id');
        }
        $ingredients = new Collection;
        $pending = array_values(array_unique($ingredientIds));
        for ($depth = 0; $depth < 12 && $pending !== []; $depth++) {
            $batch = $query()->whereKey($pending)->get();
            $ingredients = $ingredients->merge($batch)->keyBy('id');
            $pending = $batch->flatMap(fn (Ingredient $ingredient) => $ingredient->components->pluck('component_ingredient_id'))
                ->filter()->unique()->reject(fn (int $id): bool => $ingredients->has($id))->values()->all();
        }

        return $ingredients;
    }

    /** @param Collection<int, Ingredient> $ingredients
     * @param  list<int>  $ancestry
     */
    public function fingerprint(Ingredient $ingredient, Collection $ingredients, array $ancestry = []): ?string
    {
        if (count($ancestry) >= 12 || in_array($ingredient->id, $ancestry, true)) {
            return null;
        }
        $children = [];
        foreach ($ingredient->components->sortBy('component_ingredient_id') as $component) {
            $child = $ingredients->get($component->component_ingredient_id);
            if (! $child instanceof Ingredient || ($fingerprint = $this->fingerprint($child, $ingredients, [...$ancestry, $ingredient->id])) === null) {
                return null;
            }
            $children[] = [$component->component_ingredient_id, (string) $component->percentage_in_parent, $fingerprint];
        }
        $fields = $ingredient->only(['display_name', 'inci_name', 'soap_inci_naoh_name', 'soap_inci_koh_name', 'category', 'subcategory', 'is_soap_saponification_trusted', 'liquid_soap_neutralizer', 'requires_aromatic_compliance', 'is_active']);

        return hash('sha256', json_encode([
            'version' => 1, 'fields' => $fields, 'sap' => $ingredient->sapProfile?->getAttributes(),
            'fatty_acids' => $ingredient->fattyAcidEntries->sortBy('fatty_acid_id')->map(fn ($entry): array => [$entry->getAttributes(), $entry->fattyAcid?->getAttributes()])->values()->all(),
            'translations' => $ingredient->translations->sortBy('locale')->map(fn ($entry): array => $entry->only(['locale', 'display_name', 'saponification_name']))->values()->all(),
            'labels' => $ingredient->marketLabels->sortBy('id')->map(fn ($entry): array => $entry->getAttributes())->values()->all(),
            'allergens' => $ingredient->allergenEntries->sortBy('allergen_id')->map(fn ($entry): array => [$entry->getAttributes(), $entry->allergen?->getAttributes()])->values()->all(),
            'children' => $children,
        ], JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    public function configuration(string $key, ?Collection $ingredients = null): array
    {
        $family = $this->family($key);
        $cosmetic = $key === 'cosmetics';
        $liquid = $key === 'liquid-soap';
        $phases = $cosmetic
            ? [['key' => 'phase_a', 'name' => __('calculators.phase_a'), 'temporary' => true]]
            : [
                ['key' => 'saponified_oils', 'name' => __('calculators.phase_oils'), 'temporary' => false],
                ...($liquid ? [['key' => 'neutralization', 'name' => __('calculators.phase_neutralization'), 'temporary' => false]] : []),
                ['key' => 'additives', 'name' => __('calculators.phase_additives'), 'temporary' => true],
                ['key' => 'fragrance', 'name' => __('calculators.phase_fragrance'), 'temporary' => true],
            ];
        $ingredients ??= $this->ingredients();
        $catalogue = $ingredients->map(function (Ingredient $ingredient) use ($ingredients, $liquid): ?array {
            $fingerprint = $this->fingerprint($ingredient, $ingredients);
            if ($fingerprint === null) {
                return null;
            }
            $roles = $ingredient->availableWorkbenchPhases();
            $neutralizer = $liquid ? $ingredient->liquidSoapNeutralizer() : null;
            if ($neutralizer !== null) {
                $roles[] = 'neutralization';
            }

            return ['id' => $ingredient->id, 'name' => $ingredient->localizedDisplayName(), 'inci_name' => $ingredient->inci_name,
                'available_phases' => $roles, 'can_add_to_saponified_oils' => $ingredient->canDriveSoapSaponification(),
                'liquid_soap_neutralizer' => $neutralizer?->value, 'fingerprint' => $fingerprint];
        })->filter()->sortBy('name')->values()->all();
        $definition = collect($this->calculators())->firstWhere('key', $key);

        return [
            ...$definition, 'catalogue' => $catalogue, 'phases' => $phases,
            'defaults' => [
                'name' => '', 'notes' => '', 'oil_unit' => 'g', 'oil_weight' => $cosmetic ? 100 : 1000, 'editing_mode' => 'percentage',
                'lye_type' => $liquid ? 'koh' : 'naoh', 'koh_purity_percentage' => 90, 'dual_lye_koh_percentage' => 40,
                'water_mode' => 'lye_concentration', 'water_value' => $liquid ? 28 : 30, 'superfat' => $liquid ? 0 : 5,
                'cured_water_percentage' => $family->curedWaterPercentage(),
                'phases' => collect($phases)->map(fn (array $phase): array => ['key' => $phase['key'], 'name' => $phase['name']])->all(),
                'phase_items' => collect($phases)->mapWithKeys(fn (array $phase): array => [$phase['key'] => []])->all(),
                ...($liquid ? ['liquid_process' => ['first_dilution_percentage' => 35, 'final_dilution_percentage' => 25, 'neutralizer_solution_percentage' => 20, 'measured_paste_weight' => null]] : []),
            ],
            'previewUrl' => route('calculators.preview', ['calculator' => $key]),
            'printUrl' => route('calculators.print', ['calculator' => $key]),
            'numberLocale' => $this->numberLocale(), 'messages' => __('calculators'),
        ];
    }
}
