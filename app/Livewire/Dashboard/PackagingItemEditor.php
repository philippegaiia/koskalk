<?php

namespace App\Livewire\Dashboard;

use App\Forms\Components\MediaAssetPicker;
use App\Livewire\Concerns\InteractsWithAppNotifications;
use App\Livewire\Concerns\InteractsWithMediaAssetPickerUploads;
use App\MediaAssetUsageRole;
use App\Models\User;
use App\Models\UserPackagingItem;
use App\Services\CurrentAppUserResolver;
use App\Services\MediaAssetUsageService;
use App\Services\UserPackagingItemAuthoringService;
use App\Support\LocalizedDecimalInput;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Concerns\RestrictsFileUploadsToSchemaComponents;
use Filament\Schemas\Schema;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

class PackagingItemEditor extends Component implements HasActions, HasForms
{
    use InteractsWithActions;
    use InteractsWithAppNotifications;
    use InteractsWithForms;
    use InteractsWithMediaAssetPickerUploads;
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

    public function mount(
        ?UserPackagingItem $packagingItem,
        UserPackagingItemAuthoringService $authoringService,
        MediaAssetUsageService $mediaAssetUsages,
    ): void {
        $this->packagingItemId = $packagingItem?->id;
        $this->mediaPublicId = (string) ($packagingItem?->public_id ?? Str::uuid());

        $state = $packagingItem instanceof UserPackagingItem
            ? $authoringService->formData($packagingItem)
            : $authoringService->blankState();
        $state['featured_media_asset_id'] = $packagingItem instanceof UserPackagingItem
            ? ($mediaAssetUsages->idsFor($packagingItem, MediaAssetUsageRole::PackagingMain)[0] ?? null)
            : null;

        $this->form->fill($state);
    }

    public function save(
        UserPackagingItemAuthoringService $authoringService,
        MediaAssetUsageService $mediaAssetUsages,
    ) {
        $user = $this->currentUser();
        $wasEditing = $this->isEditing();

        if (! $user instanceof User) {
            $this->showAppNotification(
                __('packaging.editor.status.auth_required'),
                'error',
            );

            return null;
        }

        /** @var array<string, mixed> $state */
        $state = $this->form->getState();
        $featuredMediaAssetId = $state['featured_media_asset_id'] ?? null;
        unset($state['featured_media_asset_id']);
        $state['public_id'] = $this->mediaPublicId;
        $currentPackagingItem = $this->currentPackagingItem();

        try {
            $packagingItem = DB::transaction(function () use ($authoringService, $currentPackagingItem, $featuredMediaAssetId, $mediaAssetUsages, $state, $user): UserPackagingItem {
                $packagingItem = $currentPackagingItem instanceof UserPackagingItem
                    ? $authoringService->update($currentPackagingItem, $state, $user)
                    : $authoringService->create($state, $user);

                $mediaAssetUsages->syncSingle(
                    $user,
                    $packagingItem,
                    MediaAssetUsageRole::PackagingMain,
                    $featuredMediaAssetId,
                );

                return $packagingItem;
            });
        } catch (ValidationException $exception) {
            throw $exception;
        }

        $this->packagingItemId = $packagingItem->id;
        $statusMessage = $wasEditing
            ? __('packaging.editor.status.saved')
            : __('packaging.editor.status.created');
        $this->showAppNotification($statusMessage);

        $refreshedState = $authoringService->formData($packagingItem);
        $refreshedState['featured_media_asset_id'] = $featuredMediaAssetId;
        $this->form->fill($refreshedState);

        if (! $wasEditing) {
            session()->flash('status', $statusMessage);

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
                        MediaAssetPicker::make('featured_media_asset_id')
                            ->label(__('packaging.editor.form.image.label'))
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
