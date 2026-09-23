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
        Schema::table('ingredient_lot_number_settings', function (Blueprint $table): void {
            $table->unsignedSmallInteger('padding')->default(1)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ingredient_lot_number_settings', function (Blueprint $table): void {
            $table->unsignedSmallInteger('padding')->default(4)->change();
        });
    }
};
