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
        Schema::create('ingredient_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ingredient_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 16);
            $table->string('display_name')->nullable();
            $table->text('info_markdown')->nullable();
            $table->timestamps();

            $table->unique(['ingredient_id', 'locale']);
            $table->index(['locale', 'display_name']);
            $table->foreign('locale')
                ->references('code')
                ->on('supported_locales')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ingredient_translations');
    }
};
