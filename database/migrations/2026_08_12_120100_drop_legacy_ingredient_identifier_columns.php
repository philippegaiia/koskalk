<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ingredients', function (Blueprint $table): void {
            $table->dropColumn(['cas_number', 'ec_number']);
        });
    }

    public function down(): void
    {
        Schema::table('ingredients', function (Blueprint $table): void {
            $table->string('cas_number')->nullable();
            $table->string('ec_number')->nullable();
        });

        if (! Schema::hasTable('ingredient_identifiers')) {
            return;
        }

        DB::table('ingredient_identifiers')
            ->where('is_primary', true)
            ->whereIn('scheme', ['cas', 'ec'])
            ->orderBy('id')
            ->get(['ingredient_id', 'scheme', 'value'])
            ->each(function (object $identifier): void {
                DB::table('ingredients')
                    ->where('id', $identifier->ingredient_id)
                    ->update([
                        $identifier->scheme === 'cas' ? 'cas_number' : 'ec_number' => $identifier->value,
                    ]);
            });
    }
};
