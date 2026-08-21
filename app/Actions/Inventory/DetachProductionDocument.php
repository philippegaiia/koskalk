<?php

namespace App\Actions\Inventory;

use App\Models\ProductionDocument;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ProductionBenchAccess;
use Illuminate\Support\Facades\DB;

class DetachProductionDocument
{
    public function __construct(private readonly ProductionBenchAccess $access) {}

    public function handle(User $actor, ProductionDocument $document): void
    {
        $workspace = Workspace::withoutGlobalScopes()->find($document->workspace_id);

        abort_unless($workspace instanceof Workspace, 404);

        $this->access->assertWritable($actor, $workspace);

        DB::transaction(fn (): bool => (bool) $document->delete());
    }
}
