<?php

namespace App\Services\Inventory;

use App\Enums\MassUnit;
use App\Enums\StockUnitKind;
use App\Services\MassConverter;
use App\Support\NumberLocale;
use Illuminate\Validation\ValidationException;

class StockAdjustmentCalculator
{
    private const int Scale = 9;

    private const array Modes = ['set_counted', 'add', 'remove'];

    public function __construct(private readonly MassConverter $massConverter) {}

    /**
     * @return array{entered_quantity: string, entered_unit: string, physical_before: string, physical_after: string, delta: string, original_quantity: string}
     */
    public function calculate(
        StockUnitKind $unitKind,
        string $physical,
        string $mode,
        mixed $enteredQuantity,
        string $enteredUnit,
    ): array {
        if (! in_array($mode, self::Modes, true)) {
            $this->fail('mode', 'adjustment_invalid_mode');
        }

        $quantity = $this->normalizeQuantity($enteredQuantity);
        $canonicalQuantity = $this->canonicalQuantity($unitKind, $quantity, $enteredUnit);
        $physical = $this->fixed($physical);

        if ($mode === 'set_counted' && bccomp($canonicalQuantity, '0', self::Scale) < 0) {
            $this->fail('quantity', 'adjustment_non_negative');
        }

        if ($mode !== 'set_counted' && bccomp($canonicalQuantity, '0', self::Scale) <= 0) {
            $this->fail('quantity', 'adjustment_positive');
        }

        if ($mode === 'remove' && bccomp($canonicalQuantity, $physical, self::Scale) > 0) {
            $this->fail('quantity', 'adjustment_remove_exceeds_physical');
        }

        $after = match ($mode) {
            'set_counted' => $canonicalQuantity,
            'add' => bcadd($physical, $canonicalQuantity, self::Scale),
            'remove' => bcsub($physical, $canonicalQuantity, self::Scale),
        };
        $delta = bcsub($after, $physical, self::Scale);

        if (bccomp($delta, '0', self::Scale) === 0) {
            $this->fail('quantity', 'adjustment_no_change');
        }

        $originalQuantity = $unitKind === StockUnitKind::Mass
            ? $this->massConverter->fromGramsSigned($delta, $enteredUnit)
            : $delta;

        foreach ([$after, $delta, $originalQuantity] as $value) {
            $this->guardNumericColumn($value);
        }

        return [
            'entered_quantity' => $this->fixed($quantity),
            'entered_unit' => $enteredUnit,
            'physical_before' => $physical,
            'physical_after' => $after,
            'delta' => $delta,
            'original_quantity' => $this->fixed($originalQuantity),
        ];
    }

    private function normalizeQuantity(mixed $value): string
    {
        $normalized = NumberLocale::normalizeDecimalString($value);

        if ($normalized === null || preg_match('/^\d+(?:\.\d+)?$/', $normalized) !== 1) {
            $this->fail('quantity', 'adjustment_invalid_quantity');
        }

        $fraction = str_contains($normalized, '.') ? explode('.', $normalized, 2)[1] : '';

        if (strlen($fraction) > self::Scale) {
            $this->fail('quantity', 'adjustment_precision');
        }

        return $normalized;
    }

    private function canonicalQuantity(StockUnitKind $unitKind, string $quantity, string $unit): string
    {
        if ($unitKind === StockUnitKind::Count) {
            if ($unit !== 'count') {
                $this->fail('unit', 'adjustment_invalid_unit');
            }

            if (preg_match('/^\d+$/', $quantity) !== 1) {
                $this->fail('quantity', 'adjustment_whole_count');
            }

            return $this->fixed($quantity);
        }

        if (MassUnit::tryFrom($unit) === null) {
            $this->fail('unit', 'adjustment_invalid_unit');
        }

        $canonical = $this->massConverter->toGrams($quantity, $unit);

        if (bccomp($quantity, '0', self::Scale) > 0 && bccomp($canonical, '0', self::Scale) === 0) {
            $this->fail('quantity', 'adjustment_precision');
        }

        return $canonical;
    }

    private function fixed(string $value): string
    {
        return bcadd($value, '0', self::Scale);
    }

    private function guardNumericColumn(string $value): void
    {
        $absolute = ltrim($value, '-');
        $integer = explode('.', $absolute, 2)[0];

        if (strlen(ltrim($integer, '0')) > 11) {
            $this->fail('quantity', 'adjustment_overflow');
        }
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([
            $field => __("production_bench.inventory.validation.{$message}"),
        ]);
    }
}
