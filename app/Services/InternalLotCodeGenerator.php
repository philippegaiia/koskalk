<?php

namespace App\Services;

use App\Models\StockLot;
use App\Models\Workspace;
use Illuminate\Validation\ValidationException;

class InternalLotCodeGenerator
{
    public function next(Workspace $workspace): string
    {
        $prefix = 'SK-'.now()->format('ymd').'-';
        $highest = StockLot::query()
            ->where('workspace_id', $workspace->id)
            ->where('internal_lot_code', 'like', $prefix.'%')
            ->pluck('internal_lot_code')
            ->map(fn (string $code): string => preg_match('/^'.preg_quote($prefix, '/').'(\d+)$/', $code, $matches) === 1
                ? $matches[1] : '0')
            ->reduce(fn (string $highest, string $serial): string => bccomp($serial, $highest, 0) > 0 ? $serial : $highest, '0');
        $number = $prefix.str_pad(bcadd($highest, '1', 0), 4, '0', STR_PAD_LEFT);

        if (strlen($number) > 64) {
            throw ValidationException::withMessages(['internal_lot_code' => __('lot_numbering.validation.number_format')]);
        }

        return $number;
    }
}
