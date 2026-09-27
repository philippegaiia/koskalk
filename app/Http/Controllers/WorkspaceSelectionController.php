<?php

namespace App\Http\Controllers;

use App\Http\Requests\ListWorkspaceSelectionRequest;
use App\Http\Requests\UpdateWorkspaceSelectionRequest;
use App\Services\WorkspaceAuthorization;
use App\Services\WorkspaceSelectionService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class WorkspaceSelectionController extends Controller
{
    public function index(ListWorkspaceSelectionRequest $request, WorkspaceSelectionService $selection, WorkspaceAuthorization $authorization): View
    {
        abort_unless(config('workspaces.collaboration_enabled'), 404);
        $search = trim((string) $request->validated('search', ''));
        $page = (int) ($request->validated('workspace-selection-page') ?? 1);

        return view('dashboard.workspace-selection', [
            'workspaces' => $selection->accessibleWorkspaces($request->user(), $search, $page)->appends(['search' => $search]),
            'currentWorkspace' => $authorization->selectedWorkspace($request->user()),
            'search' => $search,
        ]);
    }

    public function update(UpdateWorkspaceSelectionRequest $request, WorkspaceSelectionService $selection): RedirectResponse
    {
        $selection->select($request->user(), $request->validated('workspace_public_id'));

        return redirect()->route('dashboard');
    }
}
