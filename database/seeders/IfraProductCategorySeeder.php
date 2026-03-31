<?php

namespace Database\Seeders;

use App\Models\IfraProductCategory;
use Illuminate\Database\Seeder;

class IfraProductCategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        collect($this->categories())
            ->each(function (array $category): void {
                IfraProductCategory::query()->updateOrCreate(
                    ['code' => $category['code']],
                    [
                        'name' => $category['name'],
                        'short_name' => $category['short_name'],
                        'description' => $category['description'],
                        'is_active' => true,
                    ],
                );
            });
    }

    /**
     * @return array<int, array{code:string, name:string, short_name:string, description:string}>
     */
    private function categories(): array
    {
        return [
            ['code' => '1', 'name' => 'Products applied to the lips', 'short_name' => 'Lips', 'description' => 'Products applied to the lips, typically leave-on lip products with potential ingestion.'],
            ['code' => '2', 'name' => 'Products applied to the axillae', 'short_name' => 'Deodorants / axillae', 'description' => 'Products applied to the axillae, such as deodorants and antiperspirants.'],
            ['code' => '3', 'name' => 'Products applied to the face/body using fingertips', 'short_name' => 'Face / body fingertip leave-on', 'description' => 'Products applied to the face or body using fingertips, generally localized leave-on applications.'],
            ['code' => '4', 'name' => 'Products related to fine fragrance', 'short_name' => 'Fine fragrance', 'description' => 'Fine fragrance products and comparable fragrance applications with similar exposure expectations.'],
            ['code' => '5A', 'name' => 'Body lotion products applied to the body using the hands (palms), primarily leave on', 'short_name' => 'Body lotion', 'description' => 'Body lotion and similar leave-on body products applied using the hands (palms).'],
            ['code' => '5B', 'name' => 'Face moisturizer products applied to the face using the hands (palms), primarily leave on', 'short_name' => 'Face moisturizer', 'description' => 'Face moisturizers and similar leave-on facial products applied using the hands (palms).'],
            ['code' => '5C', 'name' => 'Hand cream products applied to the hands using the hands (palms), primarily leave on', 'short_name' => 'Hand cream', 'description' => 'Hand cream and similar leave-on hand products applied using the hands (palms).'],
            ['code' => '5D', 'name' => 'Baby creams, baby oils and baby talc', 'short_name' => 'Baby creams / oils / talc', 'description' => 'Baby creams, baby oils, baby talc, and comparable infant leave-on products.'],
            ['code' => '6', 'name' => 'Products with oral and lip exposure', 'short_name' => 'Oral care / lip exposure', 'description' => 'Products with oral or lip exposure, including oral care contexts and similar use cases with potential ingestion.'],
            ['code' => '7A', 'name' => 'Rinse-off products applied to the hair with some hand contact', 'short_name' => 'Hair rinse-off', 'description' => 'Rinse-off products applied to the hair with some hand contact, such as shampoos and rinse-off conditioners.'],
            ['code' => '7B', 'name' => 'Leave-on products applied to the hair with some hand contact', 'short_name' => 'Hair leave-on', 'description' => 'Leave-on hair products applied with some hand contact, such as leave-in conditioners, serums, and styling creams.'],
            ['code' => '8', 'name' => 'Products with significant anogenital exposure', 'short_name' => 'Anogenital', 'description' => 'Products with significant anogenital exposure.'],
            ['code' => '9', 'name' => 'Products with body and hand exposure, primarily rinse off', 'short_name' => 'Soap / shower gel / rinse-off', 'description' => 'Products with body and hand exposure that are primarily rinse-off, such as soaps, body washes, shower gels, and similar cleansers.'],
            ['code' => '10A', 'name' => 'Household care products with mostly hand contact', 'short_name' => 'Household hand contact', 'description' => 'Household care products with mostly hand contact and generally limited leave-on skin exposure after use.'],
            ['code' => '10B', 'name' => 'Household care products with mostly hand contact, including aerosol/spray products (with potential leave-on skin contact)', 'short_name' => 'Household spray / hand contact', 'description' => 'Household care products with mostly hand contact, including aerosol or spray products with potential leave-on skin contact.'],
            ['code' => '11A', 'name' => 'Products with intended skin contact but minimal transfer of fragrance to skin from inert substrate without UV exposure', 'short_name' => 'Inert substrate, no UV', 'description' => 'Products with intended skin contact but minimal fragrance transfer from an inert substrate, without relevant UV exposure.'],
            ['code' => '11B', 'name' => 'Products with intended skin contact but minimal transfer of fragrance to skin from inert substrate with potential UV exposure', 'short_name' => 'Inert substrate, UV exposure', 'description' => 'Products with intended skin contact but minimal fragrance transfer from an inert substrate, with potential UV exposure.'],
            ['code' => '12', 'name' => 'Products not intended for direct skin contact, minimal or insignificant transfer to skin', 'short_name' => 'No direct skin contact', 'description' => 'Products not intended for direct skin contact, with minimal or insignificant transfer to skin.'],
        ];
    }
}
