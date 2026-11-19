<?php

namespace App\Actions\Inventory;

use App\Models\ProductSize;
use App\Models\Recipe;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ProductionBenchAccess;
use App\Services\WorkspaceWriteLock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SaveProductSizeSku
{
    public function __construct(
        private readonly ProductionBenchAccess $access,
        private readonly WorkspaceWriteLock $workspaceLock,
    ) {}

    public function handle(User $actor, Workspace $workspace, int $sizeId, ?string $sku): void
    {
        $this->access->assertWritable($actor, $workspace);
        $sku = Str::upper(trim($sku ?? ''));
        Validator::make(['sku' => $sku], ['sku' => ['string', 'max:64']])->validate();

        DB::transaction(function () use ($actor, $workspace, $sizeId, $sku): void {
            $lockedWorkspace = $this->workspaceLock->acquire($workspace->id);
            $this->access->assertWritable($actor, $lockedWorkspace);

            $workspaceProducts = Recipe::withoutGlobalScopes()->where('workspace_id', $lockedWorkspace->id)->select('id');
            $sizes = ProductSize::query()->whereIn('recipe_id', $workspaceProducts);
            $size = (clone $sizes)->lockForUpdate()->findOrFail($sizeId);
            $recipe = Recipe::withoutGlobalScopes()->findOrFail($size->recipe_id);
            Gate::forUser($actor)->authorize('update', $recipe);

            if ($sku !== '' && $sizes->whereKeyNot($size->id)->where('sku', $sku)->exists()) {
                throw ValidationException::withMessages(['sku' => __('production_bench.inventory.validation.sku_taken')]);
            }

            $size->update(['sku' => $sku === '' ? null : $sku]);
        }, attempts: 5);
    }
}
