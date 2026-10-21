<?php

namespace App\Livewire\ProductionBench\Purchasing;

use App\Actions\Purchasing\SaveSupplier;
use App\Forms\Components\SupplierFields;
use App\Livewire\Concerns\InteractsWithProductionWorkspace;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ContextualHelp\PurchasingHelpTopics;
use App\Services\CurrencyCatalog;
use App\Services\ProductionBenchAccess;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Concerns\RestrictsFileUploadsToSchemaComponents;
use Filament\Schemas\Schema;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class SupplierCreate extends Component implements HasForms
{
    use InteractsWithForms;
    use InteractsWithProductionWorkspace;
    use RestrictsFileUploadsToSchemaComponents;

    private CurrencyCatalog $currencyCatalog;

    /** @var array<string, mixed> */
    public array $data = [];

    public function boot(CurrencyCatalog $currencyCatalog): void
    {
        $this->currencyCatalog = $currencyCatalog;
    }

    public function mount(ProductionBenchAccess $access): void
    {
        $this->assertPageIsWritable($access);
        $this->form->fill([
            'default_currency' => $this->workspace()->default_currency,
            'is_active' => true,
        ]);
    }

    public function save(SaveSupplier $saveSupplier): void
    {
        /** @var array<string, mixed> $state */
        $state = $this->form->getState();

        try {
            $supplier = $saveSupplier->handle($this->user(), $this->workspace(), $state);
        } catch (ValidationException $exception) {
            $this->surfaceValidationErrors($exception);

            return;
        }

        $this->redirectRoute('production-bench.purchasing.supplier', ['supplier' => $supplier], navigate: true);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components($this->formComponents())
            ->statePath('data')
            ->model(Supplier::class);
    }

    public function render(PurchasingHelpTopics $helpTopics): View
    {
        return view('livewire.production-bench.purchasing.supplier-create', [
            'contextualHelp' => $helpTopics->resolve('suppliers', app()->getLocale()),
        ]);
    }

    /** @return array<int, Section> */
    private function formComponents(): array
    {
        return SupplierFields::make($this->currencyCatalog);
    }

    private function surfaceValidationErrors(ValidationException $exception): void
    {
        foreach ($exception->errors() as $field => $messages) {
            foreach ($messages as $message) {
                $this->addError('data.'.$field, $message);
            }
        }
    }

    private function assertPageIsWritable(ProductionBenchAccess $access): void
    {
        try {
            $access->assertWritable($this->user(), $this->workspace());
        } catch (ValidationException) {
            abort(403);
        }
    }

    private function user(): User
    {
        return auth()->user() ?? abort(401);
    }

    private function workspace(): Workspace
    {
        return $this->productionWorkspace();
    }
}
