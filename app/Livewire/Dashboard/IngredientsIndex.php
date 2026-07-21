<?php

namespace App\Livewire\Dashboard;

use App\Models\Ingredient;
use App\Models\User;
use App\OwnerType;
use App\Services\CurrentAppUserResolver;
use App\Services\EntitlementService;
use App\Services\IngredientFormulaMutationService;
use App\Services\IngredientFormulaUsageService;
use App\Services\MediaStorage;
use App\Services\UserIngredientPriceMemory;
use App\Support\NumberLocale;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
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
    public ?string $currentCurrency = null;

    #[Locked]
    public string $currentNumberLocale = 'en_US';

    public string $ownershipFilter = 'all';

    public string $search = '';

    public string $sortField = 'display_name';

    public string $sortDirection = 'asc';

    public int $perPage = 25;

    #[Locked]
    public ?int $pendingDeleteId = null;

    public ?int $replacementIngredientId = null;

    public ?string $statusMessage = null;

    public string $statusType = 'success';

    public ?int $expandedUsageIngredientId = null;

    public function mount(CurrentAppUserResolver $resolver): void
    {
        $user = $resolver->resolve();

        $this->currentCurrency = $user?->defaultCurrency();
        $this->currentNumberLocale = NumberLocale::resolve($user?->number_locale);
    }

    public function updatingSearch(): void
    {
        $this->expandedUsageIngredientId = null;
        $this->resetPage();
    }

    public function updatedPerPage(): void
    {
        $this->expandedUsageIngredientId = null;
        $this->perPage = $this->normalizedPerPage();
        $this->resetPage();
    }

    public function updatingPaginators(mixed $page, string $pageName): void
    {
        $this->expandedUsageIngredientId = null;
    }

    public function render(
        EntitlementService $entitlementService,
        IngredientFormulaMutationService $ingredientFormulaMutationService,
        IngredientFormulaUsageService $ingredientFormulaUsageService,
    ): View {
        $currentUser = $this->currentUser();
        $ingredients = $this->ingredients($currentUser);
        $privateIngredientUsage = $currentUser instanceof User
            ? $entitlementService->privateIngredientUsageFor($currentUser)
            : ['used' => 0, 'limit' => null, 'remaining' => null, 'allowed' => false];
        $privateIngredients = $currentUser instanceof User
            ? $ingredients->getCollection()
                ->filter(fn (Ingredient $ingredient): bool => $ingredient->isEditableBy($currentUser))
                ->values()
            : collect();
        $formulaUsageByIngredient = $currentUser instanceof User
            ? $ingredientFormulaUsageService->forIngredients($currentUser, $privateIngredients)
            : [];
        $pendingDeleteIngredient = $currentUser instanceof User
            ? $this->pendingOwnedIngredient($currentUser)
            : null;
        $pendingDeleteImpact = $pendingDeleteIngredient instanceof Ingredient
            ? $ingredientFormulaMutationService->impact($currentUser, $pendingDeleteIngredient)
            : null;
        $replacementCandidates = $pendingDeleteIngredient instanceof Ingredient
            && ($pendingDeleteImpact['formula_count'] > 0 || $pendingDeleteImpact['composite_count'] > 0)
            ? $ingredientFormulaMutationService->replacementCandidates($currentUser, $pendingDeleteIngredient)
            : collect();

        return view('livewire.dashboard.ingredients-index', [
            'currentUser' => $currentUser,
            'ingredients' => $ingredients,
            'privateIngredientUsage' => $privateIngredientUsage,
            'formulaUsageByIngredient' => $formulaUsageByIngredient,
            'priceLabel' => __('ingredients.price.column', [
                'currency' => $this->currentCurrency ?? config('currency.default', 'EUR'),
            ]),
            'pendingDeleteIngredient' => $pendingDeleteIngredient,
            'pendingDeleteImpact' => $pendingDeleteImpact,
            'replacementCandidates' => $replacementCandidates,
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function ownershipFilterOptions(): array
    {
        return [
            'all' => __('ingredients.filters.all'),
            'mine' => __('ingredients.filters.yours'),
            'platform' => __('ingredients.filters.soapkraft'),
            'priced' => __('ingredients.filters.priced'),
        ];
    }

    public function setOwnershipFilter(string $filter): void
    {
        if (! array_key_exists($filter, $this->ownershipFilterOptions())) {
            return;
        }

        $this->expandedUsageIngredientId = null;
        $this->ownershipFilter = $filter;
        $this->resetPage();
    }

    public function sortBy(string $field): void
    {
        if (! in_array($field, ['display_name', 'category'], true)) {
            return;
        }

        $this->expandedUsageIngredientId = null;

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
        $user = $this->currentUser();

        if (! $user instanceof User || ! $this->ownedIngredient($id, $user) instanceof Ingredient) {
            $this->cancelDelete();

            return;
        }

        $this->resetErrorBag();
        $this->replacementIngredientId = null;
        $this->pendingDeleteId = $id;
    }

    public function cancelDelete(): void
    {
        $this->restoreFocusToPendingTrigger();
        $this->pendingDeleteId = null;
        $this->replacementIngredientId = null;
        $this->resetErrorBag();
    }

    public function toggleUsage(int $ingredientId): void
    {
        $this->expandedUsageIngredientId = $this->expandedUsageIngredientId === $ingredientId
            ? null
            : $ingredientId;
    }

    public function selectReplacementIngredient(
        int $ingredientId,
        IngredientFormulaMutationService $ingredientFormulaMutationService,
    ): void {
        $user = $this->currentUser();
        $ingredient = $user instanceof User ? $this->pendingOwnedIngredient($user) : null;
        $replacementIsAvailable = $ingredient instanceof Ingredient
            && $ingredientFormulaMutationService
                ->replacementCandidates($user, $ingredient)
                ->contains('id', $ingredientId);

        if (! $replacementIsAvailable) {
            $this->replacementIngredientId = null;
            $this->addError('replacementIngredientId', __('ingredients.validation.choose_compatible_replacement'));

            return;
        }

        $this->replacementIngredientId = $ingredientId;
        $this->resetErrorBag('replacementIngredientId');
    }

    public function clearReplacementIngredient(): void
    {
        $this->replacementIngredientId = null;
        $this->resetErrorBag('replacementIngredientId');
    }

    public function deleteIngredient(IngredientFormulaMutationService $ingredientFormulaMutationService): void
    {
        $user = $this->currentUser();

        if (! $user instanceof User) {
            return;
        }

        $ingredient = $this->pendingOwnedIngredient($user);

        if (! $ingredient instanceof Ingredient) {
            $this->closeMissingPendingIngredient();

            return;
        }

        $impact = $ingredientFormulaMutationService->impact($user, $ingredient);

        if ($impact['formula_count'] > 0 || $impact['composite_count'] > 0) {
            $this->addError('ingredient', __('ingredients.validation.choose_dependency_action'));

            return;
        }

        try {
            $ingredientName = $ingredient->localizedDisplayName();
            $ingredientFormulaMutationService->removeEverywhereAndDelete($user, $ingredient);
        } catch (ValidationException $exception) {
            $this->surfaceMutationErrors($exception);

            return;
        }

        $this->finishMutation(__('ingredients.status.deleted', ['ingredient' => $ingredientName]));
    }

    public function replaceEverywhereAndDelete(IngredientFormulaMutationService $ingredientFormulaMutationService): void
    {
        $user = $this->currentUser();

        if (! $user instanceof User) {
            return;
        }

        $ingredient = $this->pendingOwnedIngredient($user);

        if (! $ingredient instanceof Ingredient) {
            $this->closeMissingPendingIngredient();

            return;
        }

        if ($this->replacementIngredientId === null) {
            $this->addError('replacementIngredientId', __('ingredients.validation.choose_replacement'));

            return;
        }

        $replacement = Ingredient::query()->find($this->replacementIngredientId);

        if (! $replacement instanceof Ingredient) {
            $this->addError('replacementIngredientId', __('ingredients.validation.replacement_unavailable'));

            return;
        }

        try {
            $ingredientName = $ingredient->localizedDisplayName();
            $ingredientFormulaMutationService->replaceEverywhereAndDelete($user, $ingredient, $replacement);
        } catch (ValidationException $exception) {
            $this->surfaceMutationErrors($exception);

            return;
        }

        $this->finishMutation(__('ingredients.status.replaced_and_deleted', ['ingredient' => $ingredientName]));
    }

    public function removeEverywhereAndDelete(IngredientFormulaMutationService $ingredientFormulaMutationService): void
    {
        $user = $this->currentUser();

        if (! $user instanceof User) {
            return;
        }

        $ingredient = $this->pendingOwnedIngredient($user);

        if (! $ingredient instanceof Ingredient) {
            $this->closeMissingPendingIngredient();

            return;
        }

        try {
            $ingredientName = $ingredient->localizedDisplayName();
            $ingredientFormulaMutationService->removeEverywhereAndDelete($user, $ingredient);
        } catch (ValidationException $exception) {
            $this->surfaceMutationErrors($exception);

            return;
        }

        $this->finishMutation(__('ingredients.status.removed_and_deleted', ['ingredient' => $ingredientName]));
    }

    public function catalogImageUrl(Ingredient $ingredient): ?string
    {
        if ($ingredient->owner_type !== null) {
            return $ingredient->pickerImageUrl();
        }

        return MediaStorage::publicUrlWithoutExistenceCheck($ingredient->icon_image_path ?: $ingredient->featured_image_path);
    }

    public function formattedPrice(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return NumberLocale::formatDecimal($value, 2, $this->currentNumberLocale);
    }

    private function ingredients(?User $user): LengthAwarePaginator
    {
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
                $query->where(fn (Builder $ownedQuery): Builder => $this->applyCompanyIngredientScope($ownedQuery, $user))
                    ->orWhere(fn (Builder $platformQuery): Builder => $platformQuery
                        ->whereNull('owner_type')
                        ->where('is_active', true));
            })
            ->when($this->ownershipFilter === 'mine', fn (Builder $query): Builder => $query
                ->where(fn (Builder $ownedQuery): Builder => $this->applyCompanyIngredientScope($ownedQuery, $user)))
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
        $ingredient = Ingredient::query()
            ->withCount(['costingItems', 'recipeItems'])
            ->where(fn (Builder $query): Builder => $this->applyCompanyIngredientScope($query, $user))
            ->find($id);

        return $ingredient instanceof Ingredient && $ingredient->isEditableBy($user)
            ? $ingredient
            : null;
    }

    private function applyCompanyIngredientScope(Builder $query, User $user): Builder
    {
        $workspaceId = $user->company()?->id;

        $query->where(function (Builder $ownershipQuery) use ($user, $workspaceId): void {
            $ownershipQuery
                ->where('owner_type', OwnerType::User->value)
                ->where('owner_id', $user->id);

            if ($workspaceId !== null) {
                $ownershipQuery->orWhere(function (Builder $workspaceQuery) use ($workspaceId): void {
                    $workspaceQuery
                        ->where('owner_type', OwnerType::Workspace->value)
                        ->where('owner_id', $workspaceId);
                });
            }
        });

        return $query;
    }

    private function pendingOwnedIngredient(User $user): ?Ingredient
    {
        if ($this->pendingDeleteId === null) {
            return null;
        }

        return $this->ownedIngredient($this->pendingDeleteId, $user);
    }

    private function finishMutation(string $statusMessage): void
    {
        $this->restoreFocusToPendingTrigger();
        $this->statusMessage = $statusMessage;
        $this->statusType = 'success';
        $this->pendingDeleteId = null;
        $this->replacementIngredientId = null;
        $this->expandedUsageIngredientId = null;
        $this->resetErrorBag();
        $this->resetPage();
    }

    private function closeMissingPendingIngredient(): void
    {
        $this->restoreFocusToPendingTrigger();
        $this->statusMessage = __('ingredients.status.unavailable');
        $this->statusType = 'error';
        $this->pendingDeleteId = null;
        $this->replacementIngredientId = null;
        $this->expandedUsageIngredientId = null;
        $this->resetErrorBag();
    }

    private function restoreFocusToPendingTrigger(): void
    {
        if ($this->pendingDeleteId === null) {
            return;
        }

        $this->dispatch(
            'ingredient-removal-closed',
            triggerId: 'ingredient-delete-trigger-'.$this->pendingDeleteId,
        );
    }

    private function surfaceMutationErrors(ValidationException $exception): void
    {
        $this->resetErrorBag();

        foreach ($exception->errors() as $field => $messages) {
            foreach ($messages as $message) {
                $this->addError($field, $message);
            }
        }
    }

    private function currentUser(): ?User
    {
        return app(CurrentAppUserResolver::class)->resolve();
    }

    private function normalizedPerPage(): int
    {
        return in_array($this->perPage, self::ALLOWED_PER_PAGE, true) ? $this->perPage : 25;
    }
}
