<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserPackagingItem;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class UserPackagingItemAuthoringService
{
    /**
     * @return array<string, mixed>
     */
    public function blankState(): array
    {
        return [
            'name' => null,
            'unit_cost' => null,
            'notes' => null,
            'featured_image_path' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function formData(UserPackagingItem $packagingItem): array
    {
        return [
            'name' => $packagingItem->name,
            'unit_cost' => $packagingItem->unit_cost === null ? null : (float) $packagingItem->unit_cost,
            'notes' => $packagingItem->notes,
            'featured_image_path' => $packagingItem->featured_image_path,
        ];
    }

    public function create(array $state, User $user): UserPackagingItem
    {
        $packagingItem = new UserPackagingItem([
            'user_id' => $user->id,
            'currency' => $user->defaultCurrency(),
        ]);

        return $this->persist($packagingItem, $state);
    }

    public function update(UserPackagingItem $packagingItem, array $state, User $user): UserPackagingItem
    {
        if ($packagingItem->user_id !== $user->id) {
            throw ValidationException::withMessages([
                'packaging_item' => 'Only your own packaging items can be edited from the public app.',
            ]);
        }

        $previousFeaturedImagePath = $packagingItem->featured_image_path;
        $packagingItem = $this->persist($packagingItem, $state);

        if ($previousFeaturedImagePath !== $packagingItem->featured_image_path) {
            MediaStorage::deletePublicPath($previousFeaturedImagePath);
        }

        return $packagingItem;
    }

    public function delete(UserPackagingItem $packagingItem, User $user): bool
    {
        if ($packagingItem->user_id !== $user->id || $packagingItem->costingItems()->exists()) {
            return false;
        }

        MediaStorage::deletePublicPath($packagingItem->featured_image_path);
        $packagingItem->delete();

        return true;
    }

    private function persist(UserPackagingItem $packagingItem, array $state): UserPackagingItem
    {
        $name = trim((string) Arr::get($state, 'name'));
        $unitCost = (float) Arr::get($state, 'unit_cost', 0);

        if ($name === '') {
            throw ValidationException::withMessages([
                'name' => 'The name field is required.',
            ]);
        }

        if ($unitCost < 0) {
            throw ValidationException::withMessages([
                'unit_cost' => 'The unit price must not be negative.',
            ]);
        }

        $packagingItem->name = $name;
        $packagingItem->unit_cost = $unitCost;
        $packagingItem->currency = filled($packagingItem->currency)
            ? $packagingItem->currency
            : ($state['currency'] ?? 'EUR');
        $packagingItem->notes = blank(Arr::get($state, 'notes'))
            ? null
            : trim((string) Arr::get($state, 'notes'));
        $packagingItem->featured_image_path = Arr::get($state, 'featured_image_path');
        $packagingItem->save();

        return $packagingItem->fresh();
    }
}
