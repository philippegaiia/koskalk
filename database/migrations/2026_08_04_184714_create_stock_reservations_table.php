<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_reservations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('production_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('production_requirement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_lot_id')->constrained()->cascadeOnDelete();
            $table->decimal('quantity', 20, 9);
            $table->string('status', 24);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('idempotency_key', 191);
            $table->timestamp('released_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'idempotency_key']);
            $table->index(['workspace_id', 'status']);
            $table->index(['production_run_id', 'status']);
            $table->index(['production_requirement_id', 'status']);
            $table->index(['stock_lot_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_reservations');
    }
};
