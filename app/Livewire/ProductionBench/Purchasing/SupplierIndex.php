<?php

namespace App\Livewire\ProductionBench\Purchasing;

use App\Actions\Purchasing\SaveSupplier;
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

    public string $search = '';

    public string $status = 'active';

    public string $sort = 'newest';

    public int $perPage = 25;

    public string $name = '';

    public string $addressLine1 = '';

    public string $addressLine2 = '';

    public string $city = '';

    public string $region = '';

    public string $postalCode = '';

    public string $countryCode = '';

    public string $website = '';

    public string $contactName = '';

    public string $email = '';

    public string $phone = '';

    public string $defaultCurrency = '';

    public string $notes = '';

    public bool $isActive = true;

    public function mount(): void
    {
        $this->defaultCurrency = $this->workspace()->default_currency;
    }

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
        $this->resetPage();
    }

    public function saveSupplier(SaveSupplier $saveSupplier): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'countryCode' => ['nullable', 'alpha:ascii', 'size:2'],
            'website' => ['nullable', 'url', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'defaultCurrency' => ['required', 'alpha:ascii', 'size:3'],
        ]);

        $saveSupplier->handle($this->user(), $this->workspace(), $this->supplierAttributes());

        $this->resetSupplierForm();
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
                        ->whereRaw('LOWER(name) LIKE ?', [$searchTerm])
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
            ->paginate($this->perPage);

        return view('livewire.production-bench.purchasing.supplier-index', [
            'isBenchActive' => $access->isActive($workspace),
            'isReadOnly' => $access->isReadOnly($workspace),
            'suppliers' => $suppliers,
            'workspace' => $workspace,
        ]);
    }

    /** @return array<string, mixed> */
    private function supplierAttributes(): array
    {
        return [
            'name' => $this->name,
            'address_line_1' => $this->addressLine1,
            'address_line_2' => $this->addressLine2,
            'city' => $this->city,
            'region' => $this->region,
            'postal_code' => $this->postalCode,
            'country_code' => $this->countryCode,
            'website' => $this->website,
            'contact_name' => $this->contactName,
            'email' => $this->email,
            'phone' => $this->phone,
            'default_currency' => $this->defaultCurrency,
            'notes' => $this->notes,
            'is_active' => $this->isActive,
        ];
    }

    private function resetSupplierForm(): void
    {
        $this->reset([
            'name', 'addressLine1', 'addressLine2', 'city', 'region', 'postalCode', 'countryCode',
            'website', 'contactName', 'email', 'phone', 'notes',
        ]);
        $this->defaultCurrency = $this->workspace()->default_currency;
        $this->isActive = true;
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
