<?php

namespace App\Services;

use App\MaterialPriceSource;
use App\Models\PackagingItem;
use App\Models\User;
use App\PackagingCategory;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
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
            'category' => $packagingItem->category->value,
            'unit_cost' => $packagingItem->unit_cost === null ? null : (float) $packagingItem->unit_cost,
            'notes' => $packagingItem->notes,
            'featured_image_path' => $packagingItem->featured_image_path,
            'featured_image_original_name' => $packagingItem->featured_image_original_name,
        ];
    }

    public function create(array $state, User $user): PackagingItem
    {
        $workspace = $this->workspaceProvisioner->ensureCompanyWorkspace($user);

        $packagingItem = new PackagingItem([
            'public_id' => Arr::get($state, 'public_id'),
            'workspace_id' => $workspace->id,
            'created_by_user_id' => $user->id,
        ]);

        $packagingItem = $this->persist($packagingItem, $state);

        $this->rememberPrice($packagingItem, $state['unit_cost'] ?? null, $user);

        return $packagingItem->load('currentPrice');
    }

    public function update(PackagingItem $packagingItem, array $state, User $user): PackagingItem
    {
        if (! $packagingItem->workspace->hasMember($user)) {
            throw ValidationException::withMessages([
                'packaging_item' => 'Only your own packaging items can be edited from the public app.',
            ]);
        }

        $previousFeaturedImagePath = $packagingItem->featured_image_path;
        $packagingItem = $this->persist($packagingItem, $state);

        if ($previousFeaturedImagePath !== $packagingItem->featured_image_path) {
            MediaStorage::deletePackagingItemPath($packagingItem, $previousFeaturedImagePath);
        }

        $this->rememberPrice($packagingItem, $state['unit_cost'] ?? null, $user);

        return $packagingItem->load('currentPrice');
    }

    public function updateUnitCost(PackagingItem $packagingItem, User $user, mixed $unitCost): PackagingItem
    {
        if (! $packagingItem->workspace->hasMember($user)) {
            throw ValidationException::withMessages([
                'packaging_item' => 'Only your own packaging items can be edited from the public app.',
            ]);
        }

        if ($unitCost === null || $unitCost === '') {
            throw ValidationException::withMessages([
                'unit_cost' => 'The unit price field is required.',
            ]);
        }

        $this->rememberPrice($packagingItem, $unitCost, $user);

        return $packagingItem->fresh()->load('currentPrice');
    }

    public function delete(PackagingItem $packagingItem, User $user): bool
    {
        if (! $packagingItem->workspace->hasMember($user)) {
            return false;
        }

        if ($packagingItem->costingItems()->exists() || $packagingItem->recipeVersionPackagingItems()->exists()) {
            $packagingItem->update(['is_active' => false]);

            return true;
        }

        $featuredImagePath = $packagingItem->featured_image_path;

        DB::transaction(function () use ($packagingItem, $featuredImagePath): void {
            $packagingItem->currentPrice()->delete();
            $packagingItem->delete();

            DB::afterCommit(function () use ($packagingItem, $featuredImagePath): void {
                MediaStorage::deletePackagingItemPath($packagingItem, $featuredImagePath);
                MediaStorage::deletePackagingItemDirectory($packagingItem);
            });
        });

        return true;
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

        $packagingItem->name = $name;
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
        $packagingItem->save();

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
}
