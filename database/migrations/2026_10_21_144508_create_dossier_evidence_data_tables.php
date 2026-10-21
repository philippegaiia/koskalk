<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dossier_evidence_constituents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dossier_evidence_item_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 32);
            $table->unsignedBigInteger('allergen_id')->nullable();
            $table->foreign('allergen_id')->references('id')->on('allergen_catalog')->restrictOnDelete();
            $table->unsignedBigInteger('substance_id')->nullable();
            $table->foreign('substance_id')->references('id')->on('substance_catalog')->restrictOnDelete();
            $table->decimal('max_value', 20, 6);
            $table->string('unit', 16);
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['allergen_id']);
            $table->index(['substance_id']);
        });

        Schema::create('dossier_evidence_use_limits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dossier_evidence_item_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('ifra_product_category_id')->nullable();
            $table->foreign('ifra_product_category_id')->references('id')->on('ifra_product_categories')->restrictOnDelete();
            $table->string('product_class_label')->nullable();
            $table->decimal('max_percent', 12, 6);
            $table->timestamps();
        });

        Schema::table('dossier_evidence_items', function (Blueprint $table) {
            $table->json('facts')->nullable()->after('notes');
            $table->boolean('constituents_complete')->default(false)->after('facts');
            $table->foreignId('data_updated_by_user_id')->nullable()->after('facts')->constrained('users')->nullOnDelete();
            $table->timestamp('data_updated_at')->nullable()->after('data_updated_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('dossier_evidence_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('data_updated_by_user_id');
            $table->dropColumn(['facts', 'constituents_complete', 'data_updated_at']);
        });
        Schema::dropIfExists('dossier_evidence_use_limits');
        Schema::dropIfExists('dossier_evidence_constituents');
    }
};
