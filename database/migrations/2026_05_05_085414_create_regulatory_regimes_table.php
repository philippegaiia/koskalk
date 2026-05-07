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
        Schema::create('regulatory_regimes', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('market_code', 16);
            $table->string('name');
            $table->string('version_label')->nullable();
            $table->string('status', 32)->default('active');
            $table->boolean('is_default')->default(false);
            $table->date('effective_from')->nullable();
            $table->date('effective_until')->nullable();
            $table->string('source_name')->nullable();
            $table->string('source_url')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('notes')->nullable();
            $table->json('source_data')->nullable();
            $table->timestamps();

            $table->index(['market_code', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('regulatory_regimes');
    }
};
