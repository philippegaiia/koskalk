<?php

namespace App\Actions\Purchasing;

use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierListing;
use App\Models\User;
use App\Models\Workspace;
use App\PurchaseOrderStatus;
use App\Services\ProductionBenchAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreatePurchaseOrder
{
    public function __construct(private readonly ProductionBenchAccess $access) {}

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

        return DB::transaction(function () use ($actor, $workspace, $supplier, $lines, $expectedAt, $notes, $currencies): PurchaseOrder {
            Workspace::withoutGlobalScopes()->lockForUpdate()->findOrFail($workspace->id);
            $sequence = PurchaseOrder::query()->where('workspace_id', $workspace->id)->count() + 1;
            $order = PurchaseOrder::query()->create([
                'workspace_id' => $workspace->id,
                'supplier_id' => $supplier->id,
                'reference' => 'PO-'.now()->format('ym').'-'.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT),
                'status' => PurchaseOrderStatus::Draft,
                'expected_at' => $expectedAt,
                'currency' => $currencies->sole(),
                'notes' => $notes,
                'created_by_user_id' => $actor->id,
            ]);

            foreach ($lines as $input) {
                $listing = $input['listing'];
                $packs = $input['packs'];

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
                    'listing_name' => $listing->purchase_format,
                    'unit_kind' => $listing->unit_kind,
                    'ordered_packs' => $packs,
                    'canonical_quantity_per_pack' => $listing->canonical_quantity_per_purchase_format,
                    'pack_price' => $listing->total_price,
                    'currency' => $listing->currency,
                    'expected_quantity' => bcmul($listing->canonical_quantity_per_purchase_format, (string) $packs, 9),
                    'expected_cost' => bcmul($listing->total_price, (string) $packs, 9),
                    'organic_status' => $listing->organic_status,
                ]);
            }

            return $order->load('lines');
        }, attempts: 5);
    }
}
