<?php

namespace App\Filament\Resources\AssessmentPolicies;

use App\Filament\Resources\AssessmentPolicies\Pages\CreateAssessmentPolicy;
use App\Filament\Resources\AssessmentPolicies\Pages\ListAssessmentPolicies;
use App\Filament\Resources\AssessmentPolicies\Pages\ViewAssessmentPolicy;
use App\Models\AssessmentPolicy;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AssessmentPolicyResource extends Resource
{
    protected static ?string $model = AssessmentPolicy::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::AdjustmentsHorizontal;

    protected static ?int $navigationSort = 62;

    public static function getNavigationGroup(): ?string
    {
        return 'Product dossiers';
    }

    public static function getModelLabel(): string
    {
        return 'assessment policy';
    }

    public static function getPluralModelLabel(): string
    {
        return 'assessment policies';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Default scientific assumptions')
                ->description('Used when a toxicological profile or a dossier gives no value. Saving publishes a new version.')
                ->schema([
                    TextInput::make('default_dermal_absorption_percent')->label('Default dermal absorption (%)')->numeric()->required(),
                    TextInput::make('default_oral_bioavailability_percent')->label('Default oral bioavailability (%)')->numeric()->required(),
                    TextInput::make('mos_threshold')->label('MoS threshold')->numeric()->required(),
                    TextInput::make('body_weight_kg')->label('Body weight (kg)')->numeric()->required(),
                    Textarea::make('source_reference')->label('Source')->rows(3)->required()->columnSpanFull(),
                    Textarea::make('change_note')->label('What changed in this version')->rows(2)->columnSpanFull(),
                ])
                ->columns(['md' => 2]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('version')->sortable(),
                IconColumn::make('is_current')->label('Current')->boolean(),
                TextColumn::make('default_dermal_absorption_percent')->label('DAp %'),
                TextColumn::make('default_oral_bioavailability_percent')->label('Oral %'),
                TextColumn::make('mos_threshold')->label('MoS'),
                TextColumn::make('body_weight_kg')->label('bw kg'),
                TextColumn::make('created_at')->label('Published')->dateTime(),
            ])
            ->recordActions([ViewAction::make()])
            ->defaultSort('version', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAssessmentPolicies::route('/'),
            'create' => CreateAssessmentPolicy::route('/create'),
            'view' => ViewAssessmentPolicy::route('/{record}'),
        ];
    }
}
