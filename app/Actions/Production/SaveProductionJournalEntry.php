<?php

namespace App\Actions\Production;

use App\Enums\ProductionRunStatus;
use App\Models\ProductionRun;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ProductionBenchAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaveProductionJournalEntry
{
    public function __construct(
        private readonly ProductionBenchAccess $access,
    ) {}

    /**
     * Append a journal entry to a production. Entries are immutable and the
     * journal becomes read-only once the run is completed, aborted, or
     * cancelled.
     */
    public function handle(User $actor, ProductionRun $production, string $body): ProductionRun
    {
        $workspace = $production->workspace;

        if (! $workspace instanceof Workspace) {
            throw ValidationException::withMessages([
                'production' => 'The production workspace could not be found.',
            ]);
        }

        $this->access->assertWritable($actor, $workspace);

        $body = trim($body);

        if ($body === '' || mb_strlen($body) > 20000) {
            throw ValidationException::withMessages([
                'body' => 'A journal entry between 1 and 20000 characters is required.',
            ]);
        }

        return DB::transaction(function () use ($actor, $body, $production): ProductionRun {
            $lockedWorkspace = Workspace::withoutGlobalScopes()
                ->lockForUpdate()
                ->find($production->workspace_id);
            $lockedProduction = ProductionRun::query()
                ->lockForUpdate()
                ->findOrFail($production->id);

            if (in_array($lockedProduction->status, [
                ProductionRunStatus::Completed,
                ProductionRunStatus::Aborted,
                ProductionRunStatus::Cancelled,
            ], true)) {
                throw ValidationException::withMessages([
                    'production' => 'The journal is read-only once the production is closed.',
                ]);
            }

            $lockedProduction->journalEntries()->create([
                'body' => $body,
                'created_by_user_id' => $actor->id,
            ]);

            return $lockedProduction->fresh(['journalEntries']);
        }, attempts: 5);
    }
}
