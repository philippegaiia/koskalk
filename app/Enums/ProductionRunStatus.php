<?php

namespace App\Enums;

enum ProductionRunStatus: string
{
    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Reserved = 'reserved';
    case InProduction = 'in_production';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Aborted = 'aborted';

    public function label(): string
    {
        return match ($this) {
            self::Draft => __('production_bench.production.status.draft'),
            self::Scheduled => __('production_bench.production.status.scheduled'),
            self::Reserved => __('production_bench.production.status.reserved'),
            self::InProduction => __('production_bench.production.status.in_production'),
            self::Completed => __('production_bench.production.status.completed'),
            self::Cancelled => __('production_bench.production.status.cancelled'),
            self::Aborted => __('production_bench.production.status.aborted'),
        };
    }
}
