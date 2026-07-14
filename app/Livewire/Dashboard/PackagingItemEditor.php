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
            $this->statusMessage = 'You need to be signed in before packaging items can be saved.';

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
            ? 'Packaging item saved.'
            : 'Packaging item created. You can keep using it in recipe costing.';

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
                Section::make('Packaging item')
                    ->description('Keep the reusable packaging identity, square image, unit price, and notes together here.')
                    ->columns([
                        'md' => 2,
                    ])
                    ->schema([
                        TextInput::make('name')
                            ->label('Name')
                            ->required()
                            ->maxLength(255),
                        LocalizedDecimalInput::make('unit_cost')
                            ->label(fn (): string => $this->priceFieldLabel('Effective unit price'))
                            ->minValue(0)
                            ->required(),
                        FileUpload::make('featured_image_path')
                            ->label('Packaging image')
                            ->image()
                            ->maxSize(MediaStorage::ingredientImagesMaxSize())
                            ->acceptedFileTypes([
                                'image/jpeg',
                                'image/webp',
                            ])
                            ->disk(MediaStorage::userDisk())
                            ->directory(fn (): string => MediaStorage::packagingItemDirectoryForPublicId($this->mediaPublicId, 'featured-images'))
                            ->visibility(MediaStorage::userVisibility())
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
                            ->helperText('Optional square image for packaging selectors and catalog rows.')
                            ->columnSpan(1),
                        Textarea::make('notes')
                            ->label('Notes')
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

    private function priceFieldLabel(string $label): string
    {
        return sprintf('%s (%s)', $label, $this->currentUser()?->defaultCurrency() ?? 'EUR');
    }
}
