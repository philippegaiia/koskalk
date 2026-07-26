<?php

namespace App\Services\Billing;

use App\Models\Plan;

class PlanPresenter
{
    /**
     * @return array{name: string, description: string|null, price_label: string|null}
     */
    public function present(Plan $plan): array
    {
        return [
            'name' => $this->translatedField($plan, 'name', $plan->name),
            'description' => $this->translatedField($plan, 'description', $plan->description),
            'price_label' => $this->translatedField($plan, 'price_label', $plan->price_label),
        ];
    }

    private function translatedField(Plan $plan, string $field, ?string $fallback): ?string
    {
        $key = "plans.catalog.{$plan->slug}.{$field}";
        $translated = __($key);

        return $translated === $key ? $fallback : $translated;
    }
}
