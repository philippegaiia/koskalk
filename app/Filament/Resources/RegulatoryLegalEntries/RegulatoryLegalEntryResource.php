<?php

namespace App\Filament\Resources\RegulatoryLegalEntries;

use App\Enums\LegalAnnex;
use App\Enums\LegalEntryProductScope;
use App\Filament\Resources\RegulatoryLegalEntries\Pages\CreateRegulatoryLegalEntry;
use App\Filament\Resources\RegulatoryLegalEntries\Pages\EditRegulatoryLegalEntry;
use App\Filament\Resources\RegulatoryLegalEntries\Pages\ListRegulatoryLegalEntries;
use App\Models\RegulatoryLegalEntry;
use App\Services\Dossiers\RegulatoryLegalEntryReviewer;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class RegulatoryLegalEntryResource extends Resource
{
    protected static ?string $model = RegulatoryLegalEntry::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::Scale;

    protected static ?int $navigationSort = 66;

    public static function getNavigationGroup(): ?string
    {
        return 'Product dossiers';
    }

    public static function getModelLabel(): string
    {
        return 'legal entry';
    }

    public static function getPluralModelLabel(): string
    {
        return 'EU annex legal entries';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Entry')
                ->description('Transcribe from the consolidated EUR-Lex text. CosIng is a discovery aid only. Reviewed entries are frozen.')
                ->icon(Heroicon::Scale)
                ->schema([
                    Select::make('regulatory_regime_id')
                        ->relationship(name: 'regulatoryRegime', titleAttribute: 'name')
                        ->searchable()
                        ->preload()
                        ->required(),
                    Select::make('annex')
                        ->options(collect(LegalAnnex::cases())->mapWithKeys(fn (LegalAnnex $annex): array => [$annex->value => "Annex {$annex->value}"])->all())
                        ->required(),
                    TextInput::make('entry_reference')->label('Entry (e.g. III/84)')->required()->maxLength(32),
                    TextInput::make('clause')->maxLength(32),
                    Textarea::make('substance_description')->label('Substance as named in the annex')->rows(2)->required()->columnSpanFull(),
                    Select::make('substances')
                        ->label('Linked substances')
                        ->relationship(name: 'substances', titleAttribute: 'name')
                        ->multiple()
                        ->searchable()
                        ->preload()
                        ->columnSpanFull(),
                ])
                ->columns(['md' => 2]),
            Section::make('Conditions')
                ->schema([
                    Select::make('product_scope')
                        ->options([
                            LegalEntryProductScope::AllProducts->value => 'All products',
                            LegalEntryProductScope::RinseOffOnly->value => 'Rinse-off only',
                            LegalEntryProductScope::LeaveOnOnly->value => 'Leave-on only',
                            LegalEntryProductScope::SeeConditions->value => 'See product type / body parts',
                        ])
                        ->required(),
                    TextInput::make('max_concentration_percent')->label('Max in ready-for-use product (%)')->numeric(),
                    Textarea::make('product_type_body_parts')->label('Product type, body parts (column a)')->rows(2)->columnSpanFull(),
                    Textarea::make('other_limitations')->label('Other (column c)')->rows(2)->columnSpanFull(),
                    Textarea::make('label_warnings_fr')->label('Mandatory label warnings, French (column d)')->rows(2)->columnSpanFull(),
                ])
                ->columns(['md' => 2]),
            Section::make('Dates and source')
                ->schema([
                    DatePicker::make('effective_from'),
                    DatePicker::make('effective_until'),
                    DatePicker::make('placing_on_market_until')->label('Placing on market until'),
                    DatePicker::make('making_available_until')->label('Making available until'),
                    Textarea::make('source_reference')->required()->rows(2)->columnSpanFull(),
                ])
                ->columns(['md' => 2]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['regulatoryRegime', 'substances']))
            ->columns([
                TextColumn::make('entry_reference')->label('Entry')->searchable()->sortable(),
                TextColumn::make('substance_description')->label('Substance')->limit(40)->searchable(),
                TextColumn::make('substances.name')->label('Linked')->badge(),
                TextColumn::make('max_concentration_percent')->label('Max %')->placeholder('—'),
                TextColumn::make('review_status')->badge(),
                TextColumn::make('effective_until')->date()->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('review_status')->options(['draft' => 'Draft', 'reviewed' => 'Reviewed']),
            ])
            ->recordActions([
                EditAction::make()->visible(fn (RegulatoryLegalEntry $record): bool => ! $record->isReviewed()),
                Action::make('markReviewed')
                    ->label('Mark reviewed')
                    ->requiresConfirmation()
                    ->visible(fn (RegulatoryLegalEntry $record): bool => ! $record->isReviewed())
                    ->action(function (RegulatoryLegalEntry $record): void {
                        try {
                            app(RegulatoryLegalEntryReviewer::class)->markReviewed(auth()->user(), $record);
                        } catch (ValidationException $exception) {
                            Notification::make()
                                ->title('Entry is incomplete')
                                ->body(collect($exception->errors())->flatten()->implode(' '))
                                ->danger()
                                ->send();
                        }
                    }),
                Action::make('setEffectiveUntil')
                    ->label('Set end date')
                    ->visible(fn (RegulatoryLegalEntry $record): bool => $record->isReviewed())
                    ->schema([DatePicker::make('effective_until')->required()])
                    ->action(fn (RegulatoryLegalEntry $record, array $data) => $record->update(['effective_until' => $data['effective_until']])),
            ])
            ->defaultSort('entry_reference');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRegulatoryLegalEntries::route('/'),
            'create' => CreateRegulatoryLegalEntry::route('/create'),
            'edit' => EditRegulatoryLegalEntry::route('/{record}/edit'),
        ];
    }
}
