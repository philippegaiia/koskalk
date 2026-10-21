<?php

namespace App\Livewire\Dossiers;

use App\Actions\Purchasing\SaveSupplier;
use App\Enums\WorkspaceModule;
use App\Forms\Components\SupplierFields;
use App\Models\User;
use App\Models\Workspace;
use App\Services\CurrencyCatalog;
use App\Services\WorkspaceAuthorization;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Schemas\Concerns\RestrictsFileUploadsToSchemaComponents;
use Filament\Schemas\Schema;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Creates a supplier in the shared workspace directory from the DIP pages.
 */
class QuickSupplierCreate extends Component implements HasForms
{
    use InteractsWithForms;
    use RestrictsFileUploadsToSchemaComponents;

    #[Locked]
    public int $workspaceId;

    /** @var array<string, mixed> */
    public array $data = [];

    public function mount(): void
    {
        $workspace = $this->workspace();
        $this->workspaceId = $workspace->id;
        $this->form->fill(['default_currency' => $workspace->default_currency, 'is_active' => true]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components(SupplierFields::make(app(CurrencyCatalog::class)))->statePath('data');
    }

    public function save(SaveSupplier $saveSupplier): void
    {
        /** @var array<string, mixed> $state */
        $state = $this->form->getState();

        try {
            $supplier = $saveSupplier->handle($this->user(), Workspace::withoutGlobalScopes()->findOrFail($this->workspaceId), $state);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                foreach ($messages as $message) {
                    $this->addError('data.'.$field, $message);
                }
            }

            return;
        }

        $this->dispatch('dossier-supplier-created', supplierId: $supplier->id);
        $this->form->fill(['default_currency' => $supplier->default_currency, 'is_active' => true]);
    }

    public function render(): View
    {
        return view('livewire.dossiers.quick-supplier-create');
    }

    private function user(): User
    {
        return auth()->user() ?? abort(401);
    }

    private function workspace(): Workspace
    {
        $workspace = $this->user()->company(fresh: true);

        abort_unless(
            $workspace instanceof Workspace
                && app(WorkspaceAuthorization::class)->canEditModule($this->user(), $workspace->id, WorkspaceModule::ProductDossiers),
            403,
        );

        return $workspace;
    }
}
