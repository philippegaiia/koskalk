<?php

namespace App\Console\Commands;

use App\MediaAssetStatus;
use App\Models\MediaAsset;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('media:fail-stale-assets {--age=15 : Minimum processing age in minutes}')]
#[Description('Release quota held by media assets whose processing did not complete')]
class FailStaleMediaAssets extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $age = (int) $this->option('age');

        if ($age < 6) {
            $this->error('The stale age must be at least 6 minutes.');

            return self::FAILURE;
        }

        $cutoff = now()->subMinutes($age);
        $failedCount = 0;

        MediaAsset::query()
            ->where('status', MediaAssetStatus::Processing)
            ->where('updated_at', '<', $cutoff)
            ->select('id')
            ->chunkById(100, function ($assets) use ($cutoff, &$failedCount): void {
                foreach ($assets as $asset) {
                    $failedCount += MediaAsset::query()
                        ->whereKey($asset->id)
                        ->where('status', MediaAssetStatus::Processing)
                        ->where('updated_at', '<', $cutoff)
                        ->update([
                            'status' => MediaAssetStatus::Failed,
                            'progress' => 0,
                            'processing_stage' => null,
                            'failure_code' => 'processing_timeout',
                            'failure_reason' => 'Processing did not complete. Retry this image or remove it.',
                        ]);
                }
            });

        $this->info("{$failedCount} stale media asset marked failed.");

        return self::SUCCESS;
    }
}
