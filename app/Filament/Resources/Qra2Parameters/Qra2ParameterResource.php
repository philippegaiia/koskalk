<?php

namespace App\Filament\Resources\Qra2Parameters;

use App\Filament\Resources\Qra2Parameters\Pages\CreateQra2Parameter;
use App\Filament\Resources\Qra2Parameters\Pages\ListQra2Parameters;
use App\Filament\Resources\Qra2Parameters\Pages\ViewQra2Parameter;
use App\Models\Qra2Parameter;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class Qra2ParameterResource extends Resource
{
    protected static ?string $model = Qra2Parameter::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::Sparkles;

    protected static ?int $navigationSort = 64;

    public static function getNavigationGroup(): ?string
    {
        return 'Product dossiers';
    }

    public static function getModelLabel(): string
    {
        return 'QRA2 preset';
    }

    public static function getPluralModelLabel(): string
    {
        return 'QRA2 presets';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Preset')
                ->description('One preset per kind of product you make (e.g. bar soap = IFRA category 9). It is used to check every fragrance allergen in those products. Saving publishes a new version.')
                ->schema([
                    TextInput::make('preset_label')
                        ->label('Preset name')
                        ->placeholder('Bar soap — IFRA category 9 — amendment 51')
                        ->required()
                        ->maxLength(255)
                        ->columnSpanFull(),
                    Select::make('ifra_product_category_id')
                        ->label('IFRA QRA2 category')
                        ->relationship(name: 'ifraProductCategory', titleAttribute: 'name')
                        ->searchable()
                        ->preload()
                        ->required(),
                    Select::make('ifra_amendment_id')
                        ->label('IFRA amendment')
                        ->relationship(name: 'ifraAmendment', titleAttribute: 'code')
                        ->searchable()
                        ->preload()
                        ->required(),
                    Textarea::make('source_reference')
                        ->label('Source')
                        ->placeholder('IFRA 51st Amendment — Guidance for the use of IFRA Standards, Table 6, p. 28')
                        ->rows(2)
                        ->required()
                        ->columnSpanFull(),
                    Textarea::make('change_note')->label('What changed in this version')->rows(2)->columnSpanFull(),
                ])
                ->columns(['md' => 2]),
            Section::make('IFRA values (technical)')
                ->description('Copy them from the IFRA guidance table for this category. They are method constants, not choices.')
                ->collapsible()
                ->schema([
                    TextInput::make('sensitization_assessment_factor')
                        ->label('SAF — Sensitization Assessment Factor')
                        ->helperText('Safety margin dividing the allergen\'s NESIL. Higher means more cautious.')
                        ->numeric()
                        ->required(),
                    TextInput::make('consumer_exposure_level_mg_cm2_day')
                        ->label('CEL — Consumer Exposure Level (mg/cm²/day)')
                        ->helperText('How much product stays on the skin per day for this category.')
                        ->numeric()
                        ->required(),
                    TextInput::make('qra2_factor')
                        ->label('Aggregate exposure adjustment factor')
                        ->helperText('Accounts for the same allergen reaching the skin from several products. Part of the method: the maximum concentration is multiplied by it.')
                        ->numeric()
                        ->required(),
                ])
                ->columns(['md' => 3]),
            Section::make('Expert deviation')
                ->description('Only if a qualified safety assessor decides not to apply the aggregate adjustment factor.')
                ->collapsible()
                ->collapsed()
                ->schema([
                    Toggle::make('applies_qra2_factor')
                        ->label('Apply the aggregate adjustment factor')
                        ->default(true)
                        ->live(),
                    Textarea::make('factor_deviation_reason')
                        ->label('Reason and who decided')
                        ->rows(2)
                        ->required(fn (Get $get): bool => ! $get('applies_qra2_factor'))
                        ->visible(fn (Get $get): bool => ! $get('applies_qra2_factor'))
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['ifraProductCategory', 'ifraAmendment']))
            ->columns([
                TextColumn::make('preset_label')->label('Preset')->placeholder('—')->searchable(),
                TextColumn::make('ifraProductCategory.code')->label('Category'),
                TextColumn::make('ifraAmendment.code')->label('Amendment'),
                TextColumn::make('version'),
                IconColumn::make('is_current')->label('Current')->boolean(),
                TextColumn::make('sensitization_assessment_factor')->label('SAF'),
                TextColumn::make('consumer_exposure_level_mg_cm2_day')->label('CEL'),
                TextColumn::make('qra2_factor')->label('Adjustment')->placeholder('—'),
                IconColumn::make('applies_qra2_factor')->label('Factor applied')->boolean()
                    ->tooltip(fn (Qra2Parameter $record): ?string => $record->factor_deviation_reason),
            ])
            ->filters([TernaryFilter::make('is_current')->label('Current versions')->default(true)])
            ->recordActions([ViewAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListQra2Parameters::route('/'),
            'create' => CreateQra2Parameter::route('/create'),
            'view' => ViewQra2Parameter::route('/{record}'),
        ];
    }
}
