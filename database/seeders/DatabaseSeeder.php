<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            SupportedLocaleSeeder::class,
            ProductFamilySeeder::class,
            IfraProductCategorySeeder::class,
            IfraAmendmentSeeder::class,
            ProductTaxonomySeeder::class,
            ProductTypeIfraCategorySeeder::class,
            FattyAcidSeeder::class,
            AllergenCatalogSeeder::class,
            RegulatoryRegimeSeeder::class,
            SubstanceSeeder::class,
            IngredientFunctionSeeder::class,
            PlanSeeder::class,
        ]);
    }
}
