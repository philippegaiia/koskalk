<?php

namespace App\Services\ContextualHelp;

final class MaterialHelpTopics
{
    public function __construct(private readonly HelpTopicResolver $resolver) {}

    /** @return array{keys: list<string>, index: list<string>} */
    public function forSurface(string $surface): array
    {
        $index = match ($surface) {
            'ingredients' => ['ingredients.catalogue', 'ingredients.duplicate', 'ingredients.identity', 'materials.prices', 'materials.codes', 'ingredients.editing_and_removal'],
            'ingredient' => ['ingredients.catalogue', 'ingredients.identity', 'ingredients.classification', 'ingredients.composition', 'ingredients.guidance_and_documents', 'ingredients.editing_and_removal'],
            'packaging', 'packaging_item' => ['packaging.library', 'packaging.quantities_and_costs', 'materials.prices', 'materials.codes', 'packaging.editing_and_removal'],
            default => [],
        };

        return [
            'keys' => $surface === 'ingredient' ? [...$index, 'ingredients.duplicate', 'ingredients.soap_chemistry', 'materials.codes'] : $index,
            'index' => $index,
        ];
    }

    /** @return array{topics: array<string, array>, tabs: array<string, list<string>>} */
    public function resolve(string $surface, string $locale): array
    {
        $scope = $this->forSurface($surface);
        $topics = $this->resolver->resolve($scope['keys'], $locale);

        return [
            'topics' => $topics,
            'tabs' => ['materials' => array_values(array_intersect($scope['index'], array_keys($topics)))],
        ];
    }

    /** @return list<string> */
    public function locations(string $key): array
    {
        return collect(['ingredients' => 'Ingredient library', 'ingredient' => 'Ingredient details', 'packaging' => 'Packaging library', 'packaging_item' => 'Packaging details'])
            ->filter(fn (string $label, string $surface): bool => in_array($key, $this->forSurface($surface)['keys'], true))
            ->values()->all();
    }
}
