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
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProductOrganizationService
{
    public function __construct(
        private readonly WorkspaceAuthorization $authorization,
        private readonly WorkspaceProvisioner $provisioner,
    ) {}

    /** @return array{brands: array<int, array{id: int, name: string}>, collections: array<int, array{id: int, brand_id: int, name: string}>} */
    public function options(?User $user): array
    {
        $workspace = $user instanceof User ? $this->authorization->selectedWorkspace($user) : null;
        if (! $workspace instanceof Workspace) {
            return ['brands' => [], 'collections' => []];
        }

        return [
            'brands' => Brand::query()->where('workspace_id', $workspace->id)->orderBy('name')->get(['id', 'name'])->toArray(),
            'collections' => ProductCollection::query()->where('workspace_id', $workspace->id)->orderBy('name')->get(['id', 'brand_id', 'name'])->toArray(),
        ];
    }

    public function createBrand(User $user, string $name): Brand
    {
        return $this->write($user, function (Workspace $workspace) use ($name): Brand {
            $name = $this->name($name);
            $existing = Brand::query()->where('workspace_id', $workspace->id)->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first();
            if ($existing instanceof Brand) {
                return $existing;
            }

            return Brand::query()->firstOrCreate([
                'workspace_id' => $workspace->id,
                'slug' => $this->availableSlug($name, Brand::query()->where('workspace_id', $workspace->id)),
            ], ['name' => $name]);
        });
    }

    public function createCollection(User $user, int $brandId, string $name): ProductCollection
    {
        return $this->write($user, function (Workspace $workspace) use ($brandId, $name): ProductCollection {
            $brand = Brand::query()->where('workspace_id', $workspace->id)->find($brandId);
            if (! $brand instanceof Brand) {
                throw ValidationException::withMessages(['brand_id' => __('organization.invalid_brand')]);
            }
            $name = $this->name($name);
            $existing = ProductCollection::query()->where('workspace_id', $workspace->id)->where('brand_id', $brand->id)->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first();
            if ($existing instanceof ProductCollection) {
                return $existing;
            }

            return ProductCollection::query()->firstOrCreate([
                'brand_id' => $brand->id,
                'slug' => $this->availableSlug($name, ProductCollection::query()->where('brand_id', $brand->id)),
            ], ['workspace_id' => $workspace->id, 'name' => $name]);
        });
    }

    /** @param array<string, mixed> $payload
     * @return array{brand_id: int|null, product_collection_id: int|null}
     */
    public function assignment(User $user, array $payload, Recipe $recipe): array
    {
        $brandId = $this->id(array_key_exists('brand_id', $payload) ? $payload['brand_id'] : $recipe->brand_id, 'brand_id');
        $collectionId = array_key_exists('product_collection_id', $payload)
            ? $this->id($payload['product_collection_id'], 'product_collection_id')
            : $recipe->product_collection_id;
        if (array_key_exists('brand_id', $payload) && $brandId !== $recipe->brand_id && ! array_key_exists('product_collection_id', $payload)) {
            $collectionId = null;
        }
        if ($brandId === null && $collectionId === null) {
            return ['brand_id' => null, 'product_collection_id' => null];
        }
        $workspace = $this->authorization->selectedWorkspace($user);
        abort_unless($workspace instanceof Workspace && $workspace->id === $recipe->workspace_id && $this->authorization->canEdit($user, $workspace->id), 403);

        if ($collectionId !== null) {
            $collection = ProductCollection::query()->where('workspace_id', $workspace->id)->find($collectionId);
            if (! $collection instanceof ProductCollection || (array_key_exists('brand_id', $payload) && $brandId !== null && $brandId !== (int) $collection->brand_id)) {
                throw ValidationException::withMessages(['product_collection_id' => __('organization.invalid_collection')]);
            }
            $brandId = $collection->brand_id;
        }
        if ($brandId !== null && ! Brand::query()->where('workspace_id', $workspace->id)->whereKey($brandId)->exists()) {
            throw ValidationException::withMessages(['brand_id' => __('organization.invalid_brand')]);
        }

        return ['brand_id' => $brandId, 'product_collection_id' => $collectionId];
    }

    /** @template T
     * @param  Closure(Workspace): T  $callback
     * @return T
     */
    private function write(User $user, Closure $callback): mixed
    {
        $workspace = $this->provisioner->ensureCompanyWorkspace($user);

        return DB::transaction(function () use ($user, $workspace, $callback): mixed {
            $locked = Workspace::withoutGlobalScopes()->lockForUpdate()->findOrFail($workspace->id);
            abort_unless($this->authorization->canEdit($user, $locked->id), 403);
            Gate::forUser($user)->authorize('create', Recipe::class);

            return $callback($locked);
        }, attempts: 5);
    }

    private function name(string $name): string
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 255) {
            throw ValidationException::withMessages(['name' => __('organization.invalid_name')]);
        }

        return $name;
    }

    private function availableSlug(string $name, Builder $query): string
    {
        $base = mb_substr(Str::slug($name) ?: hash('sha256', mb_strtolower($name)), 0, 240);
        $slug = $base;
        $suffix = 2;
        while (($existing = (clone $query)->where('slug', $slug)->first()) !== null) {
            if (mb_strtolower(trim($existing->name)) === mb_strtolower($name)) {
                return $slug;
            }
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }

    private function id(mixed $value, string $field): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_scalar($value) || ! ctype_digit((string) $value) || (int) $value < 1) {
            throw ValidationException::withMessages([$field => __($field === 'brand_id' ? 'organization.invalid_brand' : 'organization.invalid_collection')]);
        }

        return (int) $value;
    }
}
