<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $timestamp = now();
        $planIds = DB::table('plans')
            ->where('slug', 'free-beta')
            ->orWhereNotNull('paddle_price_id')
            ->pluck('id');

        foreach ($planIds as $planId) {
            $value = DB::table('plans')->where('id', $planId)->value('slug') === 'free-beta' ? 30 : 50;

            DB::table('plan_limits')->insertOrIgnore([
                'plan_id' => $planId,
                'key' => 'formula_items_per_recipe',
                'value' => $value,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
        }
    }

    public function down(): void
    {
        $planIds = DB::table('plans')
            ->where('slug', 'free-beta')
            ->orWhereNotNull('paddle_price_id')
            ->pluck('id');

        DB::table('plan_limits')
            ->where('key', 'formula_items_per_recipe')
            ->whereIn('plan_id', $planIds)
            ->delete();
    }
};
