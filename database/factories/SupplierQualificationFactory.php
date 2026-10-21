<?php

namespace Database\Factories;

use App\Enums\SupplierQualificationStatus;
use App\Models\Ingredient;
use App\Models\Supplier;
use App\Models\SupplierQualification;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupplierQualification>
 */
class SupplierQualificationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'ingredient_id' => Ingredient::factory(),
            'supplier_id' => fn (array $attributes): int => Supplier::factory()->create(['workspace_id' => $attributes['workspace_id']])->id,
            'supplier_reference' => '',
            'status' => SupplierQualificationStatus::Valide,
            'validated_by_user_id' => null,
            'validated_at' => now(),
            'notes' => null,
        ];
    }
}
