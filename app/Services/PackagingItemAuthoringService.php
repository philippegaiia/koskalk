<?php

namespace App\Services;

use App\Enums\MaterialPriceSource;
use App\Enums\PackagingCategory;
use App\Models\PackagingItem;
use App\Models\User;
use App\Models\Workspace;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PackagingItemAuthoringService
{
    public function __construct(
        private readonly CurrentMaterialPriceService $currentMaterialPriceService,
        private readonly WorkspaceProvisioner $workspaceProvisioner,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function blankState(): array
    {
        return [
            'name' => null,
            'material_code' => null,
            'category' => PackagingCategory::Other->value,
            'unit_cost' => null,
            'notes' => null,
            'featured_image_path' => null,
            'featured_image_original_name' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function formData(PackagingItem $packagingItem): array
    {
        return [
            'name' => $packagingItem->name,
            'material_code' => $packagingItem->material_code,
            'category' => $packagingItem->category->value,
            'unit_cost' => $packagingItem->unit_cost === null ? null : (float) $packagingItem->unit_cost,
            'notes' => $packagingItem->notes,
            'featured_image_path' => $packagingItem->featured_image_path,
            'featured_image_original_name' => $packagingItem->featured_image_original_name,
        ];
    }

    public function create(array $state, User $user): PackagingItem
    {
        $workspace = $user->company(fresh: true) ?? $this->workspaceProvisioner->ensureCompanyWorkspace($user);

        return DB::transaction(function () use ($state, $user, $workspace): PackagingItem {
            $lockedWorkspace = Workspace::withoutGlobalScopes()->lockForUpdate()->findOrFail($workspace->id);
            Gate::forUser($user)->authorize('create', [PackagingItem::class, $lockedWorkspace]);

            $packagingItem = new PackagingItem([
                'public_id' => Arr::get($state, 'public_id'),
                'workspace_id' => $lockedWorkspace->id,
                'created_by_user_id' => $user->id,
            ]);
            $packagingItem = $this->persist($packagingItem, $state);
            $this->rememberPrice($packagingItem, $state['unit_cost'] ?? null, $user);

            return $packagingItem->load('currentPrice');
        }, attempts: 5);
    }

    public function update(PackagingItem $packagingItem, array $state, User $user): PackagingItem
    {
        return $this->withAuthorizedItem($user, $packagingItem, 'update', function (PackagingItem $lockedItem) use ($state, $user): PackagingItem {
            $previousFeaturedImagePath = $lockedItem->featured_image_path;
            $lockedItem = $this->persist($lockedItem, $state);
            $this->rememberPrice($lockedItem, $state['unit_cost'] ?? null, $user);

            if ($previousFeaturedImagePath !== $lockedItem->featured_image_path) {
                DB::afterCommit(fn () => MediaStorage::deletePackagingItemPath($lockedItem, $previousFeaturedImagePath));
            }

            return $lockedItem->load('currentPrice');
        });
    }

    public function updateUnitCost(PackagingItem $packagingItem, User $user, mixed $unitCost): PackagingItem
    {
        return $this->withAuthorizedItem($user, $packagingItem, 'update', function (PackagingItem $lockedItem) use ($user, $unitCost): PackagingItem {
            $this->rememberPrice($lockedItem, $unitCost, $user);

            return $lockedItem->fresh()->load('currentPrice');
        });
    }

    public function delete(PackagingItem $packagingItem, User $user): bool
    {
        return $this->withAuthorizedItem($user, $packagingItem, 'delete', function (PackagingItem $lockedItem): bool {
            if ($lockedItem->costingItems()->exists() || $lockedItem->recipeVersionPackagingItems()->exists()) {
                $lockedItem->update(['is_active' => false]);

                return true;
            }

            $featuredImagePath = $lockedItem->featured_image_path;
            $lockedItem->currentPrice()->delete();
            $lockedItem->delete();

            DB::afterCommit(function () use ($lockedItem, $featuredImagePath): void {
                MediaStorage::deletePackagingItemPath($lockedItem, $featuredImagePath);
                MediaStorage::deletePackagingItemDirectory($lockedItem);
            });

            return true;
        });
    }

    /**
     * @template T
     *
     * @param  Closure(PackagingItem): T  $callback
     * @return T
     */
    private function withAuthorizedItem(User $user, PackagingItem $packagingItem, string $ability, Closure $callback): mixed
    {
        return DB::transaction(function () use ($user, $packagingItem, $ability, $callback): mixed {
            Workspace::withoutGlobalScopes()->lockForUpdate()->findOrFail($packagingItem->workspace_id);
            $lockedItem = PackagingItem::query()
                ->where('workspace_id', $packagingItem->workspace_id)
                ->lockForUpdate()
                ->findOrFail($packagingItem->id);
            Gate::forUser($user)->authorize($ability, $lockedItem);

            return $callback($lockedItem);
        }, attempts: 5);
    }

    private function persist(PackagingItem $packagingItem, array $state): PackagingItem
    {
        $name = trim((string) Arr::get($state, 'name'));
        $categoryState = Arr::get($state, 'category', PackagingCategory::Other);
        $category = $categoryState instanceof PackagingCategory
            ? $categoryState
            : PackagingCategory::tryFrom((string) $categoryState);

        if ($name === '') {
            throw ValidationException::withMessages([
                'name' => 'The name field is required.',
            ]);
        }

        if (! $category instanceof PackagingCategory) {
            throw ValidationException::withMessages([
                'category' => 'Select a packaging category.',
            ]);
        }

        $materialCode = array_key_exists('material_code', $state)
            ? $this->normalizeMaterialCode(Arr::get($state, 'material_code'))
            : $packagingItem->material_code;

        if ($materialCode !== null) {
            $this->validateMaterialCodeFormat($materialCode);

            $duplicateExists = PackagingItem::query()
                ->where('workspace_id', $packagingItem->workspace_id)
                ->where('material_code', $materialCode)
                ->when($packagingItem->exists, fn ($query) => $query->where('id', '!=', $packagingItem->id))
                ->exists();

            if ($duplicateExists) {
                throw ValidationException::withMessages([
                    'material_code' => __('packaging.validation.material_code_unique'),
                ]);
            }
        }

        $packagingItem->name = $name;
        $packagingItem->material_code = $materialCode;
        $packagingItem->category = $category;
        $packagingItem->notes = blank(Arr::get($state, 'notes'))
            ? null
            : trim((string) Arr::get($state, 'notes'));
        if (array_key_exists('featured_image_path', $state)) {
            $featuredImagePath = Arr::get($state, 'featured_image_path');
            $packagingItem->featured_image_path = $featuredImagePath;
            $packagingItem->featured_image_original_name = filled($featuredImagePath)
                ? Arr::get($state, 'featured_image_original_name')
                : null;
        }
        try {
            $packagingItem->save();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'material_code' => __('packaging.validation.material_code_unique'),
            ]);
        }

        return $packagingItem->fresh();
    }

    private function rememberPrice(PackagingItem $packagingItem, mixed $unitCost, User $user): void
    {
        if ($unitCost === null || $unitCost === '') {
            throw ValidationException::withMessages([
                'unit_cost' => 'The unit price field is required.',
            ]);
        }

        $this->currentMaterialPriceService->rememberPackaging(
            workspace: $packagingItem->workspace,
            packagingItem: $packagingItem,
            pricePerItem: (string) $unitCost,
            currency: $user->defaultCurrency(),
            source: MaterialPriceSource::ManualCosting,
            sourceId: null,
            actor: $user,
        );
    }

    private function normalizeMaterialCode(mixed $materialCode): ?string
    {
        $normalized = Str::upper(trim((string) $materialCode));

        return $normalized === '' ? null : $normalized;
    }

    private function validateMaterialCodeFormat(string $materialCode): void
    {
        if (preg_match('/\A[A-Z0-9][A-Z0-9._\/-]{0,63}\z/', $materialCode) === 1) {
            return;
        }

        throw ValidationException::withMessages([
            'material_code' => __('packaging.validation.material_code_format'),
        ]);
    }
}
