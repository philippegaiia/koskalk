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
        Schema::table('recipe_versions', function (Blueprint $table): void {
            $table->foreignId('ifra_amendment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_type_ifra_category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('ifra_category_selection_mode', 16)->default('automatic');
            $table->index('ifra_amendment_id');
            $table->index('product_type_ifra_category_id');
        });

        Schema::table('ifra_certificates', function (Blueprint $table): void {
            $table->foreignId('ifra_amendment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source_amendment_label')->nullable();
            $table->index('ifra_amendment_id');
        });

        DB::table('ifra_certificates')
            ->whereNotNull('ifra_amendment')
            ->orderBy('id')
            ->eachById(function (object $certificate): void {
                $sourceAmendmentLabel = (string) $certificate->ifra_amendment;
                $ifraAmendmentId = DB::table('ifra_amendments')
                    ->where('code', trim($sourceAmendmentLabel))
                    ->value('id');

                DB::table('ifra_certificates')
                    ->where('id', $certificate->id)
                    ->update([
                        'ifra_amendment_id' => $ifraAmendmentId,
                        'source_amendment_label' => $sourceAmendmentLabel,
                    ]);
            });

        DB::table('recipe_versions')
            ->whereNotNull('ifra_product_category_id')
            ->update(['ifra_category_selection_mode' => 'legacy']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('recipe_versions', function (Blueprint $table): void {
            $table->dropIndex(['product_type_ifra_category_id']);
            $table->dropIndex(['ifra_amendment_id']);
            $table->dropConstrainedForeignId('product_type_ifra_category_id');
            $table->dropConstrainedForeignId('ifra_amendment_id');
            $table->dropColumn('ifra_category_selection_mode');
        });

        Schema::table('ifra_certificates', function (Blueprint $table): void {
            $table->dropIndex(['ifra_amendment_id']);
            $table->dropConstrainedForeignId('ifra_amendment_id');
            $table->dropColumn('source_amendment_label');
        });
    }
};
