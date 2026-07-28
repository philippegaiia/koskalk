<?php

namespace Database\Factories;

use App\GoodsReceiptStatus;
use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GoodsReceipt>
 */
class GoodsReceiptFactory extends Factory
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
            'purchase_order_id' => PurchaseOrder::factory(),
            'delivery_reference' => fake()->bothify('DEL-####'),
            'received_at' => now()->toDateString(),
            'status' => GoodsReceiptStatus::Posted,
            'notes' => null,
            'received_by_user_id' => User::factory(),
            'idempotency_key' => fake()->uuid(),
            'reversed_at' => null,
            'reversed_by_user_id' => null,
        ];
    }
}
