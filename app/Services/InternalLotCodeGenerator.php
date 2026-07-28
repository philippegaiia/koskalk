<?php

namespace App\Services;

use App\Models\StockLot;
use App\Models\Workspace;

class InternalLotCodeGenerator
{
    public function next(Workspace $workspace): string
    {
        $prefix = 'SK-'.now()->format('ymd').'-';
        $lastCode = StockLot::query()
            ->where('workspace_id', $workspace->id)
            ->where('internal_lot_code', 'like', $prefix.'%')
            ->orderByDesc('internal_lot_code')
            ->value('internal_lot_code');
        $sequence = $lastCode === null ? 1 : ((int) substr($lastCode, -4)) + 1;

        return $prefix.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }
}
