<?php

namespace App\Actions\Inventory;

use App\Enums\MaterialPriceSource;
use App\Enums\StockLotOrigin;
use App\Enums\StockLotStatus;
use App\Enums\StockMovementType;
use App\Enums\StockUnitKind;
use App\Models\Ingredient;
use App\Models\PackagingItem;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\SupplierListing;
use App\Models\User;
use App\Models\Workspace;
use App\Services\CurrentMaterialPriceService;
use App\Services\InternalLotCodeGenerator;
use App\Services\MassConverter;
use App\Services\ProductionBenchAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CreateOpeningStockLot
{
    public function __construct(
        private readonly ProductionBenchAccess $access,
        private readonly MassConverter $massConverter,
        private readonly InternalLotCodeGenerator $lotCodeGenerator,
        private readonly CurrentMaterialPriceService $currentMaterialPriceService,
    ) {}

    public function handle(
        User $actor,
        Workspace $workspace,
        SupplierListing $listing,
        string $quantity,
        string $unit,
        string $pricePerCanonicalUnit,
        string $currency,
        string $idempotencyKey,
        ?string $supplierBatchNumber = null,
        ?string $stockedAt = null,
        ?string $expiresAt = null,
        ?string $notes = null,
    ): StockLot {
        $this->access->assertWritable($actor, $workspace);

        Validator::make([
            'quantity' => $quantity,
            'idempotency_key' => $idempotencyKey,
            'stocked_at' => $stockedAt,
            'expires_at' => $expiresAt,
            'price' => $pricePerCanonicalUnit,
            'currency' => $currency,
        ], [
            'quantity' => ['required', 'numeric', 'gt:0'],
            'idempotency_key' => ['required', 'string', 'max:120'],
            'stocked_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date'],
            'price' => ['required', 'numeric', 'min:0'],
            'currency' => ['required', 'string', 'size:3'],
        ])->validate();

        $currentListing = SupplierListing::query()
            ->where('workspace_id', $workspace->id)
            ->find($listing->id);

        if (! $currentListing instanceof SupplierListing) {
            throw ValidationException::withMessages([
                'supplier_listing' => 'Choose a supplier listing from this workspace.',
            ]);
        }

        $subject = $currentListing->ingredient_id !== null
            ? Ingredient::withoutGlobalScopes()->findOrFail($currentListing->ingredient_id)
            : PackagingItem::query()->where('workspace_id', $workspace->id)->findOrFail($currentListing->packaging_item_id);
        $isMass = $subject instanceof Ingredient;
        $canonicalQuantity = $isMass
            ? $this->massConverter->toGrams($quantity, $unit)
            : $this->validateCount($quantity, $unit);

        return DB::transaction(function () use (
            $actor,
            $workspace,
            $currentListing,
            $subject,
            $quantity,
            $unit,
            $pricePerCanonicalUnit,
            $currency,
            $idempotencyKey,
            $supplierBatchNumber,
            $stockedAt,
            $expiresAt,
            $notes,
            $isMass,
            $canonicalQuantity,
        ): StockLot {
            $existing = StockMovement::query()
                ->where('workspace_id', $workspace->id)
                ->where('idempotency_key', $idempotencyKey)
                ->with('stockLot')
                ->first();

            if ($existing instanceof StockMovement) {
                return $existing->stockLot;
            }

            Workspace::withoutGlobalScopes()->lockForUpdate()->findOrFail($workspace->id);

            $lot = StockLot::query()->create([
                'workspace_id' => $workspace->id,
                'supplier_listing_id' => $currentListing->id,
                'ingredient_id' => $isMass ? $subject->id : null,
                'packaging_item_id' => $isMass ? null : $subject->id,
                'organic_status' => $currentListing->organic_status,
                'internal_lot_code' => $this->lotCodeGenerator->next($workspace),
                'supplier_batch_number' => $supplierBatchNumber,
                'origin' => StockLotOrigin::OpeningBalance,
                'unit_kind' => $isMass ? StockUnitKind::Mass : StockUnitKind::Count,
                'status' => StockLotStatus::Released,
                'stocked_at' => $stockedAt ?? now()->toDateString(),
                'expires_at' => $expiresAt,
                'available_from' => $stockedAt ?? now()->toDateString(),
                'released_at' => now(),
                'released_by_user_id' => $actor->id,
                'provenance_complete' => true,
                'historical_unit_cost' => $pricePerCanonicalUnit,
                'currency' => strtoupper($currency),
                'notes' => $notes,
            ]);

            $lot->movements()->create([
                'workspace_id' => $workspace->id,
                'type' => StockMovementType::OpeningBalance,
                'quantity_delta' => $canonicalQuantity,
                'original_quantity' => $quantity,
                'original_unit' => $unit,
                'occurred_at' => now(),
                'actor_user_id' => $actor->id,
                'idempotency_key' => $idempotencyKey,
            ]);

            if ($subject instanceof Ingredient) {
                $this->currentMaterialPriceService->rememberIngredient(
                    workspace: $workspace,
                    ingredient: $subject,
                    pricePerMassUnit: $pricePerCanonicalUnit,
                    massUnit: 'g',
                    currency: $currency,
                    source: MaterialPriceSource::OpeningStock,
                    sourceId: $lot->id,
                    actor: $actor,
                );
            } else {
                $this->currentMaterialPriceService->rememberPackaging(
                    workspace: $workspace,
                    packagingItem: $subject,
                    pricePerItem: $pricePerCanonicalUnit,
                    currency: $currency,
                    source: MaterialPriceSource::OpeningStock,
                    sourceId: $lot->id,
                    actor: $actor,
                );
            }

            return $lot->load('movements');
        }, attempts: 5);
    }

    private function validateCount(string $quantity, string $unit): string
    {
        if ($unit !== 'count' || preg_match('/^[1-9]\d*$/', $quantity) !== 1) {
            throw ValidationException::withMessages([
                'quantity' => 'Packaging stock must be entered as a positive whole count.',
            ]);
        }

        return bcadd($quantity, '0', 9);
    }
}
