<?php

namespace App\Forms\Components;

use App\Services\CurrencyCatalog;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Illuminate\Support\Str;

/**
 * The supplier fields shared by every place that creates a supplier (Production Bench, DIP raw materials).
 */
class SupplierFields
{
    /** @return array<int, Section> */
    public static function make(CurrencyCatalog $currencyCatalog): array
    {
        $currencyOptions = collect($currencyCatalog->options(app()->getLocale()))
            ->mapWithKeys(fn (string $name, string $code): array => [$code => $code.' · '.$name])
            ->all();

        return [
            Section::make(__('production_bench.supplier.singular'))
                ->compact()
                ->columns(['md' => 2])
                ->schema([
                    TextInput::make('code')
                        ->label(__('production_bench.supplier.code'))
                        ->helperText(__('production_bench.supplier.code_help'))
                        ->required()
                        ->maxLength(16)
                        ->regex('/^[A-Za-z0-9_-]+$/')
                        ->mutateStateForValidationUsing(fn (?string $state): string => Str::upper(trim((string) $state)))
                        ->dehydrateStateUsing(fn (?string $state): string => Str::upper(trim((string) $state))),
                    TextInput::make('name')
                        ->label(__('production_bench.common.name'))
                        ->required()
                        ->maxLength(255)
                        ->autocomplete('organization'),
                    Select::make('default_currency')
                        ->label(__('production_bench.common.currency'))
                        ->options($currencyOptions)
                        ->searchable()
                        ->required(),
                    Toggle::make('is_active')
                        ->label(__('production_bench.common.active'))
                        ->default(true),
                ]),
            Section::make(__('production_bench.supplier.main_contact'))
                ->compact()
                ->columns(['md' => 2])
                ->schema([
                    TextInput::make('contact_name')->label(__('production_bench.common.name'))->maxLength(255)->autocomplete('name'),
                    TextInput::make('email')->label(__('production_bench.supplier.email'))->email()->maxLength(255)->autocomplete('email'),
                    TextInput::make('phone')->label(__('production_bench.supplier.telephone'))->tel()->maxLength(255)->autocomplete('tel'),
                    TextInput::make('website')->label(__('production_bench.supplier.website'))->url()->rules(['url:http,https'])->maxLength(255)->autocomplete('url')->placeholder('https://'),
                ]),
            Section::make(__('production_bench.supplier.address'))
                ->compact()
                ->columns(['md' => 2])
                ->schema([
                    TextInput::make('address_line_1')->label(__('production_bench.supplier.address_line_1'))->maxLength(255)->autocomplete('address-line1')->columnSpanFull(),
                    TextInput::make('address_line_2')->label(__('production_bench.supplier.address_line_2'))->maxLength(255)->autocomplete('address-line2')->columnSpanFull(),
                    TextInput::make('city')->label(__('production_bench.supplier.city'))->maxLength(255)->autocomplete('address-level2'),
                    TextInput::make('region')->label(__('production_bench.supplier.region'))->maxLength(255)->autocomplete('address-level1'),
                    TextInput::make('postal_code')->label(__('production_bench.supplier.postal_code'))->maxLength(32)->autocomplete('postal-code'),
                    TextInput::make('country_code')
                        ->label(__('production_bench.supplier.country_code'))
                        ->length(2)
                        ->alpha()
                        ->autocomplete('country')
                        ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? Str::upper(trim((string) $state)) : null),
                ]),
            Section::make(__('production_bench.common.notes'))
                ->compact()
                ->schema([
                    Textarea::make('notes')->hiddenLabel()->rows(4),
                ]),
        ];
    }
}
