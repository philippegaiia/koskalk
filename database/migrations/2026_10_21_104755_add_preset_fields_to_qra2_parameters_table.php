<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('qra2_parameters', function (Blueprint $table) {
            $table->string('preset_label')->nullable()->after('ifra_amendment_id');
            $table->text('factor_deviation_reason')->nullable()->after('applies_qra2_factor');
        });
    }

    public function down(): void
    {
        Schema::table('qra2_parameters', function (Blueprint $table) {
            $table->dropColumn(['preset_label', 'factor_deviation_reason']);
        });
    }
};
