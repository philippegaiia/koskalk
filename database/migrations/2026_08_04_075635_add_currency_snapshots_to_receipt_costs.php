<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('goods_receipt_lines', function (Blueprint $table): void {
            $table->decimal('costing_total_cost', 20, 9)->nullable()->after('historical_total_cost');
            $table->string('costing_currency', 3)->nullable()->after('currency');
            $table->decimal('exchange_rate', 20, 12)->nullable()->after('costing_currency');
            $table->date('exchange_rate_date')->nullable()->after('exchange_rate');
            $table->string('exchange_rate_provider', 48)->nullable()->after('exchange_rate_date');
            $table->boolean('exchange_rate_is_manual')->default(false)->after('exchange_rate_provider');
        });

        Schema::table('stock_lots', function (Blueprint $table): void {
            $table->decimal('costing_unit_cost', 20, 9)->nullable()->after('historical_unit_cost');
            $table->string('costing_currency', 3)->nullable()->after('currency');
            $table->decimal('exchange_rate', 20, 12)->nullable()->after('costing_currency');
            $table->date('exchange_rate_date')->nullable()->after('exchange_rate');
            $table->string('exchange_rate_provider', 48)->nullable()->after('exchange_rate_date');
            $table->boolean('exchange_rate_is_manual')->default(false)->after('exchange_rate_provider');
        });
    }

    public function down(): void
    {
        Schema::table('goods_receipt_lines', function (Blueprint $table): void {
            $table->dropColumn([
                'costing_total_cost',
                'costing_currency',
                'exchange_rate',
                'exchange_rate_date',
                'exchange_rate_provider',
                'exchange_rate_is_manual',
            ]);
        });

        Schema::table('stock_lots', function (Blueprint $table): void {
            $table->dropColumn([
                'costing_unit_cost',
                'costing_currency',
                'exchange_rate',
                'exchange_rate_date',
                'exchange_rate_provider',
                'exchange_rate_is_manual',
            ]);
        });
    }
};
