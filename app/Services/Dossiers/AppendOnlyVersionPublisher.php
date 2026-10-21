<?php

namespace App\Services\Dossiers;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class AppendOnlyVersionPublisher
{
    /**
     * Publish a new current version in the series identified by the model's scope columns.
     *
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $modelClass
     * @param  array<string, mixed>  $attributes
     * @return TModel
     */
    public function publish(string $modelClass, array $attributes, ?User $publisher): Model
    {
        $scope = collect($modelClass::versionScopeColumns())
            ->mapWithKeys(fn (string $column): array => [$column => $attributes[$column] ?? null])
            ->all();

        foreach ($scope as $column => $value) {
            if ($value === null) {
                throw new InvalidArgumentException("Missing version scope column [{$column}].");
            }
        }

        return DB::transaction(function () use ($modelClass, $attributes, $scope, $publisher): Model {
            $existing = $modelClass::query()->where($scope)->lockForUpdate()->get();

            $existing
                ->filter(fn (Model $version): bool => (bool) $version->getAttribute('is_current'))
                ->each(fn (Model $version) => $version->update(['is_current' => false, 'superseded_at' => now()]));

            return $modelClass::query()->create([
                ...$attributes,
                ...$scope,
                'version' => ((int) $existing->max('version')) + 1,
                'is_current' => true,
                'superseded_at' => null,
                'published_by_user_id' => $publisher?->id,
            ]);
        }, attempts: 5);
    }
}
