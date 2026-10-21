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
        return 'QRA2 parameter set';
    }

    public static function getPluralModelLabel(): string
    {
        return 'QRA2 parameters';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('QRA2 per IFRA category')
                ->description('Saving publishes a new version for the category and amendment.')
                ->schema([
                    Select::make('ifra_product_category_id')
                        ->label('IFRA category')
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
                    TextInput::make('sensitization_assessment_factor')->label('SAF')->numeric()->required(),
                    TextInput::make('consumer_exposure_level_mg_cm2_day')->label('CEL (mg/cm²/day)')->numeric()->required(),
                    TextInput::make('qra2_factor')->label('QRA2 factor')->numeric(),
                    Toggle::make('applies_qra2_factor')
                        ->label('Multiply Cmax by the QRA2 factor')
                        ->helperText('Open question for the safety assessor (DIP spec §11.1). Record the decision and its source.'),
                    Textarea::make('source_reference')->label('Source')->rows(3)->required()->columnSpanFull(),
                    Textarea::make('change_note')->label('What changed in this version')->rows(2)->columnSpanFull(),
                ])
                ->columns(['md' => 2]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['ifraProductCategory', 'ifraAmendment']))
            ->columns([
                TextColumn::make('ifraProductCategory.name')->label('Category'),
                TextColumn::make('ifraAmendment.code')->label('Amendment'),
                TextColumn::make('version'),
                IconColumn::make('is_current')->label('Current')->boolean(),
                TextColumn::make('sensitization_assessment_factor')->label('SAF'),
                TextColumn::make('consumer_exposure_level_mg_cm2_day')->label('CEL'),
                TextColumn::make('qra2_factor')->label('Factor')->placeholder('—'),
                IconColumn::make('applies_qra2_factor')->label('Factor applied')->boolean(),
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
