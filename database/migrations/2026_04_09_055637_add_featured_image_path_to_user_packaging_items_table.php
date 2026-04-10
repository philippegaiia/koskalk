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
        Schema::table('user_packaging_items', function (Blueprint $table) {
            $table->string('featured_image_path')
                ->nullable()
                ->after('notes');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_packaging_items', function (Blueprint $table) {
            $table->dropColumn('featured_image_path');
        });
    }
};
