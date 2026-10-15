<?php

namespace Database\Seeders;

use App\Enums\SoapProcess;
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
                'soap_process' => SoapProcess::Bar,
                'is_active' => true,
                'description' => 'Cold-process and related soap formulations calculated from the initial oil phase.',
            ]
        );

        ProductFamily::query()->updateOrCreate(
            ['slug' => 'cosmetic'],
            [
                'name' => 'Cosmetic',
                'calculation_basis' => 'total_formula',
                'is_active' => true,
                'description' => 'Non-saponified cosmetic formulations calculated from the total formula weight.',
            ]
        );

        // Create-only and inactive until the liquid soap paste bench ships, so
        // a rerun never reopens or reshapes the family once it is live.
        ProductFamily::query()->firstOrCreate(
            ['slug' => 'liquid-soap'],
            [
                'name' => 'Liquid Soap',
                'calculation_basis' => 'initial_oils',
                'soap_process' => SoapProcess::LiquidPaste,
                'is_active' => false,
                'description' => 'Liquid soap made by the paste method: a KOH paste diluted, optionally neutralized, then diluted to its final concentration.',
            ]
        );
    }
}
