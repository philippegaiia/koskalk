<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_runs', function (Blueprint $table): void {
            $table->unsignedBigInteger('edit_revision')->default(0);
        });
        Schema::create('production_edit_leases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('production_run_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->index()->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64);
            $table->string('holder_name');
            $table->timestamp('expires_at');
            $table->timestamps();
        });
        Schema::create('production_edit_takeovers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('production_run_id')->index()->constrained()->cascadeOnDelete();
            $table->foreignId('actor_user_id')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->foreignId('previous_user_id')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->string('actor_name');
            $table->string('previous_holder_name')->nullable();
            $table->text('reason');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_edit_takeovers');
        Schema::dropIfExists('production_edit_leases');
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('ALTER TABLE production_runs DROP COLUMN edit_revision');
        } else {
            Schema::table('production_runs', function (Blueprint $table): void {
                $table->dropColumn('edit_revision');
            });
        }
    }
};
