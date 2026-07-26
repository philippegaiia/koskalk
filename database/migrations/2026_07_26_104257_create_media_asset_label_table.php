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
        Schema::create('media_asset_label', function (Blueprint $table): void {
            $table->foreignId('media_asset_id')->constrained()->cascadeOnDelete();
            $table->foreignId('media_label_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['media_asset_id', 'media_label_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('media_asset_label');
    }
};
