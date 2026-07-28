<?php

namespace App\Services;

use App\Models\Ingredient;
use App\Models\StockLot;
use App\Models\UserPackagingItem;
use App\Models\Workspace;
use App\StockLotStatus;
use Illuminate\Database\Eloquent\Builder;

class StockPositionService
{
    /**
     * @return array{physical: string, quarantined: string, reserved: string, available: string, incoming: string, forecast: string}
     */
    public function forLot(StockLot $lot): array
    {
        $physical = $this->decimal((string) $lot->movements()->sum('quantity_delta'));
        $quarantined = $lot->status === StockLotStatus::Quarantined ? $physical : $this->zero();
        $available = $lot->status === StockLotStatus::Released ? $physical : $this->zero();

        return $this->positions($physical, $quarantined, $available);
    }

    /**
     * @return array{physical: string, quarantined: string, reserved: string, available: string, incoming: string, forecast: string}
     */
    public function forWorkspaceSubject(Workspace $workspace, Ingredient|UserPackagingItem $subject): array
    {
        $lots = StockLot::query()
            ->where('workspace_id', $workspace->id)
            ->when(
                $subject instanceof Ingredient,
                fn (Builder $query): Builder => $query->where('ingredient_id', $subject->id),
                fn (Builder $query): Builder => $query->where('user_packaging_item_id', $subject->id),
            )
            ->withSum('movements', 'quantity_delta')
            ->get();

        $physical = $this->zero();
        $quarantined = $this->zero();
        $available = $this->zero();

        foreach ($lots as $lot) {
            $quantity = $this->decimal((string) ($lot->movements_sum_quantity_delta ?? '0'));
            $physical = bcadd($physical, $quantity, 9);

            if ($lot->status === StockLotStatus::Quarantined) {
                $quarantined = bcadd($quarantined, $quantity, 9);
            } else {
                $available = bcadd($available, $quantity, 9);
            }
        }

        return $this->positions($physical, $quarantined, $available);
    }

    /**
     * @return array{physical: string, quarantined: string, reserved: string, available: string, incoming: string, forecast: string}
     */
    private function positions(string $physical, string $quarantined, string $available): array
    {
        return [
            'physical' => $physical,
            'quarantined' => $quarantined,
            'reserved' => $this->zero(),
            'available' => $available,
            'incoming' => $this->zero(),
            'forecast' => $available,
        ];
    }

    private function decimal(string $quantity): string
    {
        return bcadd($quantity, '0', 9);
    }

    private function zero(): string
    {
        return '0.000000000';
    }
}
