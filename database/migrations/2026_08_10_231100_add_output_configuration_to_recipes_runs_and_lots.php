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
        Schema::table('recipes', function (Blueprint $table): void {
            $table->string('production_output_type', 32)->default('finished_product')->index();
            $table->foreignId('output_ingredient_id')->nullable()->constrained('ingredients')->restrictOnDelete();
            $table->unsignedInteger('ready_delay_days')->nullable();
        });

        Schema::table('production_runs', function (Blueprint $table): void {
            $table->string('production_output_type', 32)->nullable()->after('recipe_version_id');
            $table->unsignedBigInteger('output_ingredient_id')->nullable()->after('production_output_type');
            $table->unsignedInteger('output_ready_delay_days')->nullable()->after('output_ingredient_id');
            $table->date('estimated_ready_on')->nullable()->after('planned_for');
        });

        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('production_runs', function (Blueprint $table): void {
                $table->foreign('output_ingredient_id')
                    ->references('id')
                    ->on('ingredients')
                    ->restrictOnDelete();
            });
        }

        Schema::table('stock_lots', function (Blueprint $table): void {
            $table->date('estimated_ready_on')->nullable()->after('available_from');
        });

        DB::table('stock_lots')
            ->where('origin', 'production_output')
            ->whereNotNull('available_from')
            ->update([
                'estimated_ready_on' => DB::raw('available_from'),
                'available_from' => null,
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('stock_lots')
            ->where('origin', 'production_output')
            ->whereNull('available_from')
            ->whereNotNull('estimated_ready_on')
            ->update([
                'available_from' => DB::raw('estimated_ready_on'),
            ]);

        Schema::table('stock_lots', function (Blueprint $table): void {
            $table->dropColumn('estimated_ready_on');
        });

        Schema::table('production_runs', function (Blueprint $table): void {
            if (DB::getDriverName() !== 'sqlite') {
                $table->dropForeign(['output_ingredient_id']);
            }
            $table->dropColumn([
                'production_output_type',
                'output_ingredient_id',
                'output_ready_delay_days',
                'estimated_ready_on',
            ]);
        });

        Schema::table('recipes', function (Blueprint $table): void {
            $table->dropForeign(['output_ingredient_id']);
            $table->dropIndex(['production_output_type']);
            $table->dropColumn([
                'production_output_type',
                'output_ingredient_id',
                'ready_delay_days',
            ]);
        });
    }
};
