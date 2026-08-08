<?php

namespace App\Jobs;

use App\Enums\MediaAssetStatus;
use App\Models\MediaAsset;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Spatie\MediaLibrary\Conversions\FileManipulator;

class RegenerateMediaAssetConversionsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public int $uniqueFor = 120;

    public function __construct(public readonly int $mediaAssetId) {}

    public function handle(FileManipulator $fileManipulator): void
    {
        $asset = MediaAsset::query()->find($this->mediaAssetId);
        $media = $asset?->getFirstMedia('master');

        if (! $asset instanceof MediaAsset || $asset->status !== MediaAssetStatus::Ready || $media === null) {
            return;
        }

        $fileManipulator->createDerivedFiles(
            $media,
            ['catalog', 'thumbnail', 'icon'],
            onlyMissing: false,
        );
    }

    public function uniqueId(): string
    {
        return "media-asset:{$this->mediaAssetId}";
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->uniqueId()))
                ->dontRelease()
                ->expireAfter($this->timeout + 30),
        ];
    }
}
