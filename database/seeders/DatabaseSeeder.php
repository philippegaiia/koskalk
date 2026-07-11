<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

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

        User::query()->updateOrCreate(
            ['email' => 'test@example.com'],
            [
                'name' => 'Test User',
                'is_admin' => true,
                'password' => Hash::make('password'),
            ]
        );
    }
}
