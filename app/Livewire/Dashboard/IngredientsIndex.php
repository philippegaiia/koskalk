<?php

namespace App\Livewire\Dashboard;

use App\Models\Ingredient;
use App\Models\User;
use App\OwnerType;
use App\Services\CurrentAppUserResolver;
use App\Services\MediaStorage;
use App\Services\UserIngredientPriceMemory;
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

class IngredientsIndex extends TableComponent
{
    use WithoutUrlPagination;

    #[Locked]
    public ?int $currentUserId = null;

    #[Locked]
    public ?string $currentCurrency = null;

    public string $ownershipFilter = 'all';

    public function mount(CurrentAppUserResolver $resolver): void
    {
        $user = $resolver->resolve();

        $this->currentUserId = $user?->id;
        $this->currentCurrency = $user?->defaultCurrency();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->tableQuery())
            ->heading('Ingredient catalog')
            ->description('Price platform ingredients for costing, or create private ingredients that only you can edit.')
            ->columns([
                ImageColumn::make('catalog_image')
                    ->label('Picture')
                    ->state(fn (Ingredient $record): ?string => $record->icon_image_path ?: $record->featured_image_path)
                    ->disk(MediaStorage::publicDisk())
                    ->visibility(MediaStorage::publicVisibility())
                    ->square()
                    ->imageSize(52)
                    ->checkFileExistence(false),
                TextColumn::make('display_name')
                    ->label('Name')
                    ->searchable()
                    ->sortable()
                    ->weight('600'),
                TextColumn::make('inci_name')
                    ->label('INCI')
                    ->searchable()
                    ->wrap(),
                TextColumn::make('category')
                    ->label('Category')
                    ->badge()
                    ->sortable(),
                TextColumn::make('catalog_source')
                    ->label('Source')
                    ->badge()
                    ->state(fn (Ingredient $record): string => $record->owner_type === OwnerType::User ? 'Mine' : 'Platform')
                    ->color(fn (string $state): string => $state === 'Mine' ? 'warning' : 'gray'),
                TextInputColumn::make('user_price_per_kg')
                    ->label(fn (): string => $this->priceColumnLabel('Price/kg'))
                    ->state(fn (Ingredient $record): ?string => self::formatPriceInput($record->user_price_per_kg))
                    ->type('number')
                    ->inputMode('decimal')
                    ->step('0.01')
                    ->rules(['nullable', 'numeric', 'min:0'])
                    ->updateStateUsing(function (Ingredient $record, $state) {
                        $user = $this->currentUser();

                        if (! $user instanceof User) {
                            return self::formatPriceInput($record->user_price_per_kg);
                        }

                        app(UserIngredientPriceMemory::class)->remember($user, $record->id, (float) ($state ?? 0));
                        $record->load(['userPrices' => fn ($q) => $q->where('user_id', $user->id)]);

                        return self::formatPriceInput($state);
                    }),
            ])
            ->striped()
            ->headerActions([
                Action::make('create')
                    ->label('Add ingredient')
                    ->icon(Heroicon::Plus)
                    ->url(route('ingredients.create'))
                    ->visible(fn (): bool => $this->currentUser() instanceof User),
            ])
            ->recordActions([
                Action::make('edit')
                    ->label('Edit')
                    ->icon(Heroicon::PencilSquare)
                    ->iconButton()
                    ->tooltip('Edit ingredient')
                    ->url(fn (Ingredient $record): string => route('ingredients.edit', $record))
                    ->visible(fn (Ingredient $record): bool => $record->owner_type === OwnerType::User),
                Action::make('delete')
                    ->label('Delete')
                    ->icon(Heroicon::Trash)
                    ->color('danger')
                    ->iconButton()
                    ->requiresConfirmation()
                    ->modalHeading('Delete ingredient')
                    ->modalDescription('This removes the ingredient from your private catalog.')
                    ->visible(fn (Ingredient $record): bool => $record->owner_type === OwnerType::User)
                    ->disabled(fn (Ingredient $record): bool => (int) ($record->costing_items_count ?? 0) > 0 || (int) ($record->recipe_items_count ?? 0) > 0)
                    ->tooltip(fn (Ingredient $record): ?string => (int) ($record->recipe_items_count ?? 0) > 0
                        ? 'This ingredient is used in official recipes.'
                        : ((int) ($record->costing_items_count ?? 0) > 0
                            ? 'This ingredient is used in saved costing rows.'
                            : null))
                    ->action(fn (Ingredient $record): bool => $this->deleteIngredient($record)),
            ])
            ->recordActionsColumnLabel('Actions')
            ->recordActionsAlignment('end')
            ->defaultSort('display_name')
            ->paginated([25, 50, 100])
            ->emptyStateHeading('No ingredients yet')
            ->emptyStateDescription('Create your first private ingredient or set a price on a platform ingredient to see it here.');
    }

    public function render(): View
    {
        return view('livewire.dashboard.ingredients-index', [
            'currentUser' => $this->currentUser(),
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function ownershipFilterOptions(): array
    {
        return [
            'all' => 'All ingredients',
            'mine' => 'Mine',
            'platform' => 'Platform',
            'priced' => 'Priced',
        ];
    }

    public function setOwnershipFilter(string $filter): void
    {
        if (! array_key_exists($filter, $this->ownershipFilterOptions())) {
            return;
        }

        $this->ownershipFilter = $filter;
        $this->resetPage();
        $this->flushCachedTableRecords();
    }

    private function tableQuery(): Builder
    {
        $user = $this->currentUser();

        if (! $user instanceof User) {
            return Ingredient::query()->whereRaw('1 = 0');
        }

        return Ingredient::query()
            ->withCount(['costingItems', 'recipeItems'])
            ->with(['userPrices' => fn ($q) => $q->where('user_id', $user->id)])
            ->where(function (Builder $q) use ($user) {
                $q->where(fn (Builder $qq) => $qq->where('owner_type', OwnerType::User->value)->where('owner_id', $user->id))
                    ->orWhere(fn (Builder $qq) => $qq->whereNull('owner_type')->where('is_active', true));
            })
            ->when($this->ownershipFilter === 'mine', fn (Builder $query): Builder => $query
                ->where('owner_type', OwnerType::User->value)
                ->where('owner_id', $user->id))
            ->when($this->ownershipFilter === 'platform', fn (Builder $query): Builder => $query
                ->whereNull('owner_type')
                ->where('is_active', true))
            ->when($this->ownershipFilter === 'priced', fn (Builder $query): Builder => $query
                ->whereNull('owner_type')
                ->where('is_active', true)
                ->whereHas('userPrices', fn (Builder $q): Builder => $q->where('user_id', $user->id)));
    }

    private function deleteIngredient(Ingredient $ingredient): bool
    {
        $user = $this->currentUser();

        if (! $user instanceof User || ! $ingredient->isOwnedBy($user)) {
            return false;
        }

        if ((int) ($ingredient->costing_items_count ?? $ingredient->costingItems()->count()) > 0) {
            return false;
        }

        if ((int) ($ingredient->recipe_items_count ?? $ingredient->recipeItems()->count()) > 0) {
            return false;
        }

        MediaStorage::deletePublicPath($ingredient->featured_image_path);
        MediaStorage::deletePublicPath($ingredient->icon_image_path);
        $ingredient->delete();

        return true;
    }

    private function currentUser(): ?User
    {
        return app(CurrentAppUserResolver::class)->resolve($this->currentUserId);
    }

    private function priceColumnLabel(string $label): string
    {
        return sprintf('%s (%s)', $label, $this->currentCurrency ?? config('currencies.default', 'EUR'));
    }

    private static function formatPriceInput(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return number_format((float) $value, 2, '.', '');
    }
}
