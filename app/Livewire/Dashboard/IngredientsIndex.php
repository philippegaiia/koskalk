<?php

namespace App\Livewire\Dashboard;

use App\Models\Ingredient;
use App\Models\User;
use App\Models\UserIngredientPrice;
use App\OwnerType;
use App\Services\CurrentAppUserResolver;
use App\Services\MediaStorage;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Filters\SelectFilter;
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

    public function mount(CurrentAppUserResolver $resolver): void
    {
        $this->currentUserId = $resolver->resolve()?->id;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->tableQuery())
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
                TextInputColumn::make('user_price_per_kg')
                    ->label('Price (EUR/kg)')
                    ->type('number')
                    ->inputMode('decimal')
                    ->updateStateUsing(function (Ingredient $record, $state) {
                        if ($this->currentUserId === null) {
                            return $state;
                        }

                        UserIngredientPrice::query()->updateOrCreate(
                            ['user_id' => $this->currentUserId, 'ingredient_id' => $record->id],
                            ['price_per_kg' => round((float) ($state ?? 0), 4), 'currency' => 'EUR', 'last_used_at' => now()],
                        );
                        $record->load(['userPrices' => fn ($q) => $q->where('user_id', $this->currentUserId)]);

                        return $state;
                    }),
            ])
            ->filters([
                SelectFilter::make('ownership')
                    ->label('Show')
                    ->options([
                        'mine' => 'My ingredients',
                        'priced' => 'Priced platform',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;

                        if ($value === 'mine') {
                            $query->where('owner_type', OwnerType::User->value)
                                ->where('owner_id', $this->currentUserId);
                        } elseif ($value === 'priced') {
                            $query->whereNull('owner_type');
                        }

                        return $query;
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
                        ? 'This ingredient is used in saved formulas.'
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

    private function tableQuery(): Builder
    {
        $user = $this->currentUser();

        if (! $user instanceof User) {
            return Ingredient::query()->whereRaw('1 = 0');
        }

        $pricedIds = UserIngredientPrice::query()
            ->where('user_id', $user->id)
            ->pluck('ingredient_id');

        return Ingredient::query()
            ->withCount(['costingItems', 'recipeItems'])
            ->with(['userPrices' => fn ($q) => $q->where('user_id', $user->id)])
            ->where(function (Builder $q) use ($user, $pricedIds) {
                $q->where(fn (Builder $qq) => $qq->where('owner_type', OwnerType::User->value)->where('owner_id', $user->id))
                    ->orWhereIn('ingredients.id', $pricedIds);
            });
    }

    private function deleteIngredient(Ingredient $ingredient): bool
    {
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
}
