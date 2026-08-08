<?php

namespace App\Forms\RichEditor\Plugins;

use App\Enums\MediaAssetStatus;
use App\Forms\Components\MediaAssetPicker;
use App\Models\MediaAsset;
use App\Models\Recipe;
use App\Support\RichContentAttachmentPaths;
use Filament\Actions\Action;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\RichEditor\EditorCommand;
use Filament\Forms\Components\RichEditor\Plugins\Contracts\RichContentPlugin;
use Filament\Forms\Components\RichEditor\RichEditorTool;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Tiptap\Core\Extension;

class MediaLibraryRichContentPlugin implements RichContentPlugin
{
    public static function make(): static
    {
        return app(static::class);
    }

    public static function identityFor(MediaAsset $asset): string
    {
        return RichContentAttachmentPaths::mediaAssetIdentity($asset->public_id);
    }

    /**
     * @return array<Extension>
     */
    public function getTipTapPhpExtensions(): array
    {
        return [];
    }

    /**
     * @return array<string>
     */
    public function getTipTapJsExtensions(): array
    {
        return [];
    }

    /**
     * @return array<RichEditorTool>
     */
    public function getEditorTools(): array
    {
        return [
            RichEditorTool::make('insertFromMediaLibrary')
                ->label(__('media_library.picker.insert_from_media_library'))
                ->action()
                ->icon(Heroicon::Photo),
        ];
    }

    /**
     * @return array<Action>
     */
    public function getEditorActions(): array
    {
        return [
            Action::make('insertFromMediaLibrary')
                ->label(__('media_library.picker.insert_from_media_library'))
                ->modalHeading(__('media_library.picker.insert_from_media_library'))
                ->modalWidth(Width::FiveExtraLarge)
                ->schema([
                    MediaAssetPicker::make('media_asset_id')
                        ->hiddenLabel()
                        ->embedded()
                        ->required(),
                ])
                ->action(function (array $arguments, array $data, RichEditor $component): void {
                    $recipe = $component->getRecord();

                    if (! $recipe instanceof Recipe) {
                        throw ValidationException::withMessages([
                            'media_asset_id' => __('workbench.instructions.draft_text_help'),
                        ]);
                    }

                    Gate::authorize('update', $recipe);

                    $asset = MediaAsset::query()
                        ->where('workspace_id', $recipe->workspace_id)
                        ->where('status', MediaAssetStatus::Ready)
                        ->find($data['media_asset_id'] ?? null);

                    if (! $asset instanceof MediaAsset) {
                        throw ValidationException::withMessages([
                            'media_asset_id' => __('media_library.validation.picker_ready_workspace'),
                        ]);
                    }

                    $component->runCommands(
                        [
                            EditorCommand::make('insertContent', arguments: [[
                                'type' => 'image',
                                'attrs' => [
                                    'alt' => null,
                                    'id' => MediaLibraryRichContentPlugin::identityFor($asset),
                                    'src' => route('media.show', [$asset, 'master']),
                                ],
                            ]]),
                        ],
                        editorSelection: $arguments['editorSelection'] ?? null,
                    );
                }),
        ];
    }
}
