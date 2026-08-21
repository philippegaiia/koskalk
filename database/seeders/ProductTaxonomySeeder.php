<?php

namespace Database\Seeders;

use App\Models\ProductArea;
use App\Models\ProductFamily;
use App\Models\ProductType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ProductTaxonomySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::transaction(function (): void {
            $families = ProductFamily::query()
                ->whereIn('slug', ['soap', 'cosmetic'])
                ->get()
                ->keyBy('slug');

            if ($families->count() !== 2) {
                throw new LogicException('Seed the soap and cosmetic Product Families before the product taxonomy.');
            }

            ProductType::query()
                ->whereIn('slug', $this->supersededSlugs())
                ->update(['is_active' => false]);

            $lipMatches = ProductType::query()
                ->where('slug', 'lip-product')
                ->orderBy('id')
                ->get();

            if ($lipMatches->count() > 1) {
                throw new LogicException(
                    'Resolve duplicate historical lip-product rows before seeding: '.$lipMatches->pluck('id')->implode(', '),
                );
            }

            $historicalLipType = $lipMatches->first();

            foreach ($this->areas() as $areaData) {
                $area = ProductArea::query()->updateOrCreate(
                    ['slug' => $areaData['slug']],
                    [
                        'name' => $areaData['name'],
                        'sort_order' => $areaData['sort_order'],
                        'is_active' => true,
                        'translations' => $this->translationsFor($areaData['slug']),
                    ],
                );

                foreach ($areaData['categories'] as $categoryData) {
                    $category = $area->productCategories()->updateOrCreate(
                        ['slug' => $categoryData['slug']],
                        [
                            'name' => $categoryData['name'],
                            'sort_order' => $categoryData['sort_order'],
                            'is_active' => true,
                            'translations' => $this->translationsFor($categoryData['slug']),
                        ],
                    );

                    foreach ($categoryData['types'] as $index => $typeData) {
                        $familyIds = collect($typeData['families'])
                            ->map(function (string $familySlug) use ($families): int {
                                $family = $families->get($familySlug);

                                if ($family === null) {
                                    throw new LogicException("Missing Product Family: {$familySlug}");
                                }

                                return $family->id;
                            })
                            ->values();
                        $attributes = [
                            'product_category_id' => $category->id,
                            'product_family_id' => $familyIds->first(),
                            'name' => $typeData['name'],
                            'sort_order' => ($index + 1) * 10,
                            'is_active' => true,
                            'translations' => $this->translationsFor($typeData['slug']),
                        ];

                        if ($typeData['slug'] === 'lip-product' && $historicalLipType !== null) {
                            $productType = $historicalLipType;
                            $productType->fill($attributes);
                            $productType->save();
                        } else {
                            $productType = ProductType::query()->updateOrCreate(
                                ['slug' => $typeData['slug'], 'is_active' => true],
                                $attributes,
                            );
                        }

                        $productType->productFamilies()->sync($familyIds->all());
                    }
                }
            }
        }, attempts: 5);
    }

    /**
     * @return array<int, string>
     */
    private function supersededSlugs(): array
    {
        return [
            'cream-lotion',
            'balm-salve',
            'deodorant',
            'hair-care',
            'mask',
            'oil-blend-serum',
            'cleansing-non-saponified',
            'bath-salts-soaks',
            'other',
        ];
    }

    /** @return array{fr: array{name: string}}|null */
    private function translationsFor(string $slug): ?array
    {
        $name = $this->frenchNames()[$slug] ?? null;

        return $name === null ? null : ['fr' => ['name' => $name]];
    }

    /** @return array<string, string> */
    private function frenchNames(): array
    {
        return [
            'personal-care' => 'Soins personnels',
            'home-household' => 'Maison et entretien',
            'body-cleansing' => 'Nettoyage du corps',
            'skin-care' => 'Soins de la peau',
            'hair-care' => 'Soins capillaires',
            'lips-oral-care' => 'Lèvres et soins bucco-dentaires',
            'deodorant-fragrance' => 'Déodorants et parfums',
            'grooming' => 'Rasage et soins masculins',
            'makeup' => 'Maquillage',
            'sun-tan-care' => 'Soins solaires et autobronzants',
            'other' => 'Autres',
            'home-fragrance' => 'Parfums d’intérieur',
            'dish-care' => 'Vaisselle',
            'laundry' => 'Linge',
            'surface-toilet-care' => 'Surfaces et sanitaires',
            'bar-soap-cleansing-bar' => 'Savon solide / pain nettoyant',
            'liquid-soap-body-wash' => 'Savon liquide / gel douche',
            'face-cleanser' => 'Nettoyant visage',
            'bath-salts-soaks-bombs' => 'Sels / bains / bombes de bain',
            'shaving-soap-cream' => 'Savon / crème à raser',
            'body-cream-lotion-oil' => 'Crème / lotion / huile pour le corps',
            'face-cream-serum-toner' => 'Crème / sérum / lotion tonique visage',
            'hand-nail-care' => 'Soins des mains / ongles',
            'foot-cream-powder' => 'Crème / poudre pour les pieds',
            'face-mask' => 'Masque visage',
            'baby-cream-oil-powder' => 'Crème / huile / poudre pour bébé',
            'massage-body-oil' => 'Huile de massage / pour le corps',
            'skin-contact-massage-candle' => 'Bougie de massage pour la peau',
            'shampoo' => 'Shampooing',
            'rinse-off-conditioner' => 'Après-shampooing à rincer',
            'leave-in-styling-hair-oil' => 'Soin sans rinçage / coiffant / huile capillaire',
            'rinse-off-hair-chemical-treatment' => 'Coloration / traitement chimique capillaire à rincer',
            'lip-product' => 'Produit pour les lèvres',
            'toothpaste-mouthwash' => 'Dentifrice / bain de bouche',
            'deodorant-antiperspirant' => 'Déodorant / anti-transpirant',
            'body-mist-spray' => 'Brume / spray corporel',
            'fine-fragrance-solid-perfume' => 'Parfum / parfum solide',
            'aftershave-splash' => 'Lotion après-rasage',
            'aftershave-cream-balm' => 'Crème / baume après-rasage',
            'face-eye-makeup' => 'Maquillage visage / yeux',
            'makeup-remover' => 'Démaquillant',
            'body-sun-self-tan-care' => 'Soin solaire / autobronzant corps',
            'face-sun-self-tan-care' => 'Soin solaire / autobronzant visage',
            'other-cosmetics' => 'Autre produit cosmétique',
            'candle-wax-melt' => 'Bougie / fondant parfumé',
            'reed-diffuser-refill' => 'Diffuseur à bâtonnets / recharge liquide',
            'room-air-freshener-spray' => 'Spray d’ambiance / désodorisant',
            'pillow-spray' => 'Brume d’oreiller',
            'fabric-linen-spray' => 'Brume textile / linge',
            'incense-passive-air-fragrance' => 'Encens / parfum d’ambiance passif',
            'hand-dishwashing-product' => 'Savon / détergent pour vaisselle à la main',
            'automatic-dishwasher-product' => 'Produit pour lave-vaisselle',
            'hand-wash-laundry-product' => 'Savon / détergent pour lavage du linge à la main',
            'machine-laundry-detergent' => 'Lessive liquide / en poudre',
            'laundry-pre-treatment' => 'Prétraitement / détachant pour le linge',
            'fabric-softener' => 'Assouplissant',
            'dryer-sheet-scent-beads' => 'Feuille sèche-linge / perles parfumées',
            'hard-surface-cleaner' => 'Nettoyant pour surfaces dures',
            'toilet-gel-rim-block' => 'Gel WC / bloc cuvette',
            'other-home-product' => 'Autre produit pour la maison',
        ];
    }

    /**
     * @return array<int, array{
     *     name:string,
     *     slug:string,
     *     sort_order:int,
     *     categories:array<int, array{
     *         name:string,
     *         slug:string,
     *         sort_order:int,
     *         types:array<int, array{name:string, slug:string, families:array<int, string>}>
     *     }>
     * }>
     */
    private function areas(): array
    {
        return [
            [
                'name' => 'Personal care',
                'slug' => 'personal-care',
                'sort_order' => 10,
                'categories' => [
                    ['name' => 'Body cleansing', 'slug' => 'body-cleansing', 'sort_order' => 10, 'types' => [
                        ['name' => 'Bar soap / cleansing bar', 'slug' => 'bar-soap-cleansing-bar', 'families' => ['soap', 'cosmetic']],
                        ['name' => 'Liquid soap / body wash', 'slug' => 'liquid-soap-body-wash', 'families' => ['soap', 'cosmetic']],
                        ['name' => 'Face cleanser', 'slug' => 'face-cleanser', 'families' => ['cosmetic']],
                        ['name' => 'Bath salts / soaks / bombs', 'slug' => 'bath-salts-soaks-bombs', 'families' => ['cosmetic']],
                        ['name' => 'Shaving soap / cream', 'slug' => 'shaving-soap-cream', 'families' => ['soap', 'cosmetic']],
                    ]],
                    ['name' => 'Skin care', 'slug' => 'skin-care', 'sort_order' => 20, 'types' => [
                        ['name' => 'Body cream / lotion / oil', 'slug' => 'body-cream-lotion-oil', 'families' => ['cosmetic']],
                        ['name' => 'Face cream / serum / toner', 'slug' => 'face-cream-serum-toner', 'families' => ['cosmetic']],
                        ['name' => 'Hand / nail care', 'slug' => 'hand-nail-care', 'families' => ['cosmetic']],
                        ['name' => 'Foot cream / powder', 'slug' => 'foot-cream-powder', 'families' => ['cosmetic']],
                        ['name' => 'Face mask', 'slug' => 'face-mask', 'families' => ['cosmetic']],
                        ['name' => 'Baby cream / oil / powder', 'slug' => 'baby-cream-oil-powder', 'families' => ['cosmetic']],
                        ['name' => 'Massage / body oil', 'slug' => 'massage-body-oil', 'families' => ['cosmetic']],
                        ['name' => 'Skin-contact massage candle', 'slug' => 'skin-contact-massage-candle', 'families' => ['cosmetic']],
                    ]],
                    ['name' => 'Hair care', 'slug' => 'hair-care', 'sort_order' => 30, 'types' => [
                        ['name' => 'Shampoo', 'slug' => 'shampoo', 'families' => ['cosmetic']],
                        ['name' => 'Rinse-off conditioner', 'slug' => 'rinse-off-conditioner', 'families' => ['cosmetic']],
                        ['name' => 'Leave-in / styling / hair oil', 'slug' => 'leave-in-styling-hair-oil', 'families' => ['cosmetic']],
                        ['name' => 'Rinse-off hair dye / chemical treatment', 'slug' => 'rinse-off-hair-chemical-treatment', 'families' => ['cosmetic']],
                    ]],
                    ['name' => 'Lips & oral care', 'slug' => 'lips-oral-care', 'sort_order' => 40, 'types' => [
                        ['name' => 'Lip product', 'slug' => 'lip-product', 'families' => ['cosmetic']],
                        ['name' => 'Toothpaste / mouthwash', 'slug' => 'toothpaste-mouthwash', 'families' => ['cosmetic']],
                    ]],
                    ['name' => 'Deodorant & fragrance', 'slug' => 'deodorant-fragrance', 'sort_order' => 50, 'types' => [
                        ['name' => 'Deodorant / antiperspirant', 'slug' => 'deodorant-antiperspirant', 'families' => ['cosmetic']],
                        ['name' => 'Body mist / body spray', 'slug' => 'body-mist-spray', 'families' => ['cosmetic']],
                        ['name' => 'Fine fragrance / solid perfume', 'slug' => 'fine-fragrance-solid-perfume', 'families' => ['cosmetic']],
                    ]],
                    ['name' => 'Grooming', 'slug' => 'grooming', 'sort_order' => 60, 'types' => [
                        ['name' => 'Aftershave splash', 'slug' => 'aftershave-splash', 'families' => ['cosmetic']],
                        ['name' => 'Aftershave cream / balm', 'slug' => 'aftershave-cream-balm', 'families' => ['cosmetic']],
                    ]],
                    ['name' => 'Makeup', 'slug' => 'makeup', 'sort_order' => 70, 'types' => [
                        ['name' => 'Face / eye makeup', 'slug' => 'face-eye-makeup', 'families' => ['cosmetic']],
                        ['name' => 'Makeup remover', 'slug' => 'makeup-remover', 'families' => ['cosmetic']],
                    ]],
                    ['name' => 'Sun & tan care', 'slug' => 'sun-tan-care', 'sort_order' => 80, 'types' => [
                        ['name' => 'Body sun / self-tan care', 'slug' => 'body-sun-self-tan-care', 'families' => ['cosmetic']],
                        ['name' => 'Face sun / self-tan care', 'slug' => 'face-sun-self-tan-care', 'families' => ['cosmetic']],
                    ]],
                    ['name' => 'Other', 'slug' => 'other', 'sort_order' => 90, 'types' => [
                        ['name' => 'Other cosmetics', 'slug' => 'other-cosmetics', 'families' => ['cosmetic']],
                    ]],
                ],
            ],
            [
                'name' => 'Home & household',
                'slug' => 'home-household',
                'sort_order' => 20,
                'categories' => [
                    ['name' => 'Home fragrance', 'slug' => 'home-fragrance', 'sort_order' => 10, 'types' => [
                        ['name' => 'Candle / wax melt', 'slug' => 'candle-wax-melt', 'families' => ['cosmetic']],
                        ['name' => 'Reed diffuser / liquid refill', 'slug' => 'reed-diffuser-refill', 'families' => ['cosmetic']],
                        ['name' => 'Room / air-freshener spray', 'slug' => 'room-air-freshener-spray', 'families' => ['cosmetic']],
                        ['name' => 'Pillow spray', 'slug' => 'pillow-spray', 'families' => ['cosmetic']],
                        ['name' => 'Fabric / linen spray', 'slug' => 'fabric-linen-spray', 'families' => ['cosmetic']],
                        ['name' => 'Incense / passive air fragrance', 'slug' => 'incense-passive-air-fragrance', 'families' => ['cosmetic']],
                    ]],
                    ['name' => 'Dish care', 'slug' => 'dish-care', 'sort_order' => 20, 'types' => [
                        ['name' => 'Hand dishwashing soap / detergent', 'slug' => 'hand-dishwashing-product', 'families' => ['soap', 'cosmetic']],
                        ['name' => 'Automatic dishwasher product', 'slug' => 'automatic-dishwasher-product', 'families' => ['cosmetic']],
                    ]],
                    ['name' => 'Laundry', 'slug' => 'laundry', 'sort_order' => 30, 'types' => [
                        ['name' => 'Hand-wash laundry soap / detergent', 'slug' => 'hand-wash-laundry-product', 'families' => ['soap', 'cosmetic']],
                        ['name' => 'Machine laundry liquid / powder', 'slug' => 'machine-laundry-detergent', 'families' => ['cosmetic']],
                        ['name' => 'Laundry pre-treatment / stain remover', 'slug' => 'laundry-pre-treatment', 'families' => ['cosmetic']],
                        ['name' => 'Fabric softener', 'slug' => 'fabric-softener', 'families' => ['cosmetic']],
                        ['name' => 'Dryer sheet / scent beads', 'slug' => 'dryer-sheet-scent-beads', 'families' => ['cosmetic']],
                    ]],
                    ['name' => 'Surface & toilet care', 'slug' => 'surface-toilet-care', 'sort_order' => 40, 'types' => [
                        ['name' => 'Hard-surface cleaner', 'slug' => 'hard-surface-cleaner', 'families' => ['soap', 'cosmetic']],
                        ['name' => 'Toilet gel / rim block', 'slug' => 'toilet-gel-rim-block', 'families' => ['cosmetic']],
                    ]],
                    ['name' => 'Other', 'slug' => 'other', 'sort_order' => 50, 'types' => [
                        ['name' => 'Other home product', 'slug' => 'other-home-product', 'families' => ['cosmetic']],
                    ]],
                ],
            ],
        ];
    }
}
