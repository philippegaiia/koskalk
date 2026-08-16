<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const INSERTED_AT = '2026-08-12 13:00:00';

    public function up(): void
    {
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
                'created_at' => self::INSERTED_AT,
                'updated_at' => self::INSERTED_AT,
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
            ->where('created_at', self::INSERTED_AT)
            ->where('updated_at', self::INSERTED_AT)
            ->delete();
    }
};
