<?php

namespace App\Actions\Purchasing;

use App\GoodsReceiptSource;
use App\GoodsReceiptStatus;
use App\ListingPriceBasis;
use App\Models\GoodsReceipt;
use App\Models\Ingredient;
use App\Models\PackagingItem;
use App\Models\Supplier;
use App\Models\SupplierListing;
use App\Models\User;
use App\Models\Workspace;
use App\OwnerType;
use App\Services\CurrencyCatalog;
use App\Services\MassConverter;
use App\Services\ProductionBenchAccess;
use App\Services\SupplierListingPriceCalculator;
use App\StockUnitKind;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReceiveDirectGoodsReceipt
{
    public function __construct(
        private readonly ProductionBenchAccess $access,
        private readonly MassConverter $massConverter,
        private readonly SupplierListingPriceCalculator $priceCalculator,
        private readonly PostGoodsReceiptLine $postLine,
        private readonly GoodsReceiptInputValidator $inputValidator,
        private readonly CurrencyCatalog $currencyCatalog,
    ) {}

    /**
     * @param  array<int, array{
     *   listing: SupplierListing,
     *   packs_received: int,
     *   actual_quantity: string,
     *   actual_unit: string,
     *   receipt_price_basis: ListingPriceBasis,
     *   receipt_price_amount: string,
     *   receipt_price_unit?: ?string,
     *   currency: string,
     *   supplier_batch_number?: ?string,
     *   expires_at?: ?string,
     *   notes?: ?string,
     * }>  $lines
     */
    public function handle(
        User $actor,
        Workspace $workspace,
        Supplier $supplier,
        string $idempotencyKey,
        array $lines,
        ?string $receivedAt,
        ?string $deliveryReference = null,
        ?string $notes = null,
    ): GoodsReceipt {
        $header = $this->inputValidator->header(
            $idempotencyKey,
            $receivedAt,
            $deliveryReference,
            $notes,
            requiresReceiptDate: true,
        );
        $idempotencyKey = $header['idempotency_key'];
        $receivedAt = $header['received_at'];
        $deliveryReference = $header['delivery_reference'];
        $notes = $header['notes'];
        $this->access->assertWritable($actor, $workspace);

        return DB::transaction(function () use (
            $actor,
            $workspace,
            $supplier,
            $idempotencyKey,
            $lines,
            $receivedAt,
            $deliveryReference,
            $notes,
        ): GoodsReceipt {
            $lockedWorkspace = Workspace::withoutGlobalScopes()->lockForUpdate()->findOrFail($workspace->id);
            $this->access->assertWritable($actor, $lockedWorkspace);
            $lockedSupplier = Supplier::query()->lockForUpdate()->findOrFail($supplier->id);

            $existing = GoodsReceipt::query()
                ->where('workspace_id', $lockedWorkspace->id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing instanceof GoodsReceipt) {
                if ($existing->source !== GoodsReceiptSource::Direct || $existing->supplier_id !== $lockedSupplier->id) {
                    throw ValidationException::withMessages([
                        'idempotency_key' => __('production_bench.receipt.idempotency_conflict'),
                    ]);
                }

                return $existing->loadMissing('lines.stockLot');
            }

            if ($lockedSupplier->workspace_id !== $lockedWorkspace->id) {
                throw ValidationException::withMessages([
                    'supplier' => __('production_bench.receipt.supplier_workspace_mismatch'),
                ]);
            }

            if ($lines === []) {
                throw ValidationException::withMessages(['lines' => __('production_bench.receipt.lines_required')]);
            }

            $normalizedLines = [];

            foreach ($lines as $index => $input) {
                $normalizedLines[] = $this->normalizeLine(
                    $actor,
                    $lockedWorkspace,
                    $lockedSupplier,
                    $input,
                    $index,
                );
            }

            $receipt = GoodsReceipt::query()->create([
                'workspace_id' => $lockedWorkspace->id,
                'supplier_id' => $lockedSupplier->id,
                'purchase_order_id' => null,
                'source' => GoodsReceiptSource::Direct,
                'delivery_reference' => $deliveryReference,
                'received_at' => $receivedAt,
                'status' => GoodsReceiptStatus::Posted,
                'notes' => $notes,
                'received_by_user_id' => $actor->id,
                'idempotency_key' => $idempotencyKey,
            ]);

            foreach ($normalizedLines as $index => $line) {
                $line['listing']->update([
                    'price_basis' => $line['receipt_price_basis'],
                    'price_amount' => $line['receipt_price_amount'],
                    'price_unit' => $line['receipt_price_unit'],
                    'total_price' => $line['purchase_format_price'],
                    'currency' => $line['currency'],
                    'price_recorded_at' => $line['price_recorded_at'],
                ]);
                $this->postLine->handle(
                    actor: $actor,
                    workspace: $lockedWorkspace,
                    receipt: $receipt,
                    listing: $line['listing'],
                    purchaseOrderLine: null,
                    packsReceived: $line['packs_received'],
                    actualQuantity: $line['actual_quantity'],
                    originalQuantity: $line['original_quantity'],
                    originalUnit: $line['original_unit'],
                    receiptPriceBasis: $line['receipt_price_basis'],
                    receiptPriceAmount: $line['receipt_price_amount'],
                    receiptPriceUnit: $line['receipt_price_unit'],
                    purchaseFormatPrice: $line['purchase_format_price'],
                    currency: $line['currency'],
                    movementIdempotencyKey: $this->inputValidator->movementKey(
                        $idempotencyKey,
                        'direct:'.$index,
                    ),
                    supplierBatchNumber: $line['supplier_batch_number'],
                    expiresAt: $line['expires_at'],
                    notes: $line['notes'],
                );
            }

            return $receipt->load('lines.stockLot');
        }, attempts: 5);
    }

    /**
     * @param  array{
     *   listing: SupplierListing,
     *   packs_received: int,
     *   actual_quantity: string,
     *   actual_unit: string,
     *   receipt_price_basis: ListingPriceBasis,
     *   receipt_price_amount: string,
     *   receipt_price_unit?: ?string,
     *   currency: string,
     *   supplier_batch_number?: ?string,
     *   expires_at?: ?string,
     *   notes?: ?string,
     * }  $input
     * @return array{
     *   listing: SupplierListing,
     *   packs_received: int,
     *   actual_quantity: string,
     *   original_quantity: string,
     *   original_unit: string,
     *   receipt_price_basis: ListingPriceBasis,
     *   receipt_price_amount: string,
     *   receipt_price_unit: ?string,
     *   purchase_format_price: string,
     *   currency: string,
     *   supplier_batch_number: ?string,
     *   expires_at: ?string,
     *   notes: ?string,
     *   price_recorded_at: Carbon,
     * }
     */
    private function normalizeLine(
        User $actor,
        Workspace $workspace,
        Supplier $supplier,
        array $input,
        int $index,
    ): array {
        $this->inputValidator->line($input, $index);
        $listing = SupplierListing::query()->lockForUpdate()->findOrFail($input['listing']->id);

        if (
            $listing->workspace_id !== $workspace->id
            || $listing->supplier_id !== $supplier->id
            || ! $listing->is_active
        ) {
            throw ValidationException::withMessages([
                "lines.$index.listing" => __('production_bench.receipt.direct_listing_active'),
            ]);
        }

        $this->assertSubjectIsAccessible($actor, $workspace, $listing, $index);

        if (($listing->ingredient_id === null) === ($listing->packaging_item_id === null)) {
            throw ValidationException::withMessages([
                "lines.$index.listing" => __('production_bench.receipt.listing_subject_required'),
            ]);
        }

        if (
            ($listing->unit_kind === StockUnitKind::Mass && $listing->ingredient_id === null)
            || ($listing->unit_kind === StockUnitKind::Count && $listing->packaging_item_id === null)
        ) {
            throw ValidationException::withMessages([
                "lines.$index.listing" => __('production_bench.receipt.listing_unit_mismatch'),
            ]);
        }

        if (! isset($input['packs_received']) || ! is_int($input['packs_received']) || $input['packs_received'] < 1) {
            throw ValidationException::withMessages([
                "lines.$index.packs_received" => __('production_bench.receipt.formats_positive_whole'),
            ]);
        }

        $basis = $input['receipt_price_basis'] ?? null;

        if (! $basis instanceof ListingPriceBasis) {
            throw ValidationException::withMessages([
                "lines.$index.receipt_price_basis" => __('production_bench.receipt.invalid_price_basis'),
            ]);
        }

        $currency = strtoupper(trim($input['currency'] ?? ''));

        if (
            ! $this->currencyCatalog->isSelectable($currency)
            || $currency !== strtoupper($listing->currency)
        ) {
            throw ValidationException::withMessages([
                "lines.$index.currency" => __('production_bench.receipt.direct_currency_mismatch'),
            ]);
        }

        $actualQuantity = $listing->unit_kind === StockUnitKind::Mass
            ? $this->positiveMassQuantity($input['actual_quantity'], $input['actual_unit'])
            : $this->countQuantity($input['actual_quantity'], $input['actual_unit']);
        $priceUnit = $input['receipt_price_unit'] ?? null;
        $calculatedPrice = $listing->unit_kind === StockUnitKind::Mass
            ? $this->priceCalculator->forMass(
                $listing->net_quantity,
                $listing->net_unit,
                $basis,
                $input['receipt_price_amount'],
                $priceUnit,
            )
            : $this->priceCalculator->forCount(
                $listing->net_quantity,
                $basis,
                $input['receipt_price_amount'],
            );

        if ($listing->unit_kind === StockUnitKind::Count && ! in_array($priceUnit, [null, 'count'], true)) {
            throw ValidationException::withMessages([
                "lines.$index.receipt_price_unit" => __('production_bench.receipt.packaging_price_unit'),
            ]);
        }

        return [
            'listing' => $listing,
            'packs_received' => $input['packs_received'],
            'actual_quantity' => $actualQuantity,
            'original_quantity' => $input['actual_quantity'],
            'original_unit' => $input['actual_unit'],
            'receipt_price_basis' => $basis,
            'receipt_price_amount' => bcadd($input['receipt_price_amount'], '0', 9),
            'receipt_price_unit' => $priceUnit,
            'purchase_format_price' => $calculatedPrice['total_price'],
            'currency' => $currency,
            'supplier_batch_number' => $input['supplier_batch_number'] ?? null,
            'expires_at' => $input['expires_at'] ?? null,
            'notes' => $input['notes'] ?? null,
            'price_recorded_at' => now(),
        ];
    }

    private function assertSubjectIsAccessible(
        User $actor,
        Workspace $workspace,
        SupplierListing $listing,
        int $index,
    ): void {
        if ($listing->ingredient_id !== null) {
            $ingredient = Ingredient::withoutGlobalScopes()->findOrFail($listing->ingredient_id);
            $ingredientWorkspaceId = $ingredient->tenantWorkspaceId();

            if ($ingredientWorkspaceId === null && $ingredient->tenantOwnerType() === OwnerType::Workspace) {
                $ingredientWorkspaceId = $ingredient->tenantOwnerId();
            }

            if (
                ! $ingredient->isAccessibleBy($actor)
                || (
                    ! $ingredient->isPublicCatalog()
                    && $ingredientWorkspaceId !== null
                    && $ingredientWorkspaceId !== $workspace->id
                )
            ) {
                throw ValidationException::withMessages([
                    "lines.$index.listing" => __('production_bench.receipt.listing_ingredient_inaccessible'),
                ]);
            }

            return;
        }

        $packagingItem = PackagingItem::query()->findOrFail($listing->packaging_item_id);

        if ($packagingItem->workspace_id !== $workspace->id) {
            throw ValidationException::withMessages([
                "lines.$index.listing" => __('production_bench.receipt.listing_packaging_workspace'),
            ]);
        }
    }

    private function positiveMassQuantity(string $quantity, string $unit): string
    {
        $canonicalQuantity = $this->massConverter->toGrams($quantity, $unit);

        if (bccomp($canonicalQuantity, '0', 9) <= 0) {
            throw ValidationException::withMessages(['actual_quantity' => __('production_bench.receipt.actual_positive')]);
        }

        return $canonicalQuantity;
    }

    private function countQuantity(string $quantity, string $unit): string
    {
        if ($unit !== 'count' || preg_match('/^[1-9]\d*$/', $quantity) !== 1) {
            throw ValidationException::withMessages(['actual_quantity' => __('production_bench.receipt.whole_count')]);
        }

        return bcadd($quantity, '0', 9);
    }
}
