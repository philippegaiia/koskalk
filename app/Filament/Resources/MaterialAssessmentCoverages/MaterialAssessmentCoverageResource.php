<?php

namespace App\Filament\Resources\MaterialAssessmentCoverages;

use App\Enums\AssessmentReviewState;
use App\Filament\Resources\MaterialAssessmentCoverages\Pages\CreateMaterialAssessmentCoverage;
use App\Filament\Resources\MaterialAssessmentCoverages\Pages\EditMaterialAssessmentCoverage;
use App\Filament\Resources\MaterialAssessmentCoverages\Pages\ListMaterialAssessmentCoverages;
use App\Models\Ingredient;
use App\Models\MaterialAssessmentCoverage;
use App\Models\Substance;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\MorphToSelect;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class MaterialAssessmentCoverageResource extends Resource
{
    protected static ?string $model = MaterialAssessmentCoverage::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::ShieldCheck;

    protected static ?int $navigationSort = 65;

    public static function getNavigationGroup(): ?string
    {
        return 'Product dossiers';
    }

    public static function getModelLabel(): string
    {
        return 'assessment coverage';
    }

    public static function getPluralModelLabel(): string
    {
        return 'assessment coverage';
    }

    /**
     * Platform coverage only: private-ingredient coverage belongs to its workspace.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereNull('workspace_id');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Material')
                ->description('A platform ingredient, or a final material such as a soap salt or glycerin.')
                ->schema([
                    MorphToSelect::make('coverable')
                        ->label('Material')
                        ->types([
                            MorphToSelect\Type::make(Ingredient::class)
                                ->titleAttribute('display_name')
                                ->modifyOptionsQueryUsing(fn (Builder $query): Builder => $query->whereNull('owner_type')->whereNull('workspace_id')),
                            MorphToSelect\Type::make(Substance::class)->titleAttribute('name'),
                        ])
                        ->searchable()
                        ->required()
                        ->disabledOn('edit'),
                ]),
            Section::make('Review')
                ->schema([
                    Select::make('review_state')
                        ->options(collect(AssessmentReviewState::cases())->mapWithKeys(fn (AssessmentReviewState $state): array => [$state->value => str($state->value)->replace('_', ' ')->ucfirst()->toString()])->all())
                        ->required()
                        ->live(),
                    Textarea::make('insufficient_reason')
                        ->label('Named uncertainty')
                        ->rows(2)
                        ->visible(fn (Get $get): bool => $get('review_state') === AssessmentReviewState::Insufficient->value),
                    Toggle::make('covers_whole_material')->label('Covers the whole material'),
                    Toggle::make('covers_constituents')->label('Covers its tracked constituents'),
                    Select::make('supporting_substance_ids')
                        ->label('Profile references')
                        ->helperText('Assessed materials whose current toxicological profile supports this coverage.')
                        ->multiple()
                        ->searchable()
                        ->options(fn (): array => Substance::query()->orderBy('name')->pluck('name', 'id')->all()),
                    Textarea::make('note')->rows(2)->columnSpanFull(),
                ])
                ->columns(['md' => 2]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['coverable', 'reviewedBy']))
            ->columns([
                TextColumn::make('coverable_type')->label('Kind')->formatStateUsing(fn (string $state): string => class_basename($state))->badge(),
                TextColumn::make('material')
                    ->label('Material')
                    ->state(fn (MaterialAssessmentCoverage $record): string => (string) ($record->coverable?->display_name ?? $record->coverable?->name ?? '—')),
                TextColumn::make('review_state')->badge(),
                IconColumn::make('covers_whole_material')->label('Whole')->boolean(),
                IconColumn::make('covers_constituents')->label('Constituents')->boolean(),
                TextColumn::make('reviewedBy.email')->label('Reviewed by')->placeholder('—'),
                TextColumn::make('reviewed_at')->dateTime()->placeholder('—'),
            ])
            ->recordActions([EditAction::make()])
            ->defaultSort('reviewed_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMaterialAssessmentCoverages::route('/'),
            'create' => CreateMaterialAssessmentCoverage::route('/create'),
            'edit' => EditMaterialAssessmentCoverage::route('/{record}/edit'),
        ];
    }
}
