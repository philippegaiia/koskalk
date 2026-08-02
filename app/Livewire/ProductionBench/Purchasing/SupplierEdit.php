<?php

namespace App\Livewire\ProductionBench\Purchasing;

use App\Actions\Purchasing\DeleteSupplier;
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

class SupplierEdit extends Component implements HasForms
{
    use InteractsWithForms;
    use RestrictsFileUploadsToSchemaComponents;

    private CurrencyCatalog $currencyCatalog;

    public string|Supplier $supplier;

    /** @var array<string, mixed> */
    public array $data = [];

    public function boot(CurrencyCatalog $currencyCatalog): void
    {
        $this->currencyCatalog = $currencyCatalog;
    }

    public function mount(string|Supplier $supplier, ProductionBenchAccess $access): void
    {
        $supplierId = $supplier instanceof Supplier ? $supplier->public_id : $supplier;
        $this->supplier = Supplier::query()
            ->where('workspace_id', $this->workspace()->id)
            ->where('public_id', $supplierId)
            ->firstOrFail();

        $this->assertPageIsWritable($access);
        $this->form->fill($this->supplier->only([
            'code', 'name', 'is_active', 'default_currency', 'contact_name', 'email', 'phone', 'website',
            'address_line_1', 'address_line_2', 'city', 'region', 'postal_code', 'country_code', 'notes',
        ]));
    }

    public function save(SaveSupplier $saveSupplier): void
    {
        if (! $this->supplier instanceof Supplier) {
            abort(404);
        }

        /** @var array<string, mixed> $state */
        $state = $this->form->getState();

        try {
            $this->supplier = $saveSupplier->handle($this->user(), $this->workspace(), $state, $this->supplier);
        } catch (ValidationException $exception) {
            $this->surfaceValidationErrors($exception);

            return;
        }

        $this->redirectRoute('production-bench.purchasing.supplier', ['supplier' => $this->supplier], navigate: true);
    }

    public function delete(DeleteSupplier $deleteSupplier): void
    {
        if (! $this->supplier instanceof Supplier) {
            abort(404);
        }

        $deleted = $deleteSupplier->handle($this->user(), $this->workspace(), $this->supplier);

        session()->flash(
            'status',
            $deleted ? __('production_bench.supplier.deleted') : __('production_bench.supplier.deactivated'),
        );

        if ($deleted) {
            $this->redirectRoute('production-bench.purchasing.suppliers', navigate: true);

            return;
        }

        $this->redirectRoute('production-bench.purchasing.supplier', ['supplier' => $this->supplier], navigate: true);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components($this->formComponents())
            ->statePath('data')
            ->model($this->supplier instanceof Supplier ? $this->supplier : Supplier::class);
    }

    public function render(): View
    {
        return view('livewire.production-bench.purchasing.supplier-edit');
    }

    /** @return array<int, Section> */
    private function formComponents(): array
    {
        return [
            Section::make(__('production_bench.supplier.singular'))->compact()->columns(['md' => 2])->schema([
                TextInput::make('code')->label(__('production_bench.supplier.code'))->helperText(__('production_bench.supplier.code_help'))->required()->maxLength(16)->regex('/^[A-Za-z0-9_-]+$/')->mutateStateForValidationUsing(fn (?string $state): string => Str::upper(trim((string) $state)))->dehydrateStateUsing(fn (?string $state): string => Str::upper(trim((string) $state))),
                TextInput::make('name')->label(__('production_bench.common.name'))->required()->maxLength(255)->autocomplete('organization'),
                Select::make('default_currency')
                    ->label(__('production_bench.common.currency'))
                    ->options($this->currencyOptions())
                    ->searchable()
                    ->required()
                    ->disabled(fn (): bool => $this->supplier instanceof Supplier && $this->supplier->listings()->exists())
                    ->dehydrated()
                    ->helperText(fn (): ?string => $this->supplier instanceof Supplier && $this->supplier->listings()->exists()
                        ? __('production_bench.supplier.currency_locked_help')
                        : null),
                Toggle::make('is_active')->label(__('production_bench.common.active')),
            ]),
            Section::make(__('production_bench.supplier.main_contact'))->compact()->columns(['md' => 2])->schema([
                TextInput::make('contact_name')->label(__('production_bench.common.name'))->maxLength(255)->autocomplete('name'),
                TextInput::make('email')->label(__('production_bench.supplier.email'))->email()->maxLength(255)->autocomplete('email'),
                TextInput::make('phone')->label(__('production_bench.supplier.telephone'))->tel()->maxLength(255)->autocomplete('tel'),
                TextInput::make('website')->label(__('production_bench.supplier.website'))->url()->rules(['url:http,https'])->maxLength(255)->autocomplete('url')->placeholder('https://'),
            ]),
            Section::make(__('production_bench.supplier.address'))->compact()->columns(['md' => 2])->schema([
                TextInput::make('address_line_1')->label(__('production_bench.supplier.address_line_1'))->maxLength(255)->autocomplete('address-line1')->columnSpanFull(),
                TextInput::make('address_line_2')->label(__('production_bench.supplier.address_line_2'))->maxLength(255)->autocomplete('address-line2')->columnSpanFull(),
                TextInput::make('city')->label(__('production_bench.supplier.city'))->maxLength(255)->autocomplete('address-level2'),
                TextInput::make('region')->label(__('production_bench.supplier.region'))->maxLength(255)->autocomplete('address-level1'),
                TextInput::make('postal_code')->label(__('production_bench.supplier.postal_code'))->maxLength(32)->autocomplete('postal-code'),
                TextInput::make('country_code')->label(__('production_bench.supplier.country_code'))->length(2)->alpha()->autocomplete('country')->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? Str::upper(trim((string) $state)) : null),
            ]),
            Section::make(__('production_bench.common.notes'))->compact()->schema([
                Textarea::make('notes')->hiddenLabel()->rows(4),
            ]),
        ];
    }

    /** @return array<string, string> */
    private function currencyOptions(): array
    {
        $includeCodes = $this->supplier instanceof Supplier ? [$this->supplier->default_currency] : [];

        return collect($this->currencyCatalog->options(app()->getLocale(), $includeCodes))
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
