<?php

namespace App\Services;

use InvalidArgumentException;

class IngredientShareFingerprint
{
    public const VERSION = 1;

    /** @param array<string, mixed> $projection */
    public function forProjection(array $projection): string
    {
        if (($projection['schema_version'] ?? null) !== self::VERSION || ! is_array($projection['technical'] ?? null)) {
            throw new InvalidArgumentException('Unsupported ingredient fingerprint version.');
        }

        return hash('sha256', 'ingredient-share-v'.self::VERSION.'\n'.json_encode($this->canonicalize($projection['technical']), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function decimal(mixed $value, int $scale): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! is_numeric($value) || ! is_finite((float) $value) || preg_match('/^-?\d+(?:\.\d+)?$/D', (string) $value) !== 1) {
            throw new InvalidArgumentException('Technical decimals must use canonical storage notation.');
        }

        return bcadd((string) $value, '0', $scale);
    }

    private function canonicalize(mixed $value, ?string $field = null): mixed
    {
        $scale = match ($field) {
            'koh_sap_value' => 6,
            'iodine_value', 'ins_value', 'peroxide_value' => 3,
            'percentage', 'percentage_in_parent', 'concentration_percent', 'max_percentage' => 5,
            default => null,
        };
        if ($scale !== null) {
            return $this->decimal($value, $scale);
        }
        if (! is_array($value)) {
            return $value;
        }
        if ($field === 'fatty_acid_profile') {
            $value = array_map(fn (mixed $entry): ?string => $this->decimal($entry, 5), $value);
        }
        if (array_is_list($value)) {
            $result = array_map(fn (mixed $entry): mixed => $this->canonicalize($entry), $value);
            if ($field !== 'components') {
                usort($result, fn (mixed $a, mixed $b): int => strcmp(json_encode($a, JSON_THROW_ON_ERROR), json_encode($b, JSON_THROW_ON_ERROR)));
            }

            return $result;
        }

        unset($value['id'], $value['component_ingredient_id']);
        if (array_key_exists('child_fingerprint', $value)) {
            unset($value['key']);
        }
        ksort($value, SORT_STRING);

        return collect($value)->map(fn (mixed $entry, string|int $key): mixed => $this->canonicalize($entry, (string) $key))->all();
    }
}
