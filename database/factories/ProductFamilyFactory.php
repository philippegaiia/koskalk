<?php

namespace Database\Factories;

use App\Enums\SoapProcess;
use App\Models\ProductFamily;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ProductFamily>
 */
class ProductFamilyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'name' => Str::title($name),
            'slug' => Str::slug($name),
            'calculation_basis' => 'initial_oils',
            'soap_process' => SoapProcess::Bar,
            'is_active' => true,
            'description' => fake()->sentence(),
        ];
    }

    public function liquidSoapPaste(): static
    {
        return $this->state([
            'name' => 'Liquid Soap',
            'slug' => 'liquid-soap',
            'calculation_basis' => 'initial_oils',
            'soap_process' => SoapProcess::LiquidPaste,
        ]);
    }
}
