<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dossier_evidence_use_limits', function (Blueprint $table) {
            $table->string('limit_type', 16)->default('maximum')->after('max_percent');
            $table->decimal('max_percent', 12, 6)->nullable()->change();
        });
    }

    public function down(): void
    {
        // A "no restriction" statement has no maximum to restore; those rows cannot exist in the older schema.
        DB::table('dossier_evidence_use_limits')->where('limit_type', 'no_restriction')->delete();

        Schema::table('dossier_evidence_use_limits', function (Blueprint $table) {
            $table->decimal('max_percent', 12, 6)->nullable(false)->change();
            $table->dropColumn('limit_type');
        });
    }
};
