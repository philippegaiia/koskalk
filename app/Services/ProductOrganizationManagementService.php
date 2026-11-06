<?php

namespace App\Services;

use App\Models\Brand;
use App\Models\ProductCollection;
use App\Models\Recipe;
use App\Models\User;
use App\Models\Workspace;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductOrganizationManagementService
{
    public function __construct(
        private readonly WorkspaceAuthorization $authorization,
        private readonly ProductOrganizationService $creation,
        private readonly RecipeControlMutationGuard $products,
    ) {}

    public function createBrand(User $user, int $workspaceId, string $name): Brand
    {
        return $this->write($user, $workspaceId, fn (User $actor): Brand => $this->creation->createBrand($actor, $name));
    }

    public function createCollection(User $user, int $workspaceId, int $brandId, string $name): ProductCollection
    {
        return $this->write($user, $workspaceId, fn (User $actor): ProductCollection => $this->creation->createCollection($actor, $brandId, $name));
    }

    public function renameBrand(User $user, int $workspaceId, int $brandId, string $name): Brand
    {
        return $this->write($user, $workspaceId, function () use ($workspaceId, $brandId, $name): Brand {
            $brand = $this->brand($workspaceId, $brandId);
            $name = $this->name($name);
            $this->uniqueName(Brand::query()->where('workspace_id', $workspaceId)->whereKeyNot($brand->id), $name);
            $brand->update(['name' => $name]);

            return $brand;
        });
    }

    public function saveCollection(User $user, int $workspaceId, int $collectionId, string $name, int $brandId): ProductCollection
    {
        return $this->write($user, $workspaceId, function (User $actor) use ($workspaceId, $collectionId, $name, $brandId): ProductCollection {
            $target = $this->brand($workspaceId, $brandId);
            $collection = $this->collection($workspaceId, $collectionId);
            $name = $this->name($name);
            $otherCollections = ProductCollection::query()->where('workspace_id', $workspaceId)->where('brand_id', $target->id)->whereKeyNot($collection->id);
            $this->uniqueName($otherCollections, $name);

            if ($collection->brand_id !== $target->id) {
                $this->assignProducts($actor, $workspaceId, Recipe::withoutGlobalScopes()->where('product_collection_id', $collection->id), [
                    'brand_id' => $target->id,
                    'product_collection_id' => $collection->id,
                ]);
                $collection->slug = $this->availableSlug($collection->slug, $otherCollections);
            }
            $collection->fill(['name' => $name, 'brand_id' => $target->id])->save();

            return $collection;
        });
    }

    public function deleteCollection(User $user, int $workspaceId, int $collectionId, bool $clearAssignments = false, ?int $targetCollectionId = null): void
    {
        $this->write($user, $workspaceId, function (User $actor) use ($workspaceId, $collectionId, $clearAssignments, $targetCollectionId): void {
            $collection = $this->collection($workspaceId, $collectionId);
            $query = Recipe::withoutGlobalScopes()->where('workspace_id', $workspaceId)->where('product_collection_id', $collection->id);
            $target = $targetCollectionId === null ? null : $this->collection($workspaceId, $targetCollectionId);
            if ($target?->id === $collection->id || ($clearAssignments && $target !== null)) {
                throw ValidationException::withMessages(['target_id' => __('organization.validation.choose_target')]);
            }
            if ($query->exists() && ! $clearAssignments && $target === null) {
                throw ValidationException::withMessages(['clear_assignments' => __('organization.validation.confirm_collection_clear')]);
            }
            $this->assignProducts($actor, $workspaceId, $query, $target === null
                ? ['product_collection_id' => null]
                : ['brand_id' => $target->brand_id, 'product_collection_id' => $target->id]);
            $collection->delete();
        });
    }

    public function deleteBrand(User $user, int $workspaceId, int $brandId, bool $clearAssignments = false, ?int $targetBrandId = null): void
    {
        $this->write($user, $workspaceId, function (User $actor) use ($workspaceId, $brandId, $clearAssignments, $targetBrandId): void {
            $brand = $this->brand($workspaceId, $brandId);
            $collectionIds = ProductCollection::query()->where('workspace_id', $workspaceId)->where('brand_id', $brand->id)->orderBy('id')->lockForUpdate()->pluck('id');
            if ($collectionIds->isNotEmpty() && ! $clearAssignments) {
                throw ValidationException::withMessages(['clear_assignments' => __('organization.validation.brand_has_collections')]);
            }
            $target = $targetBrandId === null ? null : $this->brand($workspaceId, $targetBrandId);
            if ($target?->id === $brand->id || ($clearAssignments && $target !== null)) {
                throw ValidationException::withMessages(['target_id' => __('organization.validation.choose_target')]);
            }
            $query = Recipe::withoutGlobalScopes()->where('workspace_id', $workspaceId)->where(function (Builder $query) use ($brand, $collectionIds): void {
                $query->where('brand_id', $brand->id)->orWhereIn('product_collection_id', $collectionIds);
            });
            if ($query->exists() && ! $clearAssignments && $target === null) {
                throw ValidationException::withMessages(['clear_assignments' => __('organization.validation.confirm_brand_clear')]);
            }
            $this->assignProducts($actor, $workspaceId, $query, ['brand_id' => $target?->id, 'product_collection_id' => null]);
            ProductCollection::query()->where('workspace_id', $workspaceId)->whereKey($collectionIds)->delete();
            $brand->delete();
        });
    }

    /** @param array{brand_id?: int|null, product_collection_id?: int|null} $assignment */
    private function assignProducts(User $actor, int $workspaceId, Builder $query, array $assignment): void
    {
        $recipes = $query->where('workspace_id', $workspaceId)->orderBy('id')->lockForUpdate()->get();
        foreach ($recipes as $recipe) {
            $this->products->run($recipe, $actor, (int) $recipe->edit_revision, 'update', function (Recipe $locked) use ($assignment): void {
                $locked->fill($assignment)->save();
            });
        }
    }

    /**
     * @template T
     *
     * @param  Closure(User): T  $callback
     * @return T
     */
    private function write(User $user, int $workspaceId, Closure $callback): mixed
    {
        return DB::transaction(function () use ($user, $workspaceId, $callback): mixed {
            $actor = User::query()->lockForUpdate()->findOrFail($user->id);
            Workspace::withoutGlobalScopes()->lockForUpdate()->findOrFail($workspaceId);
            abort_unless($this->authorization->canEdit($actor, $workspaceId), 403);
            $result = $callback($actor);
            abort_unless($this->authorization->canEdit($actor, $workspaceId), 403);

            return $result;
        }, attempts: 5);
    }

    private function brand(int $workspaceId, int $id): Brand
    {
        $brand = Brand::query()->where('workspace_id', $workspaceId)->lockForUpdate()->find($id);
        if (! $brand instanceof Brand) {
            throw ValidationException::withMessages(['brand_id' => __('organization.invalid_brand')]);
        }

        return $brand;
    }

    private function collection(int $workspaceId, int $id): ProductCollection
    {
        $collection = ProductCollection::query()->where('workspace_id', $workspaceId)->lockForUpdate()->find($id);
        if (! $collection instanceof ProductCollection) {
            throw ValidationException::withMessages(['target_id' => __('organization.invalid_collection')]);
        }

        return $collection;
    }

    private function name(string $name): string
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 255) {
            throw ValidationException::withMessages(['name' => __('organization.invalid_name')]);
        }

        return $name;
    }

    private function uniqueName(Builder $query, string $name): void
    {
        if ((clone $query)->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->exists()) {
            throw ValidationException::withMessages(['name' => __('organization.validation.name_taken')]);
        }
    }

    private function availableSlug(string $slug, Builder $query): string
    {
        $base = mb_substr($slug, 0, 240);
        $candidate = $base;
        $suffix = 2;
        while ((clone $query)->where('slug', $candidate)->exists()) {
            $candidate = $base.'-'.$suffix++;
        }

        return $candidate;
    }
}
