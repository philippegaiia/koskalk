<?php

namespace App\Services\ContextualHelp;

final class InventoryHelpTopics
{
    public function __construct(private readonly HelpTopicResolver $resolver) {}

    /** @return array{keys: list<string>, index: list<string>} */
    public function forSurface(string $surface, bool $usesStorageLocations): array
    {
        $index = match ($surface) {
            'materials' => ['overview', 'quantities', 'opening_stock', 'receiving_stock', 'reservations', 'buffer_stock'],
            'stock' => ['lots_and_status', 'quantities', 'opening_stock', 'receiving_stock', 'adjustments', 'reservations'],
            'detail' => ['quantities', 'lots_and_status', 'receiving_stock', 'adjustments', 'reservations', 'material_history'],
            default => [],
        };
        $index = collect($index)->map(fn (string $key): string => 'inventory.'.$key)->all();
        $keys = $index;
        if ($surface === 'detail') {
            $keys[] = 'inventory.buffer_stock';
        }
        if ($usesStorageLocations && in_array($surface, ['stock', 'detail'], true)) {
            $keys[] = 'inventory.storage_locations';
        }

        return ['keys' => $keys, 'index' => $index];
    }

    /** @return array{topics: array<string, array>, tabs: array<string, list<string>>} */
    public function resolve(string $surface, bool $usesStorageLocations, string $locale): array
    {
        $scope = $this->forSurface($surface, $usesStorageLocations);
        $topics = $this->resolver->resolve($scope['keys'], $locale);

        return [
            'topics' => $topics,
            'tabs' => ['inventory' => array_values(array_intersect($scope['index'], array_keys($topics)))],
        ];
    }

    /** @return list<string> */
    public function locations(string $key): array
    {
        return collect(['materials' => 'Stock by material', 'stock' => 'Lot Register', 'detail' => 'Material details'])
            ->filter(fn (string $label, string $surface): bool => in_array($key, $this->forSurface($surface, true)['keys'], true))
            ->map(fn (string $label): string => 'Inventory · '.$label)
            ->values()->all();
    }
}
