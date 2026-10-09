<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReleaseProductionEditingRequest;
use App\Models\ProductionRun;
use App\Services\ProductionBenchAccess;
use App\Services\ProductionEditingService;
use App\Services\WorkspaceAuthorization;
use Illuminate\Http\JsonResponse;

class ProductionEditingController extends Controller
{
    public function release(ReleaseProductionEditingRequest $request, WorkspaceAuthorization $authorization, ProductionBenchAccess $access, ProductionEditingService $editing): JsonResponse
    {
        $actor = $request->user()->fresh();
        $workspace = $authorization->selectedWorkspace($actor);
        abort_unless($workspace !== null, 403);
        $access->assertReadable($actor, $workspace);
        $data = $request->validated();
        $ids = ProductionRun::query()->where('workspace_id', $workspace->id)->whereIn('public_id', $data['production_ids'])->orderBy('id')->pluck('id')->all();
        if ($ids !== []) {
            $editing->release($actor, $workspace->id, $ids, $data['token']);
        }

        return response()->json(['ok' => true]);
    }
}
