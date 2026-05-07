<?php

namespace App\Filament\Resources\RegulatoryRegimeSubstanceRules\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class RegulatoryRegimeSubstanceRuleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Regime Substance Rule')
                    ->description('Map a substance to one market regime as prohibited, restricted, or watch-only.')
                    ->icon(Heroicon::ShieldCheck)
                    ->schema([
                        Select::make('regulatory_regime_id')
                            ->relationship(name: 'regulatoryRegime', titleAttribute: 'name')
                            ->searchable()
                            ->preload()
                            ->required(),
                        Select::make('substance_id')
                            ->relationship(name: 'substance', titleAttribute: 'name')
                            ->searchable()
                            ->preload()
                            ->required(),
                        Select::make('rule_type')
                            ->options([
                                'prohibited' => 'Prohibited',
                                'restricted' => 'Restricted',
                                'watch' => 'Watch',
                            ])
                            ->default('watch')
                            ->required(),
                        Toggle::make('is_active')
                            ->label('Active')
                            ->default(true),
                    ])
                    ->columns([
                        'md' => 2,
                    ]),
                Section::make('Exposure Limits')
                    ->description('Leave limits empty for prohibited and watch-only rules.')
                    ->icon(Heroicon::Beaker)
                    ->schema([
                        TextInput::make('rinse_off_max_percent')
                            ->label('Rinse-off max')
                            ->numeric()
                            ->inputMode('decimal')
                            ->suffix('%'),
                        TextInput::make('leave_on_max_percent')
                            ->label('Leave-on max')
                            ->numeric()
                            ->inputMode('decimal')
                            ->suffix('%'),
                        Select::make('threshold_operator')
                            ->options([
                                'less_than_or_equal' => 'Less than or equal',
                                'less_than' => 'Less than',
                            ])
                            ->default('less_than_or_equal')
                            ->required(),
                        Select::make('exposure_scope')
                            ->options([
                                'both' => 'Both',
                                'rinse_off' => 'Rinse-off only',
                                'leave_on' => 'Leave-on only',
                            ])
                            ->default('both')
                            ->required(),
                    ])
                    ->columns([
                        'md' => 2,
                    ]),
                Section::make('Effective Window And Message')
                    ->description('Optional dates and preview text shown to formulators.')
                    ->icon(Heroicon::CalendarDays)
                    ->schema([
                        DatePicker::make('effective_from'),
                        DatePicker::make('effective_until'),
                        TextInput::make('label_warning_text')
                            ->maxLength(255)
                            ->columnSpanFull(),
                    ])
                    ->columns([
                        'md' => 2,
                    ]),
                Section::make('Source Traceability')
                    ->description('Record the legal reference, supplier basis, or internal review note.')
                    ->icon(Heroicon::DocumentText)
                    ->schema([
                        Textarea::make('source_reference')
                            ->rows(4)
                            ->columnSpanFull(),
                        KeyValue::make('source_data')
                            ->keyLabel('Key')
                            ->valueLabel('Value')
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
