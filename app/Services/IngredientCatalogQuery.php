<?php

namespace App\Services;

use App\Enums\OwnerType;
use App\Models\Ingredient;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;

final class IngredientCatalogQuery
{
    public function __construct(private readonly IngredientCatalogSearchService $catalogSearch) {}

    /**
     * @param  array{ownershipFilter?: string, search?: string, sortField?: string, sortDirection?: string}  $filters
     */
    public function query(User $actor, ?Workspace $workspace, array $filters = []): Builder
    {
        $ownershipFilter = $filters['ownershipFilter'] ?? 'all';
        $search = mb_strtolower(trim($filters['search'] ?? ''));
        $sortField = $filters['sortField'] ?? 'display_name';
        $sortDirection = $filters['sortDirection'] ?? 'asc';
        $workspaceId = $workspace?->id;
        $translationLocales = Ingredient::translationLocaleCandidates();

        return Ingredient::query()
            ->withCount(['costingItems', 'recipeItems'])
            ->with([
                'mediaAssetUsages.mediaAsset',
                'currentPrices' => fn ($query) => $query->where('workspace_id', $workspaceId),
                'translations' => fn ($query) => $query->whereIn('locale', $translationLocales),
            ])
            ->where(function (Builder $query) use ($actor, $workspaceId): void {
                $query->where(fn (Builder $ownedQuery): Builder => $this->applyCompanyIngredientScope(
                    $ownedQuery,
                    $actor,
                    $workspaceId,
                ))
                    ->orWhere(fn (Builder $platformQuery): Builder => $platformQuery
                        ->whereNull('owner_type')
                        ->where('is_active', true));
            })
            ->when($ownershipFilter === 'mine', fn (Builder $query): Builder => $query
                ->where(fn (Builder $ownedQuery): Builder => $this->applyCompanyIngredientScope(
                    $ownedQuery,
                    $actor,
                    $workspaceId,
                )))
            ->when($ownershipFilter === 'platform', fn (Builder $query): Builder => $query
                ->whereNull('owner_type')
                ->where('is_active', true))
            ->when($ownershipFilter === 'priced', fn (Builder $query): Builder => $query
                ->whereNull('owner_type')
                ->where('is_active', true)
                ->whereHas('currentPrices', fn (Builder $priceQuery): Builder => $priceQuery
                    ->where('workspace_id', $workspaceId)))
            ->when($search !== '', fn (Builder $query): Builder => $this->catalogSearch->apply(
                $query,
                $search,
                $translationLocales,
            ))
            ->orderBy($sortField, $sortDirection)
            ->orderBy('id', $sortDirection);
    }

    private function applyCompanyIngredientScope(Builder $query, User $actor, ?int $workspaceId): Builder
    {
        $query->where(function (Builder $ownershipQuery) use ($actor, $workspaceId): void {
            $ownershipQuery
                ->where('owner_type', OwnerType::User->value)
                ->where('owner_id', $actor->id);

            if ($workspaceId !== null) {
                $ownershipQuery->orWhere(function (Builder $workspaceQuery) use ($workspaceId): void {
                    $workspaceQuery
                        ->where('owner_type', OwnerType::Workspace->value)
                        ->where('owner_id', $workspaceId);
                });
            }
        });

        return $query;
    }
}
