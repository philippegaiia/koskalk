<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('supplier_listings', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ingredient_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('user_packaging_item_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('supplier_sku')->nullable();
            $table->string('supplier_name')->nullable();
            $table->string('pack_description');
            $table->string('container')->nullable();
            $table->string('unit_kind', 16);
            $table->decimal('canonical_quantity_per_pack', 20, 9);
            $table->decimal('commercial_quantity', 20, 9)->nullable();
            $table->string('commercial_unit', 24)->nullable();
            $table->decimal('pack_price', 20, 9);
            $table->string('currency', 3);
            $table->unsignedInteger('minimum_packs')->default(1);
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['workspace_id', 'supplier_id', 'is_active']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE supplier_listings
            ADD CONSTRAINT supplier_listings_exact_subject_check
            CHECK (
                (ingredient_id IS NOT NULL AND user_packaging_item_id IS NULL AND unit_kind = 'mass')
                OR
                (ingredient_id IS NULL AND user_packaging_item_id IS NOT NULL AND unit_kind = 'count')
            )
        SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('supplier_listings');
    }
};
