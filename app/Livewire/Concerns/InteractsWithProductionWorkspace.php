<?php

namespace App\Livewire\Concerns;

use App\Models\Workspace;
use App\Services\ProductionBenchAccess;
use Livewire\Attributes\Locked;

trait InteractsWithProductionWorkspace
{
    #[Locked]
    public ?int $productionWorkspaceId = null;

    private ?Workspace $productionWorkspace = null;

    public function mountInteractsWithProductionWorkspace(): void
    {
        $this->productionWorkspace();
    }

    public function hydrateInteractsWithProductionWorkspace(): void
    {
        $this->productionWorkspace();
    }

    private function productionWorkspace(): Workspace
    {
        if ($this->productionWorkspace instanceof Workspace) {
            return $this->productionWorkspace;
        }

        $workspace = $this->user()->company(fresh: true) ?? abort(404);

        abort_if($this->productionWorkspaceId !== null && $this->productionWorkspaceId !== $workspace->id, 403);
        app(ProductionBenchAccess::class)->assertReadable($this->user(), $workspace);
        $this->productionWorkspaceId = $workspace->id;

        return $this->productionWorkspace = $workspace;
    }
}
