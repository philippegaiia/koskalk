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
        Schema::table('ifra_certificates', function (Blueprint $table) {
            $table->decimal('peroxide_value', 8, 3)->nullable()->after('valid_from');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ifra_certificates', function (Blueprint $table) {
            $table->dropColumn('peroxide_value');
        });
    }
};
