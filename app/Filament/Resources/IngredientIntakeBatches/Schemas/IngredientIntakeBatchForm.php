<?php

namespace App\Filament\Resources\IngredientIntakeBatches\Schemas;

use App\Enums\IngredientIntakeInputMethod;
use App\Enums\IngredientResearchFamily;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class IngredientIntakeBatchForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('ingredient_intake_admin.form.batch_section'))
                    ->description(__('ingredient_intake_admin.form.batch_description'))
                    ->schema([
                        TextInput::make('name')
                            ->label(__('ingredient_intake_admin.form.name'))
                            ->required()
                            ->maxLength(255),
                        Select::make('family_hint')
                            ->label(__('ingredient_intake_admin.form.family_hint'))
                            ->options(collect(IngredientResearchFamily::cases())->mapWithKeys(
                                fn (IngredientResearchFamily $family): array => [$family->value => $family->label()],
                            )->all())
                            ->placeholder(__('ingredient_intake_admin.form.no_family_hint'))
                            ->native(false),
                        Textarea::make('notes')
                            ->label(__('ingredient_intake_admin.form.notes'))
                            ->rows(3)
                            ->columnSpanFull(),
                        Toggle::make('allow_gap_research')
                            ->label(__('ingredient_intake_admin.form.allow_gap_research'))
                            ->helperText(__('ingredient_intake_admin.form.allow_gap_research_help')),
                    ])
                    ->columns([
                        'default' => 1,
                        'md' => 2,
                    ])
                    ->columnSpanFull(),
                Section::make(__('ingredient_intake_admin.form.input_section'))
                    ->description(__('ingredient_intake_admin.form.input_description'))
                    ->schema([
                        Select::make('input_method')
                            ->label(__('ingredient_intake_admin.form.input_method'))
                            ->options(collect(IngredientIntakeInputMethod::cases())->mapWithKeys(
                                fn (IngredientIntakeInputMethod $method): array => [$method->value => $method->label()],
                            )->all())
                            ->default(IngredientIntakeInputMethod::Paste->value)
                            ->native(false)
                            ->live()
                            ->required(),
                        Textarea::make('pasted_input')
                            ->label(__('ingredient_intake_admin.form.pasted_input'))
                            ->helperText(__('ingredient_intake_admin.form.pasted_input_help'))
                            ->rows(12)
                            ->visible(fn (Get $get): bool => $get('input_method') === IngredientIntakeInputMethod::Paste->value)
                            ->required(fn (Get $get): bool => $get('input_method') === IngredientIntakeInputMethod::Paste->value)
                            ->columnSpanFull(),
                        FileUpload::make('upload')
                            ->label(__('ingredient_intake_admin.form.upload'))
                            ->helperText(__('ingredient_intake_admin.form.upload_help'))
                            ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel'])
                            ->maxSize(10 * 1024)
                            ->storeFiles(false)
                            ->visibility('private')
                            ->visible(fn (Get $get): bool => $get('input_method') === IngredientIntakeInputMethod::Csv->value)
                            ->required(fn (Get $get): bool => $get('input_method') === IngredientIntakeInputMethod::Csv->value)
                            ->columnSpanFull(),
                    ])
                    ->columns([
                        'default' => 1,
                        'md' => 2,
                    ])
                    ->columnSpanFull(),
            ]);
    }
}
