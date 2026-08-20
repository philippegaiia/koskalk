<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class ProductTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->call(ProductTaxonomySeeder::class);
    }
}
