<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillProductionBatchWorkspaces extends Command
{
    protected $signature = 'production-batches:backfill-workspaces {--apply : Apply unambiguous assignments; otherwise preview only}';

    protected $description = 'Preview or assign legacy batch workspaces from consistent surviving formula provenance';

    public function handle(): int
    {
        $assigned = 0;
        $unresolved = 0;

        DB::table('production_batches')->whereNull('workspace_id')->orderBy('id')->chunkById(200, function ($batches) use (&$assigned, &$unresolved): void {
            foreach ($batches as $batch) {
                $workspaceId = $this->workspaceFor($batch);

                if ($workspaceId === null) {
                    $unresolved++;

                    continue;
                }

                $this->line("Batch {$batch->id} → workspace {$workspaceId}");

                if ($this->option('apply')) {
                    $assigned += DB::transaction(function () use ($batch, $workspaceId): int {
                        DB::table('workspaces')->where('id', $workspaceId)->lockForUpdate()->first();
                        $current = DB::table('production_batches')->where('id', $batch->id)->lockForUpdate()->first();

                        if ($current === null || $current->workspace_id !== null || $this->workspaceFor($current) !== $workspaceId) {
                            return 0;
                        }

                        return DB::table('production_batches')->where('id', $batch->id)->whereNull('workspace_id')->update(['workspace_id' => $workspaceId]);
                    }, attempts: 5);
                } else {
                    $assigned++;
                }
            }
        });

        $this->info(($this->option('apply') ? 'Assigned' : 'Would assign').": {$assigned}; unresolved (unchanged): {$unresolved}.");

        return self::SUCCESS;
    }

    private function workspaceFor(object $batch): ?int
    {
        $recipe = DB::table('recipes')->where('id', $batch->recipe_id)->first();

        if ($recipe === null || $recipe->workspace_id === null || $recipe->owner_type !== 'workspace' || (int) $recipe->owner_id !== (int) $recipe->workspace_id) {
            return null;
        }

        if ($batch->recipe_version_id !== null) {
            $version = DB::table('recipe_versions')->where('id', $batch->recipe_version_id)->first();

            if ($version === null || (int) $version->recipe_id !== (int) $recipe->id || $version->workspace_id !== $recipe->workspace_id || $version->owner_type !== $recipe->owner_type || $version->owner_id !== $recipe->owner_id) {
                return null;
            }
        }

        return (int) $recipe->workspace_id;
    }
}
