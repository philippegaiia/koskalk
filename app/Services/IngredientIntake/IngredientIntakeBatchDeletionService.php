<?php

namespace App\Services\IngredientIntake;

use App\Enums\IngredientIntakeBatchStatus;
use App\Models\IngredientIntakeBatch;
use App\Services\IngredientEnrichment\IngredientEnrichmentBatchDeletionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

final class IngredientIntakeBatchDeletionService
{
    public function __construct(
        private readonly IngredientEnrichmentBatchDeletionService $enrichmentBatches,
    ) {}

    public function delete(IngredientIntakeBatch $batch): bool
    {
        $cleanup = DB::transaction(function () use ($batch): array {
            $lockedBatch = IngredientIntakeBatch::query()
                ->whereKey($batch->getKey())
                ->lockForUpdate()
                ->first();

            if (! $lockedBatch instanceof IngredientIntakeBatch) {
                throw ValidationException::withMessages([
                    'batch' => __('ingredient_intake_admin.validation.batch_not_found'),
                ]);
            }

            if ($lockedBatch->status === IngredientIntakeBatchStatus::Researching) {
                throw ValidationException::withMessages([
                    'batch' => __('ingredient_intake_admin.validation.batch_not_terminal'),
                ]);
            }

            $upload = $this->uploadDescriptor($lockedBatch);

            $enrichmentCleanup = null;
            if ($lockedBatch->ingredient_enrichment_batch_id !== null) {
                $enrichmentBatch = $lockedBatch->enrichmentBatch()->lockForUpdate()->first();

                if ($enrichmentBatch !== null) {
                    $enrichmentCleanup = $this->enrichmentBatches->deleteWithinTransaction($enrichmentBatch);
                }
            }

            if ($lockedBatch->delete() !== true) {
                throw new RuntimeException(__('ingredient_intake_admin.validation.batch_delete_failed'));
            }

            return [
                'enrichment' => $enrichmentCleanup,
                'upload' => $upload,
            ];
        }, attempts: 5);

        $success = true;
        if (is_array($cleanup['enrichment'] ?? null)) {
            $success = $this->enrichmentBatches->cleanupAfterCommit($cleanup['enrichment']) && $success;
        }

        return $this->cleanupUploadAfterCommit($cleanup['upload']) && $success;
    }

    /** @return array{disk:?string,path:?string} */
    private function uploadDescriptor(IngredientIntakeBatch $batch): array
    {
        return [
            'disk' => filled($batch->storage_disk) ? (string) $batch->storage_disk : null,
            'path' => filled($batch->storage_path) ? (string) $batch->storage_path : null,
        ];
    }

    /** @param array{disk:?string,path:?string} $upload */
    private function cleanupUploadAfterCommit(array $upload): bool
    {
        if ($upload['disk'] === null || $upload['path'] === null) {
            return true;
        }

        try {
            if (! Storage::disk($upload['disk'])->delete($upload['path'])) {
                throw new RuntimeException(__('ingredient_intake_admin.validation.upload_cleanup_failed'));
            }
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }

        return true;
    }
}
