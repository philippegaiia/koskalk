<?php

namespace Database\Factories;

use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Workspace;
use App\PurchaseOrderStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PurchaseOrder>
 */
class PurchaseOrderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'supplier_id' => Supplier::factory(),
            'reference' => 'PO-'.fake()->unique()->numerify('######'),
            'status' => PurchaseOrderStatus::Draft,
            'ordered_at' => null,
            'expected_at' => null,
            'currency' => 'EUR',
            'notes' => null,
            'created_by_user_id' => User::factory(),
        ];
    }
}
