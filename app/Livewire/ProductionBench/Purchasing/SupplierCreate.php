<?php

namespace App\Livewire\ProductionBench\Purchasing;

use App\Actions\Purchasing\SaveSupplier;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Workspace;
use App\Services\CurrencyCatalog;
use App\Services\ProductionBenchAccess;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Concerns\RestrictsFileUploadsToSchemaComponents;
use Filament\Schemas\Schema;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class SupplierCreate extends Component implements HasForms
{
    use InteractsWithForms;
    use RestrictsFileUploadsToSchemaComponents;

    private CurrencyCatalog $currencyCatalog;

    /** @var array<string, mixed> */
    public array $data = [];

    public function boot(CurrencyCatalog $currencyCatalog): void
    {
        $this->currencyCatalog = $currencyCatalog;
    }

    public function mount(ProductionBenchAccess $access): void
    {
        $this->assertPageIsWritable($access);
        $this->form->fill([
            'default_currency' => $this->workspace()->default_currency,
            'is_active' => true,
        ]);
    }

    public function save(SaveSupplier $saveSupplier): void
    {
        /** @var array<string, mixed> $state */
        $state = $this->form->getState();

        try {
            $supplier = $saveSupplier->handle($this->user(), $this->workspace(), $state);
        } catch (ValidationException $exception) {
            $this->surfaceValidationErrors($exception);

            return;
        }

        $this->redirectRoute('production-bench.purchasing.supplier', ['supplier' => $supplier], navigate: true);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components($this->formComponents())
            ->statePath('data')
            ->model(Supplier::class);
    }

    public function render(): View
    {
        return view('livewire.production-bench.purchasing.supplier-create');
    }

    /** @return array<int, Section> */
    private function formComponents(): array
    {
        return [
            Section::make('Supplier')
                ->columns(['md' => 2])
                ->schema([
                    TextInput::make('code')
                        ->label('Code')
                        ->helperText('A-Z, 0-9, - or _, max 16.')
                        ->required()
                        ->maxLength(16)
                        ->regex('/^[A-Za-z0-9_-]+$/')
                        ->mutateStateForValidationUsing(fn (?string $state): string => Str::upper(trim((string) $state)))
                        ->dehydrateStateUsing(fn (?string $state): string => Str::upper(trim((string) $state))),
                    TextInput::make('name')
                        ->label('Name')
                        ->required()
                        ->maxLength(255)
                        ->autocomplete('organization'),
                    Select::make('default_currency')
                        ->label('Currency')
                        ->options($this->currencyOptions())
                        ->searchable()
                        ->required(),
                    Toggle::make('is_active')
                        ->label('Active')
                        ->default(true),
                ]),
            Section::make('Main contact')
                ->columns(['md' => 2])
                ->schema([
                    TextInput::make('contact_name')->label('Name')->maxLength(255)->autocomplete('name'),
                    TextInput::make('email')->label('Email')->email()->maxLength(255)->autocomplete('email'),
                    TextInput::make('phone')->label('Telephone')->tel()->maxLength(255)->autocomplete('tel'),
                    TextInput::make('website')->label('Website')->url()->rules(['url:http,https'])->maxLength(255)->autocomplete('url')->placeholder('https://'),
                ]),
            Section::make('Address')
                ->columns(['md' => 2])
                ->schema([
                    TextInput::make('address_line_1')->label('Address line 1')->maxLength(255)->autocomplete('address-line1')->columnSpanFull(),
                    TextInput::make('address_line_2')->label('Address line 2')->maxLength(255)->autocomplete('address-line2')->columnSpanFull(),
                    TextInput::make('city')->label('City')->maxLength(255)->autocomplete('address-level2'),
                    TextInput::make('region')->label('Region')->maxLength(255)->autocomplete('address-level1'),
                    TextInput::make('postal_code')->label('Postal code')->maxLength(32)->autocomplete('postal-code'),
                    TextInput::make('country_code')
                        ->label('Country code')
                        ->length(2)
                        ->alpha()
                        ->autocomplete('country')
                        ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? Str::upper(trim((string) $state)) : null),
                ]),
            Section::make('Notes')
                ->schema([
                    Textarea::make('notes')->hiddenLabel()->rows(4),
                ]),
        ];
    }

    /** @return array<string, string> */
    private function currencyOptions(): array
    {
        return collect($this->currencyCatalog->options(app()->getLocale()))
            ->mapWithKeys(fn (string $name, string $code): array => [$code => $code.' · '.$name])
            ->all();
    }

    private function surfaceValidationErrors(ValidationException $exception): void
    {
        foreach ($exception->errors() as $field => $messages) {
            foreach ($messages as $message) {
                $this->addError('data.'.$field, $message);
            }
        }
    }

    private function assertPageIsWritable(ProductionBenchAccess $access): void
    {
        try {
            $access->assertWritable($this->user(), $this->workspace());
        } catch (ValidationException) {
            abort(403);
        }
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
