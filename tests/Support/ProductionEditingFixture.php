<?php

namespace Tests\Support;

use App\Models\Plan;
use App\Models\ProductionDocument;
use App\Models\ProductionRun;
use App\Models\ProductionTask;
use App\Models\StockLot;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceProductionEntitlement;
use App\Services\Production\ProductionEditingContext;
use App\Services\ProductionEditingService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ProductionEditingFixture
{
    public function __construct(public readonly User $owner, public readonly Workspace $workspace) {}

    public static function create(): self
    {
        $owner = User::factory()->create();
        $workspace = Workspace::factory()->for($owner, 'owner')->create();
        $owner->forceFill(['active_workspace_id' => $workspace->id])->save();
        $plan = Plan::factory()->create(['allows_collaboration' => true]);
        $owner->entitlements()->create(['plan_id' => $plan->id, 'status' => 'active', 'starts_at' => now()->subMinute()]);
        WorkspaceProductionEntitlement::factory()->for($workspace)->create();

        return new self($owner, $workspace);
    }

    public function lease(ProductionRun $production, ?User $actor = null): ProductionEditingContext
    {
        $actor ??= $this->owner;
        $token = (string) Str::uuid();
        $revisions = [$production->id => (int) $production->fresh()->edit_revision];
        $state = app(ProductionEditingService::class)->acquire($actor, $this->workspace->id, $revisions, $token);
        if ($state['status'] !== 'acquired') {
            throw new \LogicException('Test fixture could not acquire its explicit production lease.');
        }

        return new ProductionEditingContext($this->workspace->id, $token, $revisions);
    }

    /**
     * Begin a distinct standalone test command with the currently displayed records.
     * Mounted-page tests retain their original context instead.
     *
     * @param  ProductionRun|array<int, int|string>  $selection
     */
    public static function command(User $actor, Model|array $selection): ?ProductionEditingContext
    {
        if ($selection instanceof ProductionDocument) {
            $selection = $selection->documentable;
            if (! $selection instanceof ProductionRun) {
                return null;
            }
        }
        if ($selection instanceof ProductionTask || $selection instanceof StockLot) {
            if ($selection->production_run_id === null) {
                throw new \LogicException(sprintf(
                    'ProductionEditingFixture::command() needs a durable production parent, but %s #%s has no production_run_id. Pass the ProductionRun itself, or assert the domain rejection directly.',
                    $selection::class,
                    $selection->getKey(),
                ));
            }
            $selection = [(int) $selection->production_run_id];
        }
        $ids = $selection instanceof ProductionRun ? [$selection->id] : collect($selection)->map(fn (int|string $id): int => (int) $id)->all();
        $runs = ProductionRun::query()->whereIn('id', $ids)->get()->keyBy('id');
        $workspaceId = $selection instanceof Model
            ? ($runs->first()?->workspace_id ?? $actor->company(fresh: true)->id)
            : $actor->company(fresh: true)->id;
        $revisions = collect($ids)->mapWithKeys(fn (int $id): array => [$id => (int) ($runs->get($id)?->edit_revision ?? 0)])->all();

        return new ProductionEditingContext($workspaceId, (string) Str::uuid(), $revisions, temporary: true);
    }
}
