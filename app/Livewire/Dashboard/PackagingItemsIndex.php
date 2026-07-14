<?php

namespace App\Livewire\Dashboard;

use App\Models\User;
use App\Models\UserPackagingItem;
use App\Services\CurrentAppUserResolver;
use App\Services\PackagingItemFormulaMutationService;
use App\Services\UserPackagingItemAuthoringService;
use App\Support\NumberLocale;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

class PackagingItemsIndex extends Component
{
    use WithPagination;

    private const array ALLOWED_PER_PAGE = [25, 50, 100];

    #[Locked]
    public ?string $currentCurrency = null;

    #[Locked]
    public string $currentNumberLocale = 'en_US';

    public string $search = '';

    public string $sortField = 'name';

    public string $sortDirection = 'asc';

    public int $perPage = 25;

    public ?int $pendingDeleteId = null;

    public ?string $statusMessage = null;

    public function mount(CurrentAppUserResolver $resolver): void
    {
        $user = $resolver->resolve();

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

    public function sortBy(string $field): void
    {
        if (! in_array($field, ['name', 'unit_cost'], true)) {
            return;
        }

        if ($this->sortField === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';

            return;
        }

        $this->sortField = $field;
        $this->sortDirection = 'asc';
    }

    public function updateUnitCost(int $id, string $value): void
    {
        $user = $this->currentUser();

        if (! $user instanceof User) {
            return;
        }

        $packagingItem = $this->ownedPackagingItem($id, $user);

        if (! $packagingItem instanceof UserPackagingItem) {
            return;
        }

        try {
            app(UserPackagingItemAuthoringService::class)->updateUnitCost(
                $packagingItem,
                $user,
                NumberLocale::parseDecimalInput($value),
            );
        } catch (ValidationException $exception) {
            $this->addError('unit_cost_'.$id, $exception->validator->errors()->first('unit_cost'));
        }
    }

    public function confirmDelete(int $id): void
    {
        $user = $this->currentUser();

        if (! $user instanceof User || ! $this->ownedPackagingItem($id, $user) instanceof UserPackagingItem) {
            $this->cancelDelete();

            return;
        }

        $this->resetErrorBag();
        $this->pendingDeleteId = $id;
    }

    public function cancelDelete(): void
    {
        $this->pendingDeleteId = null;
        $this->resetErrorBag();
    }

    public function deletePackagingItem(
        int $id,
        PackagingItemFormulaMutationService $packagingItemFormulaMutationService,
    ): void {
        $user = $this->currentUser();

        if (! $user instanceof User) {
            return;
        }

        $packagingItem = $this->ownedPackagingItem($id, $user);

        if (! $packagingItem instanceof UserPackagingItem) {
            return;
        }

        if ($packagingItemFormulaMutationService->impact($user, $packagingItem)['formula_count'] > 0) {
            $this->addError('packaging_item', 'Remove this packaging item from every formula before deleting it.');

            return;
        }

        if (! app(UserPackagingItemAuthoringService::class)->delete($packagingItem, $user)) {
            $this->addError('packaging_item', 'This packaging item is still in use and cannot be deleted.');

            return;
        }

        $this->finishDeletion($packagingItem->name.' was deleted.');
    }

    public function removeEverywhereAndDelete(
        PackagingItemFormulaMutationService $packagingItemFormulaMutationService,
    ): void {
        $user = $this->currentUser();

        if (! $user instanceof User || $this->pendingDeleteId === null) {
            return;
        }

        $packagingItem = $this->ownedPackagingItem($this->pendingDeleteId, $user);

        if (! $packagingItem instanceof UserPackagingItem) {
            $this->pendingDeleteId = null;

            return;
        }

        try {
            $packagingItemName = $packagingItem->name;
            $packagingItemFormulaMutationService->removeEverywhereAndDelete($user, $packagingItem);
        } catch (ValidationException $exception) {
            $this->resetErrorBag();

            foreach ($exception->errors() as $field => $messages) {
                foreach ($messages as $message) {
                    $this->addError($field, $message);
                }
            }

            return;
        }

        $this->finishDeletion($packagingItemName.' was removed from every formula and deleted.');
    }

    public function render(PackagingItemFormulaMutationService $packagingItemFormulaMutationService): View
    {
        $items = $this->items();
        $currentUser = $this->currentUser();
        $pendingDeleteItem = $currentUser instanceof User && $this->pendingDeleteId !== null
            ? $this->ownedPackagingItem($this->pendingDeleteId, $currentUser)
            : null;

        return view('livewire.dashboard.packaging-items-index', [
            'currentUser' => $currentUser,
            'items' => $items,
            'unitPriceLabel' => sprintf('Unit price (%s)', $this->currentCurrency ?? config('currencies.default', 'EUR')),
            'pendingDeleteItem' => $pendingDeleteItem,
            'pendingDeleteImpact' => $pendingDeleteItem instanceof UserPackagingItem && $currentUser instanceof User
                ? $packagingItemFormulaMutationService->impact($currentUser, $pendingDeleteItem)
                : null,
        ]);
    }

    public function formattedUnitCost(mixed $value): string
    {
        return NumberLocale::formatDecimal($value, 2, $this->currentNumberLocale);
    }

    private function items(): LengthAwarePaginator
    {
        $user = $this->currentUser();
        $perPage = $this->normalizedPerPage();

        if (! $user instanceof User) {
            return UserPackagingItem::query()->whereRaw('1 = 0')->paginate($perPage);
        }

        return UserPackagingItem::query()
            ->select(['id', 'public_id', 'user_id', 'name', 'unit_cost', 'currency', 'notes', 'featured_image_path', 'created_at', 'updated_at'])
            ->where('user_id', $user->id)
            ->withCount('costingItems')
            ->when($this->search !== '', fn (Builder $query): Builder => $query
                ->where(fn (Builder $where): Builder => $where
                    ->where('name', 'like', '%'.$this->search.'%')
                    ->orWhere('notes', 'like', '%'.$this->search.'%')))
            ->when($this->sortField === 'unit_cost', fn (Builder $query): Builder => $query->orderBy('unit_cost', $this->sortDirection)->orderBy('id'))
            ->when($this->sortField === 'name', fn (Builder $query): Builder => $query->orderBy('name', $this->sortDirection)->orderBy('id', $this->sortDirection))
            ->paginate($perPage);
    }

    private function normalizedPerPage(): int
    {
        return in_array($this->perPage, self::ALLOWED_PER_PAGE, true) ? $this->perPage : 25;
    }

    private function ownedPackagingItem(int $id, User $user): ?UserPackagingItem
    {
        return UserPackagingItem::query()->where('user_id', $user->id)->find($id);
    }

    private function finishDeletion(string $message): void
    {
        $this->statusMessage = $message;
        $this->pendingDeleteId = null;
        $this->resetErrorBag();
        $this->resetPage();
    }

    private function currentUser(): ?User
    {
        return app(CurrentAppUserResolver::class)->resolve();
    }
}
