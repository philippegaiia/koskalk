<?php

namespace App\Console\Commands;

use App\Actions\Production\BackfillProductionFormulaSnapshot;
use App\Models\ProductionRun;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Throwable;

class BackfillProductionFormulaSnapshots extends Command
{
    protected $signature = 'production:backfill-formula-snapshots
        {--production-run= : Backfill one numeric production-run ID}
        {--chunk=100 : Number of productions per chunk}';

    protected $description = 'Backfill independent formula snapshots for legacy production runs';

    public function handle(): int
    {
        $query = ProductionRun::query()
            ->whereNull('formula_snapshot_completed_at')
            ->orderBy('id');

        $singleRunId = $this->option('production-run');

        if ($singleRunId !== null) {
            $query->whereKey((int) $singleRunId);
        }

        $completed = 0;
        $skipped = 0;
        $failedIds = [];

        $query->chunkById((int) $this->option('chunk'), function (Collection $runs) use (&$completed, &$skipped, &$failedIds): void {
            foreach ($runs as $run) {
                if ($run->formula_snapshot_completed_at !== null) {
                    $skipped++;

                    continue;
                }

                try {
                    if (app(BackfillProductionFormulaSnapshot::class)->handle($run)) {
                        $completed++;
                    } else {
                        $failedIds[] = $run->id;
                    }
                } catch (Throwable) {
                    $failedIds[] = $run->id;
                }
            }
        });

        $this->info("Completed: {$completed}, skipped: {$skipped}, failed: ".count($failedIds));

        if ($failedIds !== []) {
            $this->error('Incomplete production-run IDs: '.implode(', ', $failedIds));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
