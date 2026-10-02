<?php

namespace App\Actions\Production;

use App\Enums\ProductionRunStatus;
use App\Models\ProductionRun;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Production\ProductionEditingContext;
use App\Services\Production\ProductionMutationResult;
use App\Services\ProductionBenchAccess;
use App\Services\ProductionMutationGuard;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class SaveProductionJournalEntry
{
    public function __construct(private readonly ProductionMutationGuard $guard,
        private readonly ProductionBenchAccess $access,
    ) {}

    /**
     * Append a journal entry to a production. Entries are immutable and the
     * journal becomes read-only once the run is completed, aborted, or
     * cancelled.
     */
    public function handle(User $actor, ProductionRun $production, string $body,
        ?ProductionEditingContext $editing = null,
    ): ProductionRun {
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

        return $this->guard->run($actor, [$production->id], $editing, function (User $actor, Workspace $lockedWorkspace, Collection $productions) use ($body, $production): ProductionMutationResult {
            $this->access->assertWritable($actor, $lockedWorkspace);
            $lockedProduction = $productions[$production->id];

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

            return new ProductionMutationResult($lockedProduction->fresh(['journalEntries']), [$lockedProduction->id]);
        });
    }
}
