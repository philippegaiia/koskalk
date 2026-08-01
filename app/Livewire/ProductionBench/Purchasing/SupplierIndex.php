<?php

namespace App\Livewire\ProductionBench\Purchasing;

use App\Models\Supplier;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ProductionBenchAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\WithPagination;

class SupplierIndex extends Component
{
    use WithPagination;

    private const array ALLOWED_PER_PAGE = [25, 50, 100];

    public string $search = '';

    public string $status = 'active';

    public string $sort = 'newest';

    public int $perPage = 25;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedSort(): void
    {
        $this->resetPage();
    }

    public function updatedPerPage(): void
    {
        $this->perPage = $this->normalizedPerPage();
        $this->resetPage();
    }

    public function render(ProductionBenchAccess $access): View
    {
        $workspace = $this->workspace();
        $search = trim($this->search);
        $searchTerm = '%'.Str::lower($search).'%';
        $suppliers = Supplier::query()
            ->where('workspace_id', $workspace->id)
            ->withCount('listings')
            ->when($search !== '', function ($query) use ($searchTerm): void {
                $query->where(function ($searchQuery) use ($searchTerm): void {
                    $searchQuery
                        ->whereRaw('LOWER(code) LIKE ?', [$searchTerm])
                        ->orWhereRaw('LOWER(name) LIKE ?', [$searchTerm])
                        ->orWhereRaw('LOWER(contact_name) LIKE ?', [$searchTerm])
                        ->orWhereRaw('LOWER(email) LIKE ?', [$searchTerm])
                        ->orWhereRaw('LOWER(city) LIKE ?', [$searchTerm])
                        ->orWhereRaw('LOWER(country_code) LIKE ?', [$searchTerm]);
                });
            })
            ->when($this->status === 'active', fn ($query) => $query->where('is_active', true))
            ->when($this->status === 'inactive', fn ($query) => $query->where('is_active', false))
            ->when($this->sort === 'name', fn ($query) => $query->orderBy('name')->orderByDesc('id'))
            ->when($this->sort !== 'name', fn ($query) => $query->latest('id'))
            ->paginate($this->normalizedPerPage());

        return view('livewire.production-bench.purchasing.supplier-index', [
            'isBenchActive' => $access->isActive($workspace),
            'isReadOnly' => $access->isReadOnly($workspace),
            'suppliers' => $suppliers,
        ]);
    }

    private function normalizedPerPage(): int
    {
        return in_array($this->perPage, self::ALLOWED_PER_PAGE, true) ? $this->perPage : 25;
    }

    private function user(): User
    {
        return auth()->user() ?? abort(401);
    }

    private function workspace(): Workspace
    {
        return $this->user()->company() ?? abort(404);
    }
}
