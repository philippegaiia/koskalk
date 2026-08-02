<?php

namespace App\Livewire\Dashboard;

use App\Forms\Components\MediaAssetPicker;
use App\Livewire\Concerns\InteractsWithAppNotifications;
use App\Livewire\Concerns\InteractsWithMediaAssetPickerUploads;
use App\MediaAssetUsageRole;
use App\Models\PackagingItem;
use App\Models\Supplier;
use App\Models\User;
use App\PackagingCategory;
use App\Services\CurrentAppUserResolver;
use App\Services\MediaAssetUsageService;
use App\Services\PackagingItemAuthoringService;
use App\Support\LocalizedDecimalInput;
use App\Support\NumberLocale;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\Select;
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

    #[Locked]
    public ?string $returnTo = null;

    #[Locked]
    public ?string $returnSupplierPublicId = null;

    /**
     * @var array<string, mixed>
     */
    public array $data = [];

    public ?string $statusMessage = null;

    public string $statusType = 'idle';

    public function mount(
        ?PackagingItem $packagingItem,
        PackagingItemAuthoringService $authoringService,
        MediaAssetUsageService $mediaAssetUsages,
    ): void {
        if ($packagingItem?->exists !== true) {
            $packagingItem = null;
        }

        $this->packagingItemId = $packagingItem?->id;
        $this->mediaPublicId = (string) ($packagingItem?->public_id ?? Str::uuid());

        if ($packagingItem === null && request()->query('return_to') === 'supplier_listing') {
            $this->returnTo = 'supplier_listing';
            $this->returnSupplierPublicId = $this->validReturnSupplierPublicId(request()->query('supplier'));
        }

        $state = $packagingItem instanceof PackagingItem
            ? $authoringService->formData($packagingItem)
            : $authoringService->blankState();
        $state['unit_cost'] = $this->formattedUnitCost($packagingItem?->unit_cost);
        $state['featured_media_asset_id'] = $packagingItem instanceof PackagingItem
            ? ($mediaAssetUsages->idsFor($packagingItem, MediaAssetUsageRole::PackagingMain)[0] ?? null)
            : null;

        $this->form->fill($state);
    }

    public function save(
        PackagingItemAuthoringService $authoringService,
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
            $packagingItem = DB::transaction(function () use ($authoringService, $currentPackagingItem, $featuredMediaAssetId, $mediaAssetUsages, $state, $user): PackagingItem {
                $packagingItem = $currentPackagingItem instanceof PackagingItem
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
        $refreshedState['unit_cost'] = $this->formattedUnitCost($packagingItem->unit_cost);
        $refreshedState['featured_media_asset_id'] = $featuredMediaAssetId;
        $this->form->fill($refreshedState);

        if (! $wasEditing) {
            session()->flash('status', $statusMessage);

            if ($this->returnTo === 'supplier_listing') {
                return redirect()->route('production-bench.purchasing.listings.create', array_filter([
                    'material_type' => 'packaging',
                    'packaging_item' => $packagingItem->public_id,
                    'supplier' => $this->returnSupplierPublicId,
                ]));
            }

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
                        Select::make('category')
                            ->label(__('packaging.editor.form.category'))
                            ->options(PackagingCategory::class)
                            ->required(),
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
            ->model($this->currentPackagingItem() ?? PackagingItem::class);
    }

    public function render(): View
    {
        return view('livewire.dashboard.packaging-item-editor', [
            'packagingItem' => $this->currentPackagingItem(),
        ]);
    }

    private function currentPackagingItem(): ?PackagingItem
    {
        $user = $this->currentUser();

        if (! $user instanceof User || $this->packagingItemId === null) {
            return null;
        }

        return PackagingItem::query()
            ->where('workspace_id', $user->company()?->id)
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

    private function formattedUnitCost(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return NumberLocale::formatAdaptiveDecimal(
            $value,
            minimumDecimals: 2,
            maximumDecimals: 4,
            locale: $this->currentUser()?->number_locale,
        );
    }

    private function validReturnSupplierPublicId(mixed $supplierPublicId): ?string
    {
        $workspaceId = $this->currentUser()?->company()?->id;

        if (! is_string($supplierPublicId) || $workspaceId === null) {
            return null;
        }

        return Supplier::query()
            ->where('workspace_id', $workspaceId)
            ->where('public_id', $supplierPublicId)
            ->value('public_id');
    }
}
