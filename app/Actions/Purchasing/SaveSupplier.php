<?php

namespace App\Actions\Purchasing;

use App\Models\Supplier;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ProductionBenchAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class SaveSupplier
{
    public function __construct(private readonly ProductionBenchAccess $access) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(
        User $actor,
        Workspace $workspace,
        array $attributes,
        ?Supplier $supplier = null,
    ): Supplier {
        $this->access->assertWritable($actor, $workspace);

        return DB::transaction(function () use ($attributes, $supplier, $workspace): Supplier {
            Workspace::withoutGlobalScopes()->lockForUpdate()->findOrFail($workspace->id);

            $currentSupplier = $supplier instanceof Supplier
                ? Supplier::query()
                    ->where('workspace_id', $workspace->id)
                    ->lockForUpdate()
                    ->find($supplier->id)
                : null;

            if ($supplier instanceof Supplier && ! $currentSupplier instanceof Supplier) {
                throw ValidationException::withMessages([
                    'supplier' => 'The supplier does not belong to this workspace.',
                ]);
            }

            $data = $this->validatedAttributes($attributes, $currentSupplier, $workspace);

            if (! $currentSupplier instanceof Supplier) {
                return Supplier::query()->create([
                    'workspace_id' => $workspace->id,
                    ...$data,
                ]);
            }

            $currentSupplier->update($data);

            return $currentSupplier->refresh();
        }, attempts: 5);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function validatedAttributes(array $attributes, ?Supplier $supplier, Workspace $workspace): array
    {
        $current = $supplier instanceof Supplier
            ? $supplier->only([
                'name',
                'address_line_1',
                'address_line_2',
                'city',
                'region',
                'postal_code',
                'country_code',
                'website',
                'contact_name',
                'email',
                'phone',
                'default_currency',
                'notes',
                'is_active',
            ])
            : [
                'default_currency' => $workspace->default_currency,
                'is_active' => true,
            ];
        $data = $this->normalizeStrings([...$current, ...$attributes]);
        $countryCode = $data['country_code'] ?? null;
        $data['country_code'] = $countryCode === null
            ? null
            : strtoupper($countryCode);
        $data['default_currency'] = strtoupper((string) ($data['default_currency'] ?? ''));

        return Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'address_line_1' => ['nullable', 'string', 'max:255'],
            'address_line_2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'region' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:32'],
            'country_code' => ['nullable', 'alpha:ascii', 'size:2'],
            'website' => ['nullable', 'url', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:255'],
            'default_currency' => ['required', 'alpha:ascii', 'size:3'],
            'notes' => ['nullable', 'string'],
            'is_active' => ['required', 'boolean'],
        ])->validate();
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function normalizeStrings(array $attributes): array
    {
        foreach ($attributes as $key => $value) {
            if (is_string($value)) {
                $attributes[$key] = filled(trim($value)) ? trim($value) : null;
            }
        }

        return $attributes;
    }
}
