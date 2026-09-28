<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ProductionPickingPrintRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        abort_if(strlen($this->getContent()) > 262144, 422, __('production_bench.picking.validation.limit'));
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'workspace' => ['required', 'uuid'],
            'runs' => ['required', 'array', 'min:1', 'max:100'],
            'runs.*' => ['required', 'uuid', 'distinct'],
            'allocations' => ['present', 'array', 'max:1000'],
            'allocations.*' => ['required', 'array:requirement_id,stock_lot_id,quantity'],
            'allocations.*.requirement_id' => ['required', 'integer', 'min:1'],
            'allocations.*.stock_lot_id' => ['required', 'integer', 'min:1'],
            'allocations.*.quantity' => ['required', 'string', 'max:40'],
        ];
    }
}
