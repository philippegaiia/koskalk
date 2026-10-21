<?php

namespace App\Filament\Resources\ExposurePresets;

use App\Filament\Resources\ExposurePresets\Pages\CreateExposurePreset;
use App\Filament\Resources\ExposurePresets\Pages\ListExposurePresets;
use App\Filament\Resources\ExposurePresets\Pages\ViewExposurePreset;
use App\Models\ExposurePreset;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
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

class ExposurePresetResource extends Resource
{
    protected static ?string $model = ExposurePreset::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::HandRaised;

    protected static ?int $navigationSort = 63;

    public static function getNavigationGroup(): ?string
    {
        return 'Product dossiers';
    }

    public static function getModelLabel(): string
    {
        return 'exposure preset';
    }

    public static function getPluralModelLabel(): string
    {
        return 'exposure presets';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Exposure per product type')
                ->description('SCCS Notes of Guidance values with their source. Saving publishes a new version for the product type.')
                ->schema([
                    Select::make('product_type_id')
                        ->relationship(name: 'productType', titleAttribute: 'name')
                        ->searchable()
                        ->preload()
                        ->required(),
                    TextInput::make('target_population')->required()->maxLength(255),
                    TextInput::make('amount_per_use_g')->label('Amount per use (g)')->numeric()->required(),
                    TextInput::make('uses_per_day')->label('Uses per day')->numeric()->required(),
                    TextInput::make('retention_factor')->label('Retention factor')->numeric()->required(),
                    Toggle::make('is_rinse_off')->label('Rinse-off product'),
                    TagsInput::make('application_sites')->label('Application sites')->required(),
                    TagsInput::make('direct_routes')->label('Direct exposure routes')->required(),
                    TagsInput::make('secondary_routes')->label('Secondary exposure routes'),
                    Textarea::make('source_reference')->label('Source')->rows(3)->required()->columnSpanFull(),
                    Textarea::make('change_note')->label('What changed in this version')->rows(2)->columnSpanFull(),
                ])
                ->columns(['md' => 2]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('productType'))
            ->columns([
                TextColumn::make('productType.name')->label('Product type')->searchable(),
                TextColumn::make('version'),
                IconColumn::make('is_current')->label('Current')->boolean(),
                TextColumn::make('amount_per_use_g')->label('g / use'),
                TextColumn::make('uses_per_day')->label('Uses / day'),
                TextColumn::make('retention_factor')->label('R'),
                IconColumn::make('is_rinse_off')->label('Rinse-off')->boolean(),
            ])
            ->filters([TernaryFilter::make('is_current')->label('Current versions')->default(true)])
            ->recordActions([ViewAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListExposurePresets::route('/'),
            'create' => CreateExposurePreset::route('/create'),
            'view' => ViewExposurePreset::route('/{record}'),
        ];
    }
}
