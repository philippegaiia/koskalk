<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('regulatory_legal_entries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('regulatory_regime_id');
            $table->foreign('regulatory_regime_id')->references('id')->on('regulatory_regimes')->restrictOnDelete();
            $table->string('annex', 4);
            $table->string('entry_reference', 32);
            $table->string('clause', 32)->nullable();
            $table->text('substance_description');
            $table->string('product_scope', 24);
            $table->text('product_type_body_parts')->nullable();
            $table->decimal('max_concentration_percent', 12, 6)->nullable();
            $table->text('other_limitations')->nullable();
            $table->text('label_warnings_fr')->nullable();
            $table->date('effective_from')->nullable();
            $table->date('effective_until')->nullable();
            $table->date('placing_on_market_until')->nullable();
            $table->date('making_available_until')->nullable();
            $table->text('source_reference');
            $table->string('review_status', 16)->default('draft')->index();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['regulatory_regime_id', 'entry_reference']);
        });

        Schema::create('regulatory_legal_entry_substance', function (Blueprint $table) {
            $table->foreignId('regulatory_legal_entry_id')->constrained(indexName: 'rles_entry_id_foreign')->cascadeOnDelete();
            $table->unsignedBigInteger('substance_id');
            $table->foreign('substance_id', 'rles_substance_id_foreign')->references('id')->on('substance_catalog')->restrictOnDelete();
            $table->primary(['regulatory_legal_entry_id', 'substance_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('regulatory_legal_entry_substance');
        Schema::dropIfExists('regulatory_legal_entries');
    }
};
