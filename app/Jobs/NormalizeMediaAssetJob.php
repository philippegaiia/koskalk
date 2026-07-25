<?php

namespace App\Jobs;

use App\Models\MediaAsset;
use App\Services\MediaAssetProcessingService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

class NormalizeMediaAssetJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public bool $failOnTimeout = true;

    public int $uniqueFor = 120;

    public function __construct(
        public readonly int $mediaAssetId,
        public readonly string $processingToken,
    ) {}

    public function handle(MediaAssetProcessingService $processor): void
    {
        $asset = MediaAsset::query()->find($this->mediaAssetId);

        if (! $asset instanceof MediaAsset) {
            return;
        }

        try {
            $processor->process($asset, $this->processingToken);
        } catch (Throwable $exception) {
            report($exception);
            $processor->markFailed($asset, $this->processingToken, $exception);
        }
    }

    public function failed(Throwable $exception): void
    {
        $asset = MediaAsset::query()->find($this->mediaAssetId);

        if (! $asset instanceof MediaAsset) {
            return;
        }

        app(MediaAssetProcessingService::class)->markFailed($asset, $this->processingToken, $exception);
    }

    public function uniqueId(): string
    {
        return "media-asset:{$this->mediaAssetId}:{$this->processingToken}";
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
