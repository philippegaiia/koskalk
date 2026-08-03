<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('goods_receipts', function (Blueprint $table): void {
            $table->foreignId('purchase_order_id')->nullable()->change();
            $table->foreignId('supplier_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('source', 24)->default('purchase_order');
            $table->text('reversal_reason')->nullable();

            $table->index('supplier_id');
            $table->index(['workspace_id', 'source', 'received_at'], 'goods_receipts_workspace_source_received_index');
        });

        Schema::table('goods_receipt_lines', function (Blueprint $table): void {
            $table->foreignId('purchase_order_line_id')->nullable()->change();
            $table->foreignId('supplier_listing_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('receipt_price_basis', 24)->nullable();
            $table->decimal('receipt_price_amount', 20, 9)->nullable();
            $table->string('receipt_price_unit', 24)->nullable();
            $table->decimal('purchase_format_price', 20, 9)->nullable();
            $table->string('currency', 3)->nullable();
            $table->text('notes')->nullable();

            $table->index('supplier_listing_id');
        });

        $this->backfillReceipts();
        $this->backfillReceiptLines();

        if (DB::table('goods_receipts')->whereNull('supplier_id')->exists()) {
            throw new RuntimeException('Unable to derive a supplier for every existing goods receipt.');
        }

        if (DB::table('goods_receipt_lines')
            ->whereNull('supplier_listing_id')
            ->orWhereNull('receipt_price_basis')
            ->orWhereNull('receipt_price_amount')
            ->orWhereNull('purchase_format_price')
            ->orWhereNull('currency')
            ->exists()) {
            throw new RuntimeException('Unable to derive listing and price snapshots for every existing goods receipt line.');
        }

        Schema::table('goods_receipts', function (Blueprint $table): void {
            $table->foreignId('supplier_id')->nullable(false)->change();
        });

        Schema::table('goods_receipt_lines', function (Blueprint $table): void {
            $table->foreignId('supplier_listing_id')->nullable(false)->change();
            $table->string('receipt_price_basis', 24)->nullable(false)->change();
            $table->decimal('receipt_price_amount', 20, 9)->nullable(false)->change();
            $table->decimal('purchase_format_price', 20, 9)->nullable(false)->change();
            $table->string('currency', 3)->nullable(false)->change();
        });
    }

    public function down(): void
    {
        $hasDirectReceipts = DB::table('goods_receipts')
            ->where('source', 'direct')
            ->orWhereNull('purchase_order_id')
            ->exists();
        $hasDirectReceiptLines = DB::table('goods_receipt_lines')
            ->whereNull('purchase_order_line_id')
            ->exists();

        if ($hasDirectReceipts || $hasDirectReceiptLines) {
            throw new LogicException(
                'Cannot roll back the goods receipt schema while direct receipts or lines exist; remove or migrate direct receipt data first.'
            );
        }

        Schema::table('goods_receipt_lines', function (Blueprint $table): void {
            $table->foreignId('purchase_order_line_id')->nullable(false)->change();
            $table->dropIndex(['supplier_listing_id']);
            $table->dropConstrainedForeignId('supplier_listing_id');
            $table->dropColumn([
                'receipt_price_basis',
                'receipt_price_amount',
                'receipt_price_unit',
                'purchase_format_price',
                'currency',
                'notes',
            ]);
        });

        Schema::table('goods_receipts', function (Blueprint $table): void {
            $table->foreignId('purchase_order_id')->nullable(false)->change();
            $table->dropIndex('goods_receipts_workspace_source_received_index');
            $table->dropIndex(['supplier_id']);
            $table->dropConstrainedForeignId('supplier_id');
            $table->dropColumn(['source', 'reversal_reason']);
        });
    }

    private function backfillReceipts(): void
    {
        DB::table('goods_receipts')
            ->select(['id', 'purchase_order_id'])
            ->orderBy('id')
            ->chunkById(500, function ($receipts): void {
                $supplierIds = DB::table('purchase_orders')
                    ->whereIn('id', $receipts->pluck('purchase_order_id')->filter())
                    ->pluck('supplier_id', 'id');

                foreach ($receipts as $receipt) {
                    $supplierId = $supplierIds->get($receipt->purchase_order_id);

                    if ($supplierId !== null) {
                        DB::table('goods_receipts')
                            ->where('id', $receipt->id)
                            ->update(['supplier_id' => $supplierId]);
                    }
                }
            });
    }

    private function backfillReceiptLines(): void
    {
        DB::table('goods_receipt_lines')
            ->select(['id', 'purchase_order_line_id'])
            ->orderBy('id')
            ->chunkById(500, function ($receiptLines): void {
                $orderLines = DB::table('purchase_order_lines')
                    ->whereIn('id', $receiptLines->pluck('purchase_order_line_id')->filter())
                    ->get([
                        'id',
                        'supplier_listing_id',
                        'price_basis',
                        'price_amount',
                        'price_unit',
                        'pack_price',
                        'currency',
                    ])
                    ->keyBy('id');

                foreach ($receiptLines as $receiptLine) {
                    $orderLine = $orderLines->get($receiptLine->purchase_order_line_id);

                    if ($orderLine === null) {
                        continue;
                    }

                    DB::table('goods_receipt_lines')
                        ->where('id', $receiptLine->id)
                        ->update([
                            'supplier_listing_id' => $orderLine->supplier_listing_id,
                            'receipt_price_basis' => $orderLine->price_basis ?? 'total_purchase_format',
                            'receipt_price_amount' => $orderLine->price_amount ?? $orderLine->pack_price,
                            'receipt_price_unit' => $orderLine->price_unit,
                            'purchase_format_price' => $orderLine->pack_price,
                            'currency' => $orderLine->currency,
                        ]);
                }
            });
    }
};
