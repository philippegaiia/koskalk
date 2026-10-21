<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dossier_evidence_items', function (Blueprint $table) {
            $table->text('withdrawn_reason')->nullable()->after('replaced_by_id');
            $table->foreignId('withdrawn_by_user_id')->nullable()->after('withdrawn_reason')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('dossier_evidence_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('withdrawn_by_user_id');
            $table->dropColumn('withdrawn_reason');
        });
    }
};
