<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('product_areas', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->unsignedInteger('sort_order')->default(10);
            $table->boolean('is_active')->default(true);
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('product_categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_area_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->unsignedInteger('sort_order')->default(10);
            $table->boolean('is_active')->default(true);
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(['product_area_id', 'slug']);
        });

        Schema::table('product_types', function (Blueprint $table): void {
            $table->foreignId('product_category_id')->nullable()->constrained()->restrictOnDelete();
            $table->index('product_category_id');
        });

        Schema::create('product_family_product_type', function (Blueprint $table): void {
            $table->foreignId('product_family_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_type_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['product_family_id', 'product_type_id']);
            $table->index('product_type_id');
        });

        Schema::table('recipes', function (Blueprint $table): void {
            $table->index('product_type_id');
        });

        DB::table('product_types')
            ->whereNotNull('product_family_id')
            ->select(['id', 'product_family_id'])
            ->orderBy('id')
            ->eachById(function (object $productType): void {
                DB::table('product_family_product_type')->insertOrIgnore([
                    'product_family_id' => $productType->product_family_id,
                    'product_type_id' => $productType->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });

        $activeSlugCollisions = DB::table('product_types')
            ->where('is_active', true)
            ->orderBy('slug')
            ->pluck('slug')
            ->duplicates()
            ->unique()
            ->values();

        if ($activeSlugCollisions->isNotEmpty()) {
            throw new LogicException(
                'Resolve duplicate active Product Type slugs before migrating: '.$activeSlugCollisions->implode(', '),
            );
        }

        Schema::table('product_types', function (Blueprint $table): void {
            $table->dropUnique('product_types_product_family_id_slug_unique');
            $table->foreignId('product_family_id')->nullable()->change();
        });

        $activeSlugIndex = 'product_types_active_slug_unique';
        $driverName = DB::getDriverName();

        if ($driverName === 'pgsql') {
            DB::statement(
                "CREATE UNIQUE INDEX {$activeSlugIndex} ON product_types (slug) WHERE is_active = TRUE",
            );
        }

        if ($driverName === 'sqlite') {
            DB::statement(
                "CREATE UNIQUE INDEX {$activeSlugIndex} ON product_types (slug) WHERE is_active = 1",
            );
        }

        if (! in_array($driverName, ['pgsql', 'sqlite'], true)) {
            throw new LogicException('The Product Type active-slug index is not implemented for this database driver.');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('product_types')
            ->whereNull('product_family_id')
            ->orderBy('id')
            ->eachById(function (object $productType): void {
                $productFamilyId = DB::table('product_family_product_type')
                    ->where('product_type_id', $productType->id)
                    ->orderBy('product_family_id')
                    ->value('product_family_id');

                if ($productFamilyId === null) {
                    throw new LogicException("Product Type {$productType->id} has no legacy family for rollback.");
                }

                DB::table('product_types')
                    ->where('id', $productType->id)
                    ->update(['product_family_id' => $productFamilyId]);
            });

        $legacySlugCollisions = DB::table('product_types')
            ->whereNotNull('product_family_id')
            ->get(['product_family_id', 'slug'])
            ->groupBy(fn (object $productType): string => $productType->product_family_id.':'.$productType->slug)
            ->filter(fn (Collection $productTypes): bool => $productTypes->count() > 1)
            ->keys()
            ->values();

        if ($legacySlugCollisions->isNotEmpty()) {
            throw new LogicException(
                'Resolve legacy family/slug collisions before rolling back: '.$legacySlugCollisions->implode(', '),
            );
        }

        Schema::table('product_types', function (Blueprint $table): void {
            $table->dropIndex('product_types_active_slug_unique');
            $table->dropIndex(['product_category_id']);
            $table->foreignId('product_family_id')->nullable(false)->change();
            $table->unique(['product_family_id', 'slug']);
            $table->dropConstrainedForeignId('product_category_id');
        });

        Schema::table('recipes', function (Blueprint $table): void {
            $table->dropIndex(['product_type_id']);
        });

        Schema::dropIfExists('product_family_product_type');
        Schema::dropIfExists('product_categories');
        Schema::dropIfExists('product_areas');
    }
};
