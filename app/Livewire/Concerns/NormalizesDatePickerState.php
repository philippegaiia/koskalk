<?php

namespace App\Livewire\Concerns;

trait NormalizesDatePickerState
{
    protected function normalizeDatePickerState(mixed $value): string
    {
        $value = (string) $value;

        return preg_match('/^\d{4}-\d{2}-\d{2}(?: 00:00:00)?$/', $value) === 1
            ? substr($value, 0, 10)
            : '';
    }
}
