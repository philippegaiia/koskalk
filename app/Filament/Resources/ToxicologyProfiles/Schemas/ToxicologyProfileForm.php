<?php

namespace App\Filament\Resources\ToxicologyProfiles\Schemas;

use App\Enums\NoaelRoute;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class ToxicologyProfileForm
{
    public static function configure(Schema $schema): Schema
    {
        $routes = collect(NoaelRoute::cases())->mapWithKeys(fn (NoaelRoute $route): array => [$route->value => ucfirst($route->value)])->all();

        return $schema
            ->components([
                Section::make('Assessed material')
                    ->description('Profiles are append-only: saving publishes a new version and supersedes the current one.')
                    ->icon(Heroicon::Beaker)
                    ->schema([
                        Select::make('substance_id')
                            ->label('Substance / assessed material')
                            ->relationship(name: 'substance', titleAttribute: 'name')
                            ->searchable()
                            ->preload()
                            ->required(),
                        Textarea::make('change_note')
                            ->label('What changed in this version')
                            ->rows(2),
                    ]),
                Section::make('Systemic toxicity')
                    ->schema([
                        TextInput::make('noael_mg_kg_bw_day')->label('NOAEL (mg/kg bw/day)')->numeric(),
                        Select::make('noael_route')->label('NOAEL route')->options($routes),
                        TextInput::make('noael_species')->label('Species')->maxLength(64),
                        Textarea::make('noael_study')->label('Study')->rows(2)->columnSpanFull(),
                        TextInput::make('oral_bioavailability_percent')->label('Oral bioavailability (%)')->helperText('Leave empty to use the assessment policy default.')->numeric(),
                        TextInput::make('ld50_mg_kg')->label('LD50 (mg/kg)')->numeric(),
                        Select::make('ld50_route')->label('LD50 route')->options($routes),
                    ])
                    ->columns(['md' => 2]),
                Section::make('Absorption and thresholds')
                    ->schema([
                        TextInput::make('dermal_absorption_percent')->label('Dermal absorption DAp (%)')->helperText('Leave empty to use the assessment policy default.')->numeric(),
                        TextInput::make('cmax_percent')->label('Cmax (%)')->numeric(),
                        TextInput::make('cmax_origin')->label('Cmax origin (SCCS, CIR, supplier…)')->maxLength(255),
                        TextInput::make('adi_mg_kg_bw_day')->label('ADI (mg/kg bw/day)')->numeric(),
                        Textarea::make('adi_reasoning')->label('Why the ADI applies to this use')->rows(2)->columnSpanFull(),
                        TextInput::make('ttc_class')->label('TTC class')->maxLength(32),
                        TextInput::make('ttc_mg_kg_bw_day')->label('TTC (mg/kg bw/day)')->numeric(),
                        Textarea::make('ttc_reasoning')->label('Why the TTC applies')->rows(2)->columnSpanFull(),
                        TextInput::make('nesil_ug_cm2')->label('WoE NESIL (µg/cm²)')->numeric(),
                    ])
                    ->columns(['md' => 2]),
                Section::make('Hazard notes')
                    ->schema([
                        Textarea::make('skin_irritation_note')->label('Skin irritation')->rows(2),
                        Textarea::make('eye_irritation_note')->label('Eye irritation')->rows(2),
                        Textarea::make('sensitisation_note')->label('Skin sensitisation')->rows(2),
                        Textarea::make('phototoxicity_note')->label('Phototoxicity')->rows(2),
                        Textarea::make('cmr_note')->label('CMR')->rows(2),
                        Textarea::make('clp_classification')->label('CLP classification')->rows(2),
                    ])
                    ->columns(['md' => 2]),
                Section::make('Sources')
                    ->description('Every numeric value must cite at least one numbered reference.')
                    ->icon(Heroicon::DocumentText)
                    ->schema([
                        Repeater::make('references')
                            ->schema([
                                TextInput::make('number')->numeric()->minValue(1)->required(),
                                TextInput::make('citation')->required()->maxLength(1000)->columnSpan(2),
                                TextInput::make('url')->url()->maxLength(2048),
                            ])
                            ->columns(4)
                            ->defaultItems(1)
                            ->required(),
                        KeyValue::make('value_references')
                            ->label('Value sources')
                            ->keyLabel('Value field (e.g. noael_mg_kg_bw_day)')
                            ->valueLabel('Reference numbers (comma separated)'),
                    ]),
            ]);
    }
}
