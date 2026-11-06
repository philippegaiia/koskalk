<?php

namespace App\Livewire\Dashboard;

use App\Livewire\Concerns\InteractsWithAppNotifications;
use App\Models\Brand;
use App\Models\ProductCollection;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ProductOrganizationManagementService;
use App\Services\WorkspaceAuthorization;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Schemas\Schema;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

class ProductOrganizationManager extends Component implements HasForms
{
    use InteractsWithAppNotifications;
    use InteractsWithForms;

    #[Locked]
    public ?int $mountedWorkspaceId = null;

    #[Locked]
    public ?string $editorType = null;

    #[Locked]
    public ?int $editingId = null;

    #[Locked]
    public ?string $deletionType = null;

    #[Locked]
    public ?int $deletionId = null;

    /** @var array<string, mixed> */
    public array $data = [];

    /** @var array<string, mixed> */
    public array $deletionData = [];

    public ?string $statusMessage = null;

    public function mount(): void
    {
        $workspace = app(WorkspaceAuthorization::class)->selectedWorkspace($this->user()) ?? abort(404);
        $this->mountedWorkspaceId = $workspace->id;
        $this->form->fill();
        $this->deletionForm->fill();
    }

    public function hydrate(): void
    {
        $this->workspace();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label(__('organization.name'))->required()->maxLength(255)->autocomplete('off'),
            Select::make('brand_id')->label(__('organization.brand'))
                ->options(fn (): array => $this->brandOptions())
                ->visible(fn (): bool => $this->editorType === 'collection')
                ->required(fn (): bool => $this->editorType === 'collection')
                ->helperText(__('organization.move_help')),
        ])->statePath('data');
    }

    public function deletionForm(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('target_id')
                ->label(fn (): string => __('organization.'.($this->deletionType === 'brand' ? 'reassign_brand' : 'reassign_collection')))
                ->options(fn (): array => $this->reassignmentOptions())
                ->placeholder(__('organization.no_reassignment'))
                ->helperText(__('organization.reassign_help')),
            Checkbox::make('clear_assignments')->label(__('organization.clear_assignments'))->default(false),
        ])->statePath('deletionData');
    }

    public function createBrand(): void
    {
        $this->openEditor('brand');
    }

    public function createCollection(int $brandId): void
    {
        $this->assertEditable();
        Brand::query()->where('workspace_id', $this->workspace()->id)->findOrFail($brandId);
        $this->openEditor('collection', brandId: $brandId);
    }

    public function editBrand(int $brandId): void
    {
        $this->assertEditable();
        $brand = Brand::query()->where('workspace_id', $this->workspace()->id)->findOrFail($brandId);
        $this->openEditor('brand', $brand->id, $brand->name);
    }

    public function editCollection(int $collectionId): void
    {
        $this->assertEditable();
        $collection = ProductCollection::query()->where('workspace_id', $this->workspace()->id)->findOrFail($collectionId);
        $this->openEditor('collection', $collection->id, $collection->name, $collection->brand_id);
    }

    public function cancelEdit(): void
    {
        $this->editorType = null;
        $this->editingId = null;
        $this->resetErrorBag();
    }

    public function save(ProductOrganizationManagementService $management): void
    {
        $this->assertEditable();
        abort_unless(in_array($this->editorType, ['brand', 'collection'], true), 422);
        $state = $this->form->getState();
        $workspaceId = $this->workspace()->id;
        try {
            if ($this->editorType === 'brand') {
                $this->editingId === null
                    ? $management->createBrand($this->user(), $workspaceId, $state['name'])
                    : $management->renameBrand($this->user(), $workspaceId, $this->editingId, $state['name']);
            } else {
                $this->editingId === null
                    ? $management->createCollection($this->user(), $workspaceId, (int) $state['brand_id'], $state['name'])
                    : $management->saveCollection($this->user(), $workspaceId, $this->editingId, $state['name'], (int) $state['brand_id']);
            }
        } catch (ValidationException $exception) {
            $this->surfaceErrors($exception, 'data');

            return;
        }
        $this->cancelEdit();
        $this->showAppNotification(__('organization.management_saved'));
    }

    public function confirmDelete(string $type, int $id): void
    {
        $this->assertEditable();
        abort_unless(in_array($type, ['brand', 'collection'], true), 404);
        $model = $type === 'brand' ? Brand::query() : ProductCollection::query();
        $model->where('workspace_id', $this->workspace()->id)->findOrFail($id);
        $this->cancelEdit();
        $this->deletionType = $type;
        $this->deletionId = $id;
        $this->deletionForm->fill(['target_id' => null, 'clear_assignments' => false]);
        $this->dispatch('organization-editor-opened');
    }

    public function cancelDelete(): void
    {
        $this->deletionType = null;
        $this->deletionId = null;
        $this->resetErrorBag();
    }

    public function deleteGroup(ProductOrganizationManagementService $management): void
    {
        $this->assertEditable();
        abort_unless($this->deletionId !== null && in_array($this->deletionType, ['brand', 'collection'], true), 422);
        $state = $this->deletionForm->getState();
        $target = empty($state['target_id']) ? null : (int) $state['target_id'];
        try {
            if ($this->deletionType === 'brand') {
                $management->deleteBrand($this->user(), $this->workspace()->id, $this->deletionId, (bool) ($state['clear_assignments'] ?? false), $target);
            } else {
                $management->deleteCollection($this->user(), $this->workspace()->id, $this->deletionId, (bool) ($state['clear_assignments'] ?? false), $target);
            }
        } catch (ValidationException $exception) {
            $this->surfaceErrors($exception, 'deletionData');

            return;
        }
        $this->cancelDelete();
        $this->showAppNotification(__('organization.management_deleted'));
    }

    public function render(): View
    {
        $workspace = $this->workspace();
        $brands = Brand::query()->where('workspace_id', $workspace->id)
            ->withCount(['recipes' => fn (Builder $query): Builder => $query->withoutGlobalScopes()->where('workspace_id', $workspace->id)])
            ->with(['productCollections' => function (HasMany $relation) use ($workspace): void {
                $relation->where('workspace_id', $workspace->id)->orderBy('name')
                    ->withCount(['recipes' => fn (Builder $query): Builder => $query->withoutGlobalScopes()->where('workspace_id', $workspace->id)]);
            }])->orderBy('name')->get();

        return view('livewire.dashboard.product-organization-manager', [
            'brands' => $brands,
            'pendingDeletion' => $this->deletionType === 'brand'
                ? $brands->firstWhere('id', $this->deletionId)
                : $brands->flatMap(fn (Brand $brand) => $brand->productCollections)->firstWhere('id', $this->deletionId),
            'canEdit' => app(WorkspaceAuthorization::class)->canEdit($this->user(), $workspace->id),
        ]);
    }

    private function openEditor(string $type, ?int $id = null, string $name = '', ?int $brandId = null): void
    {
        $this->assertEditable();
        $this->cancelDelete();
        $this->editorType = $type;
        $this->editingId = $id;
        $this->form->fill(['name' => $name, 'brand_id' => $brandId]);
        $this->dispatch('organization-editor-opened');
    }

    /** @return array<int, string> */
    private function brandOptions(): array
    {
        return Brand::query()->where('workspace_id', $this->workspace()->id)->orderBy('name')->pluck('name', 'id')->all();
    }

    /** @return array<int, string> */
    private function reassignmentOptions(): array
    {
        if ($this->deletionType === 'brand') {
            return Brand::query()->where('workspace_id', $this->workspace()->id)->whereKeyNot($this->deletionId)->orderBy('name')->pluck('name', 'id')->all();
        }

        return ProductCollection::query()->where('workspace_id', $this->workspace()->id)->whereKeyNot($this->deletionId)
            ->with('brand')->orderBy('name')->get()
            ->mapWithKeys(fn (ProductCollection $collection): array => [$collection->id => $collection->brand->name.' · '.$collection->name])->all();
    }

    private function surfaceErrors(ValidationException $exception, string $path): void
    {
        foreach ($exception->errors() as $field => $messages) {
            foreach ($messages as $message) {
                $this->addError($path.'.'.$field, $message);
            }
        }
    }

    private function assertEditable(): void
    {
        abort_unless(app(WorkspaceAuthorization::class)->canEdit($this->user(), $this->workspace()->id), 403);
    }

    private function workspace(): Workspace
    {
        $workspace = app(WorkspaceAuthorization::class)->selectedWorkspace($this->user());
        abort_unless($workspace instanceof Workspace && $workspace->id === $this->mountedWorkspaceId, 403);

        return $workspace;
    }

    private function user(): User
    {
        return auth()->user() ?? abort(401);
    }
}
