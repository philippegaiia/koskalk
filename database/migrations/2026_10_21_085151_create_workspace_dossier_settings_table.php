<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_dossier_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('responsible_person_name');
            $table->text('responsible_person_address');
            $table->string('responsible_person_siret', 14)->nullable();
            $table->string('manufacturer_name')->nullable();
            $table->text('manufacturer_address')->nullable();
            $table->string('packer_name')->nullable();
            $table->text('packer_address')->nullable();
            $table->string('numbering_prefix', 8);
            $table->string('assessor_name')->nullable();
            $table->text('assessor_address')->nullable();
            $table->text('assessor_qualification')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_dossier_settings');
    }
};
