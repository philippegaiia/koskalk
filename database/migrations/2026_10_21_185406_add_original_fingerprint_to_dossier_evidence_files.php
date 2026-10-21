<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dossier_evidence_files', function (Blueprint $table) {
            $table->string('storage_processing', 32)->default('as_received')->after('sha256');
            $table->char('original_sha256', 64)->nullable()->after('storage_processing');
            $table->unsignedBigInteger('original_size_bytes')->nullable()->after('original_sha256');
        });

        // Files stored so far were kept as received: their original fingerprint is the stored one.
        DB::table('dossier_evidence_files')->update([
            'original_sha256' => DB::raw('sha256'),
            'original_size_bytes' => DB::raw('size_bytes'),
        ]);
    }

    public function down(): void
    {
        Schema::table('dossier_evidence_files', function (Blueprint $table) {
            $table->dropColumn(['storage_processing', 'original_sha256', 'original_size_bytes']);
        });
    }
};
