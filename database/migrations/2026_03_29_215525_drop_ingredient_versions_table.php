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
        Schema::dropIfExists('ingredient_versions');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::create('ingredient_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ingredient_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->boolean('is_current')->default(true);
            $table->string('display_name');
            $table->string('display_name_en')->nullable();
            $table->string('display_name_fr')->nullable();
            $table->string('inci_name')->nullable();
            $table->string('supplier_name')->nullable();
            $table->string('supplier_reference')->nullable();
            $table->string('soap_inci_naoh_name')->nullable();
            $table->string('soap_inci_koh_name')->nullable();
            $table->string('cas_number')->nullable();
            $table->string('ec_number')->nullable();
            $table->string('unit')->nullable();
            $table->decimal('price_eur', 10, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_manufactured')->default(false);
            $table->string('source_file');
            $table->string('source_key');
            $table->json('source_data')->nullable();
            $table->timestamps();

            $table->unique(['ingredient_id', 'version']);
            $table->unique(['source_file', 'source_key', 'version']);
        });

        DB::table('ingredients')
            ->select([
                'id',
                'display_name',
                'display_name_en',
                'inci_name',
                'supplier_name',
                'supplier_reference',
                'soap_inci_naoh_name',
                'soap_inci_koh_name',
                'cas_number',
                'ec_number',
                'unit',
                'price_eur',
                'is_active',
                'is_manufactured',
                'source_file',
                'source_key',
                'source_data',
                'created_at',
                'updated_at',
            ])
            ->orderBy('id')
            ->get()
            ->each(function (object $ingredient): void {
                DB::table('ingredient_versions')->insert([
                    'ingredient_id' => $ingredient->id,
                    'version' => 1,
                    'is_current' => true,
                    'display_name' => $ingredient->display_name ?? $ingredient->source_key,
                    'display_name_en' => $ingredient->display_name_en,
                    'display_name_fr' => null,
                    'inci_name' => $ingredient->inci_name,
                    'supplier_name' => $ingredient->supplier_name,
                    'supplier_reference' => $ingredient->supplier_reference,
                    'soap_inci_naoh_name' => $ingredient->soap_inci_naoh_name,
                    'soap_inci_koh_name' => $ingredient->soap_inci_koh_name,
                    'cas_number' => $ingredient->cas_number,
                    'ec_number' => $ingredient->ec_number,
                    'unit' => $ingredient->unit,
                    'price_eur' => $ingredient->price_eur,
                    'is_active' => $ingredient->is_active,
                    'is_manufactured' => $ingredient->is_manufactured,
                    'source_file' => $ingredient->source_file,
                    'source_key' => $ingredient->source_key,
                    'source_data' => $ingredient->source_data,
                    'created_at' => $ingredient->created_at,
                    'updated_at' => $ingredient->updated_at,
                ]);
            });
    }
};
