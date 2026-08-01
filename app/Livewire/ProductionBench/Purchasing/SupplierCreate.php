<?php

namespace App\Livewire\ProductionBench\Purchasing;

use App\Actions\Purchasing\SaveSupplier;
use App\Models\User;
use App\Models\Workspace;
use App\Services\CurrencyCatalog;
use App\Services\ProductionBenchAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class SupplierCreate extends Component
{
    private CurrencyCatalog $currencyCatalog;

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

    public function mount(ProductionBenchAccess $access): void
    {
        $this->assertPageIsWritable($access);
        $this->defaultCurrency = $this->workspace()->default_currency;
    }

    public function save(SaveSupplier $saveSupplier): void
    {
        $this->normalizeCodes();
        $this->validate($this->rules());

        try {
            $supplier = $saveSupplier->handle(
                $this->user(),
                $this->workspace(),
                $this->supplierAttributes(),
            );
        } catch (ValidationException $exception) {
            $this->surfaceValidationErrors($exception);

            return;
        }

        $this->redirectRoute('production-bench.purchasing.supplier', ['supplier' => $supplier], navigate: true);
    }

    public function render(): View
    {
        return view('livewire.production-bench.purchasing.supplier-create');
    }

    /** @return array<string, array<int, mixed>> */
    private function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:16', 'regex:/^[A-Z0-9_-]+$/'],
            'name' => ['required', 'string', 'max:255'],
            'isActive' => ['required', 'boolean'],
            'defaultCurrency' => ['required', 'string', 'size:3', Rule::in($this->currencyCatalog->selectableCodes())],
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
