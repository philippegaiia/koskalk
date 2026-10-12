<?php

namespace App\Services;

use App\Enums\FileExportType;
use App\Enums\WorkspaceModule;
use App\Jobs\GenerateFileExport;
use App\Models\FileExport;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class QueuedFileExports
{
    public function __construct(
        private readonly IngredientFileExporter $ingredients,
        private readonly PackagingFileExporter $packaging,
        private readonly InventoryFileExporter $inventory,
        private readonly WorkspaceAuthorization $authorization,
        private readonly ProductionBenchAccess $productionAccess,
    ) {}

    /** @param array<string, mixed> $parameters */
    public function request(User $actor, FileExportType $type, array $parameters): FileExport
    {
        $workspace = match ($type) {
            FileExportType::Ingredients => $this->ingredients->validate($actor, $parameters),
            FileExportType::Packaging => $this->packaging->build($actor, $parameters, true),
            FileExportType::InventoryMaterials => $this->inventory->materials($actor, $parameters, true),
            FileExportType::StockLots => $this->inventory->stockLots($actor, $parameters, true),
        };

        return DB::transaction(function () use ($actor, $workspace, $type, $parameters): FileExport {
            Workspace::query()->whereKey($workspace->id)->lockForUpdate()->firstOrFail();
            if (FileExport::query()->where('workspace_id', $workspace->id)
                ->whereIn('status', ['pending', 'processing'])->where('expires_at', '>', now())->count()
                >= (int) config('exports.maximum_pending_per_workspace', 3)) {
                throw ValidationException::withMessages(['export' => __('ingredients.exports.ui.busy')]);
            }

            $actor = $actor->fresh();
            abort_unless($this->authorization->selectedWorkspace($actor)?->id === $workspace->id
                && $this->authorization->canAccessWorkspace($actor, $workspace), 409);
            if (in_array($type, [FileExportType::InventoryMaterials, FileExportType::StockLots], true)) {
                $this->productionAccess->assertReadable($actor, $workspace);
                abort_unless($this->authorization->canViewModule($actor, $workspace->id, WorkspaceModule::ProductionBench), 403);
            }

            $export = FileExport::query()->create([
                'user_id' => $actor->id,
                'workspace_id' => $workspace->id,
                'type' => $type,
                'status' => 'pending',
                'parameters' => $parameters,
                'locale' => app()->getLocale(),
                'disk' => config('exports.disk', 'local'),
                'expires_at' => now()->addHours((int) config('exports.retention_hours', 24)),
            ]);
            GenerateFileExport::dispatch($export->id)->afterCommit();

            return $export;
        }, attempts: 5);
    }

    public function trim(FileExport $export): void
    {
        FileExport::query()->where('workspace_id', $export->workspace_id)->where('status', 'ready')
            ->orderByDesc('id')->get()
            ->skip(max(1, (int) config('exports.maximum_retained_per_workspace', 10)))
            ->each(fn (FileExport $expired) => $expired->prune());
    }

    public function authorize(User $actor, FileExport $export): Workspace
    {
        abort_unless($export->user_id === $actor->id, 404);
        abort_if($export->expires_at->isPast(), 410);
        $workspace = Workspace::withoutGlobalScopes()->find($export->workspace_id);
        abort_unless($workspace instanceof Workspace && $this->authorization->canAccessWorkspace($actor, $workspace), 403);
        abort_unless($this->authorization->selectedWorkspace($actor)?->id === $workspace->id, 409);
        if (in_array($export->type, [FileExportType::InventoryMaterials, FileExportType::StockLots], true)) {
            $this->productionAccess->assertReadable($actor, $workspace);
            abort_unless($this->authorization->canViewModule($actor, $workspace->id, WorkspaceModule::ProductionBench), 403);
        }

        return $workspace;
    }

    /** @return array{status: string, status_url: string, download_url: ?string, error: ?string} */
    public function status(FileExport $export): array
    {
        return [
            'status' => $export->status,
            'status_url' => route('file-exports.status', $export),
            'download_url' => $export->status === 'ready' ? route('file-exports.download', $export) : null,
            'error' => $export->error,
        ];
    }

    /** @return array{path: string, filename: string} */
    public function build(FileExport $export, User $actor, string $directory): array
    {
        $this->authorize($actor, $export);

        return match ($export->type) {
            FileExportType::Ingredients => $this->ingredients->build($actor, $export->parameters, directory: $directory),
            FileExportType::Packaging => $this->packaging->build($actor, $export->parameters, directory: $directory),
            FileExportType::InventoryMaterials => $this->inventory->materials($actor, $export->parameters, directory: $directory),
            FileExportType::StockLots => $this->inventory->stockLots($actor, $export->parameters, directory: $directory),
        };
    }
}
