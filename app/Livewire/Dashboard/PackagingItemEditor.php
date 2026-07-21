<?php

namespace App\Livewire\Dashboard;

use App\Models\User;
use App\Models\UserPackagingItem;
use App\Services\CurrentAppUserResolver;
use App\Services\MediaStorage;
use App\Services\UserPackagingItemAuthoringService;
use App\Support\LocalizedDecimalInput;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\BaseFileUpload;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Concerns\RestrictsFileUploadsToSchemaComponents;
use Filament\Schemas\Schema;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class PackagingItemEditor extends Component implements HasActions, HasForms
{
    use InteractsWithActions;
    use InteractsWithForms;
    use RestrictsFileUploadsToSchemaComponents;

    #[Locked]
    public ?int $packagingItemId = null;

    #[Locked]
    public string $mediaPublicId;

    /**
     * @var array<string, mixed>
     */
    public array $data = [];

    public ?string $statusMessage = null;

    public string $statusType = 'idle';

    public function mount(?UserPackagingItem $packagingItem, UserPackagingItemAuthoringService $authoringService): void
    {
        $this->packagingItemId = $packagingItem?->id;
        $this->mediaPublicId = (string) ($packagingItem?->public_id ?? Str::uuid());

        $this->form->fill(
            $packagingItem instanceof UserPackagingItem
                ? $authoringService->formData($packagingItem)
                : $authoringService->blankState(),
        );
    }

    public function save(UserPackagingItemAuthoringService $authoringService)
    {
        $user = $this->currentUser();
        $wasEditing = $this->isEditing();

        if (! $user instanceof User) {
            $this->statusType = 'error';
            $this->statusMessage = __('packaging.editor.status.auth_required');

            return null;
        }

        /** @var array<string, mixed> $state */
        $state = $this->form->getState();
        $state['public_id'] = $this->mediaPublicId;
        $currentPackagingItem = $this->currentPackagingItem();

        try {
            $packagingItem = $currentPackagingItem instanceof UserPackagingItem
                ? $authoringService->update($currentPackagingItem, $state, $user)
                : $authoringService->create($state, $user);
        } catch (ValidationException $exception) {
            throw $exception;
        }

        $this->packagingItemId = $packagingItem->id;
        $this->statusType = 'success';
        $this->statusMessage = $wasEditing
            ? __('packaging.editor.status.saved')
            : __('packaging.editor.status.created');

        $this->form->fill($authoringService->formData($packagingItem));

        if (! $wasEditing) {
            return redirect()->route('packaging-items.edit', $packagingItem);
        }

        return null;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('packaging.editor.form.section'))
                    ->description(__('packaging.editor.form.description'))
                    ->columns([
                        'md' => 2,
                    ])
                    ->schema([
                        TextInput::make('name')
                            ->label(__('packaging.editor.form.name.label'))
                            ->placeholder(__('packaging.editor.form.name.placeholder'))
                            ->required()
                            ->maxLength(255),
                        LocalizedDecimalInput::make('unit_cost')
                            ->label(fn (): string => __('packaging.editor.form.unit_price', [
                                'currency' => $this->currentUser()?->defaultCurrency() ?? 'EUR',
                            ]))
                            ->minValue(0)
                            ->required(),
                        FileUpload::make('featured_image_path')
                            ->label(__('packaging.editor.form.image.label'))
                            ->image()
                            ->maxSize(MediaStorage::ingredientImagesMaxSize())
                            ->acceptedFileTypes([
                                'image/jpeg',
                                'image/webp',
                            ])
                            ->disk(MediaStorage::userDisk())
                            ->directory(fn (): string => MediaStorage::packagingItemDirectoryForPublicId($this->mediaPublicId, 'featured-images'))
                            ->visibility(MediaStorage::userVisibility())
                            ->getUploadedFileUsing(function (BaseFileUpload $component, string $file, string|array|null $storedFileNames): ?array {
                                $packagingItem = $this->currentPackagingItem();
                                $url = $packagingItem instanceof UserPackagingItem
                                    ? MediaStorage::packagingItemUrl($packagingItem, $file)
                                    : null;

                                if ($url === null) {
                                    return null;
                                }

                                $metadata = $component->getUploadedFile($file, $storedFileNames);

                                if ($metadata === null) {
                                    return null;
                                }

                                $metadata['url'] = $url;

                                return $metadata;
                            })
                            ->deleteUploadedFileUsing(function (string $file): void {
                                MediaStorage::deleteUserPath($file);
                            })
                            ->saveUploadedFileUsing(fn (BaseFileUpload $component, TemporaryUploadedFile $file): string => MediaStorage::storeUserFittedWebp(
                                $file,
                                (string) $component->getDirectory(),
                                MediaStorage::ingredientImageWidth(),
                                MediaStorage::ingredientImageHeight(),
                                MediaStorage::ingredientImagesQuality(),
                            ))
                            ->imageEditor()
                            ->imageAspectRatio('1:1')
                            ->imageEditorAspectRatioOptions(['1:1'])
                            ->automaticallyOpenImageEditorForAspectRatio()
                            ->helperText(__('packaging.editor.form.image.helper'))
                            ->columnSpan(1),
                        Textarea::make('notes')
                            ->label(__('packaging.editor.form.notes.label'))
                            ->helperText(__('packaging.editor.form.notes.helper'))
                            ->rows(4)
                            ->columnSpan(1),
                    ]),
            ])
            ->statePath('data')
            ->model($this->currentPackagingItem() ?? UserPackagingItem::class);
    }

    public function render(): View
    {
        return view('livewire.dashboard.packaging-item-editor', [
            'packagingItem' => $this->currentPackagingItem(),
        ]);
    }

    private function currentPackagingItem(): ?UserPackagingItem
    {
        $user = $this->currentUser();

        if (! $user instanceof User || $this->packagingItemId === null) {
            return null;
        }

        return UserPackagingItem::query()
            ->where('user_id', $user->id)
            ->find($this->packagingItemId);
    }

    private function currentUser(): ?User
    {
        return app(CurrentAppUserResolver::class)->resolve();
    }

    private function isEditing(): bool
    {
        return $this->packagingItemId !== null;
    }
}
