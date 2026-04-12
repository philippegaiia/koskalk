<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ingredients', function (Blueprint $table) {
            $table->dropColumn('price_eur');
            $table->dropColumn('display_name_en');
        });
    }

    public function down(): void
    {
        Schema::table('ingredients', function (Blueprint $table) {
            $table->decimal('price_eur', 10, 2)->nullable()->after('unit');
            $table->string('display_name_en', 255)->nullable()->after('display_name');
        });
    }
};
