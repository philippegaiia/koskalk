<?php

namespace App\Livewire\ProductionBench\Purchasing;

use App\Actions\Purchasing\SaveSupplier;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Workspace;
use App\Services\CurrencyCatalog;
use App\Services\ProductionBenchAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class SupplierEdit extends Component
{
    private CurrencyCatalog $currencyCatalog;

    public string|Supplier $supplier;

    public string $code = '';

    public string $name = '';

    public bool $isActive = true;

    public string $defaultCurrency = '';

    public string $contactName = '';

    public string $email = '';

    public string $phone = '';

    public string $website = '';

    public string $addressLine1 = '';

    public string $addressLine2 = '';

    public string $city = '';

    public string $region = '';

    public string $postalCode = '';

    public string $countryCode = '';

    public string $notes = '';

    public function boot(CurrencyCatalog $currencyCatalog): void
    {
        $this->currencyCatalog = $currencyCatalog;
    }

    public function mount(string|Supplier $supplier, ProductionBenchAccess $access): void
    {
        $supplierId = $supplier instanceof Supplier ? $supplier->public_id : $supplier;
        $this->supplier = Supplier::query()
            ->where('workspace_id', $this->workspace()->id)
            ->where('public_id', $supplierId)
            ->firstOrFail();

        $this->assertPageIsWritable($access);
        $this->fillForm();
    }

    public function save(SaveSupplier $saveSupplier): void
    {
        $this->normalizeCodes();
        $this->validate($this->rules());

        if (! $this->supplier instanceof Supplier) {
            abort(404);
        }

        try {
            $this->supplier = $saveSupplier->handle(
                $this->user(),
                $this->workspace(),
                $this->supplierAttributes(),
                $this->supplier,
            );
        } catch (ValidationException $exception) {
            $this->surfaceValidationErrors($exception);

            return;
        }

        $this->redirectRoute('production-bench.purchasing.supplier', ['supplier' => $this->supplier], navigate: true);
    }

    public function render(): View
    {
        return view('livewire.production-bench.purchasing.supplier-edit');
    }

    private function fillForm(): void
    {
        $this->fill([
            'code' => $this->supplier->code,
            'name' => $this->supplier->name,
            'isActive' => $this->supplier->is_active,
            'defaultCurrency' => $this->supplier->default_currency,
            'contactName' => $this->supplier->contact_name ?? '',
            'email' => $this->supplier->email ?? '',
            'phone' => $this->supplier->phone ?? '',
            'website' => $this->supplier->website ?? '',
            'addressLine1' => $this->supplier->address_line_1 ?? '',
            'addressLine2' => $this->supplier->address_line_2 ?? '',
            'city' => $this->supplier->city ?? '',
            'region' => $this->supplier->region ?? '',
            'postalCode' => $this->supplier->postal_code ?? '',
            'countryCode' => $this->supplier->country_code ?? '',
            'notes' => $this->supplier->notes ?? '',
        ]);
    }

    /** @return array<string, array<int, mixed>> */
    private function rules(): array
    {
        $allowedCurrencies = $this->currencyCatalog->selectableCodes();

        if ($this->supplier instanceof Supplier && $this->currencyCatalog->isKnown($this->supplier->default_currency)) {
            $allowedCurrencies[] = Str::upper($this->supplier->default_currency);
        }

        return [
            'code' => ['required', 'string', 'max:16', 'regex:/^[A-Z0-9_-]+$/'],
            'name' => ['required', 'string', 'max:255'],
            'isActive' => ['required', 'boolean'],
            'defaultCurrency' => ['required', 'string', 'size:3', Rule::in(array_unique($allowedCurrencies))],
            'contactName' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:255'],
            'website' => ['nullable', 'url:http,https', 'max:255'],
            'addressLine1' => ['nullable', 'string', 'max:255'],
            'addressLine2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'region' => ['nullable', 'string', 'max:255'],
            'postalCode' => ['nullable', 'string', 'max:32'],
            'countryCode' => ['nullable', 'alpha:ascii', 'size:2'],
            'notes' => ['nullable', 'string'],
        ];
    }

    /** @return array<string, mixed> */
    private function supplierAttributes(): array
    {
        return [
            'code' => $this->code,
            'name' => $this->name,
            'is_active' => $this->isActive,
            'default_currency' => $this->defaultCurrency,
            'contact_name' => $this->contactName,
            'email' => $this->email,
            'phone' => $this->phone,
            'website' => $this->website,
            'address_line_1' => $this->addressLine1,
            'address_line_2' => $this->addressLine2,
            'city' => $this->city,
            'region' => $this->region,
            'postal_code' => $this->postalCode,
            'country_code' => $this->countryCode,
            'notes' => $this->notes,
        ];
    }

    private function normalizeCodes(): void
    {
        $this->code = Str::upper(trim($this->code));
        $this->countryCode = Str::upper(trim($this->countryCode));
        $this->defaultCurrency = Str::upper(trim($this->defaultCurrency));
    }

    private function surfaceValidationErrors(ValidationException $exception): void
    {
        foreach ($exception->errors() as $field => $messages) {
            foreach ($messages as $message) {
                $this->addError($this->formField($field), $message);
            }
        }
    }

    private function formField(string $field): string
    {
        return match ($field) {
            'is_active' => 'isActive',
            'default_currency' => 'defaultCurrency',
            'contact_name' => 'contactName',
            'address_line_1' => 'addressLine1',
            'address_line_2' => 'addressLine2',
            'postal_code' => 'postalCode',
            'country_code' => 'countryCode',
            default => $field,
        };
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
        return $this->user()->company() ?? abort(404);
    }
}
