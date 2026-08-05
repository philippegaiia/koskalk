<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_runs', function (Blueprint $table): void {
            $table->string('planning_batch_number', 32)->nullable();
            $table->string('batch_number', 120)->nullable();
            $table->unsignedBigInteger('batch_number_serial')->nullable();
            $table->timestamp('batch_number_assigned_at')->nullable();
            $table->foreignId('batch_number_assigned_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->unique(['workspace_id', 'planning_batch_number'], 'production_runs_workspace_planning_batch_number_unique');
            $table->unique(['workspace_id', 'batch_number'], 'production_runs_workspace_batch_number_unique');
            $table->index(['workspace_id', 'batch_number_serial'], 'production_runs_workspace_batch_number_serial_index');
        });
    }

    public function down(): void
    {
        Schema::table('production_runs', function (Blueprint $table): void {
            $table->dropUnique('production_runs_workspace_planning_batch_number_unique');
            $table->dropUnique('production_runs_workspace_batch_number_unique');
            $table->dropIndex('production_runs_workspace_batch_number_serial_index');
            $table->dropForeign(['batch_number_assigned_by_user_id']);
            $table->dropColumn([
                'planning_batch_number',
                'batch_number',
                'batch_number_serial',
                'batch_number_assigned_at',
                'batch_number_assigned_by_user_id',
            ]);
        });
    }
};
