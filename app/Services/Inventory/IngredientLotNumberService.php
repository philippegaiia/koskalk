<?php

namespace App\Services\Inventory;

use App\Models\Ingredient;
use App\Models\IngredientLotNumberCounter;
use App\Models\IngredientLotNumberSetting;
use App\Models\StockLot;
use App\Models\Workspace;
use App\Services\WorkspaceIngredientCodeService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class IngredientLotNumberService
{
    public const int MaximumSerial = 999999999999;

    public function __construct(private readonly WorkspaceIngredientCodeService $materialCodes) {}

    public function settings(Workspace $workspace): IngredientLotNumberSetting
    {
        return IngredientLotNumberSetting::query()->firstOrNew(['workspace_id' => $workspace->id]);
    }

    public function period(IngredientLotNumberSetting $settings, Carbon $date): string
    {
        return match ($settings->reset_period) {
            'daily' => 'daily:'.$date->format('Ymd'),
            'monthly' => 'monthly:'.$date->format('Ym'),
            'yearly' => 'yearly:'.$date->format('Y'),
            default => 'never',
        };
    }

    public function format(IngredientLotNumberSetting $settings, Carbon $date, int $serial, ?string $materialCode = null): string
    {
        return collect([
            $settings->prefix,
            $settings->date_format === 'none' ? null : $date->format($settings->date_format),
            $settings->include_material_code ? $materialCode : null,
            str_pad((string) $serial, $settings->padding, '0', STR_PAD_LEFT),
            $settings->suffix,
        ])->filter(fn (?string $part): bool => $part !== null && $part !== '')->implode($settings->separator);
    }

    public function nextSerial(Workspace $workspace, IngredientLotNumberSetting $settings, Carbon $date): int
    {
        $stored = IngredientLotNumberCounter::query()
            ->where('workspace_id', $workspace->id)
            ->where('period', $this->period($settings, $date))
            ->value('next_serial');

        if ($stored !== null) {
            return (int) $stored;
        }

        if ($settings->prefix !== 'SK' || $settings->suffix !== '' || $settings->separator !== '-'
            || $settings->date_format !== 'ymd' || $settings->include_material_code) {
            return 1;
        }

        $prefix = 'SK-'.$date->format('ymd').'-';
        $highest = StockLot::query()->where('workspace_id', $workspace->id)
            ->where('internal_lot_code', 'like', $prefix.'%')
            ->pluck('internal_lot_code')
            ->map(fn (string $code): int => preg_match('/^'.preg_quote($prefix, '/').'(\d+)$/', $code, $matches) === 1
                ? min((int) $matches[1], self::MaximumSerial) : 0)
            ->max() ?? 0;

        return $highest + 1;
    }

    /** Called inside the transaction that creates the lot, retaining its workspace lock until commit. */
    public function allocate(Workspace $workspace, Ingredient $ingredient, string $stockedAt, ?string $manualNumber = null): string
    {
        return DB::transaction(function () use ($workspace, $ingredient, $stockedAt, $manualNumber): string {
            $workspace = Workspace::withoutGlobalScopes()->lockForUpdate()->findOrFail($workspace->id);
            $manualNumber = trim($manualNumber ?? '');

            if ($manualNumber !== '') {
                $this->validateNumber($manualNumber);

                if ($this->exists($workspace, $manualNumber)) {
                    throw ValidationException::withMessages(['internal_lot_code' => __('lot_numbering.validation.duplicate')]);
                }

                $settings = $this->settings($workspace);
                $date = $settings->date_source === 'stocked' ? Carbon::parse($stockedAt) : now();
                IngredientLotNumberCounter::query()->firstOrCreate([
                    'workspace_id' => $workspace->id,
                    'period' => $this->period($settings, $date),
                ], ['next_serial' => $this->nextSerial($workspace, $settings, $date)]);

                return $manualNumber;
            }

            $settings = $this->settings($workspace);
            $date = $settings->date_source === 'stocked' ? Carbon::parse($stockedAt) : now();
            $materialCode = $settings->include_material_code ? $this->materialCodes->codeFor($workspace, $ingredient) : null;

            if ($settings->include_material_code && blank($materialCode)) {
                throw ValidationException::withMessages(['internal_lot_code' => __('lot_numbering.validation.material_code_required')]);
            }

            $serial = $this->nextSerial($workspace, $settings, $date);

            do {
                if ($serial > self::MaximumSerial) {
                    throw ValidationException::withMessages(['internal_lot_code' => __('lot_numbering.validation.exhausted')]);
                }

                $number = $this->format($settings, $date, $serial, $materialCode);
                $this->validateNumber($number);
                $serial++;
            } while ($this->exists($workspace, $number));

            IngredientLotNumberCounter::query()->updateOrCreate([
                'workspace_id' => $workspace->id,
                'period' => $this->period($settings, $date),
            ], ['next_serial' => $serial]);

            return $number;
        }, attempts: 5);
    }

    public function validateNumber(string $number): void
    {
        if (strlen($number) > 64 || preg_match('/\A[A-Za-z0-9._\/-]+\z/', $number) !== 1) {
            throw ValidationException::withMessages(['internal_lot_code' => __('lot_numbering.validation.number_format')]);
        }
    }

    private function exists(Workspace $workspace, string $number): bool
    {
        return StockLot::query()->where('workspace_id', $workspace->id)->where('internal_lot_code', $number)->exists();
    }
}
