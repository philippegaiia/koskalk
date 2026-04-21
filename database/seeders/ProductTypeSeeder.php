<?php

namespace Database\Seeders;

use App\Models\ProductFamily;
use App\Models\ProductType;
use Illuminate\Database\Seeder;

class ProductTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $cosmeticFamily = ProductFamily::query()->firstOrCreate(
            ['slug' => 'cosmetic'],
            [
                'name' => 'Cosmetic',
                'calculation_basis' => 'total_formula',
                'is_active' => true,
                'description' => 'Non-saponified cosmetic formulations calculated from the total formula weight.',
            ],
        );

        $productTypes = [
            ['name' => 'Cream / lotion', 'slug' => 'cream-lotion'],
            ['name' => 'Balm / salve', 'slug' => 'balm-salve'],
            ['name' => 'Lip product', 'slug' => 'lip-product'],
            ['name' => 'Deodorant', 'slug' => 'deodorant'],
            ['name' => 'Hair care', 'slug' => 'hair-care'],
            ['name' => 'Mask', 'slug' => 'mask'],
            ['name' => 'Oil blend / serum', 'slug' => 'oil-blend-serum'],
            ['name' => 'Cleansing, non-saponified', 'slug' => 'cleansing-non-saponified'],
            ['name' => 'Bath salts / soaks', 'slug' => 'bath-salts-soaks'],
            ['name' => 'Other', 'slug' => 'other'],
        ];

        foreach ($productTypes as $index => $productType) {
            ProductType::query()->updateOrCreate(
                [
                    'product_family_id' => $cosmeticFamily->id,
                    'slug' => $productType['slug'],
                ],
                [
                    'name' => $productType['name'],
                    'sort_order' => ($index + 1) * 10,
                    'is_active' => true,
                ],
            );
        }
    }
}
