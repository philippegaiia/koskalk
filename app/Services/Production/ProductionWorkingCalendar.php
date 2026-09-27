<?php

namespace App\Services\Production;

use App\Models\ProductionHoliday;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class ProductionWorkingCalendar
{
    /** @var array<int, Collection<int, ProductionHoliday>> */
    private array $holidaysByWorkspace = [];

    /** @var array<int, array{dates: array<string, true>, recurring: array<string, true>}> */
    private array $holidayLookupByWorkspace = [];

    public function __construct(private readonly ProductionTaskLimits $limits) {}

    public function refresh(Workspace $workspace): void
    {
        unset($this->holidaysByWorkspace[$workspace->id], $this->holidayLookupByWorkspace[$workspace->id]);
    }

    public function isWorkingDate(Workspace $workspace, string|DateTimeInterface $date): bool
    {
        $date = $this->date($date);

        if (! $workspace->production_works_on_weekends && $date->isWeekend()) {
            return false;
        }

        $lookup = $this->holidayLookupByWorkspace[$workspace->id] ??= [
            'dates' => $this->holidays($workspace)->mapWithKeys(fn (ProductionHoliday $holiday): array => [$this->date($holiday->date)->toDateString() => true])->all(),
            'recurring' => $this->holidays($workspace)->filter(fn (ProductionHoliday $holiday): bool => $holiday->is_recurring)
                ->mapWithKeys(fn (ProductionHoliday $holiday): array => [$this->date($holiday->date)->format('m-d') => true])->all(),
        ];

        return ! isset($lookup['dates'][$date->toDateString()]) && ! isset($lookup['recurring'][$date->format('m-d')]);
    }

    public function nextWorkingDate(Workspace $workspace, string|DateTimeInterface $date): CarbonImmutable
    {
        return $this->workingDate($workspace, $date, 1);
    }

    public function previousWorkingDate(Workspace $workspace, string|DateTimeInterface $date): CarbonImmutable
    {
        return $this->workingDate($workspace, $date, -1);
    }

    private function workingDate(Workspace $workspace, string|DateTimeInterface $date, int $direction): CarbonImmutable
    {
        $candidate = $this->date($date);
        $deadline = $candidate->addYears($direction * ProductionTaskLimits::WORKING_DATE_SEARCH_YEARS);

        while (! $this->isWorkingDate($workspace, $candidate)) {
            $candidate = $candidate->addDays($direction);
            if (($direction > 0 && $candidate->greaterThan($deadline))
                || ($direction < 0 && $candidate->lessThan($deadline))
                || $candidate->year < 1 || $candidate->year > 9999) {
                throw ValidationException::withMessages(['planned_for' => __('production_bench.production.validation.working_date_unavailable')]);
            }
        }

        return $candidate;
    }

    public function dateRelativeToProduction(
        Workspace $workspace,
        string|DateTimeInterface $productionDate,
        int $daysRelativeToProduction,
    ): CarbonImmutable {
        $this->limits->assertOffset($daysRelativeToProduction);
        $candidate = $this->date($productionDate)->addDays($daysRelativeToProduction);
        if ($candidate->year < 1 || $candidate->year > 9999) {
            throw ValidationException::withMessages(['days_after_production' => __('production_bench.production.validation.task_offset_range', ['max' => ProductionTaskLimits::MAX_OFFSET_DAYS])]);
        }

        if ($daysRelativeToProduction === 0 || $this->isWorkingDate($workspace, $candidate)) {
            return $candidate;
        }

        return $daysRelativeToProduction < 0
            ? $this->previousWorkingDate($workspace, $candidate)
            : $this->nextWorkingDate($workspace, $candidate);
    }

    public function dateAfterProduction(
        Workspace $workspace,
        string|DateTimeInterface $productionDate,
        int $daysAfterProduction,
    ): CarbonImmutable {
        return $this->dateRelativeToProduction($workspace, $productionDate, $daysAfterProduction);
    }

    private function date(string|DateTimeInterface $date): CarbonImmutable
    {
        return $date instanceof DateTimeInterface
            ? CarbonImmutable::instance($date)->startOfDay()
            : CarbonImmutable::createFromFormat('!Y-m-d', $date);
    }

    /** @return Collection<int, ProductionHoliday> */
    private function holidays(Workspace $workspace): Collection
    {
        return $this->holidaysByWorkspace[$workspace->id] ??= ProductionHoliday::query()
            ->where('workspace_id', $workspace->id)
            ->get(['date', 'is_recurring']);
    }
}
