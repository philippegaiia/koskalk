<?php

namespace App\Actions\Inventory;

use App\Models\IngredientLotNumberCounter;
use App\Models\IngredientLotNumberSetting;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Inventory\IngredientLotNumberService;
use App\Services\ProductionBenchAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SaveIngredientLotNumberSettings
{
    public function __construct(
        private readonly ProductionBenchAccess $access,
        private readonly IngredientLotNumberService $numbers,
    ) {}

    /** @param array<string, mixed> $input */
    public function handle(User $actor, Workspace $workspace, array $input): IngredientLotNumberSetting
    {
        $this->access->assertCanConfigure($actor, $workspace);
        $data = validator($input, [
            'prefix' => ['nullable', 'string', 'max:24', 'regex:/\A[A-Za-z0-9._\/-]*\z/'],
            'suffix' => ['nullable', 'string', 'max:24', 'regex:/\A[A-Za-z0-9._\/-]*\z/'],
            'separator' => ['present', 'nullable', Rule::in(['-', '/', '_', ''])],
            'date_format' => ['required', Rule::in(['none', 'Y', 'ym', 'ymd', 'Ymd'])],
            'date_source' => ['required', Rule::in(['created', 'stocked'])],
            'include_material_code' => ['required', 'boolean'],
            'padding' => ['required', 'integer', 'min:1', 'max:12'],
            'reset_period' => ['required', Rule::in(['never', 'yearly', 'monthly', 'daily'])],
            'next_number' => ['required', 'integer', 'min:1', 'max:'.IngredientLotNumberService::MaximumSerial],
        ], [
            'prefix.regex' => __('lot_numbering.validation.affix'),
            'suffix.regex' => __('lot_numbering.validation.affix'),
        ])->validate();
        $data['prefix'] ??= '';
        $data['suffix'] ??= '';
        $data['separator'] ??= '';
        $allowedResets = match ($data['date_format']) {
            'none' => ['never'],
            'Y' => ['never', 'yearly'],
            'ym' => ['never', 'yearly', 'monthly'],
            default => ['never', 'yearly', 'monthly', 'daily'],
        };

        if (! in_array($data['reset_period'], $allowedResets, true)) {
            throw ValidationException::withMessages(['reset_period' => __('lot_numbering.validation.reset_date')]);
        }

        return DB::transaction(function () use ($actor, $workspace, $data): IngredientLotNumberSetting {
            $workspace = Workspace::withoutGlobalScopes()->lockForUpdate()->findOrFail($workspace->id);
            $this->access->assertCanConfigure($actor, $workspace);
            $settings = $this->numbers->settings($workspace);
            $settings->fill(collect($data)->except('next_number')->all());
            $example = $this->numbers->format($settings, now(), (int) $data['next_number'], 'OLIVE');

            if (strlen($example) > 64) {
                throw ValidationException::withMessages(['prefix' => __('lot_numbering.validation.number_format')]);
            }

            $nextSerial = $this->numbers->nextSerial($workspace, $settings, now());

            if ((int) $data['next_number'] < $nextSerial) {
                throw ValidationException::withMessages(['next_number' => __('lot_numbering.validation.counter_behind', ['number' => $nextSerial])]);
            }

            $settings->save();
            IngredientLotNumberCounter::query()->updateOrCreate([
                'workspace_id' => $workspace->id,
                'period' => $this->numbers->period($settings, now()),
            ], ['next_serial' => (int) $data['next_number']]);

            return $settings;
        }, attempts: 5);
    }
}
