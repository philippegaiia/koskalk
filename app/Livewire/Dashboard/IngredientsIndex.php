<?php

namespace App\Livewire\Dashboard;

use App\Models\Ingredient;
use App\Models\User;
use App\OwnerType;
use App\Services\CurrentAppUserResolver;
use App\Services\MediaStorage;
use App\Services\UserIngredientPriceMemory;
use App\Support\NumberLocale;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithoutUrlPagination;
use Livewire\WithPagination;

class IngredientsIndex extends Component
{
    use WithoutUrlPagination;
    use WithPagination;

    private const array ALLOWED_PER_PAGE = [25, 50, 100];

    #[Locked]
    public ?int $currentUserId = null;

    #[Locked]
    public ?string $currentCurrency = null;

    #[Locked]
    public string $currentNumberLocale = 'en_US';

    public string $ownershipFilter = 'all';

    public string $search = '';

    public string $sortField = 'display_name';

    public string $sortDirection = 'asc';

    public int $perPage = 25;

    public ?int $pendingDeleteId = null;

    public function mount(CurrentAppUserResolver $resolver): void
    {
        $user = $resolver->resolve();

        $this->currentUserId = $user?->id;
        $this->currentCurrency = $user?->defaultCurrency();
        $this->currentNumberLocale = NumberLocale::resolve($user?->number_locale);
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatedPerPage(): void
    {
        $this->perPage = $this->normalizedPerPage();
        $this->resetPage();
    }

    public function render(): View
    {
        $ingredients = $this->ingredients();

        return view('livewire.dashboard.ingredients-index', [
            'currentUser' => $this->currentUser(),
            'ingredients' => $ingredients,
            'priceLabel' => $this->priceColumnLabel('Price/kg'),
            'pendingDeleteIngredient' => $this->pendingDeleteId === null
                ? null
                : $ingredients->getCollection()->firstWhere('id', $this->pendingDeleteId),
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
    }

    public function sortBy(string $field): void
    {
        if (! in_array($field, ['display_name', 'category'], true)) {
            return;
        }

        if ($this->sortField === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';

            return;
        }

        $this->sortField = $field;
        $this->sortDirection = 'asc';
    }

    public function updateIngredientPrice(int $id, string $value): void
    {
        $user = $this->currentUser();

        if (! $user instanceof User) {
            return;
        }

        $ingredient = $this->accessibleIngredient($id, $user);

        if (! $ingredient instanceof Ingredient) {
            return;
        }

        $normalizedValue = NumberLocale::parseDecimalInput($value);
        $validator = Validator::make(
            ['price' => $normalizedValue],
            ['price' => ['required', 'numeric', 'min:0']],
        );

        if ($validator->fails()) {
            $this->addError('price_'.$id, $validator->errors()->first('price'));

            return;
        }

        app(UserIngredientPriceMemory::class)->remember($user, $ingredient->id, $normalizedValue);
    }

    public function confirmDelete(int $id): void
    {
        $this->pendingDeleteId = $id;
    }

    public function cancelDelete(): void
    {
        $this->pendingDeleteId = null;
    }

    public function deleteIngredient(int $id): void
    {
        $user = $this->currentUser();

        if (! $user instanceof User) {
            return;
        }

        $ingredient = $this->ownedIngredient($id, $user);

        if (! $ingredient instanceof Ingredient) {
            return;
        }

        $this->deleteIngredientRecord($ingredient);
        $this->pendingDeleteId = null;
    }

    public function deleteIngredientRecord(Ingredient $ingredient): bool
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

    public function catalogImageUrl(Ingredient $ingredient): ?string
    {
        return MediaStorage::publicUrlWithoutExistenceCheck($ingredient->icon_image_path ?: $ingredient->featured_image_path);
    }

    public function formattedPrice(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return NumberLocale::formatDecimal($value, 2, $this->currentNumberLocale);
    }

    private function ingredients(): LengthAwarePaginator
    {
        $user = $this->currentUser();
        $perPage = $this->normalizedPerPage();

        if (! $user instanceof User) {
            return Ingredient::query()->whereRaw('1 = 0')->paginate($perPage);
        }

        return $this->ingredientQuery($user)
            ->orderBy($this->sortField, $this->sortDirection)
            ->orderBy('id', $this->sortDirection)
            ->paginate($perPage);
    }

    private function ingredientQuery(User $user): Builder
    {
        $search = mb_strtolower(trim($this->search));
        $translationLocales = Ingredient::translationLocaleCandidates();

        return Ingredient::query()
            ->withCount(['costingItems', 'recipeItems'])
            ->with([
                'userPrices' => fn ($query) => $query->where('user_id', $user->id),
                'translations' => fn ($query) => $query->whereIn('locale', $translationLocales),
            ])
            ->where(function (Builder $query) use ($user): void {
                $query->where(fn (Builder $ownedQuery): Builder => $ownedQuery
                    ->where('owner_type', OwnerType::User->value)
                    ->where('owner_id', $user->id))
                    ->orWhere(fn (Builder $platformQuery): Builder => $platformQuery
                        ->whereNull('owner_type')
                        ->where('is_active', true));
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
                ->whereHas('userPrices', fn (Builder $priceQuery): Builder => $priceQuery->where('user_id', $user->id)))
            ->when($search !== '', fn (Builder $query): Builder => $query
                ->where(fn (Builder $where): Builder => $where
                    ->whereRaw('LOWER(display_name) LIKE ?', ['%'.$search.'%'])
                    ->orWhereRaw('LOWER(inci_name) LIKE ?', ['%'.$search.'%'])
                    ->orWhere('category', 'like', '%'.$search.'%')));
    }

    private function accessibleIngredient(int $id, User $user): ?Ingredient
    {
        return $this->ingredientQuery($user)->find($id);
    }

    private function ownedIngredient(int $id, User $user): ?Ingredient
    {
        return Ingredient::query()
            ->withCount(['costingItems', 'recipeItems'])
            ->where('owner_type', OwnerType::User->value)
            ->where('owner_id', $user->id)
            ->find($id);
    }

    private function currentUser(): ?User
    {
        return app(CurrentAppUserResolver::class)->resolve($this->currentUserId);
    }

    private function priceColumnLabel(string $label): string
    {
        return sprintf('%s (%s)', $label, $this->currentCurrency ?? config('currencies.default', 'EUR'));
    }

    private function normalizedPerPage(): int
    {
        return in_array($this->perPage, self::ALLOWED_PER_PAGE, true) ? $this->perPage : 25;
    }
}
