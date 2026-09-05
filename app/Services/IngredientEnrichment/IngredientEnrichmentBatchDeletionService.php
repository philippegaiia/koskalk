<?php

namespace App\Services\IngredientEnrichment;

use App\Models\IngredientEnrichmentBatch;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class IngredientEnrichmentBatchDeletionService
{
    public function delete(IngredientEnrichmentBatch $batch): bool
    {
        $cleanup = DB::transaction(function () use ($batch): array {
            return $this->deleteWithinTransaction($batch);
        }, attempts: 5);

        return $this->cleanupAfterCommit($cleanup);
    }

    /**
     * Delete selected batches atomically and clean their private records after commit.
     *
     * @param  Collection<int, int>  $batchIds
     */
    public function deleteMany(Collection $batchIds): bool
    {
        $cleanups = DB::transaction(function () use ($batchIds): array {
            /** @var Collection<int, IngredientEnrichmentBatch> $lockedBatches */
            $lockedBatches = IngredientEnrichmentBatch::query()
                ->whereIn('id', $batchIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($lockedBatches->count() !== $batchIds->count()) {
                throw ValidationException::withMessages([
                    'batch' => __('ingredient_enrichment_admin.validation.batch_not_found'),
                ]);
            }

            if ($lockedBatches->contains(fn (IngredientEnrichmentBatch $batch): bool => ! $batch->status->isTerminal())) {
                throw ValidationException::withMessages([
                    'batch' => __('ingredient_enrichment_admin.validation.batch_not_terminal'),
                ]);
            }

            $cleanup = $lockedBatches
                ->map(fn (IngredientEnrichmentBatch $batch): array => $this->cleanupDescriptor($batch))
                ->all();

            foreach ($lockedBatches as $batch) {
                if ($batch->delete() !== true) {
                    throw new RuntimeException(__('ingredient_enrichment_admin.validation.batch_delete_failed'));
                }
            }

            return $cleanup;
        }, attempts: 5);

        $success = true;
        foreach ($cleanups as $cleanup) {
            $success = $this->cleanupAfterCommit($cleanup) && $success;
        }

        return $success;
    }

    /**
     * Delete a batch while an outer database transaction is active.
     *
     * The returned descriptor is cleaned only after the caller's outer
     * transaction commits.
     *
     * @return array{disk:string,directory:string,public_id:string,laravel_batch_id:?string}
     */
    public function deleteWithinTransaction(IngredientEnrichmentBatch $batch): array
    {
        $lockedBatch = IngredientEnrichmentBatch::query()
            ->whereKey($batch->getKey())
            ->lockForUpdate()
            ->first();

        if (! $lockedBatch instanceof IngredientEnrichmentBatch) {
            throw ValidationException::withMessages([
                'batch' => __('ingredient_enrichment_admin.validation.batch_not_found'),
            ]);
        }

        if (! $lockedBatch->status->isTerminal()) {
            throw ValidationException::withMessages([
                'batch' => __('ingredient_enrichment_admin.validation.batch_not_terminal'),
            ]);
        }

        $cleanup = $this->cleanupDescriptor($lockedBatch);

        if ($lockedBatch->delete() !== true) {
            throw new RuntimeException(__('ingredient_enrichment_admin.validation.batch_delete_failed'));
        }

        return $cleanup;
    }

    /**
     * @return array{disk:string,directory:string,public_id:string,laravel_batch_id:?string}
     */
    private function cleanupDescriptor(IngredientEnrichmentBatch $batch): array
    {
        $disk = config('ingredient-enrichment.batch_artifacts.disk', 'local');
        $directory = config('ingredient-enrichment.batch_artifacts.directory', 'ingredient-enrichment/batches');

        if (! is_string($disk) || ! is_string($directory)) {
            throw new RuntimeException(__('ingredient_enrichment_admin.validation.batch_artifact_cleanup_failed'));
        }

        return [
            'disk' => $disk,
            'directory' => $directory,
            'public_id' => (string) $batch->public_id,
            'laravel_batch_id' => filled($batch->laravel_batch_id) ? (string) $batch->laravel_batch_id : null,
        ];
    }

    /** @param array{disk:string,directory:string,public_id:string,laravel_batch_id:?string} $cleanup */
    public function cleanupAfterCommit(array $cleanup): bool
    {
        $success = true;

        try {
            $artifactDirectory = trim($cleanup['directory'], '/').'/'.$cleanup['public_id'];
            $filesystem = Storage::disk($cleanup['disk']);

            if ($filesystem->directoryExists($artifactDirectory) && ! $filesystem->deleteDirectory($artifactDirectory)) {
                throw new RuntimeException(__('ingredient_enrichment_admin.validation.batch_artifact_cleanup_failed'));
            }
        } catch (Throwable $exception) {
            report($exception);
            $success = false;
        }

        if ($cleanup['laravel_batch_id'] === null) {
            return $success;
        }

        try {
            Bus::findBatch($cleanup['laravel_batch_id'])?->delete();
        } catch (Throwable $exception) {
            report($exception);
            $success = false;
        }

        return $success;
    }
}
