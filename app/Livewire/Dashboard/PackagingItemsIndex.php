<?php

namespace App\Livewire\Dashboard;

use App\Models\User;
use App\Models\UserPackagingItem;
use App\Services\CurrentAppUserResolver;
use App\Services\MediaStorage;
use App\Services\UserPackagingItemAuthoringService;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Table;
use Filament\Tables\TableComponent;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Locked;
use Livewire\WithoutUrlPagination;

class PackagingItemsIndex extends TableComponent
{
    use WithoutUrlPagination;

    #[Locked]
    public ?int $currentUserId = null;

    #[Locked]
    public ?string $currentCurrency = null;

    public function mount(CurrentAppUserResolver $resolver): void
    {
        $user = $resolver->resolve();

        $this->currentUserId = $user?->id;
        $this->currentCurrency = $user?->defaultCurrency();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->tableQuery())
            ->heading('Packaging catalog')
            ->description('Saved boxes, jars, labels, inserts, and other packaging costs available to recipe costing.')
            ->columns([
                ImageColumn::make('featured_image_path')
                    ->label('Picture')
                    ->disk(MediaStorage::publicDisk())
                    ->visibility(MediaStorage::publicVisibility())
                    ->square()
                    ->imageSize(52)
                    ->checkFileExistence(false),
                TextColumn::make('name')
                    ->label('Name')
                    ->searchable()
                    ->sortable()
                    ->weight('600')
                    ->width('34rem')
                    ->extraHeaderAttributes(['style' => 'min-width: 30rem;'])
                    ->extraCellAttributes(['style' => 'min-width: 30rem;']),
                TextInputColumn::make('unit_cost')
                    ->label(fn (): string => $this->priceColumnLabel('Unit price'))
                    ->sortable()
                    ->width('12rem')
                    ->grow(false)
                    ->extraAttributes(['class' => 'max-w-48'])
                    ->extraCellAttributes(['class' => 'w-48'])
                    ->state(fn (UserPackagingItem $record): string => self::formatRequiredPriceInput($record->unit_cost))
                    ->type('number')
                    ->inputMode('decimal')
                    ->step('0.01')
                    ->rules(['required', 'numeric', 'min:0'])
                    ->disabled(fn (UserPackagingItem $record): bool => $record->user_id !== $this->currentUserId)
                    ->updateStateUsing(fn (UserPackagingItem $record, mixed $state): string => $this->updatePackagingItemUnitCost($record, $state)),
                TextColumn::make('notes')
                    ->label('Notes')
                    ->searchable()
                    ->wrap(),
            ])
            ->columnManager(false)
            ->striped()
            ->headerActions([
                Action::make('create')
                    ->label('Add packaging item')
                    ->icon(Heroicon::Plus)
                    ->url(route('packaging-items.create'))
                    ->visible(fn (): bool => $this->currentUser() instanceof User),
            ])
            ->recordActions([
                Action::make('edit')
                    ->label('Edit')
                    ->icon(Heroicon::PencilSquare)
                    ->iconButton()
                    ->tooltip('Edit packaging item')
                    ->url(fn (UserPackagingItem $record): string => route('packaging-items.edit', $record->id)),
                Action::make('delete')
                    ->label('Delete')
                    ->icon(Heroicon::Trash)
                    ->color('danger')
                    ->iconButton()
                    ->tooltip(fn (UserPackagingItem $record): string => (int) ($record->costing_items_count ?? 0) > 0
                        ? 'Used in costing, so it cannot be deleted.'
                        : 'Delete packaging item')
                    ->requiresConfirmation()
                    ->modalHeading('Delete packaging item')
                    ->modalDescription('This removes the packaging item from your private catalog.')
                    ->disabled(fn (UserPackagingItem $record): bool => (int) ($record->costing_items_count ?? 0) > 0)
                    ->tooltip(fn (UserPackagingItem $record): ?string => (int) ($record->costing_items_count ?? 0) > 0
                        ? 'This packaging item is already used in saved costing rows.'
                        : null)
                    ->action(fn (UserPackagingItem $record): bool => $this->deletePackagingItem($record)),
            ])
            ->recordActionsColumnLabel('Actions')
            ->recordActionsAlignment('end')
            ->defaultSort('name')
            ->paginated([25, 50, 100])
            ->emptyStateHeading('No packaging items yet')
            ->emptyStateDescription('Create reusable boxes, labels, jars, and inserts once, then pull them into recipe costing when needed.');
    }

    public function render(): View
    {
        return view('livewire.dashboard.packaging-items-index', [
            'currentUser' => $this->currentUser(),
        ]);
    }

    private function tableQuery(): Builder
    {
        $query = UserPackagingItem::query()
            ->select(['id', 'user_id', 'name', 'unit_cost', 'notes', 'featured_image_path', 'created_at', 'updated_at'])
            ->withCount('costingItems');
        $user = $this->currentUser();

        if (! $user instanceof User) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('user_id', $user->id);
    }

    private function deletePackagingItem(UserPackagingItem $packagingItem): bool
    {
        $user = $this->currentUser();

        if (! $user instanceof User) {
            return false;
        }

        return app(UserPackagingItemAuthoringService::class)->delete($packagingItem, $user);
    }

    private function updatePackagingItemUnitCost(UserPackagingItem $packagingItem, mixed $state): string
    {
        $user = $this->currentUser();

        if (! $user instanceof User || $packagingItem->user_id !== $user->id) {
            return self::formatRequiredPriceInput($packagingItem->unit_cost);
        }

        $packagingItem = app(UserPackagingItemAuthoringService::class)->updateUnitCost($packagingItem, $user, $state);

        return self::formatRequiredPriceInput($packagingItem->unit_cost);
    }

    private function currentUser(): ?User
    {
        return app(CurrentAppUserResolver::class)->resolve($this->currentUserId);
    }

    private function priceColumnLabel(string $label): string
    {
        return sprintf('%s (%s)', $label, $this->currentCurrency ?? config('currencies.default', 'EUR'));
    }

    private static function formatRequiredPriceInput(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
