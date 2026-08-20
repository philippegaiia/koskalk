<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('ifra_product_categories')
            ->where('code', '7A')
            ->update([
                'short_name' => 'Rinse-off hair chemical treatments',
                'description' => 'Rinse-off hair permanent or other chemical treatments, including rinse-off hair dyes.',
                'updated_at' => now(),
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('ifra_product_categories')
            ->where('code', '7A')
            ->update([
                'short_name' => 'Hair rinse-off',
                'description' => 'Rinse-off products applied to the hair with some hand contact, such as shampoos and rinse-off conditioners.',
                'updated_at' => now(),
            ]);
    }
};
