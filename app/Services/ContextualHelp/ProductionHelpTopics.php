<?php

namespace App\Services\ContextualHelp;

final class ProductionHelpTopics
{
    public function __construct(private readonly HelpTopicResolver $resolver) {}

    /** @return array{keys: list<string>, index: list<string>} */
    public function forSurface(string $surface): array
    {
        $index = match ($surface) {
            'index' => ['planning', 'batch_size', 'scheduling', 'stock_preparation', 'tasks', 'cancel_abort'],
            'create' => ['planning', 'formula_snapshot', 'batch_size', 'scheduling', 'stock_preparation', 'tasks'],
            'detail' => ['planning', 'stock_preparation', 'start_and_actuals', 'completion', 'output_lot', 'cancel_abort'],
            'stock' => ['stock_preparation', 'start_and_actuals', 'cancel_abort'],
            'calendar' => ['scheduling', 'planning', 'tasks'],
            'flash' => ['flash_planning', 'batch_size', 'scheduling', 'stock_preparation'],
            'tasks' => ['tasks', 'task_sets'],
            'presets' => ['batch_size', 'formula_snapshot'],
            'task_sets' => ['task_sets', 'tasks'],
            default => [],
        };
        $keys = $surface === 'detail' ? [...$index, 'formula_snapshot', 'tasks', 'journal'] : $index;

        return collect(['keys' => $keys, 'index' => $index])
            ->map(fn (array $items): array => collect($items)->map(fn (string $key): string => 'production.'.$key)->all())
            ->all();
    }

    /** @return array{topics: array<string, array>, tabs: array<string, list<string>>} */
    public function resolve(string $surface, string $locale): array
    {
        $scope = $this->forSurface($surface);
        $topics = $this->resolver->resolve($scope['keys'], $locale);

        return [
            'topics' => $topics,
            'tabs' => ['production' => array_values(array_intersect($scope['index'], array_keys($topics)))],
        ];
    }

    /** @return list<string> */
    public function locations(string $key): array
    {
        return collect(['index' => 'Batches', 'create' => 'Plan a batch', 'detail' => 'Batch details', 'stock' => 'Stock preparation', 'calendar' => 'Calendar', 'flash' => 'Flash planner', 'tasks' => 'Tasks', 'presets' => 'Batch sizes', 'task_sets' => 'Task sets'])
            ->filter(fn (string $label, string $surface): bool => in_array($key, $this->forSurface($surface)['keys'], true))
            ->map(fn (string $label): string => 'Production · '.$label)
            ->values()->all();
    }
}
