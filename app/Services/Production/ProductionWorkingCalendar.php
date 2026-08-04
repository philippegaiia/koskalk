<?php

namespace App\Services\Production;

use App\Models\ProductionHoliday;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use DateTimeInterface;

class ProductionWorkingCalendar
{
    public function isWorkingDate(Workspace $workspace, string|DateTimeInterface $date): bool
    {
        $date = $this->date($date);

        if (! $workspace->production_works_on_weekends && $date->isWeekend()) {
            return false;
        }

        return ! ProductionHoliday::query()
            ->where('workspace_id', $workspace->id)
            ->get(['date', 'is_recurring'])
            ->contains(function (ProductionHoliday $holiday) use ($date): bool {
                $holidayDate = $this->date($holiday->date);

                return $holidayDate->toDateString() === $date->toDateString()
                    || ($holiday->is_recurring && $holidayDate->format('m-d') === $date->format('m-d'));
            });
    }

    public function nextWorkingDate(Workspace $workspace, string|DateTimeInterface $date): CarbonImmutable
    {
        $candidate = $this->date($date);

        while (! $this->isWorkingDate($workspace, $candidate)) {
            $candidate = $candidate->addDay();
        }

        return $candidate;
    }

    public function dateAfterProduction(
        Workspace $workspace,
        string|DateTimeInterface $productionDate,
        int $daysAfterProduction,
    ): CarbonImmutable {
        $candidate = $this->date($productionDate)->addDays($daysAfterProduction);

        return $this->nextWorkingDate($workspace, $candidate);
    }

    private function date(string|DateTimeInterface $date): CarbonImmutable
    {
        return $date instanceof DateTimeInterface
            ? CarbonImmutable::instance($date)->startOfDay()
            : CarbonImmutable::createFromFormat('!Y-m-d', $date);
    }
}
