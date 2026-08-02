<?php

namespace App\Livewire\ProductionBench\Purchasing;

use App\Models\Supplier;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ProductionBenchAccess;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Concerns\RestrictsFileUploadsToSchemaComponents;
use Filament\Schemas\Schema;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\WithPagination;

class SupplierIndex extends Component implements HasForms
{
    use InteractsWithForms;
    use RestrictsFileUploadsToSchemaComponents;
    use WithPagination;

    private const array ALLOWED_PER_PAGE = [25, 50, 100];

    /** @var array<string, mixed> */
    public array $filters = [];

    public int $perPage = 25;

    public function mount(): void
    {
        $this->filtersForm->fill([
            'search' => '',
            'status' => 'active',
            'sort' => 'newest',
        ]);
    }

    public function filtersForm(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make(['md' => 3])
                    ->schema([
                        TextInput::make('search')
                            ->label(__('production_bench.common.search'))
                            ->type('search')
                            ->live(debounce: 300)
                            ->afterStateUpdated(fn () => $this->resetPage()),
                        Select::make('status')
                            ->label(__('production_bench.common.status'))
                            ->options([
                                'active' => __('production_bench.common.active'),
                                'all' => __('production_bench.common.all'),
                                'inactive' => __('production_bench.common.inactive'),
                            ])
                            ->native(false)
                            ->live()
                            ->afterStateUpdated(fn () => $this->resetPage()),
                        Select::make('sort')
                            ->label(__('production_bench.common.sort'))
                            ->options([
                                'newest' => __('production_bench.filters.newest'),
                                'name' => __('production_bench.common.name'),
                            ])
                            ->native(false)
                            ->live()
                            ->afterStateUpdated(fn () => $this->resetPage()),
                    ]),
            ])
            ->statePath('filters');
    }

    public function updatedPerPage(): void
    {
        $this->perPage = $this->normalizedPerPage();
        $this->resetPage();
    }

    public function render(ProductionBenchAccess $access): View
    {
        $workspace = $this->workspace();
        $search = trim((string) ($this->filters['search'] ?? ''));
        $status = in_array($this->filters['status'] ?? null, ['active', 'all', 'inactive'], true)
            ? $this->filters['status']
            : 'active';
        $sort = in_array($this->filters['sort'] ?? null, ['newest', 'name'], true)
            ? $this->filters['sort']
            : 'newest';
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
            ->when($status === 'active', fn ($query) => $query->where('is_active', true))
            ->when($status === 'inactive', fn ($query) => $query->where('is_active', false))
            ->when($sort === 'name', fn ($query) => $query->orderBy('name')->orderByDesc('id'))
            ->when($sort !== 'name', fn ($query) => $query->latest('id'))
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
