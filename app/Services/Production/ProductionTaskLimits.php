<?php

namespace App\Services\Production;

use App\Models\ProductionTaskSet;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class ProductionTaskLimits
{
    public const int MAX_ITEMS_PER_SET = 100;

    public const int MAX_TASKS_PER_FLASH_SUBMISSION = 10000;

    public const int MAX_OFFSET_DAYS = 1827;

    public const int MAX_DURATION_MINUTES = 2630880;

    public const int WORKING_DATE_SEARCH_YEARS = 5;

    public function assertUsableTaskSet(ProductionTaskSet $taskSet): void
    {
        $count = $taskSet->getAttribute('items_count') ?? $taskSet->items()->count();
        $this->assertItemCount((int) $count, 'production_task_set');
        $taskSet->loadMissing(['items' => fn (HasMany $items): HasMany => $items->limit(self::MAX_ITEMS_PER_SET + 1), 'items.taskType']);
        $this->assertItemCount($taskSet->items->count(), 'production_task_set');
        foreach ($taskSet->items as $item) {
            $this->assertOffset((int) $item->days_after_production, 'production_task_set');
            $this->assertDuration($item->duration_minutes ?? $item->taskType?->default_duration_minutes, 'production_task_set');
        }
    }

    public function assertItemCount(int $count, string $field = 'items'): void
    {
        if ($count > self::MAX_ITEMS_PER_SET) {
            throw ValidationException::withMessages([$field => __('production_bench.production.validation.task_set_too_large', ['max' => self::MAX_ITEMS_PER_SET])]);
        }
    }

    public function assertFanout(int $count): void
    {
        if ($count > self::MAX_TASKS_PER_FLASH_SUBMISSION) {
            throw ValidationException::withMessages(['lines' => __('production_bench.production.validation.task_fanout_too_large', ['max' => self::MAX_TASKS_PER_FLASH_SUBMISSION])]);
        }
    }

    public function assertOffset(int $days, string $field = 'days_after_production'): void
    {
        if ($days < -self::MAX_OFFSET_DAYS || $days > self::MAX_OFFSET_DAYS) {
            throw ValidationException::withMessages([$field => __('production_bench.production.validation.task_offset_range', ['max' => self::MAX_OFFSET_DAYS])]);
        }
    }

    public function assertDuration(?int $minutes, string $field = 'duration_minutes'): void
    {
        if ($minutes !== null && ($minutes < 0 || $minutes > self::MAX_DURATION_MINUTES)) {
            throw ValidationException::withMessages([$field => __('production_bench.production.validation.task_duration_range', ['max' => self::MAX_DURATION_MINUTES])]);
        }
    }
}
