<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('production_runs')
            ->whereNotNull('batch_number')
            ->orderBy('id')
            ->select([
                'id',
                'workspace_id',
                'batch_number',
                'batch_number_serial',
                'batch_number_assigned_at',
                'batch_number_assigned_by_user_id',
            ])
            ->chunkById(500, function ($runs): void {
                $timestamp = now();
                $issuances = $runs->map(fn (object $run): array => [
                    'workspace_id' => $run->workspace_id,
                    'production_run_id' => $run->id,
                    'batch_number' => $run->batch_number,
                    'serial' => $run->batch_number_serial,
                    'issued_by_user_id' => $run->batch_number_assigned_by_user_id,
                    'issued_at' => $run->batch_number_assigned_at,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ])->all();

                DB::table('production_run_number_issuances')->insertOrIgnore($issuances);
            });
    }

    public function down(): void
    {
        throw new RuntimeException('Production run number issuance history is permanent.');
    }
};
