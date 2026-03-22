<?php

namespace Database\Seeders;

use App\Models\ProductFamily;
use Illuminate\Database\Seeder;

class ProductFamilySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        ProductFamily::query()->updateOrCreate(
            ['slug' => 'soap'],
            [
                'name' => 'Soap',
                'calculation_basis' => 'initial_oils',
                'is_active' => true,
                'description' => 'Cold-process and related soap formulations calculated from the initial oil phase.',
            ]
        );
    }
}
