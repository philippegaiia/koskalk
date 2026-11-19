<?php

namespace App\Services;

use App\Models\StockLot;
use App\Models\Workspace;
use App\Services\Inventory\StockLotCodeAvailability;
use Illuminate\Validation\ValidationException;

class InternalLotCodeGenerator
{
    public function __construct(private readonly StockLotCodeAvailability $lotCodes) {}

    public function next(Workspace $workspace): string
    {
        $prefix = 'SK-'.now()->format('ymd').'-';
        $highest = StockLot::query()
            ->where('workspace_id', $workspace->id)
            ->whereRaw('LOWER(internal_lot_code) LIKE ?', [strtolower($prefix).'%'])
            ->pluck('internal_lot_code')
            ->map(fn (string $code): string => preg_match('/^'.preg_quote($prefix, '/').'(\d+)$/i', $code, $matches) === 1
                ? $matches[1] : '0')
            ->reduce(fn (string $highest, string $serial): string => bccomp($serial, $highest, 0) > 0 ? $serial : $highest, '0');

        do {
            $highest = bcadd($highest, '1', 0);
            $number = $prefix.str_pad($highest, 4, '0', STR_PAD_LEFT);

            if (strlen($number) > 64) {
                throw ValidationException::withMessages(['internal_lot_code' => __('lot_numbering.validation.number_format')]);
            }
        } while ($this->lotCodes->isTaken($workspace->id, $number));

        return $number;
    }
}
