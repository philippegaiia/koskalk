<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('production_output_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('soap_ready_delay_days')->default(21);
            $table->unsignedInteger('cosmetic_ready_delay_days')->default(3);
            $table->timestamps();
        });

        foreach (DB::table('workspaces')->pluck('id') as $workspaceId) {
            DB::table('production_output_settings')->insert([
                'workspace_id' => $workspaceId,
                'soap_ready_delay_days' => 21,
                'cosmetic_ready_delay_days' => 3,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE production_output_settings
                ADD CONSTRAINT production_output_settings_non_negative_days_check
                CHECK (soap_ready_delay_days >= 0 AND cosmetic_ready_delay_days >= 0)
            SQL);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('production_output_settings');
    }
};
