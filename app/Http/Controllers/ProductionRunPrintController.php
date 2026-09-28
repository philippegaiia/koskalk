<?php

namespace App\Http\Controllers;

use App\Enums\ProductionRunStatus;
use App\Enums\WorkspaceModule;
use App\Models\ProductionRun;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Production\ProductionRunPrintDataBuilder;
use App\Services\ProductionBenchAccess;
use App\Services\WorkspaceAuthorization;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class ProductionRunPrintController extends Controller
{
    public function __invoke(
        Request $request,
        string $productionRun,
        ProductionRunPrintDataBuilder $builder,
        WorkspaceAuthorization $authorization,
        ProductionBenchAccess $access,
    ): View {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);
        $workspace = $authorization->selectedWorkspace($actor);
        abort_unless($workspace instanceof Workspace, 403);
        $origin = Workspace::withoutGlobalScopes()->where('public_id', $request->string('workspace')->toString())->first();
        abort_unless($origin instanceof Workspace && $authorization->canAccessWorkspace($actor, $origin), 403);
        abort_if($origin->isNot($workspace), 409, __('production_bench.output.validation.workspace_changed'));
        $access->assertReadable($actor, $workspace);
        abort_unless($authorization->canViewModule($actor, $workspace->id, WorkspaceModule::ProductionBench), 403);
        abort_unless($access->isActive($workspace) || $access->isReadOnly($workspace), 403);
        $production = ProductionRun::query()->where('workspace_id', $workspace->id)->where('public_id', $productionRun)->firstOrFail();
        abort_if($production->status === ProductionRunStatus::Cancelled, 409);

        return view('production-bench.production.run-print', [
            'document' => $builder->build($production),
            'generatedAt' => now()->toIso8601String(),
        ]);
    }
}
