<?php

namespace App\Services;

use App\Enums\IfraAmendmentStatus;
use App\Models\IfraAmendment;
use App\Models\IfraAmendmentMilestone;
use App\Models\IfraProductCategory;
use App\Models\ProductType;
use App\Models\ProductTypeIfraCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class ProductTypeIfraOptionsBuilder
{
    /**
     * @return array{
     *     amendment: array{id:int, code:string, status:string}|null,
     *     default_category_id: int|null,
     *     default_mapping_id: int|null,
     *     options: array<int, array{mapping_id:int, id:int, code:string, name:string, short_name:?string, description:?string, guidance:?string, is_default:bool}>,
     *     all_categories: array<int, array{id:int, code:string, name:string, short_name:?string, description:?string}>,
     *     milestones: array<int, array{standard_kind:string, creation_track:string, effective_on:string}>
     * }
     */
    public function build(?ProductType $productType, ?IfraProductCategory $selected = null): array
    {
        $notifiedAmendments = IfraAmendment::query()
            ->where('status', IfraAmendmentStatus::Notified)
            ->whereNotNull('notification_date')
            ->whereDate('notification_date', '<=', today())
            ->orderByDesc('notification_date')
            ->orderByDesc('id');

        $latestNotifiedAmendment = (clone $notifiedAmendments)->first();
        $mappedAmendment = $productType instanceof ProductType
            ? (clone $notifiedAmendments)
                ->whereHas(
                    'productTypeMappings',
                    fn (Builder $query): Builder => $query
                        ->where('product_type_id', $productType->id)
                        ->where('is_active', true),
                )
                ->first()
            : null;
        $amendment = $mappedAmendment ?? $latestNotifiedAmendment;
        $mappings = $this->mappings($productType, $amendment);
        $defaultMapping = $mappings->first(fn (ProductTypeIfraCategory $mapping): bool => $mapping->is_default);

        return [
            'amendment' => $amendment instanceof IfraAmendment
                ? [
                    'id' => $amendment->id,
                    'code' => $amendment->code,
                    'status' => $amendment->status->value,
                ]
                : null,
            'default_category_id' => $defaultMapping?->ifra_product_category_id,
            'default_mapping_id' => $defaultMapping?->id,
            'options' => $mappings
                ->map(fn (ProductTypeIfraCategory $mapping): array => [
                    'mapping_id' => $mapping->id,
                    ...$this->categoryData($mapping->ifraProductCategory),
                    'guidance' => $mapping->guidance,
                    'is_default' => $mapping->is_default,
                ])
                ->all(),
            'all_categories' => $this->allCategories($selected),
            'milestones' => $this->milestones($amendment),
        ];
    }

    /**
     * @return Collection<int, ProductTypeIfraCategory>
     */
    private function mappings(?ProductType $productType, ?IfraAmendment $amendment): Collection
    {
        if (! $productType instanceof ProductType || ! $amendment instanceof IfraAmendment) {
            return collect();
        }

        return ProductTypeIfraCategory::query()
            ->with('ifraProductCategory')
            ->where('product_type_id', $productType->id)
            ->where('ifra_amendment_id', $amendment->id)
            ->where('is_active', true)
            ->whereHas(
                'ifraProductCategory',
                fn (Builder $query): Builder => $query->where('is_active', true),
            )
            ->orderBy('sort_order')
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return array<int, array{id:int, code:string, name:string, short_name:?string, description:?string}>
     */
    private function allCategories(?IfraProductCategory $selected): array
    {
        $categories = IfraProductCategory::query()
            ->where('is_active', true)
            ->get();

        if ($selected instanceof IfraProductCategory && ! $categories->contains('id', $selected->id)) {
            $categories->push($selected);
        }

        return $categories
            ->sortBy(fn (IfraProductCategory $category): array => $this->sortKey($category->code))
            ->values()
            ->map(fn (IfraProductCategory $category): array => $this->categoryData($category))
            ->all();
    }

    /**
     * @return array<int, array{standard_kind:string, creation_track:string, effective_on:string}>
     */
    private function milestones(?IfraAmendment $amendment): array
    {
        if (! $amendment instanceof IfraAmendment) {
            return [];
        }

        return $amendment->milestones()
            ->orderBy('effective_on')
            ->orderBy('standard_kind')
            ->orderBy('creation_track')
            ->get()
            ->map(fn (IfraAmendmentMilestone $milestone): array => [
                'standard_kind' => $milestone->standard_kind->value,
                'creation_track' => $milestone->creation_track->value,
                'effective_on' => $milestone->effective_on->toDateString(),
            ])
            ->all();
    }

    /**
     * @return array{id:int, code:string, name:string, short_name:?string, description:?string}
     */
    private function categoryData(IfraProductCategory $category): array
    {
        return [
            'id' => $category->id,
            'code' => $category->code,
            'name' => $category->name,
            'short_name' => $category->short_name,
            'description' => $category->description,
        ];
    }

    /**
     * @return array{int, string}
     */
    private function sortKey(string $code): array
    {
        preg_match('/^(\d+)([A-Za-z]*)$/', $code, $matches);

        return [
            isset($matches[1]) ? (int) $matches[1] : PHP_INT_MAX,
            strtoupper($matches[2] ?? ''),
        ];
    }
}
