<?php

namespace Database\Factories;

use App\Enums\MediaAssetStatus;
use App\Enums\MediaAssetType;
use App\Models\MediaAsset;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<MediaAsset>
 */
class MediaAssetFactory extends Factory
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
            'uploaded_by_user_id' => User::factory(),
            'status' => MediaAssetStatus::Processing,
            'type' => MediaAssetType::Image,
            'original_filename' => fake()->word().'.jpg',
            'original_mime_type' => 'image/jpeg',
            'original_size' => fake()->numberBetween(10_000, 2_000_000),
            'focal_x' => 50,
            'focal_y' => 50,
            'progress' => 0,
            'processing_stage' => 'queued',
            'processing_token' => (string) Str::uuid(),
        ];
    }

    public function pdf(): static
    {
        return $this->state(fn (): array => [
            'type' => MediaAssetType::Pdf,
            'original_filename' => fake()->word().'.pdf',
            'original_mime_type' => 'application/pdf',
        ]);
    }

    public function ready(): static
    {
        return $this->state(fn (): array => [
            'status' => MediaAssetStatus::Ready,
            'progress' => 100,
            'processing_stage' => null,
            'failure_code' => null,
            'failure_reason' => null,
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (): array => [
            'status' => MediaAssetStatus::Failed,
            'progress' => 0,
            'processing_stage' => null,
            'failure_code' => 'processing_failed',
            'failure_reason' => 'The image could not be processed.',
        ]);
    }
}
