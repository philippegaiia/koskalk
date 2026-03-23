<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ifra_certificate_limits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ifra_certificate_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ifra_product_category_id')->constrained()->cascadeOnDelete();
            $table->decimal('max_percentage', 8, 5)->nullable();
            $table->text('restriction_note')->nullable();
            $table->json('source_data')->nullable();
            $table->timestamps();

            $table->unique(['ifra_certificate_id', 'ifra_product_category_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ifra_certificate_limits');
    }
};
