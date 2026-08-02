<?php

namespace App\Services;

use App\MaterialPriceSource;
use App\Models\CurrentMaterialPrice;
use App\Models\Ingredient;
use App\Models\PackagingItem;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CurrentMaterialPriceService
{
    public function __construct(
        private readonly MassConverter $massConverter,
        private readonly LiveCostingPricePropagationService $liveCostingPricePropagationService,
    ) {}

    public function rememberIngredient(
        Workspace $workspace,
        Ingredient $ingredient,
        string $pricePerMassUnit,
        string $massUnit,
        string $currency,
        MaterialPriceSource $source,
        ?int $sourceId,
        User $actor,
        ?CarbonInterface $recordedAt = null,
        ?int $exceptCostingId = null,
    ): CurrentMaterialPrice {
        $price = $this->validatedPrice($pricePerMassUnit);
        $gramsPerUnit = $this->massConverter->toGrams('1', $massUnit);
        $canonicalPrice = bcdiv($price, $gramsPerUnit, 12);

        $currentPrice = $this->remember(
            workspace: $workspace,
            ingredientId: $ingredient->id,
            packagingItemId: null,
            canonicalPrice: $canonicalPrice,
            currency: $currency,
            source: $source,
            sourceId: $sourceId,
            actor: $actor,
            recordedAt: $recordedAt,
        );

        $this->liveCostingPricePropagationService->ingredientPriceChanged(
            $workspace,
            $ingredient->id,
            bcmul($currentPrice->price_per_canonical_unit, '1000', 12),
            $exceptCostingId,
        );

        return $currentPrice;
    }

    public function rememberPackaging(
        Workspace $workspace,
        PackagingItem $packagingItem,
        string $pricePerItem,
        string $currency,
        MaterialPriceSource $source,
        ?int $sourceId,
        User $actor,
        ?CarbonInterface $recordedAt = null,
        ?int $exceptCostingId = null,
    ): CurrentMaterialPrice {
        if ($packagingItem->workspace_id !== $workspace->id) {
            throw ValidationException::withMessages([
                'packaging_item' => 'The packaging item must belong to the active workspace.',
            ]);
        }

        $currentPrice = $this->remember(
            workspace: $workspace,
            ingredientId: null,
            packagingItemId: $packagingItem->id,
            canonicalPrice: $this->validatedPrice($pricePerItem),
            currency: $currency,
            source: $source,
            sourceId: $sourceId,
            actor: $actor,
            recordedAt: $recordedAt,
        );

        $this->liveCostingPricePropagationService->packagingPriceChanged(
            $workspace,
            $packagingItem->id,
            $currentPrice->price_per_canonical_unit,
            $exceptCostingId,
        );

        return $currentPrice;
    }

    private function remember(
        Workspace $workspace,
        ?int $ingredientId,
        ?int $packagingItemId,
        string $canonicalPrice,
        string $currency,
        MaterialPriceSource $source,
        ?int $sourceId,
        User $actor,
        ?CarbonInterface $recordedAt,
    ): CurrentMaterialPrice {
        if (! $workspace->hasMember($actor)) {
            throw ValidationException::withMessages([
                'workspace' => 'The active workspace is not accessible.',
            ]);
        }

        $currency = strtoupper(trim($currency));

        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw ValidationException::withMessages([
                'currency' => 'The currency must be a three-letter code.',
            ]);
        }

        return DB::transaction(function () use (
            $workspace,
            $ingredientId,
            $packagingItemId,
            $canonicalPrice,
            $currency,
            $source,
            $sourceId,
            $actor,
            $recordedAt,
        ): CurrentMaterialPrice {
            $subject = $ingredientId === null
                ? ['packaging_item_id' => $packagingItemId]
                : ['ingredient_id' => $ingredientId];
            $recordedAt ??= now();

            $existing = CurrentMaterialPrice::query()
                ->where('workspace_id', $workspace->id)
                ->where($subject)
                ->lockForUpdate()
                ->first();

            if ($existing !== null && $existing->recorded_at->greaterThan($recordedAt)) {
                return $existing;
            }

            return CurrentMaterialPrice::query()->updateOrCreate(
                ['workspace_id' => $workspace->id, ...$subject],
                [
                    'ingredient_id' => $ingredientId,
                    'packaging_item_id' => $packagingItemId,
                    'price_per_canonical_unit' => $canonicalPrice,
                    'currency' => $currency,
                    'recorded_at' => $recordedAt,
                    'source_type' => $source,
                    'source_id' => $sourceId,
                    'created_by_user_id' => $actor->id,
                ],
            );
        });
    }

    private function validatedPrice(string $price): string
    {
        $price = trim($price);

        if (preg_match('/^\d+(?:\.\d+)?$/', $price) !== 1 || bccomp($price, '0', 12) < 0) {
            throw ValidationException::withMessages([
                'price' => 'The price must be a non-negative decimal value.',
            ]);
        }

        return bcadd($price, '0', 12);
    }
}
