<?php

namespace App\Services\Production;

use App\Enums\ProductionRunStatus;
use App\Models\ProductionRun;
use App\Models\Workspace;

class ProductionDailyOccupancy
{
    /** @return array{overall: array<string, int>, locations: array<int, array<string, int>>} */
    public function between(Workspace $workspace, string $from, string $through, ?int $exceptRunId = null): array
    {
        $rows = ProductionRun::query()->where('workspace_id', $workspace->id)
            ->whereBetween('planned_for', [$from, $through])
            ->whereNotIn('status', [ProductionRunStatus::Draft, ProductionRunStatus::Cancelled])
            ->when($exceptRunId !== null, fn ($query) => $query->whereKeyNot($exceptRunId))
            ->selectRaw('planned_for, production_location_id, COUNT(*) AS occupied')
            ->groupBy('planned_for', 'production_location_id')->get();
        $result = ['overall' => [], 'locations' => []];
        foreach ($rows as $row) {
            $date = $row->planned_for->toDateString();
            $result['overall'][$date] = ($result['overall'][$date] ?? 0) + (int) $row->occupied;
            if ($row->production_location_id !== null) {
                $result['locations'][(int) $row->production_location_id][$date] = (int) $row->occupied;
            }
        }

        return $result;
    }
}
