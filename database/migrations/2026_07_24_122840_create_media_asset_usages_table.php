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
        Schema::create('media_asset_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('media_asset_id')->constrained()->cascadeOnDelete();
            $table->morphs('usable');
            $table->string('role', 64);
            $table->timestamps();

            $table->unique(
                ['media_asset_id', 'usable_type', 'usable_id', 'role'],
                'media_asset_usage_unique',
            );
            $table->index(
                ['usable_type', 'usable_id', 'role'],
                'media_asset_usage_target_role_index',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('media_asset_usages');
    }
};
