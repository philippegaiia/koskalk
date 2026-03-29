<?php

namespace App\Services;

use App\IngredientCategory;
use App\Models\IfraCertificateLimit;
use App\Models\Ingredient;
use App\Models\IngredientVersion;
use App\Models\User;
use App\OwnerType;
use App\Visibility;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class UserIngredientAuthoringService
{
    public function __construct(
        protected IngredientDataEntryService $ingredientDataEntryService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function blankState(): array
    {
        return [
            'name' => null,
            'category' => null,
            'inci_name' => null,
            'supplier_name' => null,
            'supplier_reference' => null,
            'featured_image_path' => null,
            'info_markdown' => null,
            'allergen_entries' => [],
            'components' => [],
            'ifra' => [
                'reference_label' => null,
                'ifra_amendment' => null,
                'source_notes' => null,
                'limits' => [],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function formData(Ingredient $ingredient): array
    {
        $entryData = $this->ingredientDataEntryService->formData($ingredient);
        $currentVersion = $ingredient->currentVersion()->first()
            ?? $ingredient->versions()->where('is_current', true)->first()
            ?? $ingredient->versions()->latest('version')->first();
        $currentIfra = $currentVersion?->ifraCertificates()
            ->with('limits')
            ->where('is_current', true)
            ->latest('id')
            ->first();

        return [
            'name' => data_get($entryData, 'current_version.display_name'),
            'category' => $ingredient->category?->value,
            'inci_name' => data_get($entryData, 'current_version.inci_name'),
            'supplier_name' => data_get($entryData, 'current_version.supplier_name'),
            'supplier_reference' => data_get($entryData, 'current_version.supplier_reference'),
            'featured_image_path' => $ingredient->featured_image_path,
            'info_markdown' => $ingredient->info_markdown,
            'allergen_entries' => $entryData['allergen_entries'] ?? [],
            'components' => $entryData['components'] ?? [],
            'ifra' => [
                'reference_label' => $currentIfra?->certificate_name,
                'ifra_amendment' => $currentIfra?->ifra_amendment,
                'source_notes' => $currentIfra?->source_notes,
                'limits' => $currentIfra?->limits
                    ->sortBy('ifra_product_category_id')
                    ->map(fn (IfraCertificateLimit $limit): array => [
                        'ifra_product_category_id' => $limit->ifra_product_category_id,
                        'max_percentage' => $limit->max_percentage === null ? null : (float) $limit->max_percentage,
                        'restriction_note' => $limit->restriction_note,
                    ])
                    ->values()
                    ->all() ?? [],
            ],
        ];
    }

    public function create(array $state, User $user): Ingredient
    {
        $ingredient = new Ingredient([
            'source_file' => 'user',
            'source_key' => $this->ingredientDataEntryService->generateSourceKey('USR', 'user'),
            'source_code_prefix' => 'USR',
            'owner_type' => OwnerType::User,
            'owner_id' => $user->id,
            'workspace_id' => null,
            'visibility' => Visibility::Private,
            'requires_admin_review' => true,
            'is_active' => true,
            'is_potentially_saponifiable' => false,
        ]);

        $this->fillIngredient($ingredient, $state);
        $ingredient->save();

        return $this->syncState($ingredient, $state);
    }

    public function update(Ingredient $ingredient, array $state, User $user): Ingredient
    {
        if (! $ingredient->isOwnedBy($user)) {
            throw ValidationException::withMessages([
                'ingredient' => 'Only your own ingredients can be edited from the public app.',
            ]);
        }

        $this->fillIngredient($ingredient, $state);
        $ingredient->save();

        return $this->syncState($ingredient, $state);
    }

    public function createInlineComponent(array $state, User $user): Ingredient
    {
        return $this->create([
            'name' => $state['name'] ?? null,
            'category' => $state['category'] ?? null,
            'inci_name' => $state['inci_name'] ?? null,
            'supplier_name' => $state['supplier_name'] ?? null,
            'supplier_reference' => $state['supplier_reference'] ?? null,
            'featured_image_path' => null,
            'info_markdown' => null,
            'allergen_entries' => [],
            'components' => [],
            'ifra' => [
                'reference_label' => null,
                'ifra_amendment' => null,
                'source_notes' => null,
                'limits' => [],
            ],
        ], $user);
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function fillIngredient(Ingredient $ingredient, array $state): void
    {
        $category = $state['category'] ?? null;

        if ($category instanceof IngredientCategory) {
            $ingredient->category = $category;
        } else {
            $ingredient->category = IngredientCategory::from((string) $category);
        }

        $ingredient->featured_image_path = Arr::get($state, 'featured_image_path');
        $ingredient->info_markdown = Arr::get($state, 'info_markdown');
        $ingredient->is_potentially_saponifiable = false;
        $ingredient->is_active = true;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function syncState(Ingredient $ingredient, array $state): Ingredient
    {
        $currentVersion = $this->ingredientDataEntryService->syncCurrentData($ingredient, [
            'current_version' => [
                'display_name' => Arr::get($state, 'name'),
                'inci_name' => Arr::get($state, 'inci_name'),
                'supplier_name' => Arr::get($state, 'supplier_name'),
                'supplier_reference' => Arr::get($state, 'supplier_reference'),
                'is_active' => true,
                'is_manufactured' => false,
            ],
            'sap_profile' => [],
            'fatty_acid_entries' => [],
            'allergen_entries' => Arr::get($state, 'allergen_entries', []),
            'components' => Arr::get($state, 'components', []),
        ]);

        if ($ingredient->requiresAromaticCompliance()) {
            $this->syncIfraState($currentVersion, Arr::get($state, 'ifra', []));
        } else {
            $currentVersion->allergenEntries()->delete();
            $currentVersion->ifraCertificates()->delete();
        }

        return $ingredient->fresh([
            'components.componentIngredient.currentVersion',
            'currentVersion.allergenEntries.allergen',
            'currentVersion.ifraCertificates.limits.ifraProductCategory',
        ]);
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function syncIfraState(IngredientVersion $currentVersion, array $state): void
    {
        $limitsState = collect(Arr::get($state, 'limits', []))
            ->filter(fn (mixed $row): bool => is_array($row))
            ->filter(fn (array $row): bool => filled($row['ifra_product_category_id'] ?? null))
            ->map(fn (array $row): array => [
                'ifra_product_category_id' => (int) $row['ifra_product_category_id'],
                'max_percentage' => filled($row['max_percentage'] ?? null) ? (float) $row['max_percentage'] : null,
                'restriction_note' => filled($row['restriction_note'] ?? null) ? trim((string) $row['restriction_note']) : null,
            ])
            ->unique('ifra_product_category_id')
            ->values();

        $hasMeaningfulIfra = filled($state['reference_label'] ?? null)
            || filled($state['ifra_amendment'] ?? null)
            || filled($state['source_notes'] ?? null)
            || $limitsState->isNotEmpty();

        $currentVersion->ifraCertificates()->delete();

        if (! $hasMeaningfulIfra) {
            return;
        }

        $certificate = $currentVersion->ifraCertificates()->make([
            'certificate_name' => ($state['reference_label'] ?? null) ?: sprintf('%s current IFRA guidance', $currentVersion->display_name),
            'ifra_amendment' => $state['ifra_amendment'] ?? null,
            'source_notes' => $state['source_notes'] ?? null,
            'document_name' => null,
            'document_path' => null,
            'issuer' => null,
            'reference_code' => null,
            'published_at' => null,
            'valid_from' => null,
            'is_current' => true,
        ]);
        $certificate->save();

        $limitsState->each(function (array $limitState) use ($certificate): void {
            $certificate->limits()->create($limitState);
        });
    }
}
