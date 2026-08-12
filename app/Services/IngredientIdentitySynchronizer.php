<?php

namespace App\Services;

use App\Enums\IngredientAliasKind;
use App\Enums\IngredientIdentifierScheme;
use App\Models\Ingredient;
use App\Models\SupportedLocale;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class IngredientIdentitySynchronizer
{
    /**
     * @return array{cas_number:?string, ec_number:?string, additional_identifiers:array<int, array{scheme:string, value:string, is_primary:bool}>, aliases:array<int, array{locale:string, name:string, kind:string}>}
     */
    public function formState(Ingredient $ingredient): array
    {
        $identifiers = $ingredient->relationLoaded('identifiers')
            ? $ingredient->identifiers
            : $ingredient->identifiers()->get();
        $aliases = $ingredient->relationLoaded('aliases')
            ? $ingredient->aliases
            : $ingredient->aliases()->get();

        $primaryCas = $identifiers->first(fn ($identifier): bool => $identifier->scheme === IngredientIdentifierScheme::Cas && $identifier->is_primary);
        $primaryEc = $identifiers->first(fn ($identifier): bool => $identifier->scheme === IngredientIdentifierScheme::Ec && $identifier->is_primary);

        return [
            'cas_number' => $primaryCas?->value,
            'ec_number' => $primaryEc?->value,
            'additional_identifiers' => $identifiers
                ->reject(fn ($identifier): bool => $identifier === $primaryCas || $identifier === $primaryEc)
                ->map(fn ($identifier): array => [
                    'scheme' => $identifier->scheme->value,
                    'value' => $identifier->value,
                    'is_primary' => $identifier->is_primary,
                ])
                ->values()
                ->all(),
            'aliases' => $aliases
                ->map(fn ($alias): array => [
                    'locale' => $alias->locale,
                    'name' => $alias->name,
                    'kind' => $alias->kind->value,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     */
    public function sync(Ingredient $ingredient, array $state): void
    {
        [$identifiers, $aliases] = $this->validatedRows($state);

        DB::transaction(function () use ($ingredient, $identifiers, $aliases): void {
            $lockedIngredient = Ingredient::withoutGlobalScopes()
                ->lockForUpdate()
                ->findOrFail($ingredient->id);

            $this->assertLimits($lockedIngredient, $identifiers, $aliases);

            $lockedIngredient->identifiers()->delete();
            $lockedIngredient->identifiers()->createMany($identifiers);
            $lockedIngredient->aliases()->delete();
            $lockedIngredient->aliases()->createMany($aliases);
        }, attempts: 5);
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array{0:array<int, array{scheme:string, value:string, normalized_value:string, is_primary:bool}>, 1:array<int, array{locale:string, name:string, normalized_name:string, kind:string}>}
     */
    private function validatedRows(array $state): array
    {
        $rawIdentifiers = [];

        foreach ([
            ['scheme' => IngredientIdentifierScheme::Cas->value, 'value' => $state['cas_number'] ?? null, 'is_primary' => true, '_simple' => true],
            ['scheme' => IngredientIdentifierScheme::Ec->value, 'value' => $state['ec_number'] ?? null, 'is_primary' => true, '_simple' => true],
        ] as $row) {
            if (filled($row['value'])) {
                $row['value'] = trim((string) $row['value']);
                $rawIdentifiers[] = $row;
            }
        }

        $additionalIdentifiers = is_array($state['additional_identifiers'] ?? null)
            ? $state['additional_identifiers']
            : [];

        foreach ($additionalIdentifiers as $row) {
            if (is_array($row)) {
                $row['value'] = trim((string) ($row['value'] ?? ''));
                $row['_simple'] = false;
                $rawIdentifiers[] = $row;
            }
        }

        $explicitPrimarySchemes = collect($additionalIdentifiers)
            ->filter(fn (mixed $row): bool => is_array($row) && (bool) ($row['is_primary'] ?? false))
            ->map(fn (array $row): string => (string) ($row['scheme'] ?? ''))
            ->filter()
            ->unique()
            ->all();

        foreach ($rawIdentifiers as $index => $row) {
            if (($row['_simple'] ?? false) && in_array((string) $row['scheme'], $explicitPrimarySchemes, true)) {
                $rawIdentifiers[$index]['is_primary'] = false;
            }
        }

        $rawAliases = is_array($state['aliases'] ?? null) ? $state['aliases'] : [];
        $validator = Validator::make(
            ['identifiers' => $rawIdentifiers, 'aliases' => $rawAliases],
            [
                'identifiers' => ['array', 'max:10'],
                'identifiers.*' => ['array'],
                'identifiers.*.scheme' => ['required', Rule::enum(IngredientIdentifierScheme::class)],
                'identifiers.*.value' => ['required', 'string', 'max:64', 'regex:/^[\pL\pN][\pL\pN\s.()\/_:+#\-]*$/u'],
                'identifiers.*.is_primary' => ['boolean'],
                'aliases' => ['array'],
                'aliases.*' => ['array'],
                'aliases.*.locale' => ['required', 'string', 'max:16', 'regex:/^(?:und|[a-z]{2,3}(?:[-_][A-Z][a-z]{3})?)$/'],
                'aliases.*.name' => ['required', 'string', 'max:150'],
                'aliases.*.kind' => ['required', Rule::enum(IngredientAliasKind::class)],
            ],
        );

        $validator->after(function ($validator) use ($rawIdentifiers, $rawAliases): void {
            $identifierKeys = [];
            $primaryByScheme = [];
            foreach ($rawIdentifiers as $index => $row) {
                $scheme = (string) ($row['scheme'] ?? '');
                $normalized = $this->normalizeIdentifier((string) ($row['value'] ?? ''), $scheme);
                $key = $scheme.'|'.$normalized;
                if (isset($identifierKeys[$key])) {
                    $validator->errors()->add(
                        "identifiers.{$index}.value",
                        __('ingredients.editor.identity.validation.duplicate_identifier'),
                    );
                }
                $identifierKeys[$key] = true;
                if ((bool) ($row['is_primary'] ?? false)) {
                    if (isset($primaryByScheme[$scheme])) {
                        $validator->errors()->add(
                            "identifiers.{$index}.is_primary",
                            __('ingredients.editor.identity.validation.primary_identifier'),
                        );
                    }
                    $primaryByScheme[$scheme] = true;
                }
            }

            $aliasKeys = [];
            foreach ($rawAliases as $index => $row) {
                $locale = (string) ($row['locale'] ?? '');
                $normalized = $this->normalizeAlias((string) ($row['name'] ?? ''));
                $key = $locale.'|'.$normalized;
                if (isset($aliasKeys[$key])) {
                    $validator->errors()->add(
                        "aliases.{$index}.name",
                        __('ingredients.editor.identity.validation.duplicate_alias'),
                    );
                }
                $aliasKeys[$key] = true;
                if ($locale !== 'und' && ! SupportedLocale::query()->where('code', $locale)->exists()) {
                    $validator->errors()->add(
                        "aliases.{$index}.locale",
                        __('ingredients.editor.identity.validation.supported_language'),
                    );
                }
            }
        });

        $validated = $validator->validate();
        $identifiers = collect($validated['identifiers'] ?? [])
            ->map(function (array $row): array {
                $scheme = (string) $row['scheme'];
                $value = trim((string) $row['value']);

                return [
                    'scheme' => $scheme,
                    'value' => $value,
                    'normalized_value' => $this->normalizeIdentifier($value, $scheme),
                    'is_primary' => (bool) ($row['is_primary'] ?? false),
                ];
            })
            ->values()
            ->all();

        $primarySchemes = collect($identifiers)->pluck('scheme')->unique();
        foreach ($primarySchemes as $scheme) {
            if (! collect($identifiers)->contains(fn (array $row): bool => $row['scheme'] === $scheme && $row['is_primary'])) {
                foreach ($identifiers as $index => $row) {
                    if ($row['scheme'] === $scheme) {
                        $identifiers[$index]['is_primary'] = true;
                        break;
                    }
                }
            }
        }

        $aliases = collect($validated['aliases'] ?? [])
            ->map(fn (array $row): array => [
                'locale' => (string) $row['locale'],
                'name' => Str::of((string) $row['name'])->trim()->squish()->toString(),
                'normalized_name' => $this->normalizeAlias((string) $row['name']),
                'kind' => (string) $row['kind'],
            ])
            ->values()
            ->all();

        return [$identifiers, $aliases];
    }

    /**
     * @param  array<int, array{scheme:string, value:string, normalized_value:string, is_primary:bool}>  $identifiers
     * @param  array<int, array{locale:string, name:string, normalized_name:string, kind:string}>  $aliases
     */
    private function assertLimits(Ingredient $ingredient, array $identifiers, array $aliases): void
    {
        if (count($identifiers) > 10) {
            throw ValidationException::withMessages([
                'additional_identifiers' => __('ingredients.editor.identity.validation.identifiers_limit', ['limit' => 10]),
            ]);
        }

        if ($ingredient->owner_type === null) {
            $localeCounts = collect($aliases)->countBy('locale');
            if ($localeCounts->contains(fn (int $count): bool => $count > 5)) {
                throw ValidationException::withMessages([
                    'aliases' => __('ingredients.editor.identity.validation.platform_aliases_limit', ['limit' => 5]),
                ]);
            }
        } elseif (count($aliases) > 5) {
            throw ValidationException::withMessages([
                'aliases' => __('ingredients.editor.identity.validation.workspace_aliases_limit', ['limit' => 5]),
            ]);
        }
    }

    private function normalizeIdentifier(string $value, string $scheme): string
    {
        $normalized = Str::of($value)->trim()->replace(['–', '—'], '-')->toString();

        return $scheme === IngredientIdentifierScheme::Unii->value
            ? Str::upper($normalized)
            : mb_strtolower($normalized);
    }

    private function normalizeAlias(string $value): string
    {
        return mb_strtolower(Str::of($value)->trim()->squish()->toString());
    }
}
