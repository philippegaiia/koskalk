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
        Schema::create('production_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recipe_id')->constrained()->restrictOnDelete();
            $table->foreignId('recipe_version_id')->constrained()->restrictOnDelete();
            $table->string('status', 32)->default('draft');
            $table->string('source', 24)->default('direct');
            $table->date('planned_for')->nullable();
            $table->string('basis_kind', 32);
            $table->decimal('basis_quantity_grams', 20, 9);
            $table->decimal('basis_input_value', 20, 9);
            $table->string('basis_input_unit', 24);
            $table->unsignedBigInteger('expected_units');
            $table->text('notes')->nullable();
            $table->string('idempotency_key', 120);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'idempotency_key']);
            $table->index(['workspace_id', 'planned_for', 'status']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE production_runs
                ADD CONSTRAINT production_runs_valid_values_check
                CHECK (
                    status IN ('draft', 'scheduled', 'reserved', 'in_production', 'completed', 'cancelled', 'aborted')
                    AND source IN ('direct', 'flash')
                    AND basis_kind IN ('oil_mass', 'total_formula_mass')
                    AND basis_input_unit IN ('g', 'kg', 'oz', 'lb')
                    AND basis_quantity_grams > 0
                    AND basis_input_value > 0
                    AND expected_units > 0
                )
            SQL);

            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            DB::statement(<<<'SQL'
                CREATE TRIGGER production_runs_valid_values_insert
                BEFORE INSERT ON production_runs
                WHEN NOT (
                    NEW.status IN ('draft', 'scheduled', 'reserved', 'in_production', 'completed', 'cancelled', 'aborted')
                    AND NEW.source IN ('direct', 'flash')
                    AND NEW.basis_kind IN ('oil_mass', 'total_formula_mass')
                    AND NEW.basis_input_unit IN ('g', 'kg', 'oz', 'lb')
                    AND NEW.basis_quantity_grams > 0
                    AND NEW.basis_input_value > 0
                    AND NEW.expected_units > 0
                    AND NEW.expected_units = CAST(NEW.expected_units AS INTEGER)
                )
                BEGIN
                    SELECT RAISE(ABORT, 'production run values are invalid');
                END
            SQL);
            DB::statement(<<<'SQL'
                CREATE TRIGGER production_runs_valid_values_update
                BEFORE UPDATE OF status, source, basis_kind, basis_quantity_grams, basis_input_value, basis_input_unit, expected_units ON production_runs
                WHEN NOT (
                    NEW.status IN ('draft', 'scheduled', 'reserved', 'in_production', 'completed', 'cancelled', 'aborted')
                    AND NEW.source IN ('direct', 'flash')
                    AND NEW.basis_kind IN ('oil_mass', 'total_formula_mass')
                    AND NEW.basis_input_unit IN ('g', 'kg', 'oz', 'lb')
                    AND NEW.basis_quantity_grams > 0
                    AND NEW.basis_input_value > 0
                    AND NEW.expected_units > 0
                    AND NEW.expected_units = CAST(NEW.expected_units AS INTEGER)
                )
                BEGIN
                    SELECT RAISE(ABORT, 'production run values are invalid');
                END
            SQL);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('production_runs');
    }
};
