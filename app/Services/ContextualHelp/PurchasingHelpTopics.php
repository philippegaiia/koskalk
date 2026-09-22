<?php

namespace App\Services\ContextualHelp;

final class PurchasingHelpTopics
{
    public function __construct(private readonly HelpTopicResolver $resolver) {}

    /** @return list<string> */
    public function forSurface(string $surface): array
    {
        $keys = match ($surface) {
            'suppliers' => ['suppliers', 'listings', 'prices_and_currency'],
            'listings' => ['listings', 'purchase_formats', 'prices_and_currency'],
            'procurement' => ['quotations', 'purchase_orders', 'purchase_formats', 'prices_and_currency', 'incoming_stock', 'corrections'],
            'receipts' => ['receiving_deliveries', 'partial_deliveries', 'purchase_formats', 'prices_and_currency', 'corrections', 'receipt_documents'],
            default => [],
        };

        return collect($keys)->map(fn (string $key): string => 'purchasing.'.$key)->all();
    }

    /** @return array{topics: array<string, array>, tabs: array<string, list<string>>} */
    public function resolve(string $surface, string $locale): array
    {
        $topics = $this->resolver->resolve($this->forSurface($surface), $locale);

        return ['topics' => $topics, 'tabs' => ['purchasing' => array_keys($topics)]];
    }

    /** @return list<string> */
    public function locations(string $key): array
    {
        return collect(['suppliers' => 'Suppliers', 'listings' => 'Supplier listings', 'procurement' => 'Quotations and orders', 'receipts' => 'Goods receipts'])
            ->filter(fn (string $label, string $surface): bool => in_array($key, $this->forSurface($surface), true))
            ->map(fn (string $label): string => 'Purchasing · '.$label)
            ->values()->all();
    }
}
