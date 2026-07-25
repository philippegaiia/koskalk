<?php

namespace App\Services;

use App\Forms\Components\MediaAssetPicker;
use App\Forms\RichEditor\Plugins\MediaLibraryRichContentPlugin;
use App\Models\Recipe;
use Filament\Forms\Components\RichEditor;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class RecipeWorkbenchContentFormSchema
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('workbench.instructions.presentation_title'))
                    ->schema([
                        Grid::make([
                            'default' => 1,
                            'lg' => 12,
                        ])
                            ->schema([
                                RichEditor::make('description')
                                    ->label(__('workbench.instructions.description_label'))
                                    ->helperText(__('workbench.instructions.description_help'))
                                    ->toolbarButtons(fn (?Recipe $record): array => [
                                        ['bold', 'italic', 'underline', 'strike', 'link'],
                                        ['h2', 'h3', 'blockquote', 'bulletList', 'orderedList'],
                                        ['undo', 'redo'],
                                    ])
                                    ->extraInputAttributes([
                                        'class' => 'min-h-[12rem] [&_.fi-fo-rich-editor-content]:min-h-[10rem]',
                                    ])
                                    ->columnSpan([
                                        'lg' => 8,
                                    ]),
                                MediaAssetPicker::make('featured_media_asset_id')
                                    ->label(__('workbench.instructions.featured_label'))
                                    ->helperText(__('workbench.instructions.featured_help'))
                                    ->disabled(fn (?Recipe $record): bool => ! $record instanceof Recipe)
                                    ->columnSpan([
                                        'lg' => 4,
                                    ]),
                            ]),
                    ]),
                Section::make(__('workbench.instructions.procedure_label'))
                    ->schema([
                        Grid::make([
                            'default' => 1,
                            'lg' => 12,
                        ])
                            ->schema([
                                RichEditor::make('manufacturing_instructions')
                                    ->label(__('workbench.instructions.procedure_label'))
                                    ->hiddenLabel()
                                    ->helperText(__('workbench.instructions.procedure_help'))
                                    ->toolbarButtons(fn (?Recipe $record): array => [
                                        ['bold', 'italic', 'underline', 'strike', 'link'],
                                        ['h2', 'h3', 'blockquote', 'bulletList', 'orderedList'],
                                        ['insertFromMediaLibrary'],
                                        ['undo', 'redo'],
                                    ])
                                    ->plugins([
                                        MediaLibraryRichContentPlugin::make(),
                                    ])
                                    ->resizableImages()
                                    ->extraInputAttributes([
                                        'class' => 'min-h-[22rem] [&_.fi-fo-rich-editor-content]:mx-auto [&_.fi-fo-rich-editor-content]:min-h-[20rem] [&_.fi-fo-rich-editor-content]:max-w-[680px] [&_.fi-fo-rich-editor-content_img]:!h-auto',
                                    ])
                                    ->columnSpan([
                                        'lg' => 12,
                                    ]),
                            ]),
                    ]),
            ]);
    }
}
