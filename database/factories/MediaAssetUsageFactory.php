<?php

namespace Database\Factories;

use App\MediaAssetUsageRole;
use App\Models\MediaAsset;
use App\Models\MediaAssetUsage;
use App\Models\Recipe;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MediaAssetUsage>
 */
class MediaAssetUsageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'media_asset_id' => MediaAsset::factory(),
            'usable_type' => Recipe::class,
            'usable_id' => Recipe::factory(),
            'role' => MediaAssetUsageRole::RecipeFeatured,
        ];
    }
}
