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
            InterfaceTranslationSeeder::class,
            ProductFamilySeeder::class,
            ProductTypeSeeder::class,
            FattyAcidSeeder::class,
            AllergenCatalogSeeder::class,
            RegulatoryRegimeSeeder::class,
            SubstanceSeeder::class,
            IngredientFunctionSeeder::class,
            IfraProductCategorySeeder::class,
            PlanSeeder::class,
            IngredientCatalogSeeder::class,
            CarrierOilSeeder::class,
        ]);
    }
}
