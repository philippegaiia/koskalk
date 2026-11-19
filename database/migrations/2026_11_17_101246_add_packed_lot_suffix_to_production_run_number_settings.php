<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_run_number_settings', function (Blueprint $table): void {
            $table->string('packed_lot_suffix', 16)->default('letter');
        });
    }

    public function down(): void
    {
        Schema::table('production_run_number_settings', function (Blueprint $table): void {
            $table->dropColumn('packed_lot_suffix');
        });
    }
};
