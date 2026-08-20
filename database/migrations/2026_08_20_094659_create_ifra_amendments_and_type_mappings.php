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
        Schema::create('ifra_amendments', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 16)->unique();
            $table->string('status', 32);
            $table->date('notification_date')->nullable();
            $table->string('source_url', 2048)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('ifra_amendment_milestones', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ifra_amendment_id')->constrained()->cascadeOnDelete();
            $table->string('standard_kind', 32);
            $table->string('creation_track', 16);
            $table->date('effective_on');
            $table->timestamps();

            $table->unique(
                ['ifra_amendment_id', 'standard_kind', 'creation_track'],
                'ifra_amendment_milestones_scope_unique',
            );
        });

        Schema::create('product_type_ifra_categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_type_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ifra_amendment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ifra_product_category_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_default')->default(false);
            $table->text('guidance')->nullable();
            $table->string('source_url', 2048)->nullable();
            $table->unsignedInteger('sort_order')->default(10);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(
                ['product_type_id', 'ifra_amendment_id', 'ifra_product_category_id'],
                'product_type_ifra_category_unique',
            );
        });

        $defaultMappingIndex = 'product_type_ifra_default_unique';
        $driverName = DB::getDriverName();

        if ($driverName === 'pgsql') {
            DB::statement(
                "CREATE UNIQUE INDEX {$defaultMappingIndex} ON product_type_ifra_categories (product_type_id, ifra_amendment_id) WHERE is_default = TRUE",
            );
        }

        if ($driverName === 'sqlite') {
            DB::statement(
                "CREATE UNIQUE INDEX {$defaultMappingIndex} ON product_type_ifra_categories (product_type_id, ifra_amendment_id) WHERE is_default = 1",
            );
        }

        if (! in_array($driverName, ['pgsql', 'sqlite'], true)) {
            throw new LogicException('The IFRA default-mapping index is not implemented for this database driver.');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_type_ifra_categories');
        Schema::dropIfExists('ifra_amendment_milestones');
        Schema::dropIfExists('ifra_amendments');
    }
};
