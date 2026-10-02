<?php

namespace App\Livewire\Concerns;

use App\Models\ProductionRun;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\Production\ProductionEditingContext;
use App\Services\ProductionBenchAccess;
use App\Services\ProductionEditingService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Renderless;

trait InteractsWithProductionEditing
{
    #[Locked]
    public array $editingProductionIds = [];

    #[Locked]
    public array $editingPublicIds = [];

    #[Locked]
    public string $editingToken = '';

    #[Locked]
    public array $editingExpectedRevisions = [];

    #[Locked]
    public bool $editingOwnsLease = false;

    #[Locked]
    public array $editingState = [];

    #[Locked]
    public array $editingPresentation = [];

    #[Locked]
    public ?array $editingPendingReload = null;

    private array $productionCommandAcknowledgment = [];

    /** @param list<int> $ids */
    private function initializeProductionEditing(array $ids): void
    {
        $this->editingProductionIds = collect($ids)->unique()->sort()->values()->all();
        $this->editingToken = (string) Str::uuid();
        $this->readProductionSnapshot(initialize: true);
        $this->observeProductionEditing();
    }

    #[Renderless]
    public function beginEditing(): array
    {
        $this->resetErrorBag();

        return $this->acceptProductionEditing(app(ProductionEditingService::class)->acquire(
            $this->user(), $this->workspace()->id, $this->editingExpectedRevisions, $this->editingToken,
        ));
    }

    #[Renderless]
    public function pollEditing(): array
    {
        return $this->observeProductionEditing();
    }

    #[Renderless]
    public function heartbeatEditing(): array
    {
        try {
            return $this->acceptProductionEditing(app(ProductionEditingService::class)->heartbeat(
                $this->user(), $this->workspace()->id, $this->editingExpectedRevisions, $this->editingToken,
            ));
        } catch (ValidationException) {
            return $this->observeProductionEditing();
        }
    }

    #[Renderless]
    public function takeOverProductionEditing(string $reason): array
    {
        try {
            return $this->acceptProductionEditing(app(ProductionEditingService::class)->takeover(
                $this->user(), $this->workspace()->id, $this->editingExpectedRevisions, $this->editingToken, $reason,
            ));
        } catch (ValidationException $exception) {
            return [...$this->observeProductionEditing(), 'errors' => $exception->errors()];
        }
    }

    #[Renderless]
    public function finishEditing(): array
    {
        app(ProductionEditingService::class)->release($this->user(), $this->workspace()->id, $this->editingProductionIds, $this->editingToken);
        $this->editingOwnsLease = false;

        return $this->observeProductionEditing();
    }

    #[Renderless]
    public function reloadProductionEditing(): array
    {
        $snapshot = $this->captureProductionSnapshot();
        $this->editingPendingReload = $snapshot === null ? null : ['id' => (string) Str::uuid(), ...$snapshot];

        return $this->editingPendingReload === null
            ? ['state' => $this->observeProductionEditing()]
            : collect($this->editingPendingReload)->only(['id', 'groups', 'revisions'])->all();
    }

    public function acceptProductionReload(string $receiptId): array
    {
        abort_unless($this->editingPendingReload !== null && $this->editingPendingReload['id'] === $receiptId, 422);
        $snapshot = $this->editingPendingReload;
        if ($this->captureProductionSnapshot() === null) {
            $this->editingPendingReload = null;

            return ['accepted' => false, 'state' => $this->observeProductionEditing()];
        }
        $this->applyProductionSnapshot($snapshot, initialize: true);
        $this->editingPendingReload = null;
        $this->resetErrorBag();

        return ['accepted' => true, 'state' => $this->observeProductionEditing()];
    }

    public function refreshProductionPresentation(): void
    {
        $this->readProductionSnapshot(initialize: false);
    }

    private function productionEditingContext(): ProductionEditingContext
    {
        return new ProductionEditingContext($this->workspace()->id, $this->editingToken, $this->editingExpectedRevisions);
    }

    private function acknowledgeProductionMutation(ProductionEditingContext $context): void
    {
        $this->productionCommandAcknowledgment = $context->acknowledgedRevisions();
        foreach ($this->productionCommandAcknowledgment as $id => $revision) {
            if ($revision !== null) {
                $this->editingExpectedRevisions[$id] = $revision;
            }
        }
        $this->readProductionSnapshot(initialize: false);
    }

    private function assertProductionEditingBeforeUpload(): void
    {
        app(ProductionEditingService::class)->withLocked($this->user(), $this->workspace()->id, $this->editingProductionIds,
            function (User $actor, Workspace $workspace, Collection $productions): void {
                foreach ($productions as $production) {
                    app(ProductionEditingService::class)->assertRevision($production, $this->editingExpectedRevisions[$production->id]);
                    app(ProductionEditingService::class)->assertLease($production, $actor, $this->editingToken);
                }
            });
    }

    private function observeProductionEditing(): array
    {
        $state = app(ProductionEditingService::class)->status($this->user(), $this->workspace()->id, $this->editingProductionIds, $this->editingToken);
        if ($state['status'] !== 'unavailable') {
            foreach ($this->editingExpectedRevisions as $id => $revision) {
                if (($state['productions'][$id]['revision'] ?? null) !== $revision) {
                    $state['status'] = 'stale';
                    break;
                }
            }
        }

        return $this->acceptProductionEditing($state);
    }

    private function acceptProductionEditing(array $state): array
    {
        $this->editingState = $state;
        $this->editingOwnsLease = $state['status'] === 'acquired';

        return $state;
    }

    private function readProductionSnapshot(bool $initialize): void
    {
        $snapshot = $this->captureProductionSnapshot();
        if ($snapshot !== null) {
            $this->applyProductionSnapshot($snapshot, $initialize);
        }
    }

    private function applyProductionSnapshot(array $snapshot, bool $initialize): void
    {
        $this->editingPresentation = $snapshot['presentation'];
        if ($initialize) {
            $this->editingExpectedRevisions = $snapshot['revisions'];
            $this->editingPublicIds = $snapshot['publicIds'];
            foreach ($snapshot['groups'] as $fields) {
                foreach ($fields as $field => $value) {
                    $this->{$field} = $value;
                }
            }
        }
    }

    /** The read lock pairs a complete form snapshot with its baseline without a workspace write fence. */
    private function captureProductionSnapshot(): ?array
    {
        return DB::transaction(function (): ?array {
            $actor = User::withoutGlobalScopes()->sharedLock()->findOrFail($this->user()->id);
            $workspace = Workspace::withoutGlobalScopes()->sharedLock()->findOrFail($this->workspace()->id);
            WorkspaceMember::withoutGlobalScopes()->where('workspace_id', $workspace->id)->where('user_id', $actor->id)->sharedLock()->get();
            app(ProductionBenchAccess::class)->assertReadable($actor, $workspace);
            $productions = $this->productionSnapshotQuery()->where('workspace_id', $workspace->id)
                ->whereIn('id', $this->editingProductionIds)->orderBy('id')->sharedLock()->get()->keyBy('id');
            if ($productions->count() !== count($this->editingProductionIds)) {
                $this->editingOwnsLease = false;
                $this->editingState['status'] = 'unavailable';

                return null;
            }

            return [
                'presentation' => $productions->map(fn (ProductionRun $production): array => $this->productionModelSnapshot($production))->all(),
                'revisions' => $productions->map(fn (ProductionRun $production): int => $production->edit_revision)->all(),
                'publicIds' => $productions->pluck('public_id')->values()->all(),
                'groups' => $this->productionDraftGroupsFromSnapshot($productions),
            ];
        });
    }

    private function productionModelSnapshot(Model $model): array
    {
        $relations = [];
        foreach ($model->getRelations() as $key => $value) {
            $relations[$key] = $value instanceof Model ? ['one' => $this->productionModelSnapshot($value)]
                : ($value instanceof Collection ? ['many' => $value->map(fn (Model $child): array => $this->productionModelSnapshot($child))->all()] : null);
        }

        return ['class' => $model::class, 'attributes' => array_diff_key($model->getAttributes(), array_flip($model->getHidden())), 'relations' => $relations];
    }

    private function productionModelFromSnapshot(array $snapshot): Model
    {
        $model = (new $snapshot['class'])->newFromBuilder($snapshot['attributes']);
        foreach ($snapshot['relations'] as $key => $relation) {
            $model->setRelation($key, isset($relation['one']) ? $this->productionModelFromSnapshot($relation['one'])
                : (isset($relation['many']) ? new \Illuminate\Database\Eloquent\Collection(array_map(fn (array $child): Model => $this->productionModelFromSnapshot($child), $relation['many'])) : null));
        }

        return $model;
    }

    private function productionEditingPayload(): array
    {
        return [
            'token' => $this->editingToken, 'publicIds' => $this->editingPublicIds,
            'revisions' => $this->editingExpectedRevisions, 'state' => $this->editingState,
            'releaseUrl' => route('production-bench.production.editing.release'), 'groups' => $this->productionDraftGroups(),
            'messages' => collect(['blocked', 'group_blocked', 'stale', 'unavailable', 'status_failed', 'refresh_failed', 'reload_failed', 'available', 'discard_confirmation'])->mapWithKeys(fn (string $key): array => [$key => __('production_bench.editing.'.$key)])->all(),
        ];
    }
}
