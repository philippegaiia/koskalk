<?php

namespace Database\Factories;

use App\GoodsReceiptSource;
use App\GoodsReceiptStatus;
use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
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
            'purchase_order_id' => PurchaseOrder::factory(),
            'supplier_id' => function (array $attributes): int|Factory {
                if ($attributes['purchase_order_id'] === null) {
                    return Supplier::factory();
                }

                return PurchaseOrder::query()->findOrFail($attributes['purchase_order_id'])->supplier_id;
            },
            'workspace_id' => function (array $attributes): int {
                if ($attributes['purchase_order_id'] !== null) {
                    return PurchaseOrder::query()->findOrFail($attributes['purchase_order_id'])->workspace_id;
                }

                return Supplier::query()->findOrFail($attributes['supplier_id'])->workspace_id;
            },
            'source' => GoodsReceiptSource::PurchaseOrder,
            'delivery_reference' => fake()->bothify('DEL-####'),
            'received_at' => now()->toDateString(),
            'status' => GoodsReceiptStatus::Posted,
            'notes' => null,
            'received_by_user_id' => User::factory(),
            'idempotency_key' => fake()->uuid(),
            'reversed_at' => null,
            'reversed_by_user_id' => null,
            'reversal_reason' => null,
        ];
    }

    public function direct(): static
    {
        return $this->state(fn (): array => [
            'purchase_order_id' => null,
            'source' => GoodsReceiptSource::Direct,
        ]);
    }
}
