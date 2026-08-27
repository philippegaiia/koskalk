<?php

namespace App\Actions\Purchasing;

use App\Enums\ProcurementStage;
use App\Enums\PurchaseOrderStatus;
use App\Models\PackagingItem;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierListing;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ProductionBenchAccess;
use App\Services\WorkspaceIngredientCodeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreatePurchaseOrder
{
    public function __construct(
        private readonly ProductionBenchAccess $access,
        private readonly WorkspaceIngredientCodeService $workspaceIngredientCodes,
    ) {}

    /**
     * @param  array<int, array{listing: SupplierListing, packs: int}>  $lines
     */
    public function handle(
        User $actor,
        Workspace $workspace,
        Supplier $supplier,
        array $lines,
        ?string $expectedAt = null,
        ?string $notes = null,
        ProcurementStage $stage = ProcurementStage::PurchaseOrder,
    ): PurchaseOrder {
        $this->access->assertWritable($actor, $workspace);

        if ($supplier->workspace_id !== $workspace->id || $lines === []) {
            throw ValidationException::withMessages(['supplier' => 'Choose a supplier and at least one listing from this workspace.']);
        }

        $currencies = collect($lines)
            ->map(fn (array $line): string => strtoupper($line['listing']->currency))
            ->unique();

        if ($currencies->count() !== 1) {
            throw ValidationException::withMessages(['lines' => 'All order lines must use the same currency.']);
        }

        return DB::transaction(function () use ($actor, $workspace, $supplier, $lines, $expectedAt, $notes, $currencies, $stage): PurchaseOrder {
            Workspace::withoutGlobalScopes()->lockForUpdate()->findOrFail($workspace->id);
            $sequence = PurchaseOrder::query()->where('workspace_id', $workspace->id)->count() + 1;
            $ingredientIds = collect($lines)
                ->map(fn (array $line): ?int => $line['listing']->ingredient_id)
                ->filter()
                ->map(fn (int $ingredientId): int => $ingredientId)
                ->unique()
                ->values()
                ->all();
            $packagingItemIds = collect($lines)
                ->map(fn (array $line): ?int => $line['listing']->packaging_item_id)
                ->filter()
                ->map(fn (int $packagingItemId): int => $packagingItemId)
                ->unique()
                ->values()
                ->all();
            $ingredientCodes = $this->workspaceIngredientCodes->codesFor($workspace, $ingredientIds);
            $packagingCodes = $packagingItemIds === []
                ? collect()
                : PackagingItem::query()
                    ->where('workspace_id', $workspace->id)
                    ->whereIn('id', $packagingItemIds)
                    ->pluck('material_code', 'id');
            $order = PurchaseOrder::query()->create([
                'workspace_id' => $workspace->id,
                'supplier_id' => $supplier->id,
                'reference' => 'PO-'.now()->format('ym').'-'.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT),
                'stage' => $stage,
                'status' => PurchaseOrderStatus::Draft,
                'expected_at' => $expectedAt,
                'currency' => $currencies->sole(),
                'notes' => $notes,
                'created_by_user_id' => $actor->id,
            ]);

            foreach ($lines as $input) {
                $listing = $input['listing'];
                $packs = $input['packs'];
                $hasPrice = $stage === ProcurementStage::PurchaseOrder;

                if (
                    $listing->workspace_id !== $workspace->id
                    || $listing->supplier_id !== $supplier->id
                    || $packs < 1
                ) {
                    throw ValidationException::withMessages(['lines' => 'Every listing must belong to this supplier and use a positive whole pack quantity.']);
                }

                $order->lines()->create([
                    'supplier_listing_id' => $listing->id,
                    'ingredient_id' => $listing->ingredient_id,
                    'packaging_item_id' => $listing->packaging_item_id,
                    'supplier_sku' => $listing->supplier_sku,
                    'supplier_item_name' => $listing->supplier_item_name,
                    'listing_name' => $listing->purchase_format,
                    'material_code_snapshot' => $listing->ingredient_id !== null
                        ? $ingredientCodes->get($listing->ingredient_id)
                        : $packagingCodes->get($listing->packaging_item_id),
                    'unit_kind' => $listing->unit_kind,
                    'ordered_packs' => $packs,
                    'canonical_quantity_per_pack' => $listing->canonical_quantity_per_purchase_format,
                    'pack_price' => $hasPrice ? $listing->total_price : null,
                    'price_basis' => $hasPrice ? $listing->price_basis : null,
                    'price_amount' => $hasPrice ? $listing->price_amount : null,
                    'price_unit' => $hasPrice ? $listing->price_unit : null,
                    'price_recorded_at' => $hasPrice ? $listing->price_recorded_at : null,
                    'currency' => $listing->currency,
                    'expected_quantity' => bcmul($listing->canonical_quantity_per_purchase_format, (string) $packs, 9),
                    'expected_cost' => $hasPrice ? bcmul($listing->total_price, (string) $packs, 9) : null,
                    'organic_status' => $listing->organic_status,
                ]);
            }

            return $order->load('lines');
        }, attempts: 5);
    }
}
