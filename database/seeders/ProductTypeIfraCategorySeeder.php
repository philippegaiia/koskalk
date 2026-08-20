<?php

namespace Database\Seeders;

use App\Models\IfraAmendment;
use App\Models\IfraProductCategory;
use App\Models\ProductType;
use App\Models\ProductTypeIfraCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ProductTypeIfraCategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::transaction(function (): void {
            $amendment = IfraAmendment::query()->where('code', '51')->firstOrFail();
            $mappings = collect($this->mappings());
            $productTypes = ProductType::query()
                ->where('is_active', true)
                ->whereIn('slug', $mappings->pluck('slug')->push('other-cosmetics')->push('other-home-product')->unique())
                ->get()
                ->keyBy('slug');
            $categories = IfraProductCategory::query()
                ->whereIn('code', $mappings->pluck('code')->unique())
                ->get()
                ->keyBy('code');

            foreach ($mappings->pluck('slug')->unique()->push('other-cosmetics')->push('other-home-product') as $slug) {
                if (! $productTypes->has($slug)) {
                    throw new LogicException("Missing active Product Type for IFRA mapping: {$slug}");
                }
            }

            foreach ($mappings->pluck('code')->unique() as $code) {
                if (! $categories->has($code)) {
                    throw new LogicException("Missing IFRA Product Category: {$code}");
                }
            }

            ProductTypeIfraCategory::query()
                ->where('ifra_amendment_id', $amendment->id)
                ->whereIn('product_type_id', $productTypes->pluck('id'))
                ->update(['is_default' => false, 'is_active' => false]);

            foreach ($mappings as $mappingData) {
                $productType = $productTypes->get($mappingData['slug']);
                $category = $categories->get($mappingData['code']);

                ProductTypeIfraCategory::query()->updateOrCreate(
                    [
                        'product_type_id' => $productType->id,
                        'ifra_amendment_id' => $amendment->id,
                        'ifra_product_category_id' => $category->id,
                    ],
                    [
                        'is_default' => $mappingData['is_default'],
                        'guidance' => $mappingData['guidance'],
                        'source_url' => $this->guidanceUrl(),
                        'sort_order' => $mappingData['sort_order'],
                        'is_active' => true,
                    ],
                );
            }

            $activeGroups = ProductTypeIfraCategory::query()
                ->where('ifra_amendment_id', $amendment->id)
                ->where('is_active', true)
                ->whereIn('product_type_id', $productTypes->pluck('id'))
                ->get()
                ->groupBy('product_type_id');

            foreach ($this->defaults() as $slug => $code) {
                $productType = $productTypes->get($slug);
                $group = $activeGroups->get($productType->id, collect());

                if ($group->where('is_default', true)->count() !== 1) {
                    throw new LogicException("Product Type {$slug} must have exactly one default for IFRA Amendment {$amendment->code}.");
                }
            }
        }, attempts: 5);
    }

    /**
     * @return array<string, string>
     */
    private function defaults(): array
    {
        return [
            'bar-soap-cleansing-bar' => '9',
            'liquid-soap-body-wash' => '9',
            'face-cleanser' => '9',
            'bath-salts-soaks-bombs' => '9',
            'shaving-soap-cream' => '9',
            'body-cream-lotion-oil' => '5A',
            'face-cream-serum-toner' => '5B',
            'hand-nail-care' => '5C',
            'foot-cream-powder' => '5A',
            'face-mask' => '3',
            'baby-cream-oil-powder' => '5D',
            'massage-body-oil' => '5A',
            'skin-contact-massage-candle' => '5A',
            'shampoo' => '9',
            'rinse-off-conditioner' => '9',
            'leave-in-styling-hair-oil' => '7B',
            'rinse-off-hair-chemical-treatment' => '7A',
            'lip-product' => '1',
            'toothpaste-mouthwash' => '6',
            'deodorant-antiperspirant' => '2',
            'body-mist-spray' => '2',
            'fine-fragrance-solid-perfume' => '4',
            'aftershave-splash' => '4',
            'aftershave-cream-balm' => '5B',
            'face-eye-makeup' => '3',
            'makeup-remover' => '3',
            'body-sun-self-tan-care' => '5A',
            'face-sun-self-tan-care' => '5B',
            'candle-wax-melt' => '12',
            'reed-diffuser-refill' => '10A',
            'room-air-freshener-spray' => '10B',
            'pillow-spray' => '11B',
            'fabric-linen-spray' => '10A',
            'incense-passive-air-fragrance' => '12',
            'hand-dishwashing-product' => '10A',
            'automatic-dishwasher-product' => '12',
            'hand-wash-laundry-product' => '10A',
            'machine-laundry-detergent' => '10A',
            'laundry-pre-treatment' => '10A',
            'fabric-softener' => '10A',
            'dryer-sheet-scent-beads' => '12',
            'hard-surface-cleaner' => '10A',
            'toilet-gel-rim-block' => '12',
        ];
    }

    /**
     * @return array<int, array{slug:string, code:string, is_default:bool, guidance:?string, sort_order:int}>
     */
    private function mappings(): array
    {
        $massageCandleGuidance = 'Use this mapping only when the melted product is intended to be applied to the body as a leave-on massage or body oil. An ordinary burned candle or wax melt is Category 12.';
        $aftershaveBalmGuidance = 'Use this mapping when the product is presented and used as a leave-on face moisturizer. Aftershaves other than creams and balms are Category 4.';

        return collect($this->defaults())
            ->map(fn (string $code, string $slug): array => [
                'slug' => $slug,
                'code' => $code,
                'is_default' => true,
                'guidance' => match ($slug) {
                    'skin-contact-massage-candle' => $massageCandleGuidance,
                    'aftershave-cream-balm' => $aftershaveBalmGuidance,
                    default => null,
                },
                'sort_order' => 10,
            ])
            ->values()
            ->push([
                'slug' => 'body-mist-spray',
                'code' => '4',
                'is_default' => false,
                'guidance' => 'Use Category 4 only when the product is clearly labelled not for axillary use and not as a deodorant; otherwise foreseeable axillary use keeps it in Category 2.',
                'sort_order' => 20,
            ])
            ->all();
    }

    private function guidanceUrl(): string
    {
        return 'https://ifrafragrance.org/docs/default-source/51st-amendment/ifra-51st-amendment---guidance-for-the-use-of-ifra-standards.pdf?sfvrsn=79750005_2';
    }
}
