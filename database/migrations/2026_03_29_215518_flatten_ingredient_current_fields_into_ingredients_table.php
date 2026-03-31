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
        Schema::table('ingredients', function (Blueprint $table) {
            $table->string('display_name')->nullable()->after('category');
            $table->string('display_name_en')->nullable()->after('display_name');
            $table->string('inci_name')->nullable()->after('display_name_en');
            $table->string('supplier_name')->nullable()->after('inci_name');
            $table->string('supplier_reference')->nullable()->after('supplier_name');
            $table->string('soap_inci_naoh_name')->nullable()->after('supplier_reference');
            $table->string('soap_inci_koh_name')->nullable()->after('soap_inci_naoh_name');
            $table->string('cas_number')->nullable()->after('soap_inci_koh_name');
            $table->string('ec_number')->nullable()->after('cas_number');
            $table->string('unit')->nullable()->after('ec_number');
            $table->decimal('price_eur', 10, 2)->nullable()->after('unit');
            $table->boolean('is_manufactured')->default(false)->after('is_active');
        });

        $versionsByIngredient = DB::table('ingredient_versions')
            ->orderBy('ingredient_id')
            ->orderByDesc('is_current')
            ->orderByDesc('version')
            ->orderByDesc('id')
            ->get()
            ->groupBy('ingredient_id');

        DB::table('ingredients')
            ->select(['id', 'source_key', 'source_data', 'is_active'])
            ->orderBy('id')
            ->get()
            ->each(function (object $ingredient) use ($versionsByIngredient): void {
                $currentVersion = $versionsByIngredient->get($ingredient->id)?->first();

                DB::table('ingredients')
                    ->where('id', $ingredient->id)
                    ->update([
                        'display_name' => $currentVersion?->display_name ?? $ingredient->source_key,
                        'display_name_en' => $currentVersion?->display_name_en,
                        'inci_name' => $currentVersion?->inci_name,
                        'supplier_name' => $currentVersion?->supplier_name,
                        'supplier_reference' => $currentVersion?->supplier_reference,
                        'soap_inci_naoh_name' => $currentVersion?->soap_inci_naoh_name,
                        'soap_inci_koh_name' => $currentVersion?->soap_inci_koh_name,
                        'cas_number' => $currentVersion?->cas_number,
                        'ec_number' => $currentVersion?->ec_number,
                        'unit' => $currentVersion?->unit,
                        'price_eur' => $currentVersion?->price_eur,
                        'is_active' => $currentVersion === null
                            ? $ingredient->is_active
                            : ((bool) $ingredient->is_active && (bool) $currentVersion->is_active),
                        'is_manufactured' => (bool) ($currentVersion?->is_manufactured ?? false),
                        'source_data' => $ingredient->source_data ?? $currentVersion?->source_data,
                    ]);
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ingredients', function (Blueprint $table) {
            $table->dropColumn([
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
                'is_manufactured',
            ]);
        });
    }
};
