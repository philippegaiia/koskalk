<?php

namespace App\Livewire\ProductionBench\Purchasing;

use App\Models\Ingredient;
use App\Models\Supplier;
use App\Models\SupplierListing;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ProductionBenchAccess;
use App\Services\SupplierListingPricePresentation;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Concerns\RestrictsFileUploadsToSchemaComponents;
use Filament\Schemas\Schema;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\WithPagination;

class SupplierListingIndex extends Component implements HasForms
{
    use InteractsWithForms;
    use RestrictsFileUploadsToSchemaComponents;
    use WithPagination;

    private const array ALLOWED_PER_PAGE = [25, 50, 100];

    private const int OPTION_LIMIT = 20;

    /** @var array<string, mixed> */
    public array $filters = [];

    public int $perPage = 25;

    public function mount(): void
    {
        $this->filtersForm->fill([
            'search' => '',
            'supplier_id' => null,
            'material_type' => 'all',
            'status' => 'active',
        ]);
    }

    public function filtersForm(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make(['md' => 2, 'xl' => 4])
                    ->schema([
                        TextInput::make('search')
                            ->label(__('production_bench.common.search'))
                            ->type('search')
                            ->live(debounce: 300)
                            ->afterStateUpdated(fn () => $this->resetPage())
                            ->columnSpan(['md' => 2, 'xl' => 1]),
                        Select::make('supplier_id')
                            ->label(__('production_bench.filters.supplier'))
                            ->placeholder(__('production_bench.common.all'))
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search): array => $this->supplierFilterSearchResults($search))
                            ->getOptionLabelUsing(fn (mixed $value): ?string => $this->supplierFilterOptionLabel(is_numeric($value) ? (int) $value : null))
                            ->live()
                            ->afterStateUpdated(fn () => $this->resetPage()),
                        Select::make('material_type')
                            ->label(__('production_bench.filters.type'))
                            ->options([
                                'all' => __('production_bench.common.all'),
                                'ingredient' => __('production_bench.filters.ingredients'),
                                'packaging' => __('production_bench.filters.packaging'),
                            ])
                            ->native(false)
                            ->live()
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
                    ]),
            ])
            ->statePath('filters');
    }

    public function updatedPerPage(): void
    {
        $this->perPage = $this->normalizedPerPage();
        $this->resetPage();
    }

    public function render(
        ProductionBenchAccess $access,
        SupplierListingPricePresentation $pricePresentation,
    ): View {
        $workspace = $this->workspace();
        $search = trim((string) ($this->filters['search'] ?? ''));
        $supplierId = is_numeric($this->filters['supplier_id'] ?? null)
            ? (int) $this->filters['supplier_id']
            : null;
        $materialType = in_array($this->filters['material_type'] ?? null, ['all', 'ingredient', 'packaging'], true)
            ? $this->filters['material_type']
            : 'all';
        $status = in_array($this->filters['status'] ?? null, ['active', 'all', 'inactive'], true)
            ? $this->filters['status']
            : 'active';
        $searchTerm = '%'.Str::lower($search).'%';
        $translationLocales = Ingredient::translationLocaleCandidates();
        $listings = SupplierListing::query()
            ->where('workspace_id', $workspace->id)
            ->with([
                'supplier',
                'ingredient.translations',
                'ingredient.workspaceCodes' => fn ($query) => $query->where('workspace_id', $workspace->id),
                'packagingItem',
            ])
            ->when($supplierId, fn ($query) => $query->where('supplier_id', $supplierId))
            ->when($materialType === 'ingredient', fn ($query) => $query->whereNotNull('ingredient_id'))
            ->when($materialType === 'packaging', fn ($query) => $query->whereNotNull('packaging_item_id'))
            ->when($status === 'active', fn ($query) => $query->where('is_active', true))
            ->when($status === 'inactive', fn ($query) => $query->where('is_active', false))
            ->when($search !== '', function (Builder $query) use ($searchTerm, $translationLocales, $workspace): void {
                $query->where(function (Builder $searchQuery) use ($searchTerm, $translationLocales, $workspace): void {
                    $searchQuery
                        ->whereRaw('LOWER(supplier_sku) LIKE ?', [$searchTerm])
                        ->orWhereRaw('LOWER(supplier_item_name) LIKE ?', [$searchTerm])
                        ->orWhereRaw('LOWER(purchase_format) LIKE ?', [$searchTerm])
                        ->orWhereHas('supplier', fn ($supplierQuery) => $supplierQuery->whereRaw('LOWER(name) LIKE ?', [$searchTerm]))
                        ->orWhereHas('ingredient', function (Builder $ingredientQuery) use ($searchTerm, $translationLocales): void {
                            $ingredientQuery->where(function (Builder $ingredientNameQuery) use ($searchTerm, $translationLocales): void {
                                $ingredientNameQuery->whereRaw('LOWER(display_name) LIKE ?', [$searchTerm]);

                                if ($translationLocales !== []) {
                                    $ingredientNameQuery->orWhereHas('translations', function (Builder $translationQuery) use ($searchTerm, $translationLocales): void {
                                        $translationQuery
                                            ->whereIn('locale', $translationLocales)
                                            ->whereRaw('LOWER(display_name) LIKE ?', [$searchTerm]);
                                    });
                                }
                            });
                        })
                        ->orWhereHas('ingredient.workspaceCodes', function (Builder $codeQuery) use ($searchTerm, $workspace): void {
                            $codeQuery
                                ->where('workspace_id', $workspace->id)
                                ->whereRaw('LOWER(material_code) LIKE ?', [$searchTerm]);
                        })
                        ->orWhereHas('packagingItem', fn (Builder $packagingQuery): Builder => $packagingQuery
                            ->whereRaw('LOWER(name) LIKE ?', [$searchTerm])
                            ->orWhereRaw('LOWER(material_code) LIKE ?', [$searchTerm]));
                });
            })
            ->latest('id')
            ->paginate($this->normalizedPerPage());

        return view('livewire.production-bench.purchasing.supplier-listing-index', [
            'isBenchActive' => $access->isActive($workspace),
            'isReadOnly' => $access->isReadOnly($workspace),
            'listingRows' => $listings->through(fn (SupplierListing $listing): array => [
                'listing' => $listing,
                'price' => $pricePresentation->present($listing, $workspace),
            ]),
            'workspace' => $workspace,
        ]);
    }

    /** @return array<int, string> */
    public function supplierFilterSearchResults(string $search): array
    {
        $search = trim($search);

        return Supplier::query()
            ->where('workspace_id', $this->workspace()->id)
            ->when($search !== '', fn (Builder $query): Builder => $query->where(
                fn (Builder $nested): Builder => $nested
                    ->whereLike('code', "%{$search}%")
                    ->orWhereLike('name', "%{$search}%"),
            ))
            ->orderBy('name')
            ->limit(self::OPTION_LIMIT)
            ->get(['id', 'code', 'name'])
            ->mapWithKeys(fn (Supplier $supplier): array => [$supplier->id => $this->supplierFilterLabel($supplier)])
            ->all();
    }

    public function supplierFilterOptionLabel(?int $supplierId): ?string
    {
        if ($supplierId === null) {
            return null;
        }

        $supplier = Supplier::query()
            ->where('workspace_id', $this->workspace()->id)
            ->find($supplierId, ['id', 'code', 'name']);

        return $supplier instanceof Supplier ? $this->supplierFilterLabel($supplier) : null;
    }

    private function user(): User
    {
        return auth()->user() ?? abort(401);
    }

    private function normalizedPerPage(): int
    {
        return in_array($this->perPage, self::ALLOWED_PER_PAGE, true) ? $this->perPage : 25;
    }

    private function supplierFilterLabel(Supplier $supplier): string
    {
        return $supplier->code.' · '.$supplier->name;
    }

    private function workspace(): Workspace
    {
        return $this->user()->company() ?? abort(404);
    }
}
