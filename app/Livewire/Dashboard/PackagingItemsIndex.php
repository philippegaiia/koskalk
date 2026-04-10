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

    public function mount(CurrentAppUserResolver $resolver): void
    {
        $this->currentUserId = $resolver->resolve()?->id;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->tableQuery())
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
                    ->weight('600'),
                TextColumn::make('unit_cost')
                    ->label('Unit price')
                    ->sortable()
                    ->formatStateUsing(fn (string $state, UserPackagingItem $record): string => sprintf(
                        '%s %s',
                        $record->currency,
                        number_format((float) $state, 4, '.', ''),
                    )),
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
        $query = UserPackagingItem::query()->withCount('costingItems');
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

    private function currentUser(): ?User
    {
        return app(CurrentAppUserResolver::class)->resolve($this->currentUserId);
    }
}
