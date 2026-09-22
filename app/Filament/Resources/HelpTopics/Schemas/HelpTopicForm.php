<?php

namespace App\Filament\Resources\HelpTopics\Schemas;

use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;

class HelpTopicForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            View::make('filament.help-topics.context')->viewData(fn ($livewire): array => $livewire->editorReference())->columnSpanFull(),
            View::make('filament.help-topics.translations')->visible(fn ($livewire): bool => $livewire->editingLocale !== 'en')->columnSpanFull(),
            Grid::make()->columns(fn ($livewire): int => $livewire->editingLocale === 'en' ? 1 : 2)->schema([
                Section::make('Published English')->visible(fn ($livewire): bool => $livewire->editingLocale !== 'en')
                    ->schema([View::make('filament.help-topics.preview')->viewData(fn ($livewire): array => ['content' => $livewire->editorReference()['english']])])->columnSpan(1),
                Section::make('Draft')->schema([
                    TextInput::make('title')->required()->maxLength(160)->columnSpanFull(),
                    Textarea::make('summary')->required()->maxLength(600)->rows(3)
                        ->helperText('A short answer shown first in the help panel.')->columnSpanFull(),
                    MarkdownEditor::make('body_markdown')->label('Expanded explanation')
                        ->toolbarButtons([['bold', 'italic', 'link'], ['heading', 'bulletList', 'orderedList'], ['undo', 'redo']])
                        ->maxLength(12000)->helperText('Optional. Use headings 2 or 3, paragraphs, lists, emphasis, and links. Preview before publishing.')
                        ->columnSpanFull(),
                ])->columnSpan(1),
            ])->columnSpanFull(),
        ]);
    }
}
