<?php

namespace App\Livewire\ProductionBench\Production;

use App\Actions\Inventory\SaveIngredientLotNumberSettings;
use App\Enums\WorkspaceMemberRole;
use App\Livewire\Concerns\InteractsWithAppNotifications;
use App\Models\IngredientLotNumberSetting;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Inventory\IngredientLotNumberService;
use App\Services\ProductionBenchAccess;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

class IngredientLotNumberSettings extends Component implements HasActions, HasForms
{
    use InteractsWithActions;
    use InteractsWithAppNotifications;
    use InteractsWithForms;

    /** @var array<string, mixed> */
    public array $data = [];

    /** @var array<string, mixed> */
    #[Locked]
    public array $savedData = [];

    public function mount(IngredientLotNumberService $numbers): void
    {
        $this->fillSettings($numbers);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('prefix')->label(__('lot_numbering.prefix'))->maxLength(24)->live(onBlur: true),
            TextInput::make('suffix')->label(__('lot_numbering.suffix'))->maxLength(24)->live(onBlur: true),
            Select::make('date_format')->label(__('lot_numbering.date_format'))
                ->options([
                    'none' => __('lot_numbering.no_date'),
                    'Y' => 'YYYY · '.now()->format('Y'),
                    'ym' => 'YYMM · '.now()->format('ym'),
                    'ymd' => 'YYMMDD · '.now()->format('ymd'),
                    'Ymd' => 'YYYYMMDD · '.now()->format('Ymd'),
                ])->required()->live(),
            Select::make('date_source')->label(__('lot_numbering.date_source'))
                ->options(['created' => __('lot_numbering.created'), 'stocked' => __('lot_numbering.stocked')])
                ->required()->live(),
            Select::make('separator')->label(__('lot_numbering.separator'))
                ->options(['-' => __('lot_numbering.hyphen'), '/' => __('lot_numbering.slash'), '_' => __('lot_numbering.underscore'), '' => __('lot_numbering.none')])
                ->selectablePlaceholder(false)->live(),
            TextInput::make('padding')->label(__('lot_numbering.padding'))
                ->helperText(__('lot_numbering.padding_help'))->type('text')->inputMode('numeric')
                ->required()->integer()->minValue(1)->maxValue(12)->live(onBlur: true),
            Toggle::make('include_material_code')->label(__('lot_numbering.include_material_code'))
                ->helperText(__('lot_numbering.material_code_help'))->live()->columnSpanFull(),
            Select::make('reset_period')->label(__('lot_numbering.reset_period'))
                ->options(fn (Get $get): array => collect($get('date_format') === 'none' ? ['never'] : ['never', 'yearly', 'monthly', 'daily'])->mapWithKeys(fn (string $period): array => [$period => __('lot_numbering.'.$period)])->all())
                ->helperText(fn (Get $get): string => __($get('date_format') === 'none' ? 'lot_numbering.no_date_help' : 'lot_numbering.reset_help'))->required()->live(),
            TextInput::make('next_number')->label(__('lot_numbering.next_number'))
                ->helperText(fn (Get $get): string => $get('reset_period') === 'never'
                    ? __('lot_numbering.continuous_help')
                    : __('lot_numbering.counter_help', ['date' => now()->toDateString()]))
                ->type('text')->inputMode('numeric')->required()->integer()->minValue(1)
                ->maxValue(IngredientLotNumberService::MaximumSerial)->live(onBlur: true),
        ])->columns(['md' => 2])->statePath('data')->disabled(fn (): bool => ! $this->isEditable);
    }

    public function updatedData(mixed $value, ?string $key = null): void
    {
        if ($key === 'date_format' && $value === 'none' && $this->data['reset_period'] !== 'never') {
            $this->data['reset_period'] = 'never';
            $key = 'reset_period';
        }

        if ($key === 'reset_period') {
            $numbers = app(IngredientLotNumberService::class);
            $settings = $numbers->settings($this->workspace());
            $settings->fill(collect($this->data)->except('next_number')->all());
            $this->data['next_number'] = $numbers->nextSerial($this->workspace(), $settings, now());
        }
    }

    public function save(SaveIngredientLotNumberSettings $saveSettings, IngredientLotNumberService $numbers, ProductionBenchAccess $access): void
    {
        $access->assertCanConfigure($this->user(), $this->workspace());
        $this->resetErrorBag();

        try {
            $saveSettings->handle($this->user(), $this->workspace(), $this->form->getState());
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                foreach ($messages as $message) {
                    $this->addError(str_starts_with($field, 'data.') ? $field : 'data.'.$field, $message);
                }
            }

            return;
        }

        $this->fillSettings($numbers);
        $this->showAppNotification(__('lot_numbering.saved'));
    }

    #[Computed]
    public function isEditable(): bool
    {
        $workspace = $this->workspace();
        $access = app(ProductionBenchAccess::class);

        return in_array($workspace->roleFor($this->user()), [WorkspaceMemberRole::Owner, WorkspaceMemberRole::Admin], true)
            && $access->isActive($workspace) && ! $access->isReadOnly($workspace);
    }

    public function render(IngredientLotNumberService $numbers): View
    {
        $valid = validator($this->data, [
            'prefix' => ['nullable', 'string', 'max:24'],
            'suffix' => ['nullable', 'string', 'max:24'],
            'date_format' => ['required', 'in:none,Y,ym,ymd,Ymd'],
            'separator' => ['nullable', 'string', 'max:1'],
            'include_material_code' => ['required', 'boolean'],
            'padding' => ['required', 'integer', 'between:1,12'],
            'next_number' => ['required', 'integer', 'min:1', 'max:'.IngredientLotNumberService::MaximumSerial],
        ]);
        $example = null;

        if ($valid->passes()) {
            $settings = new IngredientLotNumberSetting(collect($valid->validated())->except('next_number')->all());
            $settings->prefix ??= '';
            $settings->suffix ??= '';
            $settings->separator ??= '';
            $example = $numbers->format($settings, now(), (int) $this->data['next_number'], 'OLIVE');
        }

        return view('livewire.production-bench.production.ingredient-lot-number-settings', ['example' => $example]);
    }

    private function fillSettings(IngredientLotNumberService $numbers): void
    {
        $settings = $numbers->settings($this->workspace());
        $this->form->fill([
            ...$settings->only(['prefix', 'suffix', 'date_format', 'date_source', 'separator', 'include_material_code', 'padding', 'reset_period']),
            'next_number' => $numbers->nextSerial($this->workspace(), $settings, now()),
        ]);
        $this->savedData = $this->data;
    }

    private function user(): User
    {
        return auth()->user() ?? abort(401);
    }

    private function workspace(): Workspace
    {
        return $this->user()->company() ?? abort(404);
    }
}
