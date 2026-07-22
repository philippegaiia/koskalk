<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recipes', function (Blueprint $table): void {
            $table->string('featured_image_original_name')->nullable();
        });

        Schema::table('ingredients', function (Blueprint $table): void {
            $table->string('featured_image_original_name')->nullable();
            $table->string('icon_image_original_name')->nullable();
        });

        Schema::table('user_packaging_items', function (Blueprint $table): void {
            $table->string('featured_image_original_name')->nullable();
        });

        Schema::table('product_types', function (Blueprint $table): void {
            $table->string('fallback_image_original_name')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('recipes', function (Blueprint $table): void {
            $table->dropColumn('featured_image_original_name');
        });

        Schema::table('ingredients', function (Blueprint $table): void {
            $table->dropColumn([
                'featured_image_original_name',
                'icon_image_original_name',
            ]);
        });

        Schema::table('user_packaging_items', function (Blueprint $table): void {
            $table->dropColumn('featured_image_original_name');
        });

        Schema::table('product_types', function (Blueprint $table): void {
            $table->dropColumn('fallback_image_original_name');
        });
    }
};
