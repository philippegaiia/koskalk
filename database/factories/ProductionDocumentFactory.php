<?php

namespace Database\Factories;

use App\Enums\ProductionDocumentType;
use App\Models\MediaAsset;
use App\Models\ProductionDocument;
use App\Models\StockLot;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductionDocument>
 */
class ProductionDocumentFactory extends Factory
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
            'media_asset_id' => MediaAsset::factory(),
            'documentable_type' => StockLot::class,
            'documentable_id' => StockLot::factory(),
            'type' => ProductionDocumentType::Other,
            'attached_by_user_id' => User::factory(),
            'note' => null,
        ];
    }
}
